<?php
namespace lib\AdminApp;

/** Reviewed configuration allowlist; never accepts administrator credentials as settings. */
final class Settings
{
    private $db;private $ip;private $conf;
    public function __construct(Store $db,$ip){$this->db=$db;$this->ip=$ip;$this->conf=$db->config();}
    public function definitions()
    {
        $groups=json_decode(file_get_contents(__DIR__.'/Settings.json'),true);
        $groups['template']=[Management::field('template','首页模板','select','index',['options'=>[]])];
        $groups['cron']=[];
        $f=[Management::class,'field'];$switch=['0'=>'关闭','1'=>'开启'];
        $groups['groupbuy']=[$f('group_buy','用户组购买','select','0',['options'=>$switch])];
        $groups['wxkf']=[
            $f('wework_token','企业微信 Token','text','',['secret'=>true]),$f('wework_aeskey','企业微信 AESKey','text','',['secret'=>true]),
            $f('wework_payopen','微信客服支付','select','0',['options'=>$switch]),$f('wework_paymsgmode','支付链接发送方式','select','0',['options'=>['0'=>'确认后发送','1'=>'直接发送']]),
            $f('wework_paykfid','指定客服','select','0',['options'=>['0'=>'随机客服']]),$f('wework_contact','联系说明','text','',['max'=>1000]),$f('wework_remark','备注','text','',['max'=>1000]),
            $f('wxappkf_token','小程序客服 Token','text','',['secret'=>true]),$f('wxappkf_aeskey','小程序客服 AESKey','text','',['secret'=>true]),
            $f('wxappkf_applet','客服小程序','select','0',['options'=>['0'=>'未设置']]),$f('wxappkf_payopen','小程序客服支付','select','0',['options'=>$switch]),$f('wxappkf_msg_transfer','转发客服消息','select','0',['options'=>$switch])
        ];
        foreach($groups as &$fields)foreach($fields as &$f){
            $key=$f['key'];
            if(in_array($key,['keywords','close_keylogin'],true))$f['secret']=false;
            if(in_array($key,['cert_appcode','cert_appcode2','ocr_baiduid'],true))$f['secret']=true;
            if($f['type']!=='select')continue;
            $options=(array)($f['options'] ?? []);
            if(preg_match('/^transfer_(alipay|wxpay|qqpay|bank)$/D',$key,$m)){
                foreach($this->db->all('SELECT C.id,C.name FROM pre_channel C JOIN pre_plugin P ON P.name=C.plugin WHERE P.transtypes LIKE ?',['%'.$m[1].'%']) as $c)$options[(string)$c['id']]=$c['name'].' (#'.$c['id'].')';
            }elseif(in_array($key,['alipay_web_login','alipay_mini_login','cert_channel','login_alipay'],true)){
                foreach($this->db->all("SELECT C.id,C.name FROM pre_channel C JOIN pre_type T ON T.id=C.type WHERE T.name='alipay'") as $c)$options[(string)$c['id']]=$c['name'].' (#'.$c['id'].')';
            }elseif(in_array($key,['wxpay_web_login','login_wx','login_wxa'],true)){
                foreach($this->db->all('SELECT id,name FROM pre_weixin WHERE type=?',[$key==='login_wxa'?1:0]) as $c)$options[(string)$c['id']]=$c['name'].' (#'.$c['id'].')';
            }elseif($key==='wework_paykfid'){
                foreach($this->db->all('SELECT A.openkfid,A.name FROM pre_wxkfaccount A JOIN pre_wework B ON B.id=A.wid WHERE B.status=1') as $c)$options[$c['openkfid']]=$c['name'];
            }elseif($key==='wxappkf_applet'){
                foreach($this->db->all('SELECT id,name FROM pre_weixin WHERE type=1') as $c)$options[(string)$c['id']]=$c['name'];
            }elseif($key==='template'){
                foreach(glob(dirname(__DIR__,3).'/template/*',GLOB_ONLYDIR) as $dir)if(is_file($dir.'/index.php'))$options[basename($dir)]=basename($dir);
            }elseif(isset($f['reference'])){
                $options=$options+(array)(new Management($this->db,$this->ip))->options($f['reference']);
            }
            $f['options']=(object)$options;
        }
        return $groups;
    }
    public function allFields(){return array_merge(...array_values($this->definitions()));}
    public function schema($group)
    {
        if($group==='cron')return ['group'=>'cron','read_only'=>true,'fields'=>[],
            'note'=>'计划任务已统一由服务器任务服务执行。请在网页后台「系统设置 → 任务与服务管理」配置周期、启停服务和查看记录；旧定时 URL 已停用。'];
        $spec=$this->definitions()[$group] ?? null;if(!$spec)throw new ApiError('设置分组不存在',404);$fields=[];
        foreach($spec as $f){
            $value=(string)($this->conf[$f['key']] ?? $f['default']);
            if($f['type']==='select'&&$value===''&&!array_key_exists('',(array)$f['options']))$value=(string)array_key_first((array)$f['options']);
            $f['value']=!empty($f['secret'])?'':$value;$f['configured']=!empty($f['secret'])&&$value!=='';$fields[]=$f;
        }
        $result=['group'=>$group,'fields'=>$fields,'note'=>''];
        return $result;
    }
    public function save(array $data)
    {
        $group=(string)($data['group'] ?? '');$fields=$this->definitions()[$group] ?? null;$values=$data['values'] ?? null;
        if(!$fields||!is_array($values))throw new ApiError('设置字段无效');$known=array_column($fields,null,'key');$updates=[];
        foreach($values as $key=>$value){
            if(!isset($known[$key])||!is_scalar($value))throw new ApiError('设置包含无效字段');$f=$known[$key];$value=(string)$value;
            if(!empty($f['secret'])&&$value==='')continue;
            if(mb_strlen($value)>($f['max'] ?? 4000))throw new ApiError($f['label'].'过长');
            // An obsolete saved option may be preserved, but cannot be selected for a new value.
            if($f['type']==='select'&&!array_key_exists($value,(array)$f['options'])&&$value!==(string)($this->conf[$key] ?? ''))throw new ApiError($f['label'].'选项无效');
            if($key==='sitename'&&(trim($value)===''||mb_strlen($value)>40))throw new ApiError('平台名称应为 1 至 40 个字符');
            if(in_array($key,['localurl','apiurl','proxy_url','wx_open_url'],true)&&$value!==''&&!preg_match('~^https?://[^\s]+$~D',$value))throw new ApiError($f['label'].'应为完整 HTTP(S) 地址');
            if(in_array($key,['email','mail_name'],true)&&$value!==''&&strpos($value,'@')!==false&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new ApiError('邮箱格式不正确');
            if(preg_match('/^(?:pre_maxmoney|pay_maxmoney|pay_minmoney|pay_daymax|settle_money|settle_rate|settle_fee_min|settle_fee_max|transfer_rate)$/D',$key)&&$value!==''&&(!preg_match('/^\d{1,8}(?:\.\d{1,4})?$/D',$value)||(strpos($key,'rate')!==false&&bccomp($value,'100',4)>0)))throw new ApiError($f['label'].'金额或比例无效');
            $updates[$key]=$value;
        }
        $next=$updates+$this->conf;if(($next['wework_payopen'] ?? '0')==='1'&&($next['wxappkf_payopen'] ?? '0')==='1')throw new ApiError('微信客服支付与小程序客服支付只能开启一种');
        $this->db->pdo->beginTransaction();try{foreach($updates as $k=>$v)$this->db->setting($k,$v);$this->db->audit('系统配置',$this->ip,['group'=>$group,'fields'=>array_keys($updates)]);$this->db->pdo->commit();}catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
        return ['saved'=>true];
    }
}
