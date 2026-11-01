<?php
namespace lib\AdminApp;

/** 原生表单契约；支付选项以插件元数据为准。 */
final class ChannelConfig
{
    private $db;
    public function __construct(Store $db){$this->db=$db;}
    public static function revision(array $row){return hash('sha256',json_encode(array_intersect_key($row,array_flip(['plugin','type','config','apptype','appwxmp','appwxa']))));}
    public function schema($id)
    {
        $row=$this->db->row('SELECT * FROM pre_channel WHERE id=?',[$id]);
        if(!$row)throw new ApiError('通道不存在',404);
        Runtime::boot($this->db,$this->db->config());
        $plugin=$row['plugin'];
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$plugin))throw new ApiError('插件名称无效');
        // 插件目录受限时，隔离测试仍可读取表单说明。
        $path=dirname(__DIR__,3).'/plugins/'.$plugin.'/'.$plugin.'_plugin.php';
        if(!is_file($path))throw new ApiError('通道插件文件不存在',404);
        require_once $path;$class='\\'.$plugin.'_plugin';
        if(!class_exists($class,false)||!property_exists($class,'info'))throw new ApiError('插件不支持配置描述');
        $payType=(string)$this->db->value('SELECT name FROM pre_type WHERE id=?',[$row['type']]);
        $values=json_decode($row['config'] ?? '{}',true) ?: [];
        $channel=array_merge($row,$values);
        $info=is_callable([$class,'configForm'])?$class::configForm($channel,$payType):$class::$info;
        $fields=[];
        foreach(($info['inputs'] ?? []) as $key=>$input){
            if(!preg_match('/^[A-Za-z0-9_]+$/D',(string)$key)||($plugin==='jktongypc'&&$key==='receipt_account_ref'))continue;
            $type=$input['type'] ?? 'text';$options=$input['options'] ?? [];
            $value=$values[$key] ?? '';
            if($value===''&&isset($input['default']))$value=$input['default'];
            if($value===''&&$type==='select'&&$options)$value=(string)array_key_first($options);
            if($type==='checkbox'&&!is_array($value))$value=[];
            $secret=(bool)preg_match('/key|secret|password|token|cert|private|sign|(?:^|_)sid$/i',$key.' '.($input['name'] ?? ''))||in_array($key,['sid','payment_link_sid','receipt_sid','app_data_url'],true);
            $field=['key'=>(string)$key,'label'=>strip_tags($input['name'] ?? $key),'type'=>in_array($type,['select','checkbox','textarea'],true)?$type:'text','secret'=>$secret,'configured'=>$value!==''&&$value!==[],'value'=>$secret?'':$value,'options'=>(object)$options,'hint'=>strip_tags($input['note'] ?? '')];
            $when=[];
            if($plugin==='jktongypc'){
                if($payType==='wxpay'){
                    $mode=['qrcode_type'=>'self','qrcode'=>'self','sid'=>'platform','payment_link_sid'=>'smallbook','payment_link_shop_id'=>'smallbook','receipt_sid'=>'receipt','receipt_shop_id'=>'receipt'];
                    if(isset($mode[$key]))$when['pay_mode']=[$mode[$key]];
                }
                if(in_array($key,['pid','qrcode_mod'],true))$when['qrcode_type']=['pid'];
                if($key==='qrcode')$when['qrcode_type']=['qrcode','image'];
            }
            if($plugin==='alipaybillautoxd'){
                if(in_array($key,['pid','qrcode_mod'],true))$when['type']=['pid'];
                if($key==='qrcode')$when['type']=['qrcode'];
                if($key==='proxy')$when['proxy_enabled']=['1'];
            }
            $field['show_when']=(object)$when;$fields[]=$field;
        }
        $interfaces=$info['select_'.$payType] ?? $info['select'] ?? [];
        if($interfaces)$fields[]=['key'=>'apptype','label'=>'可用支付接口','type'=>'checkbox','secret'=>false,'value'=>empty($row['apptype'])?[]:explode(',',$row['apptype']),'options'=>(object)$interfaces,'required'=>true];
        if($payType==='wxpay')foreach(['appwxmp'=>['bindwxmp',0,'绑定微信公众号'],'appwxa'=>['bindwxa',1,'绑定微信小程序']] as $key=>$spec){
            if(empty($info[$spec[0]]))continue;
            $options=['0'=>'不绑定'];foreach($this->db->all('SELECT id,name,appid FROM pre_weixin WHERE type=? ORDER BY id',[$spec[1]]) as $wx)$options[(string)$wx['id']]=$wx['name'].'（'.$wx['appid'].'）';
            $fields[]=['key'=>$key,'label'=>$spec[2],'type'=>'select','secret'=>false,'value'=>(string)($row[$key] ?? '0'),'options'=>(object)$options];
        }
        $note=trim(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is','',$info['note'] ?? '')));
        if($plugin==='jktongypc')$note='支付状态按商户与通道分别计算。SID 留空保留手填值；没有手填值时使用对应商户上报。';
        return ['id'=>$id,'name'=>$row['name'],'plugin'=>$plugin,'pay_type'=>$payType,'revision'=>self::revision($row),'fields'=>$fields,'note'=>$note,'special_actions'=>true];
    }
    public function save(array $data,$ip)
    {
        $id=$data['id'];$updates=$data['values'] ?? null;
        if(!is_array($updates))throw new ApiError('配置字段格式错误');
        $this->db->pdo->beginTransaction();
        try{
            $row=$this->db->row('SELECT * FROM pre_channel WHERE id=?'.$this->db->lockClause(),[$id]);
            if(!$row)throw new ApiError('通道不存在',404);
            if(isset($data['revision'])&&!hash_equals(self::revision($row),(string)$data['revision']))throw new ApiError('通道配置已变化，请刷新后重试',409);
            $schema=$this->schema($id);$allowed=array_column($schema['fields'],null,'key');$values=json_decode($row['config'] ?? '{}',true) ?: [];$columns=[];
            foreach($updates as $key=>$value){
                if(!isset($allowed[$key]))throw new ApiError('配置包含未知字段');$field=$allowed[$key];
                if(!empty($field['secret'])&&$value==='')continue;
                if($field['type']==='checkbox'){
                    if(!is_array($value)||count($value)>count((array)$field['options']))throw new ApiError('多选项不正确');
                    foreach($value as $v)if(!is_scalar($v)||!array_key_exists((string)$v,(array)$field['options']))throw new ApiError('多选项不正确');
                    $value=array_values(array_unique(array_map('strval',$value)));
                    if($key==='apptype'&&!$value)throw new ApiError('请至少选择一个可用的支付接口');
                }elseif(!is_scalar($value)||mb_strlen((string)$value)>65536)throw new ApiError('配置字段超长');
                elseif($field['type']==='select'&&!array_key_exists((string)$value,(array)$field['options']))throw new ApiError('选项不正确');
                if(in_array($key,['apptype','appwxmp','appwxa'],true))$columns[$key]=$key==='apptype'?implode(',',$value):(int)$value;
                else $values[$key]=$value;
            }
            if($row['plugin']==='jktongypc'){
                $old=json_decode($row['config'] ?? '{}',true) ?: [];
                if(!empty($updates['receipt_sid'])&&$updates['receipt_sid']!==($old['receipt_sid'] ?? '')){
                    $values['receipt_shop_id']='';$values['receipt_account_ref']='';
                }elseif(array_key_exists('receipt_shop_id',$updates)&&$updates['receipt_shop_id']!==($old['receipt_shop_id'] ?? ''))$values['receipt_account_ref']='';
            }
            $columns['config']=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $this->db->update('channel',$columns,'id',$id);$this->db->pdo->commit();
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
        $this->db->audit('通道配置',$ip,['id'=>$id,'fields'=>array_keys($updates)]);return ['saved'=>true];
    }
}
