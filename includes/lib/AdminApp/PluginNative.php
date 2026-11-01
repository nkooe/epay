<?php
namespace lib\AdminApp;

/** Explicit adapters for installed plugins. No arbitrary PHP function/HTML bridge. */
final class PluginNative
{
    const QR=['yunwechatxd','yunalipayxd','yunkdskxd','yunskljxd','yunqnmxd','yundianyxd','yunxingyifuxd'];
    private $db;private $ip;private $owner;
    public function __construct(Store $db,$ip,$owner){$this->db=$db;$this->ip=$ip;$this->owner=$owner;}
    private function target(array $data)
    {
        $kind=$data['kind'] ?? 'channel';$id=(string)($data['id'] ?? '');
        if(!in_array($kind,['channel','subchannel'],true)||!preg_match('/^[1-9]\d{0,9}$/D',$id))throw new ApiError('通道编号无效');
        Runtime::boot($this->db,$this->db->config());
        $channel=$kind==='subchannel'?\lib\Channel::getSub((int)$id):\lib\Channel::get((int)$id);
        if(!$channel)throw new ApiError('通道不存在',404);
        if(!preg_match('/^\w+$/D',$channel['plugin']))throw new ApiError('插件名称无效');
        return [$kind,$id,$channel];
    }
    private function revision($kind,$id)
    {
        $row=$kind==='channel'?$this->db->row('SELECT plugin,type,config,apptype,appwxmp,appwxa FROM pre_channel WHERE id=?',[$id]):$this->db->row('SELECT A.plugin,A.type,A.config,A.apptype,A.appwxmp,A.appwxa,B.channel,B.uid,B.status,B.info FROM pre_subchannel B INNER JOIN pre_channel A ON A.id=B.channel WHERE B.id=?',[$id]);
        return hash('sha256',json_encode($row));
    }
    public function schema(array $data)
    {
        [$kind,$id,$c]=$this->target($data);$plugin=$c['plugin'];
        $qr=in_array($plugin,self::QR,true);
        $stores=$plugin==='shouqianba';$account=in_array($plugin,['shouqianba','shouqianbapro','shouqianbaxd'],true);
        $certs=$kind==='channel'&&PluginCertificates::slots($plugin)?PluginCertificates::describe($plugin,(string)($c['appmchid'] ?? '')):null;
        // PC/X 心跳按商户和主通道记录，无法表示子通道独立在线状态，因此只在主通道展示诊断。
        $monitor=$kind==='channel'&&in_array($plugin,['jktongypc','jktongyx'],true);
        $receipt=$plugin==='jktongypc'&&(string)$this->db->value('SELECT name FROM pre_type WHERE id=?',[$c['type']])==='wxpay';
        $automatic=$plugin==='alipaybillautoxd';$shared=$plugin==='yunxingyifuxd';
        $merchant=$kind==='subchannel'?(string)$this->db->value('SELECT uid FROM pre_subchannel WHERE id=?',[$id]):'';
        $overrides=[];if($plugin==='jktongypc')foreach(['sid','payment_link_sid','receipt_sid'] as $key)$overrides[$key]=trim((string)($c[$key]??''))!=='';
        $login='';if($automatic){$gateway=rtrim((string)($c['gateway'] ?? ''),'/');if(filter_var($gateway,FILTER_VALIDATE_URL)&&preg_match('~^https?://~i',$gateway)&&parse_url($gateway,PHP_URL_USER)===null&&parse_url($gateway,PHP_URL_PASS)===null)$login=$gateway.'/alipayopen/login';}
        return ['monitor'=>$monitor,'config_generator'=>$monitor,'receipt_shops'=>$receipt,'automatic_config'=>$automatic,'shared_session'=>$shared,'sid_overrides'=>(object)$overrides,'merchant_id'=>$merchant,'parent_id'=>(string)$c['id'],'login_url'=>$login,'site'=>rtrim($GLOBALS['siteurl'],'/'),'revision'=>$this->revision($kind,$id),'id'=>$id,'kind'=>$kind,'name'=>$c['subname']??$c['name'],'plugin'=>$plugin,'qr_login'=>$qr,'stores'=>$stores,'account_login'=>$account,'certificates'=>$certs,'available'=>$qr||$stores||$account||$certs!==null||$monitor||$receipt||$automatic||$shared];
    }
    public function monitor(array $data)
    {
        [$kind,$id,$c]=$this->target($data);
        if(!in_array($c['plugin'],['jktongypc','jktongyx'],true))throw new ApiError('此通道没有监控心跳',422);
        if($kind==='subchannel')throw new ApiError('监控心跳按主通道记录，子通道没有独立状态',422);
        $uid=$data['merchant_id'] ?? ($c['plugin']==='jktongyx'?'1000':'0');
        if(!is_scalar($uid)||!preg_match('/^(?:0|[1-9]\d{0,9})$/D',(string)$uid))throw new ApiError('商户编号无效');
        if((int)$uid>0&&!$this->db->row('SELECT uid FROM pre_user WHERE uid=?',[$uid]))throw new ApiError('商户不存在',404);
        $class=Runtime::prepare($c,'monitorStatus');$result=$class::monitorStatus($c,(int)$uid);
        // SID 仅在经过认证的明确查询中显示，写入缓存时始终隐藏。
        if(($data['reveal'] ?? '')!=='1'&&isset($result['reported']['values']))foreach($result['reported']['values'] as $key=>&$value)if(stripos($key,'sid')!==false)$value=$value!==''?'已上报（已隐藏）':'';
        unset($value);return $result;
    }
    private function persist($kind,$id,$expected,array $updates)
    {
        $this->db->pdo->beginTransaction();
        try{
            if($kind==='subchannel'){
                $parent=$this->db->value('SELECT channel FROM pre_subchannel WHERE id=?',[$id]);
                $this->db->row('SELECT id FROM pre_channel WHERE id=?'.$this->db->lockClause(),[$parent]);
            }
            $this->db->row('SELECT id FROM pre_'.($kind==='channel'?'channel':'subchannel').' WHERE id=?'.$this->db->lockClause(),[$id]);
            if(!hash_equals($expected,$this->revision($kind,$id)))throw new ApiError('通道配置已变化，请刷新后重试',409);
            $table=$kind==='channel'?'channel':'subchannel';$column=$kind==='channel'?'config':'info';
            $config=json_decode((string)$this->db->value('SELECT '.$column.' FROM pre_'.$table.' WHERE id=?',[$id]),true) ?: [];
            if($kind==='subchannel'){
                $row=$this->db->row('SELECT A.plugin,A.config FROM pre_channel A INNER JOIN pre_subchannel B ON B.channel=A.id WHERE B.id=?',[$id]);
                $config=PluginConfig::childUpdates($row['plugin'],json_decode($row['config']?:'{}',true)?:[],$config,$updates);
            }else $config=array_merge($config,$updates);
            $json=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(strlen($json)>60000)throw new ApiError('通道凭据总长度超过限制',422);
            $this->db->update($table,[$column=>$json],'id',$id);
            $this->db->pdo->commit();
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
    private function receipt(array $c,$sid='')
    {
        $class=Runtime::prepare($c,'adminReceiptShops');
        try{return $class::adminReceiptShops($c,$sid);}catch(\Throwable $e){throw new ApiError('获取收款单店铺失败，请核对 SID、登录状态及店铺授权',422);}
    }
    private function invoke(array $channel,$action,array $input=[])
    {
        $class=Runtime::prepare($channel,'appLogin');
        try{$result=$class::appLogin($channel,$action,$input);}catch(\Throwable $e){error_log('Native plugin '.$channel['plugin'].': '.$e->getMessage());throw new ApiError('插件请求失败，请核对通道配置后重试',502);}
        if(!is_array($result))throw new ApiError('插件响应格式错误',502);
        return $result;
    }
    private function ok(array $r){if((int)($r['code'] ?? -1)!==0)throw new ApiError('插件操作失败：'.mb_substr(strip_tags((string)($r['msg'] ?? '请检查配置')),0,300),422);}
    private function challenge($kind,$id,$c,array $state)
    {
        $token=bin2hex(random_bytes(24));$state+=['kind'=>$kind,'id'=>$id,'revision'=>$this->revision($kind,$id)];
        // 每个会话和管理目标只保留一个有效的原生授权或选择会话。
        $proof=hash('sha256',$this->owner.':'.$kind.':'.$id);
        $this->db->query("DELETE FROM pre_app_challenge WHERE kind='plugin' AND proof=?",[$proof]);
        $this->db->insert('app_challenge',['id'=>$token,'kind'=>'plugin','proof'=>$proof,'data'=>json_encode($state,JSON_THROW_ON_ERROR),'expires'=>time()+600]);return $token;
    }
    private function resume($kind,$id,array $data)
    {
        $token=$data['ticket'] ?? '';if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))throw new ApiError('授权会话无效',409);
        $r=$this->db->row("SELECT * FROM pre_app_challenge WHERE id=? AND kind='plugin'",[$token]);
        if(!$r||!hash_equals($r['proof'],hash('sha256',$this->owner.':'.$kind.':'.$id))||(int)$r['expires']<time())throw new ApiError('授权会话已过期，请重新开始',409);
        $state=json_decode($r['data'],true);
        if(!hash_equals($state['revision'],$this->revision($kind,$id)))throw new ApiError('通道配置已变化，请重新开始',409);
        return $state;
    }
    private function qr(array $r,array $channel)
    {
        $value=(string)($r['qrcode'] ?? '');$type=$r['qrcode_type'] ?? 'text';
        if($type==='image'&&preg_match('~^https?://~i',$value)){
            $origin=parse_url((string)($channel['gateway'] ?? ''));$url=parse_url($value);
            if(!$url||!$origin||strtolower($url['host'] ?? '')!==strtolower($origin['host'] ?? '')||isset($url['user'])||isset($url['pass']))throw new ApiError('二维码图片必须来自已配置网关');
            $bytes='';$ch=curl_init($value);curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$bytes){if(strlen($bytes)+strlen($chunk)>262144)return 0;$bytes.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if(!$ok||$status!==200)throw new ApiError('二维码图片获取失败',502);
            $value='data:image/png;base64,'.base64_encode($bytes);
        }
        if(strpos($value,'data:image/')===0){
            if(!preg_match('~^data:image/(?:png|jpeg|jpg);base64,([A-Za-z0-9+/=\r\n]+)$~D',$value,$m))throw new ApiError('二维码图片格式不支持');
            $bytes=base64_decode($m[1],true);$size=$bytes===false?false:@getimagesizefromstring($bytes);
            if(!$size||strlen($bytes)>262144||$size[0]>2048||$size[1]>2048)throw new ApiError('二维码图片无效或过大');$type='image';
        }elseif($value===''||strlen($value)>4096||$type==='image')throw new ApiError('登录二维码无效');
        return ['qrcode'=>$value,'qrcode_type'=>$type];
    }
    public function run(array $data)
    {
        [$kind,$id,$c]=$this->target($data);
        return Runtime::sharingLock($this->db,'plugin:'.$kind.':'.$id,function()use($kind,$id,$data){
            [,,$c]=$this->target($data);$plugin=$c['plugin'];$action=$data['action'] ?? '';
            if($action==='app-import'){
                if($plugin!=='alipaybillautoxd')throw new ApiError('此通道不支持应用数据导入');
                $revision=$this->revision($kind,$id);
                if(!is_string($data['revision'] ?? null)||!hash_equals($revision,$data['revision']))throw new ApiError('通道配置已变化，请刷新后重试',409);
                $url=$data['url'] ?? '';if(!is_string($url)||strlen($url)>8192)throw new ApiError('应用数据地址格式无效');
                if(trim($url)==='')$url=(string)($c['app_data_url'] ?? '');
                $class=Runtime::prepare($c,'fetchApplicationData');
                try{$updates=$class::fetchApplicationData($c,trim($url));}catch(\Throwable $e){throw new ApiError('应用数据导入失败，请检查地址与网关；原有凭据已保留',422);}
                $this->persist($kind,$id,$revision,$updates);
                $this->db->audit('插件应用数据导入',$this->ip,['kind'=>$kind,'id'=>$id]);
                return ['message'=>'应用信息已导入，一次性同步地址已清除'];
            }
            if(in_array($action,['receipt-shops','receipt-bind','receipt-clear-sid'],true)){
                if($plugin!=='jktongypc'||(string)$this->db->value('SELECT name FROM pre_type WHERE id=?',[$c['type']])!=='wxpay')throw new ApiError('此通道不支持收款单店铺选择');
                $revision=$this->revision($kind,$id);
                if($action!=='receipt-bind'&&(!is_string($data['revision'] ?? null)||!hash_equals($revision,$data['revision'])))throw new ApiError('通道配置已变化，请刷新后重试',409);
                if($action==='receipt-clear-sid'){
                    $this->persist($kind,$id,$revision,['receipt_sid'=>'','receipt_shop_id'=>'','receipt_account_ref'=>'']);
                    $this->db->audit('清除收款单手填SID',$this->ip,['kind'=>$kind,'id'=>$id]);return ['message'=>'已清除手填 SID；下次查询将使用客户端上报值'];
                }
                if($action==='receipt-shops'){
                    $sid=$data['sid']??'';if(!is_string($sid)||strlen($sid)>4096||preg_match('/[\\x00-\\x1f\\x7f]/',$sid))throw new ApiError('SID 格式无效');
                    $sid=trim($sid);$r=$this->receipt($c,$sid);$items=[];
                    if($sid!==''&&$sid!==trim((string)($c['receipt_sid']??''))){
                        $this->persist($kind,$id,$revision,['receipt_sid'=>$sid,'receipt_shop_id'=>'','receipt_account_ref'=>'']);
                        [,,$c]=$this->target($data);$revision=$this->revision($kind,$id);
                    }
                    foreach(($r['shops'] ?? []) as $item)if(isset($item['ref'],$item['shop_id']))$items[]=$item;
                    if(!hash_equals($revision,$this->revision($kind,$id)))throw new ApiError('通道配置已变化，请重新获取店铺',409);
                    $ticket=$this->challenge($kind,$id,$c,['mode'=>'receipt','items'=>$items,'merchant_id'=>$r['merchant_id'],'sid_hash'=>hash('sha256',$r['sid'])]);
                    $rows=[];foreach($items as $index=>$item)$rows[]=['selection'=>(string)$index,'shop_id'=>$item['shop_id'],'shop_name'=>(string)($item['shop_name'] ?? ''),'account_name'=>(string)($item['account_name'] ?? ''),'account_id'=>(string)($item['account_id'] ?? '')];
                    return ['ticket'=>$ticket,'rows'=>$rows,'message'=>$rows?'请选择店铺与账号':'没有可选的授权店铺'];
                }
                $state=$this->resume($kind,$id,$data);
                if(($state['mode'] ?? '')!=='receipt')throw new ApiError('会话类型无效');
                $index=$data['selection'] ?? null;
                if(!is_string($index)||!preg_match('/^(?:0|[1-9]\d*)$/D',$index)||!isset($state['items'][(int)$index]))throw new ApiError('请选择本次列表中的店铺');
                $class=Runtime::prepare($c,'reportedConfig');$reported=$class::reportedConfig($c);
                $sid=trim((string)($c['receipt_sid'] ?? ''));if($sid==='')$sid=trim((string)($reported['values']['receipt_sid'] ?? ''));
                if(!hash_equals($state['sid_hash'],hash('sha256',$sid))||$state['merchant_id']!==$reported['merchant_id'])throw new ApiError('客户端登录信息已变化，请重新获取店铺',409);
                $selected=$state['items'][(int)$index];
                $this->persist($kind,$id,$state['revision'],['pay_mode'=>'receipt','receipt_shop_id'=>(string)$selected['shop_id'],'receipt_account_ref'=>(string)$selected['ref']]);
                $this->db->query('DELETE FROM pre_app_challenge WHERE id=?',[$data['ticket']]);
                $this->db->audit('收款单店铺绑定',$this->ip,['id'=>$id,'shop_id'=>$selected['shop_id']]);return ['message'=>'收款单店铺和账号已绑定'];
            }
            if($action==='cert-upload'){
                if($kind!=='channel')throw new ApiError('证书按主通道商户号管理，不支持子通道单独上传');
                $result=PluginCertificates::save($plugin,(string)($c['appmchid'] ?? ''),$data);
                $this->db->audit('插件证书上传',$this->ip,['kind'=>$kind,'id'=>$id,'files'=>$result['files']]);return $result;
            }
            if(in_array($action,['qr-start','qr-poll'],true)){
                if(!in_array($plugin,self::QR,true))throw new ApiError('此通道不支持扫码授权');
                if($action==='qr-start'){
                    $revision=$this->revision($kind,$id);
                    list($r,$captured)=PluginConfig::collect($c,function()use($c){return $this->invoke($c,'start');});
                    $this->ok($r);$qr=$this->qr($r,$c);
                    $remote=(string)($r['session_id'] ?? $r['uid'] ?? '');if($remote===''||strlen($remote)>1024)throw new ApiError('插件登录会话不完整',502);
                    $this->persist($kind,$id,$revision,$captured);
                    $ticket=$this->challenge($kind,$id,$c,['mode'=>'qr','remote'=>$remote,'appid'=>(string)($r['appid'] ?? '')]);
                    $this->db->audit('插件扫码开始',$this->ip,['id'=>$id]);return $qr+['ticket'=>$ticket,'state'=>'waiting','message'=>'请扫码并在对应应用中确认，10 分钟后会话失效'];
                }
                $state=$this->resume($kind,$id,$data);if(($state['mode'] ?? '')!=='qr')throw new ApiError('会话类型无效');
                list($r,$captured)=PluginConfig::collect($c,function()use($c,$state){return $this->invoke($c,'poll',['session_id'=>$state['remote'],'appid'=>$state['appid']]);});
                if((int)($r['code'] ?? -1)===201)return ['state'=>($r['state'] ?? '')==='scanned'?'scanned':'waiting','message'=>mb_substr(strip_tags((string)($r['msg'] ?? '等待扫码')),0,300)];
                $this->ok($r);$this->persist($kind,$id,$state['revision'],$captured);$this->db->query('DELETE FROM pre_app_challenge WHERE id=?',[$data['ticket']]);
                $this->db->audit('插件扫码成功',$this->ip,['id'=>$id]);return ['state'=>'done','message'=>'授权成功，登录信息已保存'];
            }
            if($action==='shared-session-sync'){
                if($plugin!=='yunxingyifuxd')throw new ApiError('此插件不支持共享登录态同步');
                $revision=$this->revision($kind,$id);
                if(!is_string($data['revision']??null)||!hash_equals($revision,$data['revision']))throw new ApiError('通道配置已变化，请刷新后重试',409);
                list($r,$captured)=PluginConfig::collect($c,function()use($c){return $this->invoke($c,'sync');});
                if((int)($r['code']??-1)!==0)throw new ApiError('星译付登录态同步失败，请检查账号、密码及网关配置',422);
                if($captured)$this->persist($kind,$id,$revision,$captured);
                $this->db->audit('星译付共享登录态同步',$this->ip,['kind'=>$kind,'id'=>$id]);return ['message'=>'共享 AppSession 已同步并保存'];
            }
            if(in_array($action,['account-login','stores','store-bind'],true)){
                if(!in_array($plugin,['shouqianba','shouqianbapro','shouqianbaxd'],true)||($action!=='account-login'&&$plugin!=='shouqianba'))throw new ApiError('插件不支持此操作');
                if($action==='account-login'){
                    $r=$this->invoke($c,$plugin==='shouqianba'?'sync':'activate');$this->ok($r);
                    $this->db->audit('插件账号登录',$this->ip,['kind'=>$kind,'id'=>$id]);return ['message'=>'登录信息已同步保存'];
                }
                if($action==='stores'){
                    $r=$this->invoke($c,'stores');$this->ok($r);$items=[];
                    foreach(($r['terminals'] ?? []) as $t){
                        $sn=(string)($t['sn'] ?? $t['terminal_sn'] ?? $t['terminalSn'] ?? $t['client_sn'] ?? '');
                        $store=(string)($t['store_sn'] ?? $t['storeSn'] ?? '');if($sn===''||$store==='')continue;
                        $items[]=['store_sn'=>$store,'store_id'=>(string)($t['store_id'] ?? $t['storeId'] ?? ''),'terminal_sn'=>$sn,'terminal_id'=>(string)($t['id'] ?? $t['terminal_id'] ?? ''),'store_name'=>(string)($t['store_name'] ?? $store),'terminal_name'=>(string)($t['name'] ?? $t['terminal_name'] ?? $sn)];
                    }
                    $ticket=$this->challenge($kind,$id,$c,['mode'=>'stores','items'=>$items]);return ['ticket'=>$ticket,'rows'=>$items,'message'=>$items?'请选择门店与终端':'没有可绑定的门店终端'];
                }
                $state=$this->resume($kind,$id,$data);if(($state['mode'] ?? '')!=='stores')throw new ApiError('会话类型无效');
                $selection=null;foreach($state['items'] as $item)if($item['terminal_sn']===($data['terminal_sn'] ?? '')&&$item['store_sn']===($data['store_sn'] ?? '')){$selection=$item;break;}
                if(!$selection)throw new ApiError('请选择本次门店列表中的终端');
                $r=$this->invoke($c,'sync_qrcodes',$selection);$this->ok($r);$this->db->query('DELETE FROM pre_app_challenge WHERE id=?',[$data['ticket']]);
                $this->db->audit('插件门店绑定',$this->ip,['kind'=>$kind,'id'=>$id,'store_sn'=>$selection['store_sn'],'terminal_sn'=>$selection['terminal_sn']]);return ['message'=>'门店、终端与收款码已绑定'];
            }
            throw new ApiError('插件操作不存在',404);
        });
    }
}
