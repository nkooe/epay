<?php
namespace lib\AdminApp;

final class ApiError extends \RuntimeException
{
    public $status;
    public function __construct($message, $status = 400, $code = -1) { parent::__construct($message, $code); $this->status = $status; }
}


final class Store
{
    public $pdo;
    private $prefix;
    public function __construct(\PDO $pdo, $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new ApiError('数据库前缀无效', 503);
        $this->pdo = $pdo;
        $this->prefix = $prefix . '_';
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }
    public function query($sql, array $params = []) { $s = $this->pdo->prepare(str_replace('pre_', $this->prefix, $sql)); $s->execute($params); return $s; }
    public function row($sql, array $params = []) { return $this->query($sql, $params)->fetch() ?: null; }
    public function all($sql, array $params = []) { return $this->query($sql, $params)->fetchAll(); }
    public function value($sql, array $params = []) { return $this->query($sql, $params)->fetchColumn(); }
    public function insert($table, array $data)
    {
        $keys = array_keys($data);
        $this->query('INSERT INTO pre_'.$table.' (`'.implode('`,`', $keys).'`) VALUES ('.implode(',', array_fill(0, count($keys), '?')).')', array_values($data));
        return $this->pdo->lastInsertId();
    }
    public function update($table, array $data, $key, $id)
    {
        $sets = array_map(function ($k) { return '`'.$k.'`=?'; }, array_keys($data));
        return $this->query('UPDATE pre_'.$table.' SET '.implode(',', $sets).' WHERE `'.$key.'`=?', array_merge(array_values($data), [$id]))->rowCount();
    }
    public function config() { return array_column($this->all('SELECT k,v FROM pre_config'), 'v', 'k'); }
    public function tablePrefix() { return $this->prefix; }
    public function lockClause() { return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
    public function setting($key, $value)
    {
        if ($this->row('SELECT k FROM pre_config WHERE k=?', [$key])) $this->query('UPDATE pre_config SET v=? WHERE k=?', [$value, $key]);
        else $this->insert('config', ['k'=>$key, 'v'=>$value]);
        $this->query("UPDATE pre_cache SET v='' WHERE k='config'");
    }
    public function installed()
    {
        try { foreach(['app_session','app_challenge','app_limit','app_request','app_operation','app_notification'] as $table)$this->query('SELECT id FROM pre_'.$table.' WHERE 1=0');$this->query('SELECT detail FROM pre_app_operation WHERE 1=0');$this->query('SELECT device_id FROM pre_app_notification_device WHERE 1=0'); return true; } catch (\PDOException $e) { return false; }
    }
    /** 清理已过期的应用数据，未确认的资金操作请求继续保留。 */
    public function cleanup($now=null)
    {
        $now=$now??time();$deleted=0;
        foreach(['app_challenge','app_limit','app_session'] as $table)$deleted+=$this->query('DELETE FROM pre_'.$table.' WHERE expires<?',[$now-86400])->rowCount();
        $deleted+=$this->query('DELETE FROM pre_app_request WHERE created<? AND response IS NOT NULL',[$now-90*86400])->rowCount();
        $this->query('UPDATE pre_app_notification_device SET enabled=0 WHERE enabled=1 AND last_seen<?',[$now-30*86400]);
        $deleted+=$this->query('DELETE FROM pre_app_notification WHERE created<?',[$now-90*86400])->rowCount();
        return $deleted;
    }
    public static function auditType($type)
    {
        $type=(string)$type;
        if(strpos($type,'App:')!==0)return $type;
        $action=substr($type,4);
        $labels=[
            'balance'=>'调整商户余额','freeze'=>'冻结订单','unfreeze'=>'解冻订单',
            'refund'=>'订单退款','close'=>'关闭未付订单','order-delete'=>'删除订单',
            'order-mark-unpaid'=>'改未完成','order-mark-paid'=>'改已完成',
            'order-notify'=>'通知商户','manual-fill'=>'手工补单','order-settle'=>'确认订单结算',
            'preauth-pay'=>'预授权扣款','preauth-release'=>'预授权解冻','redpacket-retry'=>'红包转账重试',
            'merchant-password'=>'重设商户密码','merchant-key'=>'重置商户密钥',
            'merchant-rsa'=>'生成商户密钥','merchant-delete'=>'删除无业务商户',
            'settle-complete'=>'登记结算完成','settle-fail'=>'登记结算失败','settle-return'=>'结算失败退回余额',
            'settle-pay'=>'结算转账','settle-query'=>'查询结算转账',
            'batch-create'=>'创建结算批次','batch-complete'=>'登记批次完成',
            'transfer'=>'新增付款','redpacket'=>'创建红包','transfer-query'=>'刷新付款状态',
            'transfer-cancel'=>'撤销付款','transfer-approve'=>'审核并付款',
            'transfer-complete'=>'登记付款完成','transfer-fail'=>'登记付款失败并退回',
            'transfer-proof'=>'获取转账凭证','transfer-balance'=>'查询通道余额',
            'sharing-submit'=>'提交分账','sharing-query'=>'查询分账','sharing-cancel'=>'取消分账',
            'sharing-return'=>'回退分账','sharing-amount'=>'查询可分账金额','sharing-edit-money'=>'修改分账金额',
            'receiver-enable'=>'启用分账规则','receiver-disable'=>'停用分账规则',
            'order-reconcile'=>'核实渠道结果','operation-reconcile'=>'登记渠道核实结果',
            'payment-password'=>'设置支付密码','读取监控商户密钥'=>'读取监控商户密钥','星译付共享登录态同步'=>'星译付会话同步','清除收款单手填SID'=>'清除收款单SID'
        ];

        return '原生'.($labels[$action] ?? $action);
    }
    public function audit($action, $ip, array $data = [])
    {

        $type=self::auditType('App:'.$action);
        if($type!=='原生'.$action)$data['_action']=$action;

        if(mb_strlen($type)>20){$data['_action']=$action;$type=mb_substr($type,0,20);}
        $this->insert('log', ['uid'=>0, 'type'=>$type, 'date'=>date('Y-m-d H:i:s'), 'ip'=>$ip, 'data'=>json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }
}
