<?php
namespace lib\Notification;

use lib\AdminApp\ApiError;
use lib\AdminApp\Runtime;
use lib\AdminApp\Store;

final class Delivery
{
    private $db;
    private $config;
    private $transport;
    private $sign;

    public function __construct(Store $db, array $config, $transport = null, $sign = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->transport = $transport ?: new HttpTransport();
        $this->sign = $sign ?: static function ($order) { return \creat_callback($order)['notify']; };
    }

    public static function legacy()
    {
        global $DB, $conf;
        return new self(Runtime::legacyStore($DB), $conf);
    }

    /** Named lock covers signing, gateway I/O and result writes across every sender. */
    public function send($trade, $source, $mode = 'manual', $uid = 0)
    {
        if (!in_array($source, ['payment','cron','cron_exhausted','admin','merchant','app'], true)
            || !in_array($mode, ['initial','retry','exhausted','manual'], true)) throw new ApiError('通知来源无效');
        if ($this->db->pdo->inTransaction()) throw new \LogicException('通知只能在入账事务提交后发送');
        $key = 'epay.notify.'.substr(hash('sha256', $this->db->value('SELECT DATABASE()').':'.$this->db->tablePrefix().':'.$trade), 0, 51);
        if ((int)$this->db->value('SELECT GET_LOCK(?,0)', [$key]) !== 1) throw new ApiError('该订单通知正在发送，请稍后查看结果', 409);
        try {
            $order = $this->db->row('SELECT * FROM pre_order WHERE trade_no=?', [$trade]);
            if (!$order || ($uid > 0 && (int)$order['uid'] !== (int)$uid)) throw new ApiError('订单不存在', 404);
            if (!in_array((int)$order['status'], [1,2,3], true)) throw new ApiError('未支付订单不能发送通知');
            if ((int)$order['tid'] !== 0) throw new ApiError('该订单无需商户回调');
            $now = date('Y-m-d H:i:s');
            $recent = !empty($order['endtime']) && strtotime($order['endtime']) >= strtotime('today -1 day');
            if ($mode === 'retry' && (!$recent || (int)$order['notify'] <= 0 || empty($order['notifytime']) || $order['notifytime'] > $now)) return ['skipped'=>true];
            if ($mode === 'exhausted' && (!$recent || (int)$order['notify'] !== -1)) return ['skipped'=>true];
            if ($mode === 'retry' && (int)$order['notify'] >= 5) {
                $this->db->query('UPDATE pre_order SET notify=-1,notifytime=NULL WHERE trade_no=?', [$trade]);
                return ['skipped'=>true];
            }
            if ($mode === 'initial' && $this->db->value('SELECT COUNT(*) FROM pre_order_notify_attempt WHERE trade_no=?', [$trade])) return ['skipped'=>true];
            // A previous sender lost its connection after claiming: its delivery cannot be asserted.
            $this->db->query("UPDATE pre_order_notify_attempt SET status='unknown',finished_at=?,error_code='interrupted',error_summary='发送进程中断，接收结果未确认' WHERE trade_no=? AND status='sending'", [$now, $trade]);
            $attempt = $this->db->insert('order_notify_attempt', ['trade_no'=>$trade, 'source'=>$source,
                'status'=>'sending', 'started_at'=>$now]);
            // Persist a retry intent before any external request, including manual sends.
            $this->db->query('UPDATE pre_order SET notify=?,notifytime=? WHERE trade_no=?',
                [max(1, (int)$order['notify']), date('Y-m-d H:i:s', time()+60), $trade]);
            $url = ($this->sign)($order);
            $result = $this->transport->send($url, $this->config);
            $delivered = $result['delivered'] === true;
            $stage = $mode === 'retry' ? (int)$order['notify'] + 1 : 1;
            $waits = [1=>60, 2=>120, 3=>960, 4=>2160, 5=>3600];
            $retry = !$delivered && $recent && $mode !== 'exhausted';
            $notify = $delivered ? 0 : ($retry ? $stage : -1);
            $next = $retry ? date('Y-m-d H:i:s', time()+$waits[$stage]) : null;
            $this->db->pdo->beginTransaction();
            try {
                $this->db->update('order_notify_attempt', ['status'=>$delivered?'succeeded':'failed',
                    'finished_at'=>date('Y-m-d H:i:s'), 'http_status'=>$result['http_status'] ?? null,
                    'duration_ms'=>$result['duration_ms'] ?? null, 'error_code'=>$result['error_code'] ?? null,
                    'error_summary'=>$result['error'] ?? null], 'id', $attempt);
                $this->db->query('UPDATE pre_order SET notify=?,notifytime=? WHERE trade_no=?', [$notify,$next,$trade]);
                $this->db->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->db->pdo->inTransaction()) $this->db->pdo->rollBack();
                throw $e;
            }
            return ['queued'=>false, 'delivered'=>$delivered, 'attempt_id'=>(int)$attempt,
                'next_retry_at'=>$next, 'message'=>$delivered?'商户已确认接收通知':$result['error']];
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }

    public function detail(array $order, $before = 0)
    {
        $trade = $order['trade_no'];
        $last = $this->db->row('SELECT * FROM pre_order_notify_attempt WHERE trade_no=? ORDER BY id DESC LIMIT 1', [$trade]);
        $params = [$trade];
        $where = 'trade_no=?';
        if ($before > 0) { $where .= ' AND id<?'; $params[] = $before; }
        $rows = $this->db->all('SELECT id,source,status,started_at,finished_at,http_status,duration_ms,error_code,error_summary FROM pre_order_notify_attempt WHERE '.$where.' ORDER BY id DESC LIMIT 21', $params);
        $more = count($rows) > 20;
        if ($more) array_pop($rows);
        $state = !$last ? 'unrecorded' : $last['status'];
        if (!$last && !empty($order['tid'])) $state = 'not_required';
        if ($last && $last['status'] !== 'succeeded' && (int)$order['notify'] === -1) $state = 'exhausted';
        return ['state'=>$state, 'attempts'=>(int)$this->db->value('SELECT COUNT(*) FROM pre_order_notify_attempt WHERE trade_no=?', [$trade]),
            'next_retry_at'=>(int)$order['notify'] > 0 ? $order['notifytime'] : null,
            'last_error'=>$last['error_summary'] ?? null, 'history'=>$rows,
            'has_more'=>$more, 'next_cursor'=>$more ? (int)end($rows)['id'] : null];
    }
}
