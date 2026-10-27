<?php
namespace EpayMonitor\yunqnmxd;
use \yunqnmxd_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function monitorTarget($target, &$lastCloudState)
{
    global $DB, $channel;
    $channelId = (int)$target['channel_id'];
    $subchannelId = (int)$target['subchannel_id'];
    $channel = $subchannelId > 0 ? \lib\Channel::getSub($subchannelId) : \lib\Channel::get($channelId);
    if (!$channel || $channel['plugin'] !== 'yunqnmxd') return;
    $subSql = " AND subchannel='" . $subchannelId . "'";
    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime<DATE_SUB(NOW(),INTERVAL " . yunqnmxd_plugin::orderExpireSeconds() . " SECOND) AND (ext IS NULL OR ext NOT LIKE '%yunqnmxd_expired%')");
    foreach ($expired as $order) { PaymentMonitor::checkpoint(); yunqnmxd_plugin::markExpired($order); }

    // 没有本地未支付订单时，不检查云端状态、不刷新账本会话，也不请求微信流水。
    $orders = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . $channelId . "'{$subSql} AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL " . yunqnmxd_plugin::flowWindowSeconds($channel) . " SECOND) ORDER BY A.addtime ASC");
    $pending = [];
    foreach ($orders as $order) {
        if (yunqnmxd_plugin::isExpired($order)) continue;
        $pending[] = $order;
    }
    if (!$pending) return;

    $cloud = yunqnmxd_plugin::checkCloudStatus($channel);
    $state = (int)$cloud['code'] === 1 ? 'online' : ((int)$cloud['code'] === 0 ? 'offline' : 'unavailable');
    $stateKey = $channelId . ':' . $subchannelId;
    if (($lastCloudState[$stateKey] ?? '') !== $state) {
        $lastCloudState[$stateKey] = $state;
        echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' ' . ($state === 'online' ? '云端在线' : ($state === 'offline' ? '云端明确返回离线，请重新扫码登录' : '云端不可用：' . $cloud['msg'])) . "\n";
    }
    if ($state === 'offline') throw new ServiceFault('云端账号离线，请重新扫码登录');
    if ($state !== 'online') throw new RuntimeException((string)$cloud['msg']);

    $ledger = yunqnmxd_plugin::getLedger($channel);
    if ((int)$ledger['code'] !== 0) throw new RuntimeException((string)$ledger['msg']);
    foreach ((array)$ledger['items'] as $flow) {
        if (flowAlreadyUsed($flow['id'])) continue;
        $matched = matchFlow($pending, $flow, $channel);
        if ($matched) settleFlow($target, $matched, $flow);
    }
}


function matchFlow($orders, $flow, $channel)
{
    $candidates = [];
    foreach ($orders as $order) {
        if (yunqnmxd_plugin::isExpired($order)) continue;
        $created = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
        if ($created <= 0 || (int)$flow['time'] <= $created || (int)$flow['time'] > $created + yunqnmxd_plugin::flowWindowSeconds($channel)) continue;
        if (!amountMatches($order['realmoney'], $flow['amount'])) continue;
        $candidates[] = $order;
    }
    if (!$candidates) return null;
    usort($candidates, function ($a, $b) use ($flow) { return abs((int)$flow['time'] - strtotime($a['addtime'])) <=> abs((int)$flow['time'] - strtotime($b['addtime'])); });
    return $candidates[0];
}


function settleFlow($target, $matched, $flow)
{
    global $DB, $channel;
    $lockName = 'yunqnmxd_flow_' . sha1((string)$flow['id']);
    if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lockName]) !== 1) return;
    try {
        if (flowAlreadyUsed($flow['id'])) return;
        $order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $matched['trade_no']]);
        if (!$order || (int)$order['status'] !== 0 || yunqnmxd_plugin::isExpired($order)) return;
        $matched['plugin'] = 'yunqnmxd';
        \lib\Task\PaymentMonitor::notify($matched, $flow['id'], $flow['buyer'] !== '' ? $flow['buyer'] : null, null, null, date('Y-m-d H:i:s', (int)$flow['time']));
        $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $matched['trade_no']]);
        if ($status === 1) echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' 订单 ' . $matched['trade_no'] . ' (' . formatAmount($order['realmoney']) . ") 支付成功\n";
    } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]); }
}


function flowAlreadyUsed($flowId)
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
