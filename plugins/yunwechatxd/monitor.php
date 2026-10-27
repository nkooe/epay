<?php
namespace EpayMonitor\yunwechatxd;
use \yunwechatxd_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

const MONITOR_GRACE_SECONDS = 480;

function pollTarget($target, &$lastCloudStatus)
{
    global $DB, $channel;
    $channelId = (int)$target['channel_id'];
    $subchannelId = (int)$target['subchannel_id'];
    $channel = $subchannelId > 0 ? \lib\Channel::getSub($subchannelId) : \lib\Channel::get($channelId);
    if (!$channel || $channel['plugin'] !== 'yunwechatxd') {
        return;
    }
    $subSql = " AND subchannel='" . $subchannelId . "'";

    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%yun_wechat_xd_expired%')");
    foreach ($expired as $row) {
        PaymentMonitor::checkpoint(); yunwechatxd_plugin::markExpired($row);
    }

    $monitorMinutes = (int)ceil(MONITOR_GRACE_SECONDS / 60);
    $orders = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . $channelId . "'{$subSql} AND A.status=0 AND A.addtime >= DATE_SUB(NOW(), INTERVAL {$monitorMinutes} MINUTE)");
    if (!$orders) {
        return;
    }
    $cloud = yunwechatxd_plugin::checkChannelStatus($channel);
    $cloudKey = $channelId . ':' . $subchannelId;
    $cloudCode = (int)($cloud['code'] ?? -1);
    $cloudState = $cloudCode === 1 ? 'online' : ($cloudCode === 0 ? 'offline' : 'unavailable');
    if (!isset($lastCloudStatus[$cloudKey]) || $lastCloudStatus[$cloudKey] !== $cloudState) {
        $lastCloudStatus[$cloudKey] = $cloudState;
        if ($cloudState === 'online') {
            echo '[' . date('H:i:s') . '] ' . targetLabel($target) . " 云端在线\n";
        } elseif ($cloudState === 'offline') {
            echo '[' . date('H:i:s') . '] ' . targetLabel($target) . " 云端明确返回离线，请重新扫码登录\n";
        } else {
            echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' 云端不可用：' . $cloud['msg'] . "\n";
        }
    }

    if ($cloudState === 'offline') throw new ServiceFault('云端账号离线，请重新扫码登录');
    if ($cloudState !== 'online') throw new RuntimeException((string)$cloud['msg']);
    $flows = yunwechatxd_plugin::getFlows($channel);
    if ((int)$flows['code'] !== 0) throw new RuntimeException((string)$flows['msg']);
    $smallbook = (($channel['pay_mode'] ?? 'receipt') === 'smallbook');
    foreach ($flows['items'] as $item) {
        if (!$smallbook && isset($item['state']) && strtolower((string)$item['state']) !== 'success') {
            continue;
        }
        $flow = yunwechatxd_plugin::flowValues($item, $smallbook);
        if ($flow['id'] === '' || $flow['amount'] <= 0) {
            continue;
        }
        if (flowAlreadyConsumed($flow['id'])) {
            continue;
        }
        $tradeNo = extractTradeNo($flow['remark']);
        $candidates = [];
        foreach ($orders as $row) {
            if (!flowAmountMatches($row['realmoney'], $flow['amount']) || monitorOrderExpired($row)) {
                continue;
            }
            if (!flowIsAfterOrder($flow, $row)) {
                continue;
            }
            if ($tradeNo !== '' && $row['trade_no'] !== $tradeNo) {
                continue;
            }
            if ($tradeNo === '' && !$smallbook) {
                continue;
            }
            $orderTime = !empty($row['addtime']) ? strtotime($row['addtime']) : 0;
            if ($tradeNo === '' && (!$orderTime || !$flow['time'] || $flow['time'] <= $orderTime || $flow['time'] > $orderTime + MONITOR_GRACE_SECONDS)) {
                continue;
            }
            $candidates[] = $row;
        }
        if ($tradeNo === '' && count($candidates) > 1) {
            usort($candidates, function ($a, $b) use ($flow) {
                return abs($flow['time'] - strtotime($a['addtime'])) <=> abs($flow['time'] - strtotime($b['addtime']));
            });
        }
        if ($tradeNo === '' && !empty($candidates)) {
            $tradeNo = $candidates[0]['trade_no'];
        }
        if ($tradeNo === '') {
            continue;
        }
        foreach ($orders as $row) {
            if ($row['trade_no'] !== $tradeNo || !flowAmountMatches($row['realmoney'], $flow['amount']) || monitorOrderExpired($row) || !flowIsAfterOrder($flow, $row)) {
                continue;
            }
            $order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $tradeNo]);
            if (!$order || (int)$order['status'] !== 0) {
                continue;
            }
            $row['plugin'] = 'yunwechatxd';
            \lib\Task\PaymentMonitor::notify($row, $flow['id'], $flow['buyer'], null, null, $flow['time'] ? date('Y-m-d H:i:s', $flow['time']) : null);
            $notified = true;
            $updated = $DB->getColumn("SELECT status FROM pre_order WHERE trade_no='" . daddslashes($tradeNo) . "' LIMIT 1");
            if ((int)$updated === 1) {
                $displayAmount = number_format((float)($order['realmoney'] ?? $row['realmoney'] ?? 0), 2, '.', '');
                echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' 订单 ' . $tradeNo . ' (' . $displayAmount . ')' . ($notified ? " 支付成功，回调已发送\n" : " 支付成功，回调发送失败，等待系统重试\n");
            } else {
                echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' 订单 ' . $tradeNo . " 匹配成功但状态更新失败\n";
            }
            break;
        }
    }
}


function flowAmountMatches($orderAmount, $flowAmount)
{
    $orderAmount = (float)$orderAmount;
    $flowAmount = (float)$flowAmount;
    return abs($orderAmount - $flowAmount) <= 0.001
        || abs($orderAmount - ($flowAmount / 100)) <= 0.001;
}


function monitorOrderExpired($order)
{
    if (empty($order['addtime'])) {
        return true;
    }
    return strtotime($order['addtime']) + MONITOR_GRACE_SECONDS <= time();
}


function flowIsAfterOrder($flow, $order)
{
    $flowTime = (int)($flow['time'] ?? 0);
    $orderTime = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
    return $flowTime > 0 && $orderTime > 0
        && $flowTime > $orderTime
        && $flowTime <= $orderTime + MONITOR_GRACE_SECONDS;
}




function targetLabel($target)
{
    $label = '通道 ' . (int)$target['channel_id'];
    if (!empty($target['subchannel_id'])) {
        $label .= '/' . (int)$target['subchannel_id'];
    }
    return $label;
}


function extractTradeNo($remark)
{
    $remark = trim((string)$remark);
    $remark = preg_replace('/^请勿添加备注[-:：]?/u', '', $remark);
    if (preg_match('/^\d{16,24}$/', $remark)) {
        return $remark;
    }
    return '';
}


function flowAlreadyConsumed($flowId)
{
    global $DB;
    $flowId = trim((string)$flowId);
    if ($flowId === '') {
        return false;
    }
    $tradeNo = $DB->getColumn(
        "SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND status=1 LIMIT 1",
        [':api_trade_no' => $flowId]
    );
    return $tradeNo !== false && $tradeNo !== null && $tradeNo !== '';
}
