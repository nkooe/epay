<?php
namespace lib\Merchant;

use lib\AdminApp\ApiError;
use lib\AdminApp\ChannelConfig;
use lib\AdminApp\Store;

/** 商户独立通道。平台、子通道继续使用各自原有配置。 */
final class OwnedChannels
{
    private $db;
    public function __construct(Store $db) { $this->db=$db; }

    public function enabled()
    {
        return (string)$this->db->value("SELECT v FROM pre_config WHERE k='merchant_channel_open'")==='1';
    }
    public function authorize($uid)
    {
        if (!$this->enabled()) throw new ApiError('平台未开启商户通道管理',403);
        $user=$this->db->row('SELECT * FROM pre_user WHERE uid=?',[(int)$uid]);
        if (!$user || (int)$user['status']!==1) throw new ApiError('商户不存在或已停用',403);
        return $user;
    }
    private static function id($value)
    {
        if ((!is_string($value)&&!is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)) throw new ApiError('编号无效');
        return (int)$value;
    }
    private static function amount($value,$label)
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',$value)) throw new ApiError($label.'须为非负金额，最多两位小数');
        return number_format((float)$value,2,'.','');
    }
    private function owned($uid,$id,$lock=false)
    {
        $row=$this->db->row('SELECT * FROM pre_channel WHERE id=? AND owner_uid=?'.($lock?$this->db->lockClause():''),[self::id($id),(int)$uid]);
        if (!$row) throw new ApiError('通道不存在或不属于当前商户',404);
        return $row;
    }
    public static function revision(array $row) { return hash('sha256',json_encode($row)); }
    private static function checkRevision(array $row,$revision)
    {
        if (!is_string($revision)||!hash_equals(self::revision($row),$revision)) throw new ApiError('通道已变化，请刷新后重试',409);
    }
    private function audit($uid,$action,$id,$ip)
    {
        $this->db->insert('log',['uid'=>(int)$uid,'type'=>'商户通道'.$action,'date'=>date('Y-m-d H:i:s'),'ip'=>$ip,'data'=>json_encode(['channel'=>(int)$id],JSON_UNESCAPED_UNICODE)]);
    }
    public function metadata($uid)
    {
        $this->authorize($uid);
        $types=$this->db->all('SELECT id,name,showname FROM pre_type WHERE status=1 ORDER BY id');
        $plugins=$this->db->all('SELECT name,showname,types FROM pre_plugin ORDER BY name');
        $sources=$this->db->all('SELECT type,source FROM pre_merchant_channel_source WHERE uid=?',[(int)$uid]);
        return ['types'=>$types,'plugins'=>$plugins,'sources'=>$sources,'routing_locked'=>(new ChannelPolicy($this->db))->load($uid)['mode']==='custom'];
    }
    public function listing($uid)
    {
        $this->authorize($uid);
        $today=date('Y-m-d'); $yesterday=date('Y-m-d',strtotime('-1 day'));
        $rows=$this->db->all('SELECT C.id,C.name,C.mode,C.type,C.plugin,C.status,C.daystatus,C.daytop,C.daymaxorder,C.paymin,C.paymax,C.timestart,C.timestop,T.showname typename,P.showname pluginname FROM pre_channel C LEFT JOIN pre_type T ON T.id=C.type LEFT JOIN pre_plugin P ON P.name=C.plugin WHERE C.owner_uid=? ORDER BY C.id DESC',[(int)$uid]);
        $sums=$this->db->all('SELECT channel,date,SUM(realmoney) money FROM pre_order WHERE uid=? AND date IN (?,?) AND status>0 GROUP BY channel,date',[(int)$uid,$today,$yesterday]);
        $money=[];foreach($sums as $r)$money[(int)$r['channel']][$r['date']]=$r['money'];
        foreach($rows as &$r){$r['today']=number_format((float)($money[$r['id']][$today]??0),2,'.','');$r['yesterday']=number_format((float)($money[$r['id']][$yesterday]??0),2,'.','');}
        return $rows;
    }
    public function detail($uid,$id)
    {
        $this->authorize($uid);$row=$this->owned($uid,$id);
        $fields=array_flip(['id','name','type','plugin','daytop','daymaxorder','paymin','paymax','timestart','timestop','status','mode']);
        return array_intersect_key($row,$fields)+['revision'=>self::revision($row)];
    }
    /** Shared by merchant, admin Web and App editors for merchant-owned channels. */
    public function dailyStatus(array $old,array $next,$refresh=false)
    {
        if(!$refresh&&round((float)$next['daytop'],2)===round((float)$old['daytop'],2)&&(int)$next['daymaxorder']===(int)$old['daymaxorder'])return (int)$old['daystatus'];
        $today=$this->db->row('SELECT COUNT(*) orders,COALESCE(SUM(realmoney),0) money FROM pre_order WHERE channel=? AND status>0 AND date=?',[(int)$old['id'],date('Y-m-d')]);
        return (int)(((float)$next['daytop']>0&&(float)$today['money']>=(float)$next['daytop'])||((int)$next['daymaxorder']>0&&(int)$today['orders']>=(int)$next['daymaxorder']));
    }
    public function save($uid,array $data,$ip)
    {
        $this->authorize($uid);
        $allowed=['id','revision','name','type','plugin','daytop','daymaxorder','paymin','paymax','timestart','timestop'];
        if (array_diff(array_keys($data),$allowed)) throw new ApiError('通道包含不允许修改的字段');
        $name=trim((string)($data['name']??''));
        if ($name===''||mb_strlen($name)>30) throw new ApiError('显示名称应为1至30个字符');
        $type=$this->db->row('SELECT id,name FROM pre_type WHERE id=? AND status=1',[self::id($data['type']??'')]);
        $plugin=$data['plugin']??'';
        if (!$type||!is_string($plugin)||!preg_match('/^[A-Za-z0-9_]+$/D',$plugin)) throw new ApiError('支付方式或插件无效');
        $p=$this->db->row('SELECT types FROM pre_plugin WHERE name=?',[$plugin]);
        if (!$p||!in_array($type['name'],explode(',',$p['types']??''),true)||!is_file(dirname(__DIR__,3).'/plugins/'.$plugin.'/'.$plugin.'_plugin.php')) throw new ApiError('插件未安装或不支持该支付方式');
        $next=['name'=>$name,'type'=>(int)$type['id'],'plugin'=>$plugin];
        foreach(['paymin'=>'单笔最小','paymax'=>'单笔最大'] as $key=>$label)$next[$key]=self::amount((string)(($data[$key]??'')===''?'0':$data[$key]),$label);
        if ((float)$next['paymax']>0&&(float)$next['paymin']>(float)$next['paymax']) throw new ApiError('单笔最小不能超过单笔最大');
        foreach(['daytop'=>'单日限额','daymaxorder'=>'单日限笔'] as $key=>$label){
            $v=(string)(($data[$key]??'')===''?'0':$data[$key]);
            if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D',$v)) throw new ApiError($label.'须为非负整数');
            $next[$key]=(int)$v;
        }
        foreach(['timestart','timestop'] as $key){$v=(string)($data[$key]??'');if($v!==''&&(!ctype_digit($v)||(int)$v>23))throw new ApiError('开放时间须为0至23小时');$next[$key]=$v===''?null:(int)$v;}
        if (($next['timestart']===null)!==($next['timestop']===null)) throw new ApiError('请同时填写开始和结束时间，或同时留空');
        $create=empty($data['id']);$this->db->pdo->beginTransaction();
        try{
            $this->db->row('SELECT uid FROM pre_user WHERE uid=?'.$this->db->lockClause(),[(int)$uid]);
            $id=$create?0:self::id($data['id']);$old=$create?[]:$this->owned($uid,$id,true);
            if(!$create)self::checkRevision($old,$data['revision']??null);
            if($this->db->row('SELECT id FROM pre_channel WHERE owner_uid=? AND name=? AND id<>?',[(int)$uid,$name,$id]))throw new ApiError('同名通道已存在');
            if($create){
                $next+=['owner_uid'=>(int)$uid,'mode'=>1,'rate'=>'100.00','costrate'=>'0.00','status'=>0,'daystatus'=>0];
                $id=(int)$this->db->insert('channel',$next);
            }else{
                if($old['plugin']!==$plugin||(int)$old['type']!==$next['type']){
                    if($this->db->row('SELECT trade_no FROM pre_order WHERE channel=? LIMIT 1',[$id]))throw new ApiError('已有订单的通道不能变更支付方式或插件，请新增通道');
                    $next+=['config'=>null,'apptype'=>null,'appwxmp'=>null,'appwxa'=>null,'status'=>0];
                }
                $next['daystatus']=$this->dailyStatus($old,$next);
                $this->db->query('UPDATE pre_channel SET '.implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($next))).' WHERE id=? AND owner_uid=?',array_merge(array_values($next),[$id,(int)$uid]));
            }
            $this->audit($uid,$create?'新增':'编辑',$id,$ip);$this->db->pdo->commit();return ['id'=>$id];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public function credentials($uid,$id)
    {
        $this->authorize($uid);$this->owned($uid,$id);
        return (new ChannelConfig($this->db))->schema((int)$id,true,true);
    }
    public function saveCredentials($uid,array $data,$ip)
    {
        $this->authorize($uid);$this->db->pdo->beginTransaction();
        try{
            $row=$this->owned($uid,$data['id']??'',true);
            if(!is_string($data['revision']??null)||!hash_equals(ChannelConfig::revision($row),$data['revision']))throw new ApiError('密钥配置已变化，请刷新',409);
            $schema=(new ChannelConfig($this->db))->schema((int)$row['id'],true,true);$known=array_column($schema['fields'],null,'key');
            $updates=$data['values']??null;if(!is_array($updates)||array_diff(array_keys($updates),array_keys($known)))throw new ApiError('配置包含未声明字段');
            $values=json_decode($row['config']??'{}',true)?:[];$columns=[];
            foreach($updates as $k=>$v){
                $f=$known[$k];
                if($f['type']==='checkbox'){
                    if(!is_array($v)||count($v)>count((array)$f['options']))throw new ApiError('多选参数无效');
                    foreach($v as $item)if(!is_scalar($item)||!array_key_exists((string)$item,(array)$f['options']))throw new ApiError('多选参数无效');
                    $v=array_values(array_unique(array_map('strval',$v)));if($k==='apptype'&&!$v)throw new ApiError('请选择可用支付接口');
                }elseif(!is_string($v)||strlen($v)>65536)throw new ApiError('配置字段格式或长度无效');
                elseif($f['type']==='select'&&!array_key_exists($v,(array)$f['options']))throw new ApiError('配置选项无效');
                if($k==='apptype')$columns['apptype']=implode(',',$v);else $values[$k]=$v;
            }
            if($row['plugin']==='jktongypc'){
                $old=json_decode($row['config']??'{}',true)?:[];
                if(isset($updates['receipt_sid'])&&$updates['receipt_sid']!==($old['receipt_sid']??'')){$values['receipt_shop_id']='';$values['receipt_account_ref']='';}
                elseif(isset($updates['receipt_shop_id'])&&$updates['receipt_shop_id']!==($old['receipt_shop_id']??''))$values['receipt_account_ref']='';
            }
            self::validateConfig($values);
            $json=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>60000)throw new ApiError('密钥配置总长度超过限制');
            $columns['config']=$json;$this->db->update('channel',$columns,'id',$row['id']);
            $revision=ChannelConfig::revision(array_replace($row,$columns));
            $this->audit($uid,'配置',$row['id'],$ip);$this->db->pdo->commit();return ['saved'=>true,'revision'=>$revision];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public function action($uid,$id,$action,array $data,$ip)
    {
        $this->authorize($uid);$this->db->pdo->beginTransaction();
        try{
            $row=$this->owned($uid,$id,true);self::checkRevision($row,$data['revision']??null);
            if($action==='delete'){
                if($this->db->row('SELECT id FROM pre_psreceiver WHERE channel=? LIMIT 1',[$row['id']]))throw new ApiError('通道关联分账规则或进件渠道，请先解除关联');
                if($this->db->row('SELECT id FROM pre_subchannel WHERE channel=? LIMIT 1',[$row['id']]))throw new ApiError('通道存在子通道关联，请管理员先处理');
                $this->db->query('DELETE FROM pre_channel WHERE id=? AND owner_uid=?',[$row['id'],(int)$uid]);
            }elseif($action==='status'){
                if(!in_array($data['status']??null,[0,1,'0','1'],true))throw new ApiError('状态无效');
                if((int)$data['status']===1&&(!$row['config']||$row['config']==='{}'||$row['config']==='[]'))throw new ApiError('请先配置密钥');
                $this->db->query('UPDATE pre_channel SET status=? WHERE id=? AND owner_uid=?',[(int)$data['status'],$row['id'],(int)$uid]);
            }else throw new ApiError('操作不存在');
            $this->audit($uid,$action==='delete'?'删除':'启停',$row['id'],$ip);$this->db->pdo->commit();return ['saved'=>true];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public function source($uid,$type)
    {
        return (string)$this->db->value('SELECT source FROM pre_merchant_channel_source WHERE uid=? AND type=?',[(int)$uid,(int)$type])==='owned'?'owned':'platform';
    }
    public function saveSource($uid,$type,$source,$ip)
    {
        $this->authorize($uid);$type=self::id($type);
        if((new ChannelPolicy($this->db))->load($uid)['mode']==='custom')throw new ApiError('管理员已设置独立通道策略，请联系管理员调整',403);
        if(!in_array($source,['platform','owned'],true)||!$this->db->row('SELECT id FROM pre_type WHERE id=? AND status=1',[$type]))throw new ApiError('收款来源或支付方式无效');
        $this->db->pdo->beginTransaction();try{
            $this->db->row('SELECT uid FROM pre_user WHERE uid=?'.$this->db->lockClause(),[(int)$uid]);
            if($this->db->row('SELECT uid FROM pre_merchant_channel_source WHERE uid=? AND type=?',[(int)$uid,$type]))$this->db->query('UPDATE pre_merchant_channel_source SET source=? WHERE uid=? AND type=?',[$source,(int)$uid,$type]);
            else $this->db->insert('merchant_channel_source',['uid'=>(int)$uid,'type'=>$type,'source'=>$source]);
            $this->audit($uid,'选路',$type,$ip);$this->db->pdo->commit();return ['saved'=>true];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    public function select($uid,$gid,$type,$money=0)
    {
        if($this->source($uid,$type)!=='owned')return null;
        $group=$this->db->value('SELECT info FROM pre_group WHERE gid=?',[(int)$gid]);
        if(!$group&&(int)$gid!==0)$group=$this->db->value('SELECT info FROM pre_group WHERE gid=0');
        $rules=json_decode($group?:'{}',true)?:[];$rule=$rules[$type]??[];
        if(isset($rule['channel'])&&(int)$rule['channel']===0)throw new ApiError('平台未允许商户使用该支付方式');
        $paytype=$this->db->row('SELECT name,status FROM pre_type WHERE id=?',[(int)$type]);
        if(!$paytype||(int)$paytype['status']!==1)throw new ApiError('支付方式已停用');
        $rows=$this->db->all('SELECT * FROM pre_channel WHERE owner_uid=? AND type=? AND status=1 AND daystatus=0 ORDER BY id',[(int)$uid,(int)$type]);
        $rows=array_values(array_filter($rows,fn($r)=>self::available($r,$money)));
        if(!$rows)throw new ApiError('没有可用的自有通道，请检查启停、限额和开放时间');
        $mode=(int)($rule['channel']??-1);$r=$rows[$mode===-1?random_int(0,count($rows)-1):0];
        $rate=$rule['rate']??null;
        return ['typeid'=>(int)$type,'typename'=>$paytype['name'],'plugin'=>$r['plugin'],'channel'=>(int)$r['id'],'subchannel'=>0,'rate'=>empty($rate)?$r['rate']:$rate,'apptype'=>$r['apptype'],'mode'=>$r['mode'],'paymin'=>$r['paymin'],'paymax'=>$r['paymax'],'timestart'=>$r['timestart'],'timestop'=>$r['timestop']];
    }
    public static function available(array $r,$money=0)
    {
        if($money>0&&((float)$r['paymin']>$money||((float)$r['paymax']>0&&$money>(float)$r['paymax'])))return false;
        if($r['timestart']!==null&&$r['timestop']!==null&&((int)$r['timestart']>0||(int)$r['timestop']>0)){
            $h=(int)date('H');$s=(int)$r['timestart'];$e=(int)$r['timestop'];
            if($s<$e?($h<$s||$h>$e):($h<$s&&$h>$e))return false;
        }
        return true;
    }

    /** 商户可配置凭据，不能借插件字段读取服务器本地文件或指定内网地址。 */
    public static function validateConfig(array $values)
    {
        foreach($values as $key=>$value){
            if(is_array($value)){self::validateConfig($value);continue;}
            if(!is_scalar($value)&&$value!==null)throw new ApiError('配置格式无效');
            $text=trim((string)$value);if($text==='')continue;
            if($key==='proxy'){self::publicProxy($text);continue;}
            if(preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\|\.{1,2}[\\\\/]|(?:file|php|phar|zip|glob|expect)://)~i',$text))throw new ApiError('商户配置不能引用服务器文件路径');
            if(preg_match('~^https?://~i',$text)){
                try{\lib\Security\PublicUrl::resolve($text);}catch(\InvalidArgumentException $e){throw new ApiError('接口或资源地址必须指向有效公网服务');}
            }
        }
    }

    /** Resolve a merchant proxy without letting protocol syntax bypass public-address checks. */
    public static function publicProxy($proxy)
    {
        try{
            $parts=parse_url(strpos($proxy,'://')===false?'socks5h://'.$proxy:$proxy);
            if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https','socks5','socks5h'],true)||empty($parts['host'])||empty($parts['port'])||!empty($parts['path'])||isset($parts['query'])||isset($parts['fragment']))throw new \InvalidArgumentException();
            return \lib\Security\PublicUrl::resolve('http://'.$parts['host'].':'.$parts['port'].'/');
        }catch(\InvalidArgumentException|\ValueError $e){throw new ApiError('商户代理必须使用有效的公网 HTTP 或 SOCKS5 地址及端口');}
    }

    public function test($uid,$id,array $data,$ip,$base)
    {
        $user=$this->authorize($uid);$row=$this->owned($uid,$id);
        if((int)$user['pay']!==1)throw new ApiError('商户支付权限未开启',403);
        $conf=FinancialPolicy::config($this->db,$this->db->config(),$user);
        if(!empty($conf['cert_force'])&&empty($user['cert']))throw new ApiError('请先完成实名认证',403);
        if(!$this->db->value('SELECT status FROM pre_type WHERE id=?',[$row['type']]))throw new ApiError('支付方式已停用');
        if(!$row['config']||$row['config']==='{}'||$row['config']==='[]')throw new ApiError('请先配置密钥');
        $money=self::amount((string)($data['money']??''),'测试金额');
        if((float)$money<=0)throw new ApiError('测试金额必须大于0');
        if(!self::available($row,(float)$money))throw new ApiError('测试金额或开放时间不符合通道设置');
        foreach(['pay_minmoney','pay_maxmoney'] as $k){$limit=(float)($conf[$k]??0);if($limit>0&&($k==='pay_minmoney'?(float)$money<$limit:(float)$money>$limit))throw new ApiError('测试金额超出平台支付范围');}
        foreach(['pay_minmoney','pay_maxmoney'] as $k){$limit=(float)($user[$k]??0);if($limit>0&&($k==='pay_minmoney'?(float)$money<$limit:(float)$money>$limit))throw new ApiError('测试金额超出当前商户支付范围');}
        $name=trim((string)($data['name']??'支付测试'));if($name===''||mb_strlen($name)>64)throw new ApiError('订单名称应为1至64个字符');
        $info=$this->db->value('SELECT info FROM pre_group WHERE gid=?',[(int)$user['gid']]);
        if(!$info)$info=$this->db->value('SELECT info FROM pre_group WHERE gid=0');
        $rules=json_decode($info?:'{}',true)?:[];$rule=$rules[$row['type']]??[];
        if(isset($rule['channel'])&&(int)$rule['channel']===0)throw new ApiError('平台未允许商户使用该支付方式');
        $rate=empty($rule['rate'])?$row['rate']:$rule['rate'];
        $fee=round((float)$money*(100-(float)$rate)/100,2);
        if((float)($conf['payfee_lessthan']??0)>0&&(float)($conf['payfee_mincost']??0)>0&&$fee<round((float)$conf['payfee_lessthan'],2))$fee=round((float)$conf['payfee_mincost'],2);
        $realmoney=number_format((int)$user['mode']===1?(float)$money+$fee:(float)$money,2,'.','');
        $getmoney=number_format((int)$user['mode']===1?(float)$money:max(0,(float)$money-$fee),2,'.','');
        $trade=date('YmdHis').random_int(10000,99999);$return=rtrim($base,'/').'/user/channel_test.php?trade_no='.$trade;
        $this->db->pdo->beginTransaction();try{
            $fresh=$this->db->row('SELECT money,status,pay FROM pre_user WHERE uid=?'.$this->db->lockClause(),[(int)$uid]);
            if(!$fresh||(int)$fresh['status']!==1||(int)$fresh['pay']!==1)throw new ApiError('商户支付权限未开启',403);
            $locked=$this->owned($uid,$id,true);if(!hash_equals(self::revision($row),self::revision($locked)))throw new ApiError('通道配置已变化，请重试',409);
            if((int)$row['mode']===1&&round((float)$realmoney-(float)$getmoney,2)>(float)$fresh['money'])throw new ApiError('余额不足以支付通道服务费');
            $this->db->insert('order',['trade_no'=>$trade,'out_trade_no'=>$trade,'uid'=>(int)$uid,'tid'=>3,'channel_test'=>1,'addtime'=>date('Y-m-d H:i:s'),'name'=>$name,'money'=>$money,'type'=>$row['type'],'channel'=>$row['id'],'subchannel'=>0,'realmoney'=>$realmoney,'getmoney'=>$getmoney,'notify_url'=>$return,'return_url'=>$return,'domain'=>parse_url($return,PHP_URL_HOST),'ip'=>$ip,'status'=>0]);
            $this->audit($uid,'测试',$row['id'],$ip);$this->db->pdo->commit();return ['url'=>'channel_test.php?trade_no='.$trade,'trade_no'=>$trade];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
}
