<?php
namespace EpayMonitor\alipaybillautoxd;
use \alipaybillautoxd_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function monitorTarget($target, &$state)
{
    global $DB;
    $channelId = (int)$target['channel_id'];
    $subchannelId = (int)$target['subchannel_id'];
    $channel = $subchannelId > 0 ? \lib\Channel::getSub($subchannelId) : \lib\Channel::get($channelId);
    if (!$channel || $channel['plugin'] !== 'alipaybillautoxd') return;
    $subSql = " AND subchannel='" . $subchannelId . "'";
    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%alipaybillautoxd_expired%')");
    foreach ($expired as $row) { PaymentMonitor::checkpoint(); alipaybillautoxd_plugin::markExpired($row); }
    $pending = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . $channelId . "'{$subSql} AND A.status=0 AND A.addtime >= DATE_SUB(NOW(), INTERVAL " . alipaybillautoxd_plugin::FLOW_GRACE_SECONDS . " SECOND) ORDER BY A.addtime ASC");
    $orders = [];
    foreach ($pending as $row) {
        if (alipaybillautoxd_plugin::isExpired($row)) continue;
        $orders[] = $row;
    }
    if (!$orders) return;
    $now = time();
    $bills = alipaybillautoxd_plugin::getBills($channel, $now - alipaybillautoxd_plugin::FLOW_GRACE_SECONDS, $now + 60);
    if ((int)$bills['code'] !== 0) throw new RuntimeException('查询支付宝官方账单失败：'.$bills['msg']);
    foreach ($bills['items'] as $item) {
        $flow = alipaybillautoxd_plugin::billValues($item);
        if (!validIncomingBill($flow) || flowAlreadyConsumed($flow['id'])) continue;
        $matched = matchBill($orders, $flow);
        if ($matched) settleBill($target, $matched, $flow, $state);
    }
}


function validIncomingBill($flow)
{
    if (!is_array($flow) || trim((string)$flow['id']) === '' || (float)$flow['amount'] <= 0 || (int)$flow['time'] <= 0) return false;
    $direction = strtolower(trim((string)$flow['direction']));
    if ($direction !== '' && !in_array($direction, ['收入', 'income', 'in', '收款', '交易', '转账'], true)) return false;
    $type = strtolower(trim((string)($flow['type'] ?? '')));
    if ($type !== '' && !in_array($type, ['转账', '交易', 'transfer', 'trade'], true)) return false;
    if (trim((string)($flow['merchant_order_no'] ?? '')) !== '' && !in_array($type, ['交易', 'trade'], true)) return false;
    $state = strtolower(trim((string)$flow['state']));
    return $state === '' || in_array($state, ['1', '200', 'true', 'success', 'succeed', 'paid', 'trade_success', 'finished', 'confirmed', '成功'], true);
}


function matchBill($orders, $flow)
{
    $remark = trim((string)$flow['remark']);
    $remark = preg_replace('/^(请勿添加备注|备注)[\s\-:：]*/u', '', $remark);
    $tradeNo = preg_match('/(?<!\d)\d{16,24}(?!\d)/', $remark, $m) ? $m[0] : '';
    $candidates = [];
    foreach ($orders as $order) {
        if (alipaybillautoxd_plugin::isExpired($order)) continue;
        $orderTime = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
        if (!$orderTime || $flow['time'] <= $orderTime || $flow['time'] > $orderTime + alipaybillautoxd_plugin::FLOW_GRACE_SECONDS) continue;
        if (!amountMatches($order['realmoney'], $flow['amount'])) continue;
        if ($tradeNo !== '' && (string)$order['trade_no'] !== $tradeNo) continue;
        $candidates[] = $order;
    }
    if (!$candidates || ($tradeNo !== '' && count($candidates) !== 1)) return null;
    usort($candidates, function ($a, $b) use ($flow) { return abs($flow['time'] - strtotime($a['addtime'])) <=> abs($flow['time'] - strtotime($b['addtime'])); });
    return $candidates[0];
}


function settleBill($target, $order, $flow, &$state)
{
    global $DB, $channel;
    $channel = !empty($target['subchannel_id'])
        ? \lib\Channel::getSub((int)$target['subchannel_id'])
        : \lib\Channel::get((int)$target['channel_id']);
    if (!$channel || $channel['plugin'] !== 'alipaybillautoxd') return;
    $lockName = 'alipaybillautoxd_bill_' . sha1((string)$flow['id']);
    $locked = (int)$DB->getColumn('SELECT GET_LOCK(:lock_name, 2)', [':lock_name' => $lockName]);
    if ($locked !== 1) return;
    try {
        $fresh = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $order['trade_no']]);
        if (!$fresh || (int)$fresh['status'] !== 0 || flowAlreadyConsumed($flow['id'])) return;
        $order['plugin'] = 'alipaybillautoxd';
        \lib\Task\PaymentMonitor::notify($order, $flow['id'], $flow['buyer'] !== '' ? $flow['buyer'] : null, null, null, date('Y-m-d H:i:s', $flow['time']));
        $status = (int)$DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1", [':trade_no' => $fresh['trade_no']]);
        if ($status === 1) { echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' 订单 ' . $fresh['trade_no'] . ' (' . formatAmount($fresh['realmoney']) . ") 支付成功\n"; }
    } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]); }
}


function flowAlreadyConsumed($flowId)
{
    global $DB;
    $value = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND status=1 LIMIT 1', [':api_trade_no' => (string)$flowId]);
    return $value !== false && $value !== null && $value !== '';
}


function amountMatches($orderAmount, $flowAmount)
{
    $orderAmount = (float)$orderAmount; $flowAmount = (float)$flowAmount;
    return abs($orderAmount - $flowAmount) <= 0.001 || abs($orderAmount - ($flowAmount / 100)) <= 0.001;
}


function formatAmount($amount)
{
    $value = number_format((float)$amount, 2, '.', '');
    return rtrim(rtrim($value, '0'), '.');
}


function targetLabel($target)
{
    $label = '通道 ' . (int)$target['channel_id'];
    if (!empty($target['subchannel_id'])) $label .= '/' . (int)$target['subchannel_id'];
    return $label;
}
