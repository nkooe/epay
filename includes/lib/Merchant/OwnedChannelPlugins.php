<?php
namespace lib\Merchant;

use lib\AdminApp\ApiError;
use lib\AdminApp\ChannelConfig;
use lib\AdminApp\PluginNative;
use lib\AdminApp\Store;

/** Plugin controls for a logged-in merchant's own primary channels. */
final class OwnedChannelPlugins
{
    private $db;
    public function __construct(Store $db){$this->db=$db;}
    private function channel($uid,$id)
    {
        (new OwnedChannels($this->db))->authorize($uid);
        if((!is_string($id)&&!is_int($id))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id))throw new ApiError('编号无效');
        $row=$this->db->row('SELECT * FROM pre_channel WHERE id=? AND owner_uid=?',[(int)$id,(int)$uid]);
        if(!$row)throw new ApiError('通道不存在或不属于当前商户',404);
        return $row;
    }
    private function native($uid,$session,$ip){return new PluginNative($this->db,$ip,'merchant:'.$uid.':'.$session,(int)$uid);}
    public function schema($uid,$id,$session,$ip)
    {
        $this->channel($uid,$id);
        $schema=$this->native($uid,$session,$ip)->schema(['id'=>(string)$id]);
        return array_intersect_key($schema,array_flip(['id','revision','plugin','qr_login','account_login','stores','receipt_shops','automatic_config','shared_session','monitor','config_generator']));
    }
    public function run($uid,array $data,$session,$ip)
    {
        $row=$this->channel($uid,$data['id']??'');
        $action=$data['action']??'';if(!is_string($action))throw new ApiError('操作无效');
        $id=(string)$row['id'];
        // Client-supplied kind, owner or merchant ID never selects an administrative target.
        if(array_diff(array_keys($data),['id','action','revision','ticket','store_sn','terminal_sn','selection','sid','url']))throw new ApiError('插件包含不允许的参数');
        if($action==='application-fetch'){
            if($row['plugin']!=='alipaybillautoxd')throw new ApiError('此插件不支持应用数据获取');
            if(!is_string($data['revision']??null)||!hash_equals(ChannelConfig::revision($row),$data['revision']))throw new ApiError('密钥配置已变化，请刷新',409);
            $values=$this->fetchApplication($data['url']??'');
            $fresh=$this->channel($uid,$row['id']);if(!hash_equals(ChannelConfig::revision($row),ChannelConfig::revision($fresh)))throw new ApiError('通道配置已变化，请刷新',409);
            return ['values'=>$values,'message'=>'获取成功，请点击保存'];
        }
        if($action==='config-generator'){
            if(!in_array($row['plugin'],['jktongypc','jktongyx'],true))throw new ApiError('此插件没有客户端配置信息');
            $user=(new OwnedChannels($this->db))->authorize($uid);
            return ['pid'=>(int)$uid,'key'=>$user['key']];
        }
        $native=$this->native($uid,$session,$ip);
        if($action==='monitor')return $native->monitor(['id'=>$id]);
        $result=$native->run(array_replace($data,['id'=>$id,'kind'=>'channel']));
        // Retain the requesting merchant's attribution without including plugin credentials.
        $this->db->insert('log',['uid'=>(int)$uid,'type'=>'商户通道插件操作','date'=>date('Y-m-d H:i:s'),'ip'=>$ip,'data'=>json_encode(['channel'=>(int)$id,'action'=>$action])]);
        return $result;
    }
    public static function applicationValues(array $data)
    {
        $find=function(array $keys,array $source,$depth=0)use(&$find){
            foreach($keys as $key)if(isset($source[$key])&&is_scalar($source[$key])&&(string)$source[$key]!=='')return (string)$source[$key];
            if($depth<12)foreach($source as $value)if(is_array($value)&&($found=$find($keys,$value,$depth+1))!=='')return $found;
            return '';
        };
        $values=['app_id'=>$find(['app_id','appid','appId','application_id'],$data),'app_secret'=>$find(['private_key','privateKey','app_secret','appSecret','app_private_key'],$data)];
        if($values['app_id']===''||$values['app_secret']==='')throw new ApiError('地址未返回完整应用信息，原有配置已保留',422);
        $pid=$find(['pid','user_id','userId','alipay_user_id','principal_id'],$data);if($pid!=='')$values['pid']=$pid;
        OwnedChannels::validateConfig($values);return $values;
    }
    private function fetchApplication($url)
    {
        if(!is_string($url)||strlen($url)>8192)throw new ApiError('应用数据地址格式无效');
        try{$target=\lib\Security\PublicUrl::resolve(trim($url));}catch(\InvalidArgumentException $e){throw new ApiError('应用数据地址必须指向有效公网服务');}
        $host=strpos($target['host'],':')!==false?'['.$target['host'].']':$target['host'];$ip=strpos($target['ip'],':')!==false?'['.$target['ip'].']':$target['ip'];
        $body='';$ch=curl_init(trim($url));
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECT_TO=>[$host.':'.$target['port'].':'.$ip.':'.$target['port']],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_HTTPHEADER=>['Accept: application/json','Connection: close'],CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>131072)return 0;$body.=$chunk;return strlen($chunk);}]);
        try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);}finally{curl_close($ch);}
        if($ok===false||$status<200||$status>=300)throw new ApiError('获取失败，请核对地址或网络；原有配置已保留',422);
        $data=json_decode($body,true,24);if(!is_array($data))throw new ApiError('地址返回的不是有效应用信息',422);
        return self::applicationValues($data);
    }
}
