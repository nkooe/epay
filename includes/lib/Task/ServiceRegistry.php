<?php
namespace lib\Task;

use lib\AdminApp\Store;
use lib\AdminApp\ApiError;

final class ServiceRegistry
{
    private $db;private $plugins;private $turnCatalog=[];private $cacheTurn=false;
    public function __construct(Store $db,$plugins=null){$this->db=$db;$this->plugins=$plugins??ServiceConfig::root().'/plugins';}
    public function beginTurn() { $this->turnCatalog=[];$this->cacheTurn=true; }
    public function endTurn() { $this->turnCatalog=[];$this->cacheTurn=false; }
    public function discover($plugin=null)
    {
        if($this->cacheTurn&&$plugin!==null&&isset($this->turnCatalog[$plugin]))return $this->turnCatalog[$plugin];
        $groups=[];$errors=[];$manifests=[];$digests=[];$children=[];
        $channels=$plugin===null?$this->db->all('SELECT id,name,plugin,status,config FROM pre_channel ORDER BY id'):
            $this->db->all('SELECT id,name,plugin,status,config FROM pre_channel WHERE plugin=? ORDER BY id',[$plugin]);
        foreach($channels as $channel){
            try{
                $directory=Manifest::directory($this->plugins,$channel['plugin']);
                $manifest=$manifests[$channel['plugin']]??($manifests[$channel['plugin']]=Manifest::read($directory));
                if(!$manifest['services'])continue;
                $code=$digests[$channel['plugin']]??($digests[$channel['plugin']]=Manifest::digest($directory));$config=json_decode($channel['config']?:'{}',true,32,JSON_THROW_ON_ERROR);
                foreach($manifest['services'] as $spec){
                    $resource='plugin:'.$channel['id'];
                    if($spec['scope']==='plugin'){
                        $resource='plugin-group:'.$channel['plugin'];
                        if(!isset($children[$channel['plugin']]))$children[$channel['plugin']]=$this->db->all('SELECT B.id,B.channel,B.name,B.status,B.info FROM pre_subchannel B INNER JOIN pre_channel A ON B.channel=A.id WHERE A.plugin=? ORDER BY B.channel,B.id',[$channel['plugin']]);
                    }
                    if($spec['scope']==='shared'){
                        $value=$config[$spec['resource_field']]??null;
                        if(!is_scalar($value)||trim((string)$value)==='')throw new \RuntimeException('共享资源配置为空');
                        $resource='shared:'.hash('sha256',$channel['plugin'].':'.$spec['key'].':'.(string)$value);
                    }
                    $id=substr(hash('sha256',$channel['plugin'].':'.$spec['key'].':'.$resource),0,32);
                    if(!isset($groups[$id]))$groups[$id]=['id'=>$id,'channel_id'=>(int)$channel['id'],'plugin'=>$channel['plugin'],'service_key'=>$spec['key'],
                        'name'=>$spec['name'],'resource_key'=>$resource,'spec'=>$spec,'channels'=>[],'channel_enabled'=>$spec['scope']!=='plugin','code_digest'=>$code];
                    $groups[$id]['channels'][]=$channel;
                    if($spec['scope']!=='plugin')$groups[$id]['channel_enabled']=$groups[$id]['channel_enabled']&&(int)$channel['status']===1;
                }
            }catch(\PDOException $e){throw $e;}
            catch(\Throwable $e){$errors[]=['channel_id'=>$channel['id'],'error'=>SystemdController::redact($e->getMessage())];}
        }
        foreach($groups as &$group){
            if($group['spec']['scope']==='plugin'){
                $group['subchannels']=$children[$group['plugin']];
                $group['targets']=self::targets($group['channels'],$group['subchannels']);
                $group['channel_enabled']=(bool)$group['targets'];
                $group['configuration_digest']=self::configurationDigest($group['channels'],$group['subchannels']);
            }
            $group['digest']=hash('sha256',$group['code_digest'].json_encode([$group['channels'],$group['subchannels']??[]],JSON_THROW_ON_ERROR));
        }
        unset($group);$result=['services'=>$groups,'errors'=>$errors];
        if($this->cacheTurn&&$plugin!==null)$this->turnCatalog[$plugin]=$result;
        return $result;
    }
    public static function targets(array $channels,array $children)
    {
        $targets=[];$enabled=[];
        foreach($channels as $channel){
            if((int)$channel['status']!==1)continue;
            $enabled[(int)$channel['id']]=true;
            $config=json_decode($channel['config']?:'{}',true,32,JSON_THROW_ON_ERROR);$template=false;
            foreach($config as $value)if(is_string($value)&&preg_match('/^\[[^\[\]]+\]$/D',$value)){$template=true;break;}
            // A credential template is not a payable account. Its enabled children are.
            if(!$template)$targets[]=['channel_id'=>(int)$channel['id'],'subchannel_id'=>0];
        }
        foreach($children as $child)if(isset($enabled[(int)$child['channel']])&&(int)$child['status']===1)$targets[]=['channel_id'=>(int)$child['channel'],'subchannel_id'=>(int)$child['id']];
        return $targets;
    }
    public static function configurationDigest(array $channels,array $children)
    {
        return hash('sha256',json_encode([$channels,$children],JSON_THROW_ON_ERROR));
    }
    public function currentConfigurationDigest($plugin)
    {
        return self::configurationDigest(
            $this->db->all('SELECT id,name,plugin,status,config FROM pre_channel WHERE plugin=? ORDER BY id',[$plugin]),
            $this->db->all('SELECT B.id,B.channel,B.name,B.status,B.info FROM pre_subchannel B INNER JOIN pre_channel A ON B.channel=A.id WHERE A.plugin=? ORDER BY B.channel,B.id',[$plugin])
        );
    }
    public function sync($plugin=null)
    {
        $catalog=$this->discover($plugin);$created=0;
        foreach($catalog['services'] as $item){
            if($this->db->row('SELECT id FROM pre_plugin_service WHERE id=?',[$item['id']]))continue;
            $this->db->insert('plugin_service',array_intersect_key($item,array_flip(['id','channel_id','plugin','service_key','name','resource_key','digest'])));$created++;
        }
        return ['created'=>$created,'errors'=>$catalog['errors']];
    }
    public function get($id)
    {
        $row=$this->db->row('SELECT * FROM pre_plugin_service WHERE id=?',[Execution::id($id)]);
        if(!$row)throw new ApiError('插件服务不存在',404);return $row;
    }
    public function resolve(array $row,$acceptChange=false)
    {
        $catalog=$this->discover($row['plugin']);$item=$catalog['services'][$row['id']]??null;
        if(!$item)throw new \RuntimeException('插件能力、通道归属或共享资源已变化，请停止旧服务并同步新能力');
        if(!$item['channel_enabled'])throw new \RuntimeException('服务所属通道已停用');
        if(!$acceptChange&&!hash_equals($row['digest'],$item['digest'])){
            if($this->cacheTurn){unset($this->turnCatalog[$row['plugin']]);$catalog=$this->discover($row['plugin']);$item=$catalog['services'][$row['id']]??null;}
            // The running owner can atomically persist a provider credential refresh.
            // A supervisor turn may have read its row just before that commit.
            $current=$this->get($row['id']);
            if(!$item||!$item['channel_enabled']||(int)$current['revision']!==(int)$row['revision']||(int)$current['generation']!==(int)$row['generation']||!hash_equals($current['digest'],$item['digest']))throw new \RuntimeException('插件文件或通道配置已变化，请应用配置后重新启动');
        }
        return $item;
    }
    public function listing()
    {
        $catalog=$this->discover();$rows=$this->db->all('SELECT * FROM pre_plugin_service ORDER BY plugin,channel_id,service_key');
        foreach($rows as &$row){
            $item=$catalog['services'][$row['id']]??null;
            $row['changed']=!$item||!hash_equals($row['digest'],$item['digest']);
            $row['healthy']=$row['observed']==='running'&&$row['ready_at']!==null&&$item&&$item['channel_enabled']&&strtotime($row['heartbeat_at']??'1970-01-01')>=time()-$item['spec']['heartbeat_seconds'];
            $row['channels']=$item?array_map(static function($c){return ['id'=>$c['id'],'name'=>$c['name']];},$item['channels']):[];
            $row['automatic']=true;
            $row['channel_enabled']=$item ? $item['channel_enabled'] : false;
            $row['poll_interval_seconds']=Automatic::POLL_SECONDS;
            $row['operation']=$this->db->row('SELECT id,action,state,message FROM pre_service_operation WHERE service_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$row['id']]);
            unset($row['token']);
        }
        unset($row);return $rows;
    }
    public function submit(array $input)
    {
        $fields=['operation_id','id','revision','action'];
        if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new \InvalidArgumentException('服务操作字段不完整或含未知字段');
        $operation=Execution::id($input['operation_id']);$id=Execution::id($input['id']);
        $revision=Definitions::integer($input['revision'],1,2147483647,'配置版本');$action=$input['action'];
        if(!in_array($action,['start','stop','restart','refresh'],true))throw new \InvalidArgumentException('服务操作无效');
        $old=$this->db->row('SELECT * FROM pre_service_operation WHERE id=?',[$operation]);
        if($old){
            if($old['service_id']!==$id||(int)$old['revision']!==$revision||$old['action']!==$action)throw new ApiError('操作编号已用于其他请求',409);
            return ['operation_id'=>$operation,'status'=>$old['state']];
        }
        if(!(new Dispatch($this->db))->heartbeat()['healthy'])throw new ApiError('调度器未就绪，请先启动整体服务',409);
        $this->db->pdo->beginTransaction();
        try{
            $row=$this->db->row('SELECT * FROM pre_plugin_service WHERE id=?'.$this->db->lockClause(),[$id]);
            if(!$row)throw new ApiError('插件服务不存在',404);
            if((int)$row['revision']!==$revision)throw new ApiError('服务配置已更新，请刷新',409);
            if($this->db->row("SELECT id FROM pre_service_operation WHERE service_id=? AND state IN ('pending','starting')",[$id]))throw new ApiError('上次启停尚未完成',409);
            if($action!=='stop'){
                $this->resolve($row,$action==='refresh');
                if($row['observed']==='attention'&&$action!=='refresh')throw new ApiError('请先核查故障，再应用配置恢复',409);
            }
            $this->db->insert('service_operation',['id'=>$operation,'service_id'=>$id,'revision'=>$revision,'action'=>$action,'state'=>'pending','created_at'=>date('Y-m-d H:i:s')]);
            $this->db->query('UPDATE pre_plugin_service SET revision=revision+1,desired=? WHERE id=? AND revision=?',[$action==='stop'?0:1,$id,$revision]);
            $this->db->pdo->commit();return ['operation_id'=>$operation,'status'=>'queued'];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public static function instance(array $row){return $row['id'].'g'.$row['generation'];}
}
