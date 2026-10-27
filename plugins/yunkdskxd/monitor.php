<?php
namespace EpayMonitor\yunkdskxd;
use \yunkdskxd_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function monitorTarget($target, &$lastCloudState)
{
    global $DB, $channel;
    $channelId = (int)$target['channel_id'];
    $subchannelId = (int)$target['subchannel_id'];
    $channel = $subchannelId > 0 ? \lib\Channel::getSub($subchannelId) : \lib\Channel::get($channelId);
    if (!$channel || $channel['plugin'] !== 'yunkdskxd') return;
    $subSql = " AND subchannel='" . $subchannelId . "'";

    // 订单 5 分钟后不再展示为待支付，但云端流水仍保留到 8 分钟用于网络宽限；
    // 宽限窗口结束后必须取消对应的小票单，避免微信端留下长期未支付订单。
    $cancelDue = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime < DATE_SUB(NOW(), INTERVAL 8 MINUTE) AND ext LIKE '%yunkdskxd_mini_receipt_order_id%'");
    foreach ($cancelDue as $order) {
        PaymentMonitor::checkpoint();
        $cancel = yunkdskxd_plugin::cancelExpiredReceipt($channel, $order);
        if (empty($cancel['skipped']) && (int)($cancel['code']??-1)!==0) throw new RuntimeException((string)$cancel['msg']);
        PaymentMonitor::progress();
        if (!empty($cancel['skipped'])) continue;
        if ((int)($cancel['code'] ?? -1) === 0) {
            echo '[' . date('H:i:s') . '] ' . label($target) . ' 订单 ' . $order['trade_no'] . " 云端小票单已取消\n";
        } else {
            echo '[' . date('H:i:s') . '] ' . label($target) . ' 订单 ' . $order['trade_no'] . ' 取消云端小票单失败：' . $cancel['msg'] . "\n";
        }
    }

    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
    foreach ($expired as $order) { PaymentMonitor::checkpoint(); yunkdskxd_plugin::markExpired($order); }

    $orders = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . $channelId . "'{$subSql} AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL 8 MINUTE) ORDER BY A.addtime ASC");
    $pending = [];
    foreach ($orders as $order) {
        if (yunkdskxd_plugin::isExpired($order)) continue;
        $pending[] = $order;
    }
    if (!$pending) return;
    $key = $channelId . ':' . $subchannelId;
    $cloud = yunkdskxd_plugin::checkChannelStatus($channel);
    $state = (int)$cloud['code'] === 1 ? 'online' : ((int)$cloud['code'] === 0 ? 'offline' : 'unavailable');
    if (($lastCloudState[$key] ?? '') !== $state) {
        $lastCloudState[$key] = $state;
        echo '[' . date('H:i:s') . '] ' . label($target) . ' ' . ($state === 'online' ? '云端在线' : ($state === 'offline' ? '云端明确返回离线，请重新扫码登录' : '云端不可用：' . $cloud['msg'])) . "\n";
    }

    if ($state === 'offline') throw new ServiceFault('云端账号离线，请重新扫码登录');
    if ($state !== 'online') throw new RuntimeException((string)$cloud['msg']);

    $flows = yunkdskxd_plugin::getFlows($channel);
    if ((int)$flows['code'] !== 0) throw new RuntimeException((string)$flows['msg']);
    foreach ($flows['items'] as $raw) {
        $flow = yunkdskxd_plugin::flowValues($raw);
        if (!$flow['paid'] || $flow['id'] === '' || flowAlreadyUsed($flow['id'])) continue;
        $order = matchOrder($pending, $flow);
        if (!$order) continue;
        settle($target, $order, $flow);
    }
}


function matchOrder($orders, $flow)
{
    $remark = trim((string)$flow['remark']);
    $remark = preg_replace('/^(请勿添加备注|备注)[\s\-:：]*/u', '', $remark);
    $explicit = preg_match('/\d{10,24}/', $remark, $m) ? $m[0] : '';
    $candidates = [];
    foreach ($orders as $order) {
        if (yunkdskxd_plugin::isExpired($order) || !amountMatches($order['realmoney'], $flow['amount'])) continue;
        $orderTime = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
        if (!$orderTime || (int)$flow['time'] <= $orderTime || (int)$flow['time'] > $orderTime + yunkdskxd_plugin::FLOW_GRACE_SECONDS) continue;
        $tradeNo = (string)$order['trade_no'];
        if ($explicit !== '' && substr($tradeNo, -strlen($explicit)) !== $explicit && substr($explicit, -strlen($tradeNo)) !== $tradeNo) continue;
        $candidates[] = $order;
    }
    if (!$candidates) return null;
    usort($candidates, function ($a, $b) use ($flow) {
        return abs((int)$flow['time'] - strtotime($a['addtime'])) <=> abs((int)$flow['time'] - strtotime($b['addtime']));
    });
    return $candidates[0];
}


function settle($target, $matched, $flow)
{
    global $DB, $channel;
    $lock = 'yunkdskxd_paid_' . sha1((string)$flow['id']);
    if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lock]) !== 1) return;
    try {
        if (flowAlreadyUsed($flow['id'])) return;
        $order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $matched['trade_no']]);
        if (!$order || (int)$order['status'] !== 0) return;
        $matched['plugin'] = 'yunkdskxd';
        \lib\Task\PaymentMonitor::notify($matched, $flow['id'], $flow['buyer'] !== '' ? $flow['buyer'] : null, null, null, $flow['time'] > 0 ? date('Y-m-d H:i:s', $flow['time']) : null);
        $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $matched['trade_no']]);
        if ($status === 1) {
            $sent = (int)$DB->getColumn('SELECT notify FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no'=>$matched['trade_no']]) === 0;
            echo '[' . date('H:i:s') . '] ' . label($target) . ' 订单 ' . $matched['trade_no'] . ' (' . formatMoney($order['realmoney']) . ')' . ($sent ? " 支付成功，回调已发送\n" : " 支付成功，回调待系统重试\n");
        }
    } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lock]); }
}


function flowAlreadyUsed($id)
{
    global $DB;
    $id = trim((string)$id); if ($id === '') return false;
    $value = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND status=1 LIMIT 1', [':api_trade_no' => $id]);
    return $value !== false && $value !== null && $value !== '';
}




function amountMatches($orderAmount, $flowAmount)
{
    $orderAmount = (float)$orderAmount; $flowAmount = (float)$flowAmount;
    return abs($orderAmount - $flowAmount) <= 0.001 || abs($orderAmount - ($flowAmount / 100)) <= 0.001;
}


function formatMoney($amount)
{
    $value = number_format((float)$amount, 2, '.', '');
    return rtrim(rtrim($value, '0'), '.');
}


function label($target)
{
    return '通道 ' . (int)$target['channel_id'] . (!empty($target['subchannel_id']) ? '/' . (int)$target['subchannel_id'] : '');
}
