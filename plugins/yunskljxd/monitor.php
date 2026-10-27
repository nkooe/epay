<?php
namespace EpayMonitor\yunskljxd;
use \yunskljxd_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function monitorTarget($target, &$lastCloudState)
{
    global $DB, $channel;
    $channelId = (int)$target['channel_id'];
    $subchannelId = (int)$target['subchannel_id'];
    $channel = $subchannelId > 0 ? \lib\Channel::getSub($subchannelId) : \lib\Channel::get($channelId);
    if (!$channel || $channel['plugin'] !== 'yunskljxd') return;
    $subSql = " AND subchannel='" . $subchannelId . "'";
    $expireSeconds = yunskljxd_plugin::expireSeconds($channel);

    // 没有本地活跃订单或最近刚结束的本地订单时，不向微信请求状态、流水或收款链接列表。
    // 这样空闲通道不会产生无意义的微信轮询。
    $localActivity = $DB->getAll("SELECT trade_no,status,addtime,ext FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND ((status=0 AND (ext IS NULL OR ext NOT LIKE '%yunskljxd_waiting%') AND addtime>=DATE_SUB(NOW(),INTERVAL " . (int)$expireSeconds . " SECOND)) OR (status=1 AND ext LIKE '%yunskljxd_receipt_id%' AND (ext IS NULL OR ext NOT LIKE '%yunskljxd_receipt_deleted%'))) LIMIT 50");
    if (!$localActivity) return;

    $key = $channelId . ':' . $subchannelId;
    $cloud = yunskljxd_plugin::checkChannelStatus($channel);
    $state = (int)$cloud['code'] === 1 ? 'online' : ((int)$cloud['code'] === 0 ? 'offline' : 'unavailable');
    if (($lastCloudState[$key] ?? '') !== $state) {
        $lastCloudState[$key] = $state;
        echo '[' . date('H:i:s') . '] ' . label($target) . ' ' . ($state === 'online' ? '云端在线' : ($state === 'offline' ? '云端明确返回离线，请重新扫码登录' : '云端不可用：' . $cloud['msg'])) . "\n";
    }

    if ($state === 'offline') throw new ServiceFault('云端账号离线，请重新扫码登录');
    if ($state !== 'online') throw new RuntimeException((string)$cloud['msg']);

    // 无经营权限订单允许 3 分钟，有经营权限订单允许 5 分钟；超时订单写入插件私有标记。
    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . $channelId . "'{$subSql} AND status=0 AND addtime<DATE_SUB(NOW(),INTERVAL " . (int)$expireSeconds . " SECOND)");
    foreach ($expired as $order) { PaymentMonitor::checkpoint(); yunskljxd_plugin::markExpired($order); }

    $orders = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . $channelId . "'{$subSql} AND A.status=0 AND (A.ext IS NULL OR A.ext NOT LIKE '%yunskljxd_waiting%') AND A.addtime>=DATE_SUB(NOW(),INTERVAL " . (int)$expireSeconds . " SECOND) ORDER BY A.addtime ASC");
    $pending = [];
    foreach ($orders as $order) {
        if (yunskljxd_plugin::isExpired($order, $channel)) continue;
        $pending[] = $order;
    }
    $flows = null;
    if ($pending && $state === 'online') {
        $flows = yunskljxd_plugin::getFlows($channel);
        if ((int)$flows['code'] !== 0) {
            throw new RuntimeException((string)$flows['msg']);
        } else {
            foreach ($flows['items'] as $raw) {
                $flow = yunskljxd_plugin::flowValues($raw);
                if (!$flow['paid'] || $flow['id'] === '' || flowAlreadyUsed($flow['id'])) continue;
                $order = matchOrder($pending, $flow, $channel);
                if (!$order) continue;
                settle($target, $order, $flow);
            }
        }
    }

    // 结算尝试完成后清理远端孤立、已支付和超时链接；有流水结果时复用本轮查询。
    if ($state === 'online') {
        PaymentMonitor::checkpoint();
        $cleanup = yunskljxd_plugin::cleanupRemoteLinks($channel, $channelId, $subchannelId, $flows);
        if ((int)($cleanup['code'] ?? -1) !== 0 && !empty($cleanup['msg'])) {
            throw new RuntimeException((string)$cleanup['msg']);
        }
    }
}


