<?php
namespace lib\Task;

use lib\AdminApp\Store;

/** Cooperative liveness: validate local idle work or completed provider operations before heartbeat. */
final class ServiceContext
{
    private $db;private $row;private $spec;private $stop=false;private $ready=false;private $locks=[];private $namespace;private $configurationDigest;private $channels;private $children;private $codeDigest;
    public function __construct(Store $db,array $row,array $item)
    {
        $this->db=$db;$this->row=$row;$this->spec=$item['spec'];
        $this->configurationDigest=$item['configuration_digest']??null;
        $this->channels=$item['channels'];$this->children=$item['subchannels']??[];$this->codeDigest=$item['code_digest']??null;
        $this->namespace=$db->value('SELECT DATABASE()').':'.$db->tablePrefix();
        $resources=[$row['resource_key']];
        foreach($item['channels'] as $channel)$resources[]='plugin:'.$channel['id'];
        $resources=array_unique($resources);sort($resources);
        try{
            foreach($resources as $resource){
                $key='epay.task.'.substr(hash('sha256',$this->namespace.':'.$resource),0,54);
                if((int)$db->value('SELECT GET_LOCK(?,0)',[$key])!==1)throw new \RuntimeException('服务业务资源正被占用');
                $this->locks[]=$key;
            }
        }catch(\Throwable $e){$this->release();throw $e;}
    }
    public function stopRequested()
    {
        if($this->stop)return true;
        $row=$this->db->row('SELECT desired,revision,token FROM pre_plugin_service WHERE id=?',[$this->row['id']]);
        $worker=(new Dispatch($this->db))->heartbeat();
        if(!$row||!(int)$row['desired']||(int)$row['revision']!==(int)$this->row['revision']||$row['token']!==$this->row['token']||!$worker['healthy'])$this->requestStop();
        return $this->stop;
    }
    public function requestStop(){if(!$this->stop){$this->stop=true;pcntl_alarm($this->spec['stop_seconds']);}}
    /** Check again after provider I/O, immediately before changing any payment. */
    public function checkpoint()
    {
        if($this->stopRequested())throw new ServiceStopped('服务正在停止');
        if($this->configurationDigest!==null&&!hash_equals($this->configurationDigest,(new ServiceRegistry($this->db))->currentConfigurationDigest($this->row['plugin'])))throw new ServiceFault('主通道或子通道配置已变化，请应用配置后重新启动');
    }
    /** Only explicit provider-returned credential fields may update a live snapshot. */
    public function saveCredentials(array $target,array $updates,array $allowed,$childOverrides=false)
    {
        if(!$this->configurationDigest||array_diff(array_keys($updates),$allowed))throw new ServiceFault('监控服务尝试写入未声明的配置字段');
        $this->checkpoint();$channels=$this->channels;$children=$this->children;$ci=null;$si=null;
        foreach($channels as $i=>$c)if((int)$c['id']===(int)$target['channel_id']&&(int)$c['status']===1)$ci=$i;
        if($ci===null)throw new ServiceFault('凭据不属于当前启用通道');
        if($target['subchannel_id']){
            foreach($children as $i=>$c)if((int)$c['id']===(int)$target['subchannel_id']&&(int)$c['channel']===(int)$target['channel_id']&&(int)$c['status']===1)$si=$i;
            if($si===null)throw new ServiceFault('凭据不属于当前启用子通道');
        }
        $parent=json_decode($channels[$ci]['config']?:'{}',true,32,JSON_THROW_ON_ERROR);
        $child=$si===null?[]:json_decode($children[$si]['info']?:'{}',true,32,JSON_THROW_ON_ERROR);
        foreach($updates as $field=>$value){
            if(!is_scalar($value)&&$value!==null)throw new ServiceFault('凭据字段必须为标量');
            if($si!==null&&($child[\lib\AdminApp\PluginConfig::KEY]['plugin']??'')===$this->row['plugin']&&in_array($field,\lib\AdminApp\PluginConfig::FIELDS[$this->row['plugin']]??[],true)){
                $child=\lib\AdminApp\PluginConfig::childUpdates($this->row['plugin'],$parent,$child,[$field=>(string)$value]);
            }elseif($si!==null&&($childOverrides||preg_match('/^\[([^\[\]]+)\]$/D',(string)($parent[$field]??''),$match)))$child[$childOverrides?$field:$match[1]]=(string)$value;
            else $parent[$field]=(string)$value;
        }
        $channels[$ci]['config']=json_encode($parent,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if($si!==null)$children[$si]['info']=json_encode($child,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $digest=hash('sha256',$this->codeDigest.json_encode([$channels,$children],JSON_THROW_ON_ERROR));
        $this->db->pdo->beginTransaction();
        try{
            // Check-and-swap prevents a concurrent administrator edit from being overwritten.
            foreach([['channel','config',$channels,$this->channels,$ci],['subchannel','info',$children,$this->children,$si]] as [$table,$field,$next,$old,$index]){
                if($index===null||$next[$index][$field]===$old[$index][$field])continue;
                $count=$this->db->query('UPDATE pre_'.$table.' SET '.$field.'=? WHERE id=? AND '.$field.'=? AND status=1',[$next[$index][$field],$old[$index]['id'],$old[$index][$field]])->rowCount();
                if($count!==1)throw new ServiceFault('凭据刷新期间配置发生变化');
            }
            // Verify other channels too, not only the account that refreshed its token.
            if(!hash_equals(ServiceRegistry::configurationDigest($channels,$children),(new ServiceRegistry($this->db))->currentConfigurationDigest($this->row['plugin'])))throw new ServiceFault('凭据刷新期间其他通道配置发生变化');
            if($digest!==$this->row['digest']){
                $count=$this->db->query('UPDATE pre_plugin_service SET digest=? WHERE id=? AND token=? AND revision=? AND desired=1 AND digest=?',[$digest,$this->row['id'],$this->row['token'],$this->row['revision'],$this->row['digest']])->rowCount();
                if($count!==1)throw new ServiceFault('凭据刷新期间服务代次发生变化');
            }
            $this->db->pdo->commit();
            $this->channels=$channels;$this->children=$children;$this->row['digest']=$digest;$this->configurationDigest=ServiceRegistry::configurationDigest($channels,$children);
            return true;
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public function ready()
    {
        if($this->stopRequested())throw new ServiceStopped('服务正在停止，取消就绪');
        if($this->ready)throw new \LogicException('服务不能重复上报就绪');
        $this->ready=true;
        $this->write("observed='running',ready_at=?,heartbeat_at=?",[date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
        pcntl_alarm($this->spec['heartbeat_seconds']);
    }
    public function heartbeat()
    {
        if(!$this->ready)throw new \LogicException('服务尚未业务就绪');
        if($this->stopRequested()){$this->requestStop();return false;}
        foreach($this->locks as $key)if((int)$this->db->value('SELECT IS_USED_LOCK(?)',[$key])!==(int)$this->db->value('SELECT CONNECTION_ID()'))throw new ServiceFault('业务资源锁丢失');
        $this->write('heartbeat_at=?',[date('Y-m-d H:i:s')]);pcntl_alarm($this->spec['heartbeat_seconds']);return true;
    }
    private function write($sets,array $values)
    {
        $this->db->query('UPDATE pre_plugin_service SET '.$sets.' WHERE id=? AND token=? AND revision=?',
            array_merge($values,[$this->row['id'],$this->row['token'],$this->row['revision']]));
        $current=$this->db->row('SELECT token,revision FROM pre_plugin_service WHERE id=?',[$this->row['id']]);
        if(!$current||$current['token']!==$this->row['token']||(int)$current['revision']!==(int)$this->row['revision'])throw new ServiceFault('实例代次已过期');
    }
    public function release()
    {
        foreach(array_reverse($this->locks) as $key){try{$this->db->value('SELECT RELEASE_LOCK(?)',[$key]);}catch(\Throwable $ignored){}}
        $this->locks=[];
    }
}
