<?php
namespace lib\AdminApp;

/** Edits only variables declared by installed plugins; never accepts paths or SQL from clients. */
final class Credentials
{
    private $db; private $ip;
    public function __construct(Store $db,$ip){$this->db=$db;$this->ip=$ip;}
    private function target(array $data,$lock=false)
    {
        $kind=$data['kind'] ?? ''; $id=(string)($data['id'] ?? '');
        if(!preg_match('/^[1-9]\d{0,9}$/D',$id)||!in_array($kind,['subchannel','merchant'],true))throw new ApiError('配置对象无效');
        $table=$kind==='merchant'?'user':'subchannel';$pk=$kind==='merchant'?'uid':'id';$col=$kind==='merchant'?'channelinfo':'info';
        $row=$this->db->row('SELECT * FROM pre_'.$table.' WHERE '.$pk.'=?'.($lock?$this->db->lockClause():''),[$id]);if(!$row)throw new ApiError('记录不存在',404);
        return [$table,$pk,$col,$row];
    }
    public function schema(array $data)
    {
        [$table,$pk,$col,$row]=$this->target($data);$fields=[];
        $channels=$table==='subchannel'?$this->db->all('SELECT id,name,plugin,config FROM pre_channel WHERE id=?',[$row['channel']]):$this->db->all('SELECT id,name,plugin,config FROM pre_channel ORDER BY id');
        $saved=json_decode($row[$col] ?? '',true) ?: [];$revisions=[$row[$col] ?? ''];
        foreach($channels as $c){
            $revisions[]=[$c['id'],$c['plugin'],$c['config']];if(!preg_match('/^\w+$/D',$c['plugin']))continue;
            $file=(defined('PLUGIN_ROOT')?PLUGIN_ROOT:dirname(__DIR__,3).'/plugins/').$c['plugin'].'/'.$c['plugin'].'_plugin.php';if(!is_file($file))continue;require_once $file;$class='\\'.$c['plugin'].'_plugin';if(!class_exists($class,false)||!property_exists($class,'info'))continue;
            $config=json_decode($c['config'] ?? '',true) ?: [];
            foreach(($class::$info['inputs'] ?? []) as $key=>$meta){
                $variable=$config[$key] ?? null;if(!is_string($variable)||!preg_match('/^\[([a-zA-Z0-9_]+)\]$/D',$variable,$m))continue;$name=$m[1];
                $type=$meta['type'] ?? 'text';$type=in_array($type,['select','checkbox','textarea'],true)?$type:'text';
                $field=Management::field($name,strip_tags($meta['name'] ?? $name),$type,'',['max'=>65536,'secret'=>(bool)preg_match('/key|secret|password|token|cert|private|sign/i',$key.' '.$name.' '.($meta['name'] ?? '')),'options'=>(object)($meta['options'] ?? []),'hint'=>strip_tags($meta['note'] ?? '')]);
                if(isset($fields[$name])&&($fields[$name]['type']!==$type||json_encode($fields[$name]['options'])!==json_encode($field['options'])))throw new ApiError('同名通道变量定义冲突：'.$name.'，请先统一父通道配置',409);
                if(isset($fields[$name]))$field['secret']=$field['secret']||$fields[$name]['secret'];$fields[$name]=$field;
            }
        }
        if($table==='user'){
            $settings=$this->db->value('SELECT settings FROM pre_group WHERE gid=?',[$row['gid']]);
            foreach(explode(',',(string)$settings) as $entry){$parts=explode(':',$entry,2);if(count($parts)===2&&preg_match('/^\w+$/D',$parts[0])&&!isset($fields[$parts[0]]))$fields[$parts[0]]=Management::field($parts[0],$parts[1],'text','',['max'=>1000]);}
            $revisions[]=$settings;
        }
        foreach($fields as &$f){$value=$saved[$f['key']] ?? '';$f['configured']=$value!=='';$f['value']=!empty($f['secret'])?'':$value;}
        return ['kind'=>$data['kind'],'id'=>(string)$row[$pk],'name'=>'自定义支付参数','fields'=>array_values($fields),'revision'=>hash('sha256',json_encode($revisions)),'note'=>'参数来自父通道的 [变量名] 和商户组配置。密钥留空保留原值。'];
    }
    public function save(array $data)
    {
        $this->db->pdo->beginTransaction();try{
            [$table,$pk,$col,$row]=$this->target($data,true);$schema=$this->schema($data);
            if(!hash_equals($schema['revision'],(string)($data['revision'] ?? '')))throw new ApiError('配置已改变，请刷新后重试',409);
            if(!is_array($data['values'] ?? null))throw new ApiError('参数格式无效');$values=(new Management($this->db,$this->ip))->values($schema['fields'],$data['values']);
            $saved=json_decode($row[$col] ?? '',true) ?: [];foreach($values as $k=>$v)$saved[$k]=$v;$json=json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>60000)throw new ApiError('参数总长度超过限制');
            $this->db->update($table,[$col=>$json],$pk,$row[$pk]);$this->db->audit('自定义支付参数',$this->ip,['kind'=>$data['kind'],'id'=>$row[$pk],'fields'=>array_keys($values)]);$this->db->pdo->commit();return ['saved'=>true];
        }catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}
    }
}