function matchOrder($orders, $flow, $channel)
{
    $remark = trim((string)$flow['remark']);
    $remark = preg_replace('/^(请勿添加备注|备注)[\s\-:：]*/u', '', $remark);
    $explicit = trim((string)($flow['order_no'] ?? ''));
    if ($explicit === '') $explicit = preg_match('/\d{10,24}/', $remark, $m) ? $m[0] : '';
    $receiptId = trim((string)($flow['receipt_id'] ?? ''));
    if ($receiptId === '') $receiptId = trim((string)($flow['id'] ?? ''));
    $linkedOrder = null;
    foreach ($orders as $savedOrder) {
        $savedExt = !empty($savedOrder['ext']) ? @unserialize($savedOrder['ext']) : [];
        if (is_array($savedExt) && $receiptId !== '' && (string)($savedExt['yunskljxd_receipt_id'] ?? '') === $receiptId) {
            $linkedOrder = $savedOrder;
            break;
        }
    }
    // 收款链接备注可能是账号名、固定说明或其它业务文本，只有确实命中本地订单号时才当作订单号。
    // 已有 receipt_id 强绑定时，备注里的其它数字不能把绑定订单过滤掉。
    if ($linkedOrder !== null && $explicit !== '') {
        $linkedTradeNo = (string)($linkedOrder['trade_no'] ?? '');
        if ($linkedTradeNo === '' || (substr($linkedTradeNo, -strlen($explicit)) !== $explicit && substr($explicit, -strlen($linkedTradeNo)) !== $linkedTradeNo)) {
            $explicit = '';
        }
    }
    if ($explicit !== '') {
        $explicitMatchesOrder = false;
        foreach ($orders as $savedOrder) {
            $tradeNo = (string)($savedOrder['trade_no'] ?? '');
            if ($tradeNo !== '' && (substr($tradeNo, -strlen($explicit)) === $explicit || substr($explicit, -strlen($tradeNo)) === $tradeNo)) {
                $explicitMatchesOrder = true;
                break;
            }
        }
        if (!$explicitMatchesOrder) $explicit = '';
    }
    $candidates = [];
    foreach ($orders as $order) {
        // CLOSED 状态的已支付收款链接经常不返回交易金额（插件层会得到 0）。
        // 这时使用本地订单金额继续校验，订单尾号和支付时间仍必须满足下面的严格条件。
        if (yunskljxd_plugin::isExpired($order, $channel) || ($flow['amount'] > 0 && !amountMatches($order['realmoney'], $flow['amount']))) continue;
        $orderTime = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
        if (!$orderTime || (int)$flow['time'] <= $orderTime || (int)$flow['time'] > $orderTime + yunskljxd_plugin::flowGraceSeconds($channel)) continue;
        $tradeNo = (string)$order['trade_no'];
        if ($explicit !== '' && substr($tradeNo, -strlen($explicit)) !== $explicit && substr($explicit, -strlen($tradeNo)) !== $tradeNo) continue;
        $candidates[] = $order;
    }
    if (!$candidates) return null;
    // 本地订单创建时已保存收款链接 ID，优先使用这个强绑定关系。
    if ($linkedOrder !== null) {
        foreach ($candidates as $candidate) {
            if ((string)$candidate['trade_no'] === (string)$linkedOrder['trade_no']) return $candidate;
        }
    }
    usort($candidates, function ($a, $b) use ($flow) {
        return abs((int)$flow['time'] - strtotime($a['addtime'])) <=> abs((int)$flow['time'] - strtotime($b['addtime']));
    });
    return $candidates[0];
}


function settle($target, $matched, $flow)
{
    global $DB, $channel;
    $lock = 'yunskljxd_paid_' . sha1((string)$flow['id']);
    if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lock]) !== 1) return;
    try {
        if (flowAlreadyUsed($flow['id'])) return;
        $order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $matched['trade_no']]);
        if (!$order || (int)$order['status'] !== 0) return;
        $matched['plugin'] = 'yunskljxd';
        \lib\Task\PaymentMonitor::notify($matched, $flow['id'], $flow['buyer'] !== '' ? $flow['buyer'] : null, null, null, $flow['time'] > 0 ? date('Y-m-d H:i:s', $flow['time']) : null);
        $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $matched['trade_no']]);
        if ($status === 1) {
            $order['status'] = 1;
            // \lib\Task\PaymentMonitor::notify() 已经执行商户回调并维护 notify 状态；这里不能再次发送。
            $notifyState = (int)$DB->getColumn('SELECT notify FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => $matched['trade_no']]);
            $callbackText = $notifyState === 0 ? '回调已发送' : ($notifyState > 0 ? '回调待系统重试' : '回调未发送');
            PaymentMonitor::checkpoint();
            $paused = yunskljxd_plugin::releaseOrderReceipt($channel, $order, (int)$flow['time']);
            $releaseText = !empty($paused['skipped']) || (int)($paused['code'] ?? -1) === 0 ? '收款链接已暂停保留' : '收款链接暂停失败：' . $paused['msg'];
            echo '[' . date('H:i:s') . '] ' . label($target) . ' 订单 ' . $matched['trade_no'] . ' (' . formatMoney($order['realmoney']) . ") 支付成功，{$callbackText}，{$releaseText}\n";
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
