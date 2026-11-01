<?php
namespace lib\AdminApp;

final class Api
{
    private $db;
    private $conf;
    private $auth;
    private $ip;
    private $session;
    private $requestKey;
    public function __construct(Store $db, $ip, $clock = null) { $this->db=$db; $this->conf=$db->config(); $this->ip=$ip; $this->auth=new Auth($db,$this->conf,$ip,$clock); }
    private function text(array $data, $key, $max=128, $required=false)
    {
        $value=$data[$key] ?? '';
        if (!is_scalar($value) || mb_strlen((string)$value)>$max) throw new ApiError('字段 '.$key.' 格式不正确');
        $value=trim((string)$value);
        if ($required && $value==='') throw new ApiError('请填写 '.$key);
        return $value;
    }
    private function id(array $data, $key) { $id=$this->text($data,$key,12,true); if (!ctype_digit($id)||$id==='0') throw new ApiError('无效的编号'); return (int)$id; }
    private function flag(array $data,$key) { $v=$this->text($data,$key,1,true); if (!in_array($v,['0','1'],true)) throw new ApiError('状态必须为 0 或 1'); return (int)$v; }
    private function bounds(array $data) { $page=max(1,min(100000,(int)($data['page'] ?? 1))); $limit=max(1,min(100,(int)($data['limit'] ?? 30))); return [$limit,($page-1)*$limit,$page]; }
    private function dateValue($value)
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new ApiError('日期格式应为 YYYY-MM-DD');
        return $d;
    }
    public function handle($method, $route, array $data=[], $bearer='', $requestKey='')
    {
        if (($this->conf['app_api_enabled'] ?? '1')==='0') throw new ApiError('管理员 App 接口已关闭',503);
        if (!$this->db->installed()) throw new ApiError('请先运行 bin/install-app-api.php --apply 安装 App 数据表',503);
        $route=trim($route,'/');
        $expected=['v1/site'=>'GET','v1/auth/captcha'=>'GET','v1/auth/login'=>'POST','v1/auth/totp'=>'POST','v1/auth/refresh'=>'POST'];
        if (isset($expected[$route])) {
            if ($method!==$expected[$route]) throw new ApiError('请求方法不允许',405);
            if ($route==='v1/site') return ['name'=>$this->conf['sitename'] ?? '易支付','api_version'=>1,'release_version'=>'1.2.19','captcha'=>($this->conf['app_captcha_open'] ?? '1')!=='0','timezone'=>'Asia/Shanghai'];
            if ($route==='v1/auth/captcha') return $this->auth->captcha();
            if ($route==='v1/auth/login') return $this->auth->login($data);
            if ($route==='v1/auth/totp') return $this->auth->totp($data);
            if ($route==='v1/auth/refresh') return $this->auth->refresh($this->text($data,'refresh_token',64,true));
        }
        $this->session=$this->auth->authenticate($bearer);
        $this->auth->rate('api:'.$this->session['id'],180,60);
        if ($method==='GET') return $this->read($route,$data);
        if ($method!=='POST') throw new ApiError('请求方法不允许',405);
        if ($route==='v1/auth/logout') { $this->db->update('app_session',['revoked'=>1],'id',$this->session['id']); $this->db->audit('退出',$this->ip); return ['logged_out'=>true]; }
        // Every business write has an explicit endpoint and a durable idempotency key.
        $writes=['v1/merchants/create','v1/merchants/update','v1/channels/status','v1/channels/config/save','v1/profile/save','v1/profile/password','v1/sessions/revoke','v1/orders/notify','v1/settings/save','v1/management/save','v1/management/delete','v1/actions/run','v1/tools/run','v1/credentials/save','v1/notifications/device'];
        $writes=array_merge($writes,['v1/profile/totp/prepare','v1/profile/totp/bind','v1/profile/totp/disable','v1/channels/test','v1/plugins/run','v1/merchants/policy/save']);
        $writes=array_merge($writes,array_keys(NativeManagement::WRITES));
        if (!in_array($route,$writes,true)) throw new ApiError('接口不存在',404);
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$requestKey)) throw new ApiError('缺少有效的 Idempotency-Key');
        $id=hash('sha256',$this->session['username'].':'.$requestKey);
        $this->requestKey=$id;
        ksort($data);
        $fingerprint=hash('sha256',$route.'|'.json_encode($data,JSON_UNESCAPED_UNICODE));
        $prior=$this->db->row('SELECT * FROM pre_app_request WHERE id=?',[$id]);
        if ($prior) return $this->replay($prior,$fingerprint);
        try { $this->db->insert('app_request',['id'=>$id,'fingerprint'=>$fingerprint,'created'=>time()]); }
        catch (\PDOException $e) { $prior=$this->db->row('SELECT * FROM pre_app_request WHERE id=?',[$id]); if ($prior) return $this->replay($prior,$fingerprint); throw $e; }
        try { $result=$this->write($route,$data); }
        catch (ApiError $e) { $this->db->update('app_request',['response'=>json_encode(['error'=>$e->getMessage(),'status'=>$e->status])],'id',$id); throw $e; }
        // Unexpected failures retain a pending entry: financial operations are never retried blindly.
        $cachedResult=$result;
        if(isset($cachedResult['secret'])){unset($cachedResult['secret'],$cachedResult['uri']);$cachedResult['message']='操作已完成，敏感信息仅在首次响应显示';}
        $this->db->update('app_request',['response'=>json_encode(['result'=>$cachedResult],JSON_UNESCAPED_UNICODE)],'id',$id);
        return $result;
    }
    private function replay($prior,$fingerprint)
    {
        if (!hash_equals($prior['fingerprint'],$fingerprint)) throw new ApiError('该请求编号已用于其他操作',409);
        if ($prior['response']===null) throw new ApiError('请求处理中或结果待核实，请先查询业务状态',409);
        $cached=json_decode($prior['response'],true);
        if (isset($cached['error'])) throw new ApiError($cached['error'],$cached['status']);
        return $cached['result'];
    }
    private function nativeManagement()
    {
        Runtime::boot($this->db,$this->conf);
        return new NativeManagement($this->db,$this->conf,$this->ip);
    }
    private function read($route,array $data)
    {
        if(isset(NativeManagement::READS[$route]))return $this->nativeManagement()->handle('GET',$route,$data);
        if($route==='v1/plugins/schema')return $this->pluginNative()->schema($data);
        if($route==='v1/plugins/monitor')return $this->pluginNative()->monitor($data);
        if($route==='v1/merchants/monitor-options'){
            $this->auth->rate('monitor-options:'.$this->session['id'],30,60);
            $q=$this->text($data,'q',128);$params=[];$where='';
            if($q!==''){$where=' WHERE username LIKE ? OR CAST(uid AS CHAR)=?';$params=['%'.$q.'%',$q];}
            return ['rows'=>$this->db->all('SELECT uid,username FROM pre_user'.$where.' ORDER BY uid DESC LIMIT 100',$params)];
        }
        if($route==='v1/merchants/monitor-key'){
            $this->auth->rate('monitor-key:'.$this->session['id'],8,60);
            $uid=$this->id($data,'uid');$merchant=$this->db->row('SELECT uid,username,key FROM pre_user WHERE uid=?',[$uid]);
            if(!$merchant)throw new ApiError('商户不存在',404);
            $this->db->audit('读取监控商户密钥',$this->ip,['uid'=>$uid]);
            return ['uid'=>(string)$merchant['uid'],'username'=>$merchant['username'],'key'=>(string)$merchant['key']];
        }
        switch ($route) {
            case 'v1/dashboard': return $this->dashboard($data);
            case 'v1/notifications/status': return $this->notificationStatus($data);
            case 'v1/notifications/cursor': return ['latest_id'=>(string)$this->db->value('SELECT COALESCE(MAX(id),0) FROM pre_app_notification')];
            case 'v1/notifications/poll': return $this->pollNotifications($data);
            case 'v1/orders': return $this->orders($data);
            case 'v1/orders/detail': return $this->orderDetail($this->text($data,'trade_no',32,true));
            case 'v1/orders/notify-history':
                $full=$this->db->row('SELECT trade_no,tid,notify,notifytime FROM pre_order WHERE trade_no=?',[$this->text($data,'trade_no',32,true)]);
                if(!$full)throw new ApiError('订单不存在',404);
                $detail=(new \lib\Notification\Delivery($this->db,$this->conf))->detail($full,max(0,(int)($data['before'] ?? 0)));
                return ['rows'=>$detail['history'],'has_more'=>$detail['has_more'],'next_cursor'=>$detail['next_cursor']];
            case 'v1/merchants': return $this->merchants($data);
            case 'v1/merchants/detail': return $this->merchantDetail($this->id($data,'uid'));
            case 'v1/merchants/policy': return (new \lib\Merchant\ChannelPolicy($this->db))->schema($this->id($data,'uid'));
            case 'v1/groups': return ['rows'=>$this->db->all('SELECT gid,name FROM pre_group ORDER BY gid'),'total'=>(int)$this->db->value('SELECT COUNT(*) FROM pre_group')];
            case 'v1/channels': return $this->channels($data);
            case 'v1/channels/config': return $this->channelSchema($this->id($data,'id'));
            case 'v1/credentials/schema': return (new Credentials($this->db,$this->ip))->schema($data);
            case 'v1/profile': return $this->profile();
            case 'v1/sessions': return ['rows'=>array_map(function($r){$r['current']=$r['id']===$this->session['id'];return $r;},$this->db->all('SELECT id,device,ip,created,last_seen FROM pre_app_session WHERE username=? AND revoked=0 AND expires>? ORDER BY last_seen DESC',[$this->session['username'],time()]))];
            case 'v1/menu': return ['groups'=>Menu::groups($this->conf)];
            case 'v1/resources': return $this->resources($data);
            case 'v1/settings': return $this->settings($this->text($data,'group',30,true));
            case 'v1/management/schema': return (new Management($this->db,$this->ip))->schema($data);
            case 'v1/actions/schema': return (new Operations($this->db,$this->ip))->schema($data);
            case 'v1/statistics': return (new Tools($this->db,$this->ip))->statistics($data);
            case 'v1/tools/schema': return (new Tools($this->db,$this->ip))->schema($data);
        }
        throw new ApiError('接口不存在',404);
    }
    private function dashboard(array $data)
    {
        $days=(int)($data['days'] ?? 7); if (!in_array($days,[7,30],true)) throw new ApiError('趋势范围只支持 7 或 30 天');
        $today=date('Y-m-d');$tomorrow=date('Y-m-d',strtotime('+1 day'));$yesterday=date('Y-m-d',strtotime('-1 day'));$start=date('Y-m-d',strtotime('-'.($days-1).' days'));
        $sql="SELECT COUNT(*) total, SUM(CASE WHEN status IN (1,2,3) THEN 1 ELSE 0 END) paid, COALESCE(SUM(CASE WHEN status IN (1,2,3) THEN COALESCE(realmoney,money) ELSE 0 END),0) amount, COALESCE(SUM(CASE WHEN status IN (1,3) THEN profitmoney ELSE 0 END),0) profit FROM pre_order WHERE addtime>=? AND addtime<?";
        $stats=$this->db->row($sql,[$today,$tomorrow]);$previous=$this->db->row($sql,[$yesterday,$today]);
        $trend=$this->db->all('SELECT DATE(addtime) day, COUNT(*) orders, COALESCE(SUM(CASE WHEN status IN (1,2,3) THEN COALESCE(realmoney,money) ELSE 0 END),0) amount FROM pre_order WHERE addtime>=? AND addtime<? GROUP BY DATE(addtime) ORDER BY day',[$start,$tomorrow]);
        $indexed=array_column($trend,null,'day');$points=[];
        for($i=0;$i<$days;$i++){$day=date('Y-m-d',strtotime($start.' +'.$i.' days'));$points[]=['day'=>$day,'amount'=>(string)($indexed[$day]['amount'] ?? '0.00'),'orders'=>(int)($indexed[$day]['orders'] ?? 0)];}
        return ['amount'=>(string)$stats['amount'],'previous_amount'=>(string)$previous['amount'],'profit'=>(string)$stats['profit'],'paid'=>(int)$stats['paid'],'total'=>(int)$stats['total'],'success_rate'=>$stats['total']?number_format($stats['paid']*100/$stats['total'],1,'.',''):'0.0','merchants'=>(int)$this->db->value('SELECT COUNT(*) FROM pre_user'),'trend'=>$points,'recent'=>$this->orders(['limit'=>3])['rows'],'updated_at'=>date('Y-m-d H:i:s'),'timezone'=>'Asia/Shanghai','amount_basis'=>'按创建日期统计已支付、退款及冻结订单原始成交金额，退款不冲减此指标'];
    }
    private function orders(array $data)
    {
        list($limit,$offset,$page)=$this->bounds($data);$where=['1=1'];$params=[];
        $q=$this->text($data,'q',150);
        if($q!==''){$where[]='(A.trade_no LIKE ? OR A.out_trade_no LIKE ? OR A.name LIKE ? OR CAST(A.uid AS CHAR) = ?)';$params=array_merge($params,['%'.$q.'%','%'.$q.'%','%'.$q.'%',$q]);}
        if(isset($data['status']) && $data['status']!=='') { $status=$this->text($data,'status',1);if(!in_array($status,['0','1','2','3','4'],true))throw new ApiError('订单状态无效');$where[]='A.status=?';$params[]=$status; }
        foreach(['uid','channel'] as $key)if(!empty($data[$key])){$where[]='A.'.$key.'=?';$params[]=$this->id($data,$key);}
        if(!empty($data['from'])){$where[]='A.addtime>=?';$params[]=$this->dateValue($data['from'])->format('Y-m-d');}
        if(!empty($data['to'])){$where[]='A.addtime<?';$params[]=$this->dateValue($data['to'])->modify('+1 day')->format('Y-m-d');}
        if(!empty($data['from'])&&!empty($data['to'])&&$data['from']>$data['to'])throw new ApiError('开始日期不能晚于结束日期');
        $clause=implode(' AND ',$where);
        $total=(int)$this->db->value('SELECT COUNT(*) FROM pre_order A WHERE '.$clause,$params);
        $rows=$this->db->all('SELECT A.trade_no,A.out_trade_no,A.uid,A.name,A.money,A.realmoney,A.refundmoney,A.addtime,A.endtime,A.status,A.notify,A.channel,B.name channel_name,C.showname type_name,U.username merchant_name FROM pre_order A LEFT JOIN pre_channel B ON B.id=A.channel LEFT JOIN pre_type C ON C.id=A.type LEFT JOIN pre_user U ON U.uid=A.uid WHERE '.$clause.' ORDER BY A.trade_no DESC LIMIT '.$limit.' OFFSET '.$offset,$params);
        return ['total'=>$total,'page'=>$page,'has_more'=>$offset+count($rows)<$total,'rows'=>$rows];
    }
    private function orderDetail($trade)
    {
        $row=$this->db->row('SELECT A.trade_no,A.out_trade_no,A.api_trade_no,A.uid,A.tid,A.name,A.money,A.realmoney,A.getmoney,A.profitmoney,A.refundmoney,A.addtime,A.endtime,A.status,A.notify,A.notifytime,A.channel,A.subchannel,A.combine,A.settle,B.plugin,B.name channel_name,C.showname type_name FROM pre_order A LEFT JOIN pre_channel B ON B.id=A.channel LEFT JOIN pre_type C ON C.id=A.type WHERE trade_no=?',[$trade]);
        if(!$row)throw new ApiError('订单不存在',404);
        $pending=$this->db->row("SELECT reference,state FROM pre_app_operation WHERE id=? AND state='pending'",['order:'.$trade]);
        if($pending)$row['pending_operation']=$pending;
        $row['channel_closed']=(bool)$this->db->value("SELECT COUNT(*) FROM pre_app_operation WHERE id=? AND state='done'",['closed:'.$trade]);
        $row['status_only_reset']=(bool)$this->db->value('SELECT COUNT(*) FROM pre_app_operation WHERE id=?',['status-only:'.$trade]);
        if(!empty($row['combine']))$row['suborders']=$this->db->all('SELECT sub_trade_no,api_trade_no,money,refundmoney,status,settle FROM pre_suborder WHERE trade_no=? ORDER BY sub_trade_no',[$trade]);
        $row['notification']=(new \lib\Notification\Delivery($this->db,$this->conf))->detail($row);
        return $row;
    }
    private function merchants(array $data)
    {
        list($limit,$offset,$page)=$this->bounds($data);$where='1=1';$params=[];$q=$this->text($data,'q',128);
        if($q!==''){$where.=' AND (U.username LIKE ? OR U.email LIKE ? OR U.phone LIKE ? OR CAST(U.uid AS CHAR)=?)';$params=['%'.$q.'%','%'.$q.'%','%'.$q.'%',$q];}
        if(isset($data['status'])&&$data['status']!==''){$where.=' AND U.status=?';$params[]=$this->flag($data,'status');}
        $total=(int)$this->db->value('SELECT COUNT(*) FROM pre_user U WHERE '.$where,$params);
        $rows=$this->db->all('SELECT U.uid,U.gid,U.username,U.email,U.phone,U.money,U.status,U.pay,U.settle,U.addtime,G.name group_name FROM pre_user U LEFT JOIN pre_group G ON G.gid=U.gid WHERE '.$where.' ORDER BY U.uid DESC LIMIT '.$limit.' OFFSET '.$offset,$params);
        return ['total'=>$total,'page'=>$page,'has_more'=>$offset+count($rows)<$total,'rows'=>$rows];
    }
    private function merchantDetail($uid)
    {
        $row=$this->db->row('SELECT uid,gid,username,email,phone,money,status,pay,settle,addtime,url,account,settle_id,qq,endtime,cert,certtype,certmethod,certno,certname,certcorpno,certcorpname,certtime,keytype FROM pre_user WHERE uid=?',[$uid]);if(!$row)throw new ApiError('商户不存在',404);return $row;
    }
    private function channels(array $data)
    {
        $q=$this->text($data,'q',128);$params=[];$where='';
        if($q!==''){$where=' WHERE (A.name LIKE ? OR A.plugin LIKE ? OR B.showname LIKE ? OR CAST(A.id AS CHAR)=?)';$params=['%'.$q.'%','%'.$q.'%','%'.$q.'%',$q];}
        $rows=$this->db->all('SELECT A.id,A.name,A.type,A.plugin,A.mode,A.rate,A.costrate,A.status,A.daytop,A.daystatus,A.paymin,A.paymax,B.showname type_name FROM pre_channel A LEFT JOIN pre_type B ON B.id=A.type'.$where.' ORDER BY A.id DESC',$params);
        foreach($rows as &$row)if(in_array($row['plugin'],['jktongypc','jktongyx'],true)){
            $row['monitoring']=true;
            try{$state=$this->pluginNative()->monitor(['id'=>(string)$row['id']]);$row['monitor']=array_intersect_key($state,array_flip(['online','channel_enabled','heartbeat_enabled','merchant_id','last_seen','reason']));}
            catch(\Throwable $e){$row['monitor']=['reason'=>'监控状态读取失败，请打开诊断查看'];}
        }
        unset($row);
        return ['rows'=>$rows,'total'=>count($rows)];
    }
    private function channelSchema($id)
    {
        require_once __DIR__.'/ChannelConfig.php';
        return (new ChannelConfig($this->db))->schema($id);
    }

    private function profile() { return ['username'=>$this->session['username'],'nickname'=>$this->conf['app_admin_nickname'] ?? $this->session['username'],'email'=>$this->conf['app_admin_email'] ?? '','role'=>'超级管理员','totp'=>($this->conf['totp_open'] ?? '0')==='1','platform'=>$this->conf['sitename'] ?? '易支付']; }
    private function write($route,array $data)
    {
        if(isset(NativeManagement::WRITES[$route]))return $this->nativeManagement()->handle('POST',$route,$data,$this->requestKey);
        if($route==='v1/merchants/policy/save')return (new \lib\Merchant\ChannelPolicy($this->db))->save($data,$this->ip);
        if($route==='v1/notifications/device') {
            $device=$this->notificationDeviceId($data);$enabled=$this->flag($data,'enabled');$now=time();
            $existing=$this->db->row('SELECT device_id FROM pre_app_notification_device WHERE device_id=?',[$device]);
            if($existing)$this->db->update('app_notification_device',['username'=>$this->session['username'],'enabled'=>$enabled,'last_seen'=>$now],'device_id',$device);
            else {
                try{$this->db->insert('app_notification_device',['device_id'=>$device,'username'=>$this->session['username'],'enabled'=>$enabled,'created'=>$now,'last_seen'=>$now]);}
                catch(\PDOException $e){if(!$this->db->row('SELECT device_id FROM pre_app_notification_device WHERE device_id=?',[$device]))throw $e;$this->db->update('app_notification_device',['username'=>$this->session['username'],'enabled'=>$enabled,'last_seen'=>$now],'device_id',$device);}
            }
            $this->db->audit($enabled?'开启收款通知':'关闭收款通知',$this->ip);
            return ['enabled'=>(bool)$enabled];
        }
        if($route==='v1/plugins/run'){
            $this->auth->rate('plugin-management:'.$this->session['id'],60,60);
            return $this->pluginNative()->run($data);
        }
        if(strpos($route,'v1/profile/totp/')===0) {
            $this->auth->rate('totp-management:'.$this->session['username'],15,600);
            require_once __DIR__.'/ProfileSecurity.php';
            return (new ProfileSecurity($this->db,$this->session,$this->ip))->run(substr($route,strlen('v1/profile/totp/')),$data);
        }
        if($route==='v1/channels/test') {
            $this->auth->rate('channel-test:'.$this->session['username'],30,60);
            require_once __DIR__.'/ChannelTest.php';
            return ChannelTest::create($this->db,$this->conf,$data,$this->ip);
        }
        if($route==='v1/credentials/save')return (new Credentials($this->db,$this->ip))->save($data);
        if($route==='v1/management/save')return (new Management($this->db,$this->ip))->save($data);
        if($route==='v1/management/delete')return (new Management($this->db,$this->ip))->delete($data);
        if($route==='v1/actions/run'){
            $this->auth->rate('actions:'.$this->session['username'],90,60);
            $values=$data['values'] ?? [];
            // A valid batch may contain 50 actions; only wrong credentials use the stricter bucket.
            if(is_array($values))foreach(['paypwd'=>'admin_paypwd','admin_password'=>'admin_pwd'] as $input=>$setting){
                if(isset($values[$input])&&(!is_string($values[$input])||!hash_equals((string)($this->conf[$setting] ?? ''),$values[$input])))$this->auth->rate('payment-password-failed:'.$this->session['username'],10,60);
            }
            return (new Operations($this->db,$this->ip,$this->requestKey))->run($data);
        }
        if($route==='v1/tools/run')return (new Tools($this->db,$this->ip))->run($data);
        if($route==='v1/merchants/create'||$route==='v1/merchants/update')return $this->saveMerchant($data,$route==='v1/merchants/create');
        if($route==='v1/channels/status'){$id=$this->id($data,'id');$status=$this->flag($data,'status');if(!$this->db->row('SELECT id FROM pre_channel WHERE id=?',[$id]))throw new ApiError('通道不存在',404);$this->db->update('channel',['status'=>$status],'id',$id);$this->db->audit('通道状态',$this->ip,['id'=>$id,'status'=>$status]);return ['id'=>$id,'status'=>$status];}
        if($route==='v1/channels/config/save'){
            require_once __DIR__.'/ChannelConfig.php';
            $data['id']=$this->id($data,'id');
            return (new ChannelConfig($this->db))->save($data,$this->ip);
        }
        if($route==='v1/profile/save'){$nickname=$this->text($data,'nickname',40,true);$email=$this->text($data,'email',128);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new ApiError('邮箱格式不正确');$this->db->pdo->beginTransaction();try{$this->db->setting('app_admin_nickname',$nickname);$this->db->setting('app_admin_email',$email);$this->db->pdo->commit();}catch(\Throwable $e){$this->db->pdo->rollBack();throw $e;}$this->db->audit('个人资料',$this->ip);return ['saved'=>true];}
        if($route==='v1/profile/password'){
            $current=$data['current_password'] ?? ''; $next=$data['new_password'] ?? ''; if(!is_string($current)||!is_string($next)||strlen($current)>800||strlen($next)>800)throw new ApiError('密码格式无效');
            $this->auth->rate('password:'.$this->session['id'],5);
            if(!hash_equals($this->conf['admin_pwd'],$current))throw new ApiError('原密码错误',403);
            if(mb_strlen($next)<12||$next===$current)throw new ApiError('新密码至少 12 位，且不能与原密码相同');
            $this->db->setting('admin_pwd',$next);$this->db->query('UPDATE pre_app_session SET revoked=1 WHERE username=?',[$this->session['username']]);$this->db->audit('修改密码',$this->ip);return ['reauthenticate'=>true];
        }
        if($route==='v1/sessions/revoke'){$id=$this->text($data,'id',32,true);$this->db->query('UPDATE pre_app_session SET revoked=1 WHERE id=? AND username=?',[$id,$this->session['username']]);$this->db->audit('撤销会话',$this->ip,['id'=>$id]);return ['revoked'=>true,'current'=>$id===$this->session['id']];}
        if($route==='v1/settings/save')return $this->saveSettings($data);
        $trade=$this->text($data,'trade_no',32,true);$row=$this->orderDetail($trade);
        if($route==='v1/orders/notify'){
            if(!in_array((int)$row['status'],[1,2,3],true))throw new ApiError('未支付订单不能重发通知');
            Runtime::boot($this->db,$this->conf);
            $result=(new \lib\Notification\Delivery($this->db,$this->conf))->send($trade,'app');
            $this->db->audit('重发通知',$this->ip,['trade_no'=>$trade,'success'=>$result['delivered']]);
            return $result;
        }
        throw new ApiError('此操作请在网页后台完成',422);
    }
    private function pluginNative()
    {
        require_once __DIR__.'/PluginCertificates.php';
        require_once __DIR__.'/PluginNative.php';
        return new PluginNative($this->db,$this->ip,$this->session['id']);
    }
    private function saveMerchant(array $data,$create)
    {
        $values=[];foreach(['username'=>128,'email'=>32,'phone'=>20,'account'=>128,'url'=>64,'qq'=>20] as $key=>$max)if($create||array_key_exists($key,$data))$values[$key]=$this->text($data,$key,$max,$key==='username');
        if(isset($values['email'])&&$values['email']!==''&&!filter_var($values['email'],FILTER_VALIDATE_EMAIL))throw new ApiError('邮箱格式不正确');
        if(isset($values['phone'])&&$values['phone']!==''&&!preg_match('/^[+0-9 -]{5,20}$/D',$values['phone']))throw new ApiError('手机号格式不正确');
        $uid=$create?0:$this->id($data,'uid');if(!$create)$old=$this->merchantDetail($uid);
        if(empty($values['email'] ?? $old['email'] ?? '')&&empty($values['phone'] ?? $old['phone'] ?? ''))throw new ApiError('手机号和邮箱不能都为空');
        foreach(['phone','email'] as $key)if(!empty($values[$key])&&$this->db->row('SELECT uid FROM pre_user WHERE '.$key.'=? AND uid<>?',[$values[$key],$uid]))throw new ApiError('手机号或邮箱已被使用');
        foreach(['status','pay','settle'] as $key)if(isset($data[$key]))$values[$key]=$this->flag($data,$key);
        if(isset($data['gid'])){$gid=(int)$data['gid'];if($gid<0||($gid>0&&!$this->db->row('SELECT gid FROM pre_group WHERE gid=?',[$gid])))throw new ApiError('商户分组不存在');$values['gid']=$gid;}
        if($create){$values+=['gid'=>0,'settle_id'=>1,'key'=>bin2hex(random_bytes(16)),'money'=>'0.00','mode'=>0,'cert'=>0,'pay'=>1,'settle'=>1,'status'=>1,'addtime'=>date('Y-m-d H:i:s')];$uid=(int)$this->db->insert('user',$values);}
        else {if(!$values)throw new ApiError('没有可修改的字段');$this->db->update('user',$values,'uid',$uid);}
        $this->db->audit($create?'新增商户':'修改商户',$this->ip,['uid'=>$uid]);return ['uid'=>$uid];
    }
    private function resources(array $data)
    {
        $key=$this->text($data,'resource',30,true);$spec=Menu::resources()[$key] ?? null;if(!$spec)throw new ApiError('该功能尚无原生接口，请使用平台网页后台',422);
        if($key==='buyers')return $this->buyers($data);
        list($table,$fields,$sort)=$spec;list($limit,$offset,$page)=$this->bounds($data);
        $where=[];$params=[];$columns=explode(',',$fields);$q=$this->text($data,'q',150);
        if($q!==''){$parts=[];foreach($columns as $col){$parts[]='CAST(`'.$col.'` AS CHAR) LIKE ?';$params[]='%'.$q.'%';}$where[]='('.implode(' OR ',$parts).')';}
        foreach(['uid','status','channel'] as $filter)if(in_array($filter,$columns,true)&&isset($data[$filter])&&$data[$filter]!==''){$where[]='`'.$filter.'`=?';$params[]=$this->text($data,$filter,30);}
        $date=in_array('addtime',$columns,true)?'addtime':(in_array('date',$columns,true)?'date':null);
        if(!empty($data['from'])&&!empty($data['to'])&&$data['from']>$data['to'])throw new ApiError('开始日期不能晚于结束日期');
        if($date){if(!empty($data['from'])){$where[]='`'.$date.'`>=?';$params[]=$this->dateValue($data['from'])->format('Y-m-d');}if(!empty($data['to'])){$where[]='`'.$date.'`<?';$params[]=$this->dateValue($data['to'])->modify('+1 day')->format('Y-m-d');}}
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $projection=implode(',',array_map(function($column){return '`'.$column.'`';},$columns));
        $rows=$this->db->all('SELECT '.$projection.' FROM pre_'.$table.$clause.' ORDER BY '.$sort.' LIMIT '.$limit.' OFFSET '.$offset,$params);
        if($key==='logs')foreach($rows as $index=>$row)$rows[$index]['type']=Store::auditType($row['type']);
        $total=(int)$this->db->value('SELECT COUNT(*) FROM pre_'.$table.$clause,$params);
        $edit=Management::definitions()[$key] ?? null;
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'has_more'=>$offset+count($rows)<$total,'editable'=>(bool)$edit,'can_create'=>$edit&&empty($edit['edit_only']),'pk'=>$edit['pk'] ?? explode(',',$fields)[0]];
    }
    private function notificationDeviceId(array $data)
    {
        $device=$this->text($data,'device_id',36,true);
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$device))throw new ApiError('设备编号格式无效');
        return strtolower($device);
    }
    private function notificationStatus(array $data)
    {
        $device=$this->notificationDeviceId($data);
        $row=$this->db->row('SELECT enabled,last_seen FROM pre_app_notification_device WHERE device_id=? AND username=?',[$device,$this->session['username']]);
        return ['enabled'=>(bool)($row&&(int)$row['enabled']===1),'registered'=>(bool)$row,'latest_id'=>(string)$this->db->value('SELECT COALESCE(MAX(id),0) FROM pre_app_notification')];
    }
    private function pollNotifications(array $data)
    {
        $device=$this->notificationDeviceId($data);
        $registered=$this->db->row('SELECT device_id FROM pre_app_notification_device WHERE device_id=? AND username=? AND enabled=1',[$device,$this->session['username']]);
        if(!$registered)return ['rows'=>[],'latest_id'=>(string)$this->db->value('SELECT COALESCE(MAX(id),0) FROM pre_app_notification')];
        $after=$this->text($data,'after_id',19);
        if($after!==''&&!ctype_digit($after))throw new ApiError('通知游标无效');
        $after=$after===''?0:(int)$after;
        $this->db->update('app_notification_device',['last_seen'=>time()],'device_id',$device);
        $rows=$this->db->all('SELECT id,event_key,type,title,body,trade_no,created FROM pre_app_notification WHERE id>? ORDER BY id ASC LIMIT 50',[$after]);
        return ['rows'=>$rows,'latest_id'=>(string)$this->db->value('SELECT COALESCE(MAX(id),0) FROM pre_app_notification')];
    }
    private function buyers(array $data)
    {
        list($limit,$offset,$page)=$this->bounds($data);
        $q=$this->text($data,'q',128);$where="buyer IS NOT NULL AND buyer<>''";$params=[];if($q!==''){$where.=' AND buyer LIKE ?';$params[]='%'.$q.'%';}
        $total=(int)$this->db->value('SELECT COUNT(DISTINCT buyer) FROM pre_order WHERE '.$where,$params);
        $rows=$this->db->all("SELECT buyer,COUNT(*) orders,SUM(CASE WHEN status IN (1,2,3) THEN 1 ELSE 0 END) paid,COALESCE(SUM(CASE WHEN status IN (1,2,3) THEN COALESCE(realmoney,money) ELSE 0 END),0) amount,MAX(addtime) last_time FROM pre_order WHERE ".$where.' GROUP BY buyer ORDER BY last_time DESC LIMIT '.$limit.' OFFSET '.$offset,$params);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'has_more'=>$offset+count($rows)<$total];
    }
    private function settings($group) { return (new Settings($this->db,$this->ip))->schema($group); }
    private function saveSettings(array $data) { return (new Settings($this->db,$this->ip))->save($data); }
}
