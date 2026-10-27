<?php
namespace lib\AdminApp;

final class Tools
{
    private $db;private $ip;private $conf;
    public function __construct(Store $db,$ip){$this->db=$db;$this->ip=$ip;$this->conf=$db->config();}
    private function day($v){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$v);if(!$d||$d->format('Y-m-d')!==$v)throw new ApiError('日期格式应为 YYYY-MM-DD');return $d;}
    public function statistics(array $data)
    {
        $kind=$data['kind'] ?? 'payments';if(!in_array($kind,['payments','transfers'],true))throw new ApiError('统计类型无效');
        $from=$this->day($data['from'] ?? date('Y-m-d',strtotime('-6 days')));$to=$this->day($data['to'] ?? date('Y-m-d'));
        $days=(int)$from->diff($to)->format('%r%a');if($days<0||$days>365)throw new ApiError('统计范围应在 1 至 366 天内');
        $table=$kind==='payments'?'order':'transfer';$paid=$kind==='payments'?'status IN (1,2,3)':'status=1';$money=$kind==='payments'?'COALESCE(realmoney,money)':'money';
        $where='addtime>=? AND addtime<?';$params=[$from->format('Y-m-d'),$to->modify('+1 day')->format('Y-m-d')];
        foreach(['uid','channel'] as $key)if(isset($data[$key])&&$data[$key]!==''){if(!ctype_digit((string)$data[$key]))throw new ApiError('编号格式无效');$where.=' AND '.$key.'=?';$params[]=$data[$key];}
        $select="COUNT(*) total, COALESCE(SUM(CASE WHEN $paid THEN 1 ELSE 0 END),0) paid, COALESCE(SUM(CASE WHEN $paid THEN $money ELSE 0 END),0) amount";
        $total=$this->db->row('SELECT '.$select.' FROM pre_'.$table.' WHERE '.$where,$params);
        $rows=$this->db->all('SELECT DATE(addtime) day, '.$select.' FROM pre_'.$table.' WHERE '.$where.' GROUP BY DATE(addtime) ORDER BY day',$params);$byDay=array_column($rows,null,'day');$trend=[];
        for($i=0;$i<=$days;$i++){$day=$from->modify('+'.$i.' day')->format('Y-m-d');$trend[]=['day'=>$day]+($byDay[$day] ?? ['total'=>0,'paid'=>0,'amount'=>'0.00']);}
        $breakdown=[];foreach(['type','channel','uid'] as $key)$breakdown[$key]=$this->db->all('SELECT '.$key.' name, '.$select.' FROM pre_'.$table.' WHERE '.$where.' GROUP BY '.$key.' ORDER BY amount DESC LIMIT 100',$params);
        return ['kind'=>$kind,'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'total'=>$total,'trend'=>$trend,'breakdown'=>$breakdown,'note'=>$kind==='payments'?'按订单创建日期统计原始成交金额，包含退款及冻结订单。':'按付款创建日期统计，仅成功付款计入金额。'];
    }
    public function schema(array $data)
    {
        $tool=$data['tool'] ?? '';$f=[Management::class,'field'];$fields=[];$note='';
        if($tool==='logo')$note='选择 PNG/JPEG 图片，App 将转换为 PNG。最大 512 KB，宽高不超过 2048。替换前会保留上一版 Logo。';
        elseif($tool==='cleanup'){
            $fields=[$f('resource','清理数据','select','logs',['options'=>['cache'=>'设置缓存','settlements'=>'已完成结算','transfers'=>'已结束付款','sharing'=>'已完成或已取消分账','records'=>'资金明细','logs'=>'登录及操作日志']]),$f('days','保留天数（至少 30 天）','integer','90')];
            $note='先预览数量，再输入“确认清理”和支付密码。删除数据无法恢复，请确保已有备份。进行中的业务不会清理。';
            if(isset($data['preview']))return $this->preview($data);
        }elseif($tool==='identity'){
            $fields=[$f('kind','用户标识类型','select','wechat',['options'=>['wechat'=>'公众号 OpenID','applet'=>'小程序 OpenID','alipayuid'=>'支付宝 UID','douyinopenid'=>'抖音 OpenID','apptoken'=>'App 授权令牌','appassign'=>'指定 App 授权']]),$f('id','公众号 / 小程序 / 通道编号','integer','',['required'=>true])];$note='生成原平台授权链接，可复制或展示二维码。';
        }elseif($tool==='plugins')$note='扫描服务器现有插件并同步元数据，保留已不存在的插件记录以保护现有通道。';
        else throw new ApiError('工具不存在',404);
        foreach($fields as &$field)$field['value']=$field['default'];
        return ['tool'=>$tool,'fields'=>$fields,'note'=>$note];
    }
    private function cleanupSpec(array $v)
    {
        $maps=['settlements'=>['settle','addtime','status=1'],'transfers'=>['transfer','addtime','status IN (1,2)'],'sharing'=>['psorder','addtime','status IN (2,4)'],'records'=>['record','date','1=1'],'logs'=>['log','date','1=1']];
        $resource=$v['resource'] ?? '';if($resource==='cache')return ['cache','1=1',[]];if(!isset($maps[$resource]))throw new ApiError('不支持清理此数据');
        $days=$v['days'] ?? '';if(!preg_match('/^\d{2,4}$/D',(string)$days)||(int)$days<30||(int)$days>3650)throw new ApiError('保留天数应为 30 至 3650');
        [$table,$date,$condition]=$maps[$resource];$cutoff=$v['cutoff'] ?? date('Y-m-d',strtotime('-'.$days.' days'));$this->day($cutoff);
        if($cutoff!==date('Y-m-d',strtotime('-'.$days.' days')))throw new ApiError('预览已过期，请重新预览',409);
        // Any unresolved external operation prevents deleting its business evidence.
        if($this->db->value("SELECT COUNT(*) FROM pre_app_operation WHERE state='pending'"))throw new ApiError('存在渠道结果待核实的操作，请先核对，暂停清理');
        return [$table,$condition.' AND `'.$date.'`<?',[$cutoff]];
    }
    private function preview(array $v)
    {
        [$table,$where,$params]=$this->cleanupSpec($v);$count=(int)$this->db->value('SELECT COUNT(*) FROM pre_'.$table.' WHERE '.$where,$params);$cutoff=$params[0] ?? '';
        $proof=hash_hmac('sha256',($v['resource'] ?? '').'|'.($v['days'] ?? '').'|'.$cutoff.'|'.$count,(string)$this->conf['admin_pwd']);
        return ['count'=>$count,'cutoff'=>$cutoff,'proof'=>$proof,'message'=>'将清理 '.$count.' 条记录'];
    }
    public function run(array $data)
    {
        $tool=$data['tool'] ?? '';$v=$data['values'] ?? [];if(!is_array($v))throw new ApiError('工具参数无效');
        if($tool==='identity'){
            $kind=$v['kind'] ?? '';$id=$v['id'] ?? '';if(!preg_match('/^\d{1,9}$/D',(string)$id)||(int)$id===0)throw new ApiError('编号无效');Runtime::boot($this->db,$this->conf);$base=$GLOBALS['siteurl'];
            if(in_array($kind,['wechat','applet'],true)){if(!$this->db->row('SELECT id FROM pre_weixin WHERE id=? AND type=?',[$id,$kind==='applet'?1:0]))throw new ApiError('公众号或小程序不存在');}
            elseif(!in_array($kind,['alipayuid','douyinopenid','apptoken','appassign'],true)||!$this->db->row('SELECT id FROM pre_channel WHERE id=?',[$id]))throw new ApiError('通道或类型无效');
            if($kind==='applet'){$wx=new \lib\wechat\WechatAPI($id);$url=$wx->generate_link('pages/getopenid/getopenid','url='.$base.'&wechatid='.$id,3600);}
            else $url=$base.'user/openid.php?'.($kind==='wechat'?'wechatid='.$id:(in_array($kind,['apptoken','appassign'],true)?'act='.($kind==='appassign'?'app_auth_assign':'app_auth').'&':'').'channel='.$id);
            if(!is_string($url)||!preg_match('~^https?://~',$url))throw new ApiError('授权链接生成失败');return ['message'=>'链接已生成','url'=>$url];
        }
        if($tool==='cleanup'){
            if(!hash_equals((string)($this->conf['admin_paypwd'] ?? ''),(string)($v['paypwd'] ?? ''))||empty($this->conf['admin_paypwd']))throw new ApiError('支付密码错误或未设置',403);
            if(($v['confirm'] ?? '')!=='确认清理')throw new ApiError('请输入“确认清理”');$preview=$this->preview($v);if(!hash_equals($preview['proof'],(string)($v['proof'] ?? '')))throw new ApiError('数量或日期已改变，请重新预览',409);
            [$table,$where,$params]=$this->cleanupSpec($v);$this->db->pdo->beginTransaction();try{$n=$this->db->query('DELETE FROM pre_'.$table.' WHERE '.$where,$params)->rowCount();$this->db->audit('数据清理',$this->ip,['resource'=>$v['resource'],'cutoff'=>$preview['cutoff'],'count'=>$n]);$this->db->pdo->commit();}catch(\Throwable $e){if($this->db->pdo->inTransaction())$this->db->pdo->rollBack();throw $e;}return ['message'=>'清理完成，共 '.$n.' 条'];
        }
        if($tool==='logo'){
            $raw=$v['image'] ?? '';if(!is_string($raw)||strlen($raw)>710000)throw new ApiError('图片过大');$bytes=base64_decode($raw,true);$size=$bytes===false?false:@getimagesizefromstring($bytes);
            if(!$size||$size[2]!==IMAGETYPE_PNG||strlen($bytes)>524288||$size[0]>2048||$size[1]>2048)throw new ApiError('请选择 512 KB 以内、2048 像素以内的 PNG 图片');
            $file=dirname(__DIR__,3).'/assets/img/logo.png';$tmp=$file.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes))throw new ApiError('图片写入失败',500);
            if(is_file($file)&&!copy($file,$file.'.previous.png')){unlink($tmp);throw new ApiError('旧 Logo 备份失败',500);}if(!rename($tmp,$file)){unlink($tmp);throw new ApiError('Logo 替换失败',500);}$this->db->audit('更新Logo',$this->ip);return ['message'=>'Logo 已更新，上一版已备份'];
        }
        if($tool==='plugins'){
            Runtime::boot($this->db,$this->conf);$count=0;foreach(\lib\Plugin::getList() as $name){$c=\lib\Plugin::getConfig($name);if(!$c||$c['name']!==$name)continue;$row=['showname'=>$c['showname'],'author'=>$c['author'],'link'=>$c['link'],'types'=>implode(',',$c['types']),'transtypes'=>empty($c['transtypes'])?null:implode(',',$c['transtypes'])];if($this->db->row('SELECT name FROM pre_plugin WHERE name=?',[$name]))$this->db->update('plugin',$row,'name',$name);else $this->db->insert('plugin',['name'=>$name]+$row);$count++;}$this->db->audit('同步插件',$this->ip,['count'=>$count]);return ['message'=>'已同步 '.$count.' 个插件'];
        }
        throw new ApiError('工具不存在',404);
    }
}
