<?php
namespace EpayMonitor\shouqianbapro;
use \shouqianbapro_plugin;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function groupShouqianbaProTargets($targets)
{
    $groups = [];
    foreach ($targets as $target) {
        $targetChannel = !empty($target['subchannel_id']) ? \lib\Channel::getSub((int)$target['subchannel_id']) : \lib\Channel::get((int)$target['channel_id']);
        if (!$targetChannel || ($targetChannel['plugin'] ?? '') !== 'shouqianbapro') continue;
        $config = shouqianbapro_plugin::clientConfig($targetChannel);
        $fingerprint = hash('sha256', trim((string)$config['username']) . "\0" . md5((string)$config['password']));
        if (!isset($groups[$fingerprint])) $groups[$fingerprint] = ['channel' => $targetChannel, 'targets' => []];
        $groups[$fingerprint]['targets'][] = ['target' => $target, 'channel' => $targetChannel];
    }
    return array_values($groups);
}


function monitorShouqianbaProGroup($group)
{
    global $channel;
    $activeTargets = [];
    $createdAt = time();
    foreach ($group['targets'] as $item) {
        $orders = activeShouqianbaProOrders($item['target']);
        if (!$orders) continue;
        foreach ($orders as $row) $createdAt = min($createdAt, (int)$row['_created_at']);
        $item['orders'] = $orders;
        $activeTargets[] = $item;
    }
    if (!$activeTargets) return;

    $client = shouqianbapro_plugin::createClient($group['channel']);
    $runtime = $client->getRuntime();
    $records = $client->accountBookRecords(max(0, ($createdAt - 120) * 1000), (int)(microtime(true) * 1000), $runtime);
    $transactions = isset($records['transactions']) && is_array($records['transactions']) ? $records['transactions'] : [];
    $orderCount = 0;
    foreach ($activeTargets as $item) $orderCount += count($item['orders']);
    echo '[' . date('H:i:s') . '] ' . shouqianbaProGroupLabel($group) . ' 活动订单 ' . $orderCount . '，账本流水 ' . count($transactions) . " 条\n";

    foreach ($activeTargets as $item) {
        PaymentMonitor::progress();
        $channel = $item['channel'];
        foreach ($item['orders'] as $row) {
            PaymentMonitor::progress();
            if (!hasShouqianbaProTransactionSignal($transactions, $row)) continue;
            $data = shouqianbapro_plugin::query($row);
            if ((int)($data['status'] ?? 0) !== 1) continue;
            $result = settleShouqianbaProOrder($row, $data);
            if ($result === 'consumed') echo '[' . date('H:i:s') . '] ' . $row['trade_no'] . " 流水号已被其他订单使用，拒绝补单\n";
            elseif ($result === 'paid') echo '[' . date('H:i:s') . '] ' . $row['trade_no'] . ' 支付成功，payTsn=' . $data['api_trade_no'] . "\n";
        }
    }
}


function settleShouqianbaProOrder($order, $data)
{
    global $DB;
    $flowId = trim((string)($data['api_trade_no'] ?? ''));
    $expectedExt = readShouqianbaProExt($order['ext'] ?? '');
    $preOrderId = trim((string)($expectedExt['pre_order_id'] ?? ''));
    if ($flowId === '' || $preOrderId === '') throw new RuntimeException('结算参数不完整');

    $lockName = 'sqbp:settle:' . substr(hash('sha256', $flowId), 0, 48);
    if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name, 2)', [':lock_name' => $lockName]) !== 1) return 'locked';
    try {
        $fresh = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => (string)$order['trade_no']]);
        if (!$fresh || (int)$fresh['status'] !== 0) return 'skipped';
        $freshExt = readShouqianbaProExt($fresh['ext'] ?? '');
        if (($freshExt['provider'] ?? '') !== 'shouqianbapro' || !hash_equals($preOrderId, trim((string)($freshExt['pre_order_id'] ?? '')))) {
            throw new RuntimeException('锁内订单上下文已改变，拒绝补单');
        }
        if (shouqianbaProFlowConsumed($flowId, (string)$fresh['trade_no'])) return 'consumed';

        $order['plugin'] = 'shouqianbapro';
        \lib\Task\PaymentMonitor::notify($order, $flowId, $data['buyer'] ?? null, $data['bill_trade_no'] ?? null, $data['bill_mch_trade_no'] ?? null, $data['endtime'] ?? null);
        $status = (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$fresh['trade_no']]);
        return $status === 1 ? 'paid' : 'skipped';
    } finally {
        $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]);
    }
}


function activeShouqianbaProOrders($target)
{
    global $DB;
    $channelId = (int)$target['channel_id'];
    $subchannelId = (int)$target['subchannel_id'];
    $subSql = " AND A.subchannel='" . $subchannelId . "'";
    $rows = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='{$channelId}'{$subSql} AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL 8 MINUTE) AND A.addtime<=NOW() ORDER BY A.addtime ASC");
    $active = [];
    foreach ($rows as $row) {
        $createdAt = strtotime((string)($row['addtime'] ?? ''));
        if ($createdAt === false || !shouqianbapro_plugin::isQueryWindowOpen($createdAt)) continue;
        $ext = readShouqianbaProExt($row['ext'] ?? '');
        if (($ext['provider'] ?? '') !== 'shouqianbapro' || trim((string)($ext['pre_order_id'] ?? '')) === '') continue;
        $row['_created_at'] = $createdAt;
        $active[] = $row;
    }
    return $active;
}


function hasShouqianbaProTransactionSignal($transactions, $order)
{
    $amount = (int)round((float)($order['realmoney'] ?? $order['money'] ?? 0) * 100);
    $createdAt = (int)($order['_created_at'] ?? 0);
    if ($amount <= 0 || $createdAt <= 0) return false;
    foreach ($transactions as $transaction) {
        if (!is_array($transaction) || (int)($transaction['status'] ?? 0) !== 1) continue;
        $paid = (int)($transaction['actualReceiveAmount'] ?? ($transaction['paidAmount'] ?? ($transaction['totalFee'] ?? 0)));
        if ($paid !== $amount) continue;
        $ctime = (int)($transaction['ctime'] ?? ($transaction['finishTime'] ?? 0));
        if ($ctime > 20000000000) $ctime = (int)floor($ctime / 1000);
        if ($ctime >= $createdAt - 120 && $ctime <= $createdAt + shouqianbapro_plugin::QUERY_GRACE_SECONDS) return true;
    }
    return false;
}


function shouqianbaProFlowConsumed($flowId, $tradeNo)
{
    global $DB;
    $value = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND trade_no<>:trade_no AND status=1 LIMIT 1', [':api_trade_no' => $flowId, ':trade_no' => $tradeNo]);
    return $value !== false && $value !== null && $value !== '';
}


function readShouqianbaProExt($value)
{
    $data = is_string($value) && $value !== '' ? @unserialize($value) : [];
    return is_array($data) ? $data : [];
}


function shouqianbaProTargetLabel($target)
{
    return '通道 ' . (int)$target['channel_id'] . (!empty($target['subchannel_id']) ? '/' . (int)$target['subchannel_id'] : '');
}


function shouqianbaProGroupLabel($group)
{
    $labels = [];
    foreach ($group['targets'] as $item) $labels[] = shouqianbaProTargetLabel($item['target']);
    return implode('、', $labels);
}
