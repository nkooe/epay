<?php
namespace lib\AdminApp;

/** Bounded SSE connection for the administrator's explicitly enabled device. */
final class NotificationStream
{
    private $db;
    private $ip;
    private $bearer;
    private $device;
    private $after;
    private $username;
    private $lock;

    public function __construct(Store $db, $ip) { $this->db=$db;$this->ip=$ip; }
    public function open($bearer, array $data)
    {
        if(!$this->db->installed())throw new ApiError('请先安装 App 数据表',503);
        $this->bearer=$bearer;
        $this->authorize();
        $device=$data['device_id'] ?? '';$after=$data['after_id'] ?? '0';
        if(!is_string($device)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$device))throw new ApiError('设备编号格式无效');
        if(!is_scalar($after)||!preg_match('/^(0|[1-9][0-9]{0,18})$/D',(string)$after)||strlen((string)$after)===19&&strcmp((string)$after,(string)PHP_INT_MAX)>0)throw new ApiError('通知游标无效');
        $this->device=strtolower($device);$this->after=(int)$after;
        $this->checkDevice();
        (new Auth($this->db,$this->db->config(),$this->ip))->rate('receipt-stream:'.$this->username,12,60);
        if($this->db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'){
            $key='epay.receipt.'.substr(hash('sha256',$this->db->value('SELECT DATABASE()').':'.$this->db->tablePrefix().':'.$this->device),0,48);
            if((int)$this->db->value('SELECT GET_LOCK(?,0)',[$key])!==1)throw new ApiError('本设备已有收款连接',429);
            $this->lock=$key;
        }
        $this->db->update('app_notification_device',['last_seen'=>time()],'device_id',$this->device);
    }
    private function authorize()
    {
        $config=$this->db->config();
        if(($config['app_api_enabled'] ?? '1')==='0')throw new ApiError('管理员 App 接口已关闭',503);
        $session=(new Auth($this->db,$config,$this->ip))->authenticate($this->bearer);
        if($this->username!==null&&$this->username!==$session['username'])throw new ApiError('登录已变化，请重新连接',401);
        $this->username=$session['username'];
    }
    private function checkDevice()
    {
        if(!$this->db->row('SELECT device_id FROM pre_app_notification_device WHERE device_id=? AND username=? AND enabled=1',[$this->device,$this->username]))throw new ApiError('本设备收款通知未开启',403);
    }
    public function run(callable $emit, callable $connected, $seconds=25, callable $pause=null)
    {
        if($this->device===null)throw new \LogicException('Open the stream first');
        $pause=$pause ?: function(){usleep(1000000);};
        $start=microtime(true);$heartbeat=0;$authCheck=$start;
        try{
            $emit('ready',['server_time'=>time()]);
            while($connected()&&microtime(true)-$start<min(25,max(1,$seconds))){
                if(microtime(true)-$authCheck>=5){$this->authorize();$authCheck=microtime(true);}
                $this->checkDevice();
                $rows=$this->db->all('SELECT id,event_key,type,title,body,trade_no,created FROM pre_app_notification WHERE id>? ORDER BY id ASC LIMIT 50',[$this->after]);
                foreach($rows as $row){if(!$connected())return;$emit('receipt',$row);$this->after=(int)$row['id'];}
                if(microtime(true)-$heartbeat>=5){$emit('heartbeat',['server_time'=>time()]);$heartbeat=microtime(true);}
                if(count($rows)<50)$pause();
            }
            if($connected())$emit('end',['reason'=>'reconnect']);
        }catch(ApiError $e){if($connected())$emit('error',['status'=>$e->status,'message'=>$e->getMessage()]);}
        finally{$this->close();}
    }
    public function close()
    {
        if($this->lock!==null){$key=$this->lock;$this->lock=null;try{$this->db->value('SELECT RELEASE_LOCK(?)',[$key]);}catch(\Throwable $ignored){}}
    }
    public function __destruct(){$this->close();}
}
