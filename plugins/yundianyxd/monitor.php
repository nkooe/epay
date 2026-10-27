<?php
namespace EpayMonitor\yundianyxd;
use \yundianyxd_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function monitorTarget($target, &$lastState)
{
    global $DB, $channel;
    $channelId = (int)$target['channel_id']; $subchannelId = (int)$target['subchannel_id'];
    $channel = $subchannelId > 0 ? \lib\Channel::getSub($subchannelId) : \lib\Channel::get($channelId);
    if (!$channel || $channel['plugin'] !== 'yundianyxd') return;
    $subSql = " AND subchannel='" . $subchannelId . "'";
    $flowWindow = yundianyxd_plugin::flowWindowSeconds($channel);
    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime<DATE_SUB(NOW(),INTERVAL " . yundianyxd_plugin::orderExpireSeconds() . " SECOND) AND (ext IS NULL OR ext NOT LIKE '%yundianyxd_expired%')");
    foreach ($expired as $order) { PaymentMonitor::checkpoint(); yundianyxd_plugin::markExpired($order); }

    // 本地没有未支付订单时，不请求云端状态或微信店员账单。
    $orders = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . $channelId . "'{$subSql} AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL " . (int)$flowWindow . " SECOND) ORDER BY A.addtime ASC");
    $pending = [];
    foreach ($orders as $order) {
        // 收银台 5 分钟到期只写私有标记；为覆盖网络延迟，后台仍在 8 分钟流水窗口内继续匹配。
        $pending[] = $order;
    }
    if (!$pending) return;

    $cloud = yundianyxd_plugin::checkCloudStatus($channel);
    $state = (int)$cloud['code'] === 1 ? 'online' : ((int)$cloud['code'] === 0 ? 'offline' : 'unavailable');
    $stateKey = $channelId . ':' . $subchannelId;
    if (($lastState[$stateKey] ?? '') !== $state) {
        $lastState[$stateKey] = $state;
        echo '[' . date('H:i:s') . '] ' . label($target) . ' ' . ($state === 'online' ? '云端在线' : ($state === 'offline' ? '云端明确返回离线，请重新扫码登录' : '云端不可用：' . $cloud['msg'])) . "\n";
    }
    if ($state === 'offline') throw new ServiceFault('云端账号离线，请重新扫码登录');
    if ($state !== 'online') throw new RuntimeException((string)$cloud['msg']);

    $flows = yundianyxd_plugin::getFlows($channel);
    if ((int)$flows['code'] !== 0 && yundianyxd_plugin::isCredentialError($flows['msg'])) {
        $refresh = yundianyxd_plugin::refreshCredentials($channel);
        if ((int)$refresh['code'] === 0) $flows = yundianyxd_plugin::getFlows(yundianyxd_plugin::reloadChannel($channel));
    }
    if ((int)$flows['code'] !== 0) throw new RuntimeException((string)$flows['msg']);
    foreach ((array)$flows['items'] as $flow) {
        if (flowAlreadyUsed($flow['id'])) continue;
        $matched = matchOrder($pending, $flow, $flowWindow);
        if ($matched) settleOrder($target, $matched, $flow);
    }
}


function matchOrder($orders, $flow, $window)
{
    $candidates = [];
    foreach ($orders as $order) {
        $created = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
        if ($created <= 0 || (int)$flow['time'] <= $created || (int)$flow['time'] > $created + $window) continue;
        if (!amountMatches($order['realmoney'], $flow['amount'])) continue;
        $candidates[] = $order;
    }
    if (!$candidates) return null;
    usort($candidates, function ($a, $b) use ($flow) { return abs((int)$flow['time'] - strtotime($a['addtime'])) <=> abs((int)$flow['time'] - strtotime($b['addtime'])); });
    return $candidates[0];
}


function settleOrder($target, $matched, $flow)
{
    global $DB, $channel;
    $lock = 'yundianyxd_paid_' . sha1((string)$flow['id']);
    if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lock]) !== 1) return;
    try {
        if (flowAlreadyUsed($flow['id'])) return;
        $order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $matched['trade_no']]);
        // 订单超过收银台 5 分钟后仍可在流水 8 分钟窗口内完成结算，避免支付已到账但网络延迟导致漏单。
        if (!$order || (int)$order['status'] !== 0) return;
        $matched['plugin'] = 'yundianyxd';
        \lib\Task\PaymentMonitor::notify($matched, $flow['id'], null, null, null, date('Y-m-d H:i:s', (int)$flow['time']));
        $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $matched['trade_no']]);
        if ($status === 1) echo '[' . date('H:i:s') . '] ' . label($target) . ' 订单 ' . $matched['trade_no'] . ' (' . moneyText($order['realmoney']) . ") 支付成功，回调已处理\n";
    } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lock]); }
}


function flowAlreadyUsed($id)
{
    global $DB;
    $value = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND status=1 LIMIT 1', [':api_trade_no' => (string)$id]);
    return $value !== false && $value !== null && $value !== '';
}


function amountMatches($orderAmount, $flowAmount)
{
    $orderAmount = (float)$orderAmount; $flowAmount = (float)$flowAmount;
    return abs($orderAmount - $flowAmount) <= 0.001 || abs($orderAmount - ($flowAmount / 100)) <= 0.001;
}


function moneyText($amount)
{
    $value = number_format((float)$amount, 2, '.', ''); return rtrim(rtrim($value, '0'), '.');
}


function label($target)
{
    return '通道 ' . (int)$target['channel_id'] . (!empty($target['subchannel_id']) ? '/' . (int)$target['subchannel_id'] : '');
}
