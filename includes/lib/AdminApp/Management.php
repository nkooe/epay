<?php
namespace lib\AdminApp;

/** Native forms use a fixed schema. Neither table names nor column names come from the client. */
final class Management
{
    private $db;
    private $ip;
    public function __construct(Store $db, $ip) { $this->db=$db; $this->ip=$ip; }
    public static function field($key,$label,$type='text',$default='',$extra=[]) { return $extra+['key'=>$key,'label'=>$label,'type'=>$type,'default'=>$default,'max'=>128]; }
    public static function definitions()
    {
        $f=[self::class,'field'];
        $status=$f('status','启用状态','select','1',['options'=>['0'=>'停用','1'=>'启用']]);
        $money=function($key,$label,$default='0.00'){return self::field($key,$label,'decimal',$default);};
        return [
            'subchannels'=>['table'=>'subchannel','pk'=>'id','title'=>'商户子通道','fields'=>[
                $f('uid','商户编号','integer','',['reference'=>'merchants','required'=>true]),$f('name','子通道备注','text','',['max'=>30,'required'=>true]),
                $f('channel','支付通道','select','',['reference'=>'channels','required'=>true]),$status]],
            'merchant-api'=>['table'=>'user','pk'=>'uid','title'=>'商户 API 密钥','edit_only'=>true,'fields'=>[
                $f('keytype','API 签名模式','select','0',['options'=>['0'=>'MD5 + RSA（兼容模式）','1'=>'仅 RSA（安全模式）']]),
                $f('publickey','商户 RSA 公钥','textarea','',['max'=>500,'hint'=>'填写 PEM 公钥，或使用“生成 RSA 密钥对”。'])]],
            'types'=>['table'=>'type','pk'=>'id','title'=>'支付方式','fields'=>[
                $f('name','方式标识','text','',['required'=>true,'max'=>20,'pattern'=>'/^[a-zA-Z0-9_]+$/D']),$f('showname','显示名称','text','',['required'=>true,'max'=>30]),
                $f('device','支持设备','select','0',['options'=>['0'=>'全部设备','1'=>'电脑','2'=>'手机']]),$status]],
            'channels'=>['table'=>'channel','pk'=>'id','title'=>'支付通道','fields'=>[
                $f('name','通道名称','text','',['required'=>true,'max'=>30]),$f('type','支付方式','select','',['reference'=>'types','required'=>true]),
                $f('plugin','支付插件','select','',['reference'=>'plugins','required'=>true]),$f('mode','结算模式','select','0',['options'=>['0'=>'平台代收','1'=>'商户直清']]),
                $money('rate','商户结算比例（%）','100.00'),$money('costrate','通道成本比例（%）'),$money('daytop','每日金额上限'),$money('paymin','单笔最小金额'),$money('paymax','单笔最大金额'),
                $f('daymaxorder','每日订单上限','integer','0'),$f('timestart','开放开始（HH:mm）','time','00:00'),$f('timestop','开放结束（HH:mm）','time','00:00'),
                $f('status','启用状态','select','0',['options'=>['0'=>'停用','1'=>'启用']])]],
            'groups'=>['table'=>'group','pk'=>'gid','title'=>'用户组','fields'=>[
                $f('name','用户组名称','text','',['required'=>true,'max'=>30]),
                $f('settings','用户变量','text','',['max'=>250,'hint'=>'变量名:显示名，多项用逗号分隔']),
                $f('isbuy','允许购买','select','0',['options'=>['0'=>'关闭','1'=>'开启']]),$money('price','购买价格'),$f('expire','有效月数（0 永久）','integer','0'),$f('sort','排序','integer','0'),$f('visible','可购买用户组（逗号分隔）','text','',['max'=>30])]],
            'blacklist'=>['table'=>'blacklist','pk'=>'id','title'=>'黑名单','fields'=>[
                $f('type','拦截类型','select','0',['options'=>['0'=>'支付账号','1'=>'IP 地址']]),$f('content','拦截内容','text','',['required'=>true,'max'=>50]),$f('remark','备注','text','',['max'=>80]),$f('endtime','到期时间（留空永久）','datetime','')]],
            'announcements'=>['table'=>'anounce','pk'=>'id','title'=>'网站公告','fields'=>[
                $f('content','公告内容','textarea','',['required'=>true,'max'=>4000]),$f('color','颜色（如 #078744）','text','',['max'=>10]),$f('sort','排序','integer','0'),$status]],
            'rolls'=>['table'=>'roll','pk'=>'id','title'=>'通道轮询','fields'=>[
                $f('name','轮询名称','text','',['required'=>true,'max'=>30]),$f('type','支付方式','select','',['reference'=>'types','required'=>true]),
                $f('kind','轮询模式','select','0',['options'=>['0'=>'顺序轮询','1'=>'权重随机轮询','2'=>'首个可用通道']]),
                $f('info','通道配置','textarea','',['max'=>4000,'hint'=>'顺序/首个：通道编号用逗号分隔。权重：编号:权重，例如 1:3,2:1。']),$money('rate','默认结算比例（%）','100.00'),$f('status','启用状态','select','0',['options'=>['0'=>'停用','1'=>'启用']])]],
            'weixin'=>['table'=>'weixin','pk'=>'id','title'=>'公众号 / 小程序','fields'=>[
                $f('name','名称','text','',['required'=>true,'max'=>30]),$f('type','类型','select','0',['options'=>['0'=>'公众号','1'=>'小程序']]),
                $f('appid','AppID','text','',['required'=>true,'max'=>150]),$f('appsecret','AppSecret','text','',['secret'=>true,'max'=>250]),$status]],
            'domains'=>['table'=>'domain','pk'=>'id','title'=>'授权域名','fields'=>[
                $f('uid','商户编号','integer','',['reference'=>'merchants','required'=>true]),$f('domain','域名（不含协议）','text','',['required'=>true,'max'=>128]),$f('endtime','到期时间（留空永久）','datetime',''),$status]],
            'invites'=>['table'=>'invitecode','pk'=>'id','title'=>'邀请码','fields'=>[
                $f('code','邀请码','text','',['required'=>true,'max'=>40])]],
            'receivers'=>['table'=>'psreceiver','pk'=>'id','title'=>'分账规则','fields'=>[
                $f('channel','支付通道','select','',['reference'=>'channels','required'=>true]),$f('uid','指定商户（0 为全部）','integer','0',['reference'=>'merchants']),
                $f('subchannel','子通道编号（0 为默认）','integer','0'),
                $f('mode','规则模式','select','0',['options'=>['0'=>'实时分账','1'=>'延迟分账']]),
                $f('info','分账接收人','receivers','[]',['max'=>16000,'required'=>true]),$money('minmoney','最低分账金额')]],
            'merchants'=>['table'=>'user','pk'=>'uid','title'=>'商户高级资料','edit_only'=>true,'fields'=>[
                $f('gid','用户组','select','0',['reference'=>'groups']),$f('endtime','用户组到期时间（留空永久）','datetime',''),$f('upid','上级商户编号','integer','0',['reference'=>'merchants']),
                $f('settle_id','结算方式','select','1',['options'=>['1'=>'支付宝','2'=>'微信','3'=>'QQ 钱包','4'=>'银行卡']]),
                $f('account','结算账号','text','',['max'=>128]),$f('username','真实姓名','text','',['max'=>128,'required'=>true]),$f('ordername','自定义商品名','text','',['max'=>255]),
                $f('mode','手续费承担','select','0',['options'=>['0'=>'商户承担','1'=>'付款方承担']]),$f('cert','实名认证','select','0',['options'=>['0'=>'未认证','1'=>'已认证']]),
                $f('certmethod','认证方式','select','3',['options'=>['0'=>'支付宝快捷认证','1'=>'微信快捷认证','2'=>'手机号三要素','3'=>'人工审核']]),$f('certtype','认证类型','select','0',['options'=>['0'=>'个人','1'=>'企业']]),$f('certno','证件号码','text','',['max'=>18]),$f('certname','认证姓名','text','',['max'=>32]),
                $f('certcorpno','企业证件号','text','',['max'=>30]),$f('certcorpname','企业名称','text','',['max'=>80]),$money('remain_money','结算保留余额'),$money('deposit','保证金'),
                $f('refund','允许退款','select','1',['options'=>['0'=>'关闭','1'=>'开启']]),$f('transfer','允许代付','select','0',['options'=>['0'=>'关闭','1'=>'开启']]),$f('open_code','允许收款码','select','0',['options'=>['0'=>'关闭','1'=>'开启']])]],
            'settlements'=>['table'=>'settle','pk'=>'id','title'=>'结算资料','edit_only'=>true,'fields'=>[
                $f('type','结算方式','select','1',['options'=>['1'=>'支付宝','2'=>'微信','3'=>'QQ 钱包','4'=>'银行卡']]),$f('account','结算账号','text','',['required'=>true,'max'=>128]),$f('username','收款人姓名','text','',['required'=>true,'max'=>128]),$f('result','备注 / 失败原因','text','',['max'=>64])]]
        ];
    }
    public function options($reference)
    {
        $maps=['types'=>['type','id','showname'],'plugins'=>['plugin','name','showname'],'channels'=>['channel','id','name'],'groups'=>['group','gid','name']];
        if(!isset($maps[$reference]))return [];
        list($table,$pk,$name)=$maps[$reference];$out=[];
        foreach($this->db->all('SELECT `'.$pk.'`,`'.$name.'` FROM pre_'.$table.' ORDER BY `'.$pk.'`') as $row)$out[(string)$row[$pk]]=$row[$name].' (#'.$row[$pk].')';
        return (object)$out;
    }
    private function spec($resource) { $s=self::definitions()[$resource] ?? null; if(!$s)throw new ApiError('该记录没有编辑接口',422);return $s; }
    private function groupFields()
    {
        $fields=[];
        foreach($this->db->all('SELECT id,showname FROM pre_type ORDER BY id') as $type){
            $id=$type['id'];$options=['channel:0'=>'关闭','channel:-1'=>'随机可用通道','channel:-4'=>'顺序可用通道','channel:-5'=>'首个可用通道','channel:-2'=>'用户自定义子通道','channel:-3'=>'随机可用轮询组'];
            foreach(['channel','roll'] as $table)foreach($this->db->all('SELECT id,name FROM pre_'.$table.' WHERE type=?',[$id]) as $c)$options[$table.':'.$c['id']]=($table==='roll'?'轮询 · ':'').$c['name'].' (#'.$c['id'].')';
            $fields[]=self::field('route_'.$id,$type['showname'].' · 通道','select','channel:0',['options'=>(object)$options]);
            $fields[]=self::field('rate_'.$id,$type['showname'].' · 结算比例（%）','decimal','');
        }
        // Group overrides use the same validated system setting definitions.
        $keys=['transfer_alipay','transfer_wxpay','transfer_qqpay','transfer_bank','settle_open','settle_type','settle_transfer','settle_money','settle_rate','settle_alipay','settle_wxpay','settle_qqpay','settle_bank','onecode','user_transfer','user_transfer_red','transfer_rate','pay_maxmoney','pay_minmoney','pay_daymax','pay_domain_forbid','direct_settle_time','ordername'];
        foreach((new Settings($this->db,$this->ip))->allFields() as $f)if(in_array($f['key'],$keys,true)){
            $f['key']='config_'.$f['key'];$f['label']='覆盖设置 · '.$f['label'];$f['default']='';
            if($f['type']==='select')$f['options']=[''=>'跟随系统设置']+(array)$f['options'];$fields[]=$f;
        }
        return $fields;
    }
    private function row($s,$id,$lock=false)
    {
        $fields=array_column($s['fields'],'key');$fields[]=$s['pk'];
        if($s['table']==='psreceiver'){$fields[]='account';$fields[]='name';$fields[]='rate';}
        if($s['table']==='group'){$fields[]='info';$fields[]='config';}
        // Revision includes secret values, but exposes only a hash.
        $r=$this->db->row('SELECT `'.implode('`,`',$fields).'` FROM pre_'.$s['table'].' WHERE `'.$s['pk'].'`=?'.($lock?$this->db->lockClause():''),[$id]);
        if(!$r)throw new ApiError('记录不存在',404);return $r;
    }
    public function schema(array $data)
    {
        $resource=(string)($data['resource'] ?? '');$s=$this->spec($resource);$id=(string)($data['id'] ?? '');
        if($id===''&&!empty($s['edit_only']))throw new ApiError('请选择要编辑的记录');
        $row=$id!==''?$this->row($s,$id):[];$fields=[];$display=$row;
        if($resource==='receivers'&&$row&&empty($row['info']))$display['info']=json_encode([['account'=>$row['account'],'name'=>$row['name'] ?? '', 'rate'=>$row['rate'] ?? '0']]);
        if($resource==='groups'){
            foreach((json_decode($row['info'] ?? '',true) ?: []) as $type=>$v){$display['route_'.$type]=($v['type'] ?: 'channel').':'.$v['channel'];$display['rate_'.$type]=$v['rate'];}
            foreach((json_decode($row['config'] ?? '',true) ?: []) as $k=>$v)$display['config_'.$k]=$v;
            $s['fields']=array_merge($s['fields'],$this->groupFields());
        }
        foreach($s['fields'] as $f){if(isset($f['reference'])&&$f['type']==='select')$f['options']=$this->options($f['reference']);$f['value']=(string)($display[$f['key']] ?? $f['default']);if(!empty($f['secret'])){$f['configured']=$f['value']!=='';$f['value']='';}$fields[]=$f;}
        $result=['resource'=>$resource,'id'=>$id,'name'=>$s['title'],'fields'=>$fields,'revision'=>$row?hash('sha256',json_encode($row)):'','can_delete'=>$id!==''&&empty($s['edit_only']),'note'=>$resource==='channels'?'新通道默认停用。保存后进入“凭据配置”填写插件参数。':''];
        // Display-only credentials are returned only for this authenticated detail request.
        // Keep them outside the editable fields, revisions, write results and audit records.
        if($resource==='merchant-api'){
            $conf=$this->db->config();
            $result['name']='商户 API 密钥';
            $result['readonly']=true;
            $result['fields']=[];
            $result['api_info']=[
                'uid'=>(string)$row['uid'],
                'keytype'=>(string)($row['keytype'] ?? '0'),
                'apiurl'=>(string)($conf['apiurl'] ?? ''),
                'md5_key'=>(string)$this->db->value('SELECT `key` FROM pre_user WHERE uid=?',[$id]),
                'platform_public_key'=>(string)($conf['public_key'] ?? ''),
                'merchant_public_key'=>(string)($row['publickey'] ?? '')
            ];
        }
        return $result;
    }
    public function values(array $fields,array $values,$creating=false)
    {
        $out=[];$known=array_column($fields,null,'key');
        if($creating)foreach($fields as $f)if(!array_key_exists($f['key'],$values))$values[$f['key']]=$f['default'];
        foreach($values as $key=>$v){
            if(isset($known[$key])&&$known[$key]['type']==='checkbox'){
                if(!is_array($v)||count($v)>100)throw new ApiError('多选项无效');$options=(array)($known[$key]['options'] ?? []);
                foreach($v as $item)if(!is_scalar($item)||!array_key_exists((string)$item,$options))throw new ApiError('多选项无效');$out[$key]=array_values(array_unique(array_map('strval',$v)));continue;
            }
            if(!isset($known[$key])||!is_scalar($v))throw new ApiError('包含未知或无效字段');
            $f=$known[$key];$v=trim((string)$v);
            if(mb_strlen($v)>$f['max'])throw new ApiError($f['label'].'过长');
            if(!empty($f['secret'])&&$v==='')continue;
            if(!empty($f['required'])&&$v==='')throw new ApiError('请填写'.$f['label']);
            if($f['type']==='select'&&!array_key_exists($v,(array)($f['options'] ?? $this->options($f['reference'] ?? ''))))throw new ApiError($f['label'].'选项无效');
            if($f['type']==='integer'&&!preg_match('/^\d{1,9}$/D',$v))throw new ApiError($f['label'].'必须为非负整数');
            if($f['type']==='decimal'&&($v!==''&&!preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/D',$v)))throw new ApiError($f['label'].'应为非负数，最多两位小数');
            if($f['type']==='time'&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$v))throw new ApiError($f['label'].'格式应为 HH:mm');
            if($f['type']==='datetime'&&$v!==''){$dt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v);if(!$dt||$dt->format('Y-m-d H:i:s')!==$v)throw new ApiError($f['label'].'格式应为 YYYY-MM-DD HH:mm:ss');}
            if(isset($f['pattern'])&&!preg_match($f['pattern'],$v))throw new ApiError($f['label'].'格式无效');
            if(($f['reference'] ?? '')==='merchants'&&(int)$v>0&&!$this->db->row('SELECT uid FROM pre_user WHERE uid=?',[$v]))throw new ApiError('商户编号不存在');
            if($f['type']==='datetime'&&$v==='')$v=null;
            if($f['type']==='decimal'&&$v===''&&strpos($key,'rate_')!==0&&strpos($key,'config_')!==0)$v='0.00';
            $out[$key]=$v;
        }
        return $out;
    }
    public function save(array $data)
    {
        if(($data['resource'] ?? '')==='merchant-api')throw new ApiError('此页面仅支持查看与复制',405);
        $resource=(string)($data['resource'] ?? '');$s=$this->spec($resource);$id=(string)($data['id'] ?? '');$create=$id==='';
        if($create&&!empty($s['edit_only']))throw new ApiError('不支持新增此类记录');
        if(!is_array($data['values'] ?? null))throw new ApiError('字段格式无效');
        $fields=$resource==='groups'?array_merge($s['fields'],$this->groupFields()):$s['fields'];
        $v=$this->values($fields,$data['values'],$create);if(!$v)throw new ApiError('没有可保存的字段');
        $this->db->pdo->beginTransaction();
        try{
            $old=$create?[]:$this->row($s,$id,true);
            if(!$create&&!hash_equals(hash('sha256',json_encode($old)),(string)($data['revision'] ?? '')))throw new ApiError('记录已被修改，请刷新后重新编辑',409);
            $next=$v+$old;
            if($resource==='settlements'){$full=$this->db->row('SELECT status,transfer_status,transfer_no FROM pre_settle WHERE id=?',[$id]);if((int)$full['status']===1||(int)$full['transfer_status']===1||$full['transfer_no'])throw new ApiError('已付款或转账中的结算不能修改收款资料');}
            if($resource==='invites'&&!$create&&$this->db->value('SELECT status FROM pre_invitecode WHERE id=?',[$id]))throw new ApiError('已使用的邀请码不能修改');
            if($resource==='merchants'&&(string)$next['upid']===$id)throw new ApiError('上级商户不能是自己');
            if($resource==='subchannels'){
                $this->unique('subchannel','name',$next['name'],'id',$id,['uid'=>$next['uid']]);
                if(!$create&&((string)$old['uid']!==(string)$next['uid']||(string)$old['channel']!==(string)$next['channel'])&&$this->db->value('SELECT COUNT(*) FROM pre_order WHERE subchannel=?',[$id]))throw new ApiError('已有订单的子通道不能变更商户或通道');
            }
            if($resource==='types')$this->unique('type','name',$next['name'],'id',$id,['device'=>$next['device']]);
            if($resource==='channels'){
                $this->unique('channel','name',$next['name'],'id',$id);
                if(bccomp($next['rate'],'100',2)>0||bccomp($next['costrate'],'100',2)>0)throw new ApiError('比例不能超过 100%');
                if(bccomp($next['paymax'],'0',2)>0&&bccomp($next['paymin'],$next['paymax'],2)>0)throw new ApiError('单笔最小金额不能超过最大金额');
                if(!$create&&((string)$old['type']!==(string)$next['type']||(string)$old['plugin']!==(string)$next['plugin'])&&$this->db->value('SELECT COUNT(*) FROM pre_order WHERE channel=?',[$id]))throw new ApiError('已有订单的通道不能变更支付方式或插件，请新增通道');
                if($create)$v['status']='0';
            }
            if($resource==='blacklist'){if((int)$next['type']===1&&!filter_var($next['content'],FILTER_VALIDATE_IP))throw new ApiError('IP 地址格式无效');$this->unique('blacklist','content',$next['content'],'id',$id,['type'=>$next['type']]);}
            if($resource==='groups'){
                $info=json_decode($old['info'] ?? '',true) ?: [];$config=json_decode($old['config'] ?? '',true) ?: [];
                foreach($v as $k=>$value){
                    if(strpos($k,'route_')===0){$tid=substr($k,6);list($kind,$cid)=explode(':',$value);$info[$tid]=['type'=>$kind,'channel'=>$cid,'rate'=>$v['rate_'.$tid] ?? ($info[$tid]['rate'] ?? '')];unset($v[$k]);}
                    elseif(strpos($k,'rate_')===0){if($value!==''&&bccomp($value,'100',2)>0)throw new ApiError('结算比例不能超过 100%');$info[substr($k,5)]['rate']=$value;unset($v[$k]);}
                    elseif(strpos($k,'config_')===0){$config[substr($k,7)]=$value;unset($v[$k]);}
                }
                $v['info']=json_encode((object)$info,JSON_UNESCAPED_UNICODE);$v['config']=json_encode((object)$config,JSON_UNESCAPED_UNICODE);
                if($next['settings']!==''&&!preg_match('/^\w+:[^,:]+(?:,\w+:[^,:]+)*$/uD',$next['settings']))throw new ApiError('用户变量格式应为 变量名:显示名');
                if($next['visible']!=='')foreach(explode(',',$next['visible']) as $gid)if(!ctype_digit($gid)||!$this->db->row('SELECT gid FROM pre_group WHERE gid=?',[$gid]))throw new ApiError('可购买用户组编号无效');
            }
            if($resource==='weixin'&&isset($v['appsecret'])){$v['access_token']=null;$v['expiretime']=null;}
            if($resource==='invites')$this->unique('invitecode','code',$next['code'],'id',$id);
            if($resource==='domains'){
                if(!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/D',$next['domain']))throw new ApiError('域名格式无效');
                $this->unique('domain','domain',$next['domain'],'id',$id,['uid'=>$next['uid']]);
            }
            if($resource==='announcements'&&$next['color']!==''&&!preg_match('/^(?:#[a-fA-F0-9]{6}|[a-zA-Z]{1,10})$/D',$next['color']))throw new ApiError('颜色格式无效');
            if($resource==='receivers'){
                if(!$create)$this->receiverPending($id);
                if(!$create&&(int)$this->db->value('SELECT status FROM pre_psreceiver WHERE id=?',[$id])===1)throw new ApiError('请先停用分账规则再编辑');
                $items=json_decode($next['info'],true);if(!is_array($items)||!$items||count($items)>20)throw new ApiError('请添加 1 至 20 位分账接收人');$total='0';
                foreach($items as $item){if(!is_array($item)||array_diff(array_keys($item),['account','name','rate'])||!is_string($item['account'] ?? null)||trim($item['account'])===''||mb_strlen($item['account'])>128||!is_string($item['name'] ?? null)||mb_strlen($item['name'])>128||!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D',(string)($item['rate'] ?? '')))throw new ApiError('分账接收人资料无效');$total=bcadd($total,$item['rate'],2);}
                if(bccomp($total,'100',2)>0||bccomp($total,'0',2)<=0)throw new ApiError('接收人总比例必须大于 0 且不超过 100%');
                if((int)$next['subchannel']>0&&!$this->db->row('SELECT id FROM pre_subchannel WHERE id=? AND channel=? AND uid=?',[$next['subchannel'],$next['channel'],$next['uid']]))throw new ApiError('子通道与商户或支付通道不符');
                $v['uid']=$next['uid']?:null;$v['subchannel']=$next['subchannel']?:null;$v['info']=json_encode($items,JSON_UNESCAPED_UNICODE);if(strlen($v['info'])>1024)throw new ApiError('接收人资料超过数据库容量，请减少接收人数或名称长度');$v['account']=$items[0]['account'];$v['name']=$items[0]['name'];$v['rate']=$items[0]['rate'];if($create)$v['status']=0;
            }
            if($resource==='rolls'){
                if($next['info']===''&&(int)$next['status']===1)throw new ApiError('启用轮询前请填写通道配置');
                if($next['info']!=='')foreach(explode(',',$next['info']) as $part){$pieces=explode(':',trim($part));if(!ctype_digit($pieces[0])||!$this->db->row('SELECT id FROM pre_channel WHERE id=? AND type=?',[$pieces[0],$next['type']]))throw new ApiError('轮询配置包含无效或方式不符的通道');if((int)$next['kind']===1&&(count($pieces)!==2||!ctype_digit($pieces[1])||(int)$pieces[1]<1))throw new ApiError('通道权重必须为正整数');}
            }
            if($create){if(in_array($resource,['blacklist','announcements','weixin','domains','invites','receivers','subchannels'],true))$v['addtime']=date('Y-m-d H:i:s');$id=(string)$this->db->insert($s['table'],$v);}
            else $this->db->update($s['table'],$v,$s['pk'],$id);
            $this->db->audit('保存'.$s['title'],$this->ip,['id'=>$id,'fields'=>array_keys($v)]);$this->db->pdo->commit();
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
        return ['saved'=>true,'id'=>$id];
    }
    private function unique($table,$key,$value,$pk,$id,$extra=[])
    {
        $sql='SELECT `'.$pk.'` FROM pre_'.$table.' WHERE `'.$key.'`=? AND `'.$pk.'`<>?';$params=[$value,$id===''?-1:$id];foreach($extra as $k=>$v){$sql.=' AND `'.$k.'`=?';$params[]=$v;}if($this->db->row($sql,$params))throw new ApiError('相同记录已存在');
    }
    public function delete(array $data)
    {
        $resource=(string)($data['resource'] ?? '');$s=$this->spec($resource);if(!empty($s['edit_only']))throw new ApiError('不允许删除此类记录');$id=(string)($data['id'] ?? '');
        $this->db->pdo->beginTransaction();try{
            $old=$this->row($s,$id,true);if(!hash_equals(hash('sha256',json_encode($old)),(string)($data['revision'] ?? '')))throw new ApiError('记录已变化，请刷新',409);
            $refs=['types'=>[['channel','type']], 'channels'=>[['order','channel'],['transfer','channel'],['psreceiver','channel'],['subchannel','channel']], 'weixin'=>[['channel','appwxmp'],['channel','appwxa']]];
            foreach($refs[$resource] ?? [] as $ref)if($this->db->value('SELECT COUNT(*) FROM pre_'.$ref[0].' WHERE `'.$ref[1].'`=?',[$id]))throw new ApiError('记录仍被业务数据引用，请停用代替删除');
            if($resource==='channels'&&$this->db->value('SELECT COUNT(*) FROM pre_roll'))throw new ApiError('存在通道轮询配置，请先停用通道并检查轮询');
            if($resource==='groups'){if((int)$id===0)throw new ApiError('默认用户组不能删除');$this->db->query('UPDATE pre_user SET gid=0 WHERE gid=?',[$id]);}
            if($resource==='subchannels'&&($this->db->value('SELECT COUNT(*) FROM pre_order WHERE subchannel=?',[$id])||$this->db->value('SELECT COUNT(*) FROM pre_psreceiver WHERE subchannel=?',[$id])))throw new ApiError('子通道仍有业务引用，请停用代替删除');
            if($resource==='receivers'){$this->receiverPending($id);if((int)$old['status']===1)throw new ApiError('请先停用分账规则');if($this->db->value('SELECT COUNT(*) FROM pre_psorder WHERE rid=?',[$id]))throw new ApiError('规则已被分账记录引用，请停用代替删除');}
            if($resource==='invites'&&$this->db->value('SELECT status FROM pre_invitecode WHERE id=?',[$id]))throw new ApiError('已使用的邀请码不能删除');
            $this->db->query('DELETE FROM pre_'.$s['table'].' WHERE `'.$s['pk'].'`=?',[$id]);$this->db->audit('删除'.$s['title'],$this->ip,['id'=>$id]);$this->db->pdo->commit();
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}return ['deleted'=>true];
    }
    private function receiverPending($id){if($this->db->row("SELECT id FROM pre_app_operation WHERE id=? AND state='pending'",['receiver:'.$id]))throw new ApiError('接收人同步结果待核实，暂不能编辑或删除',409);}
}
