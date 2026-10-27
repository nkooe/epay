<?php
namespace EpayMonitor\shouqianbaxd;
use \shouqianbaxd_plugin;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function groupShouqianbaxdTargets($targets)
{
    $groups = [];
    foreach ($targets as $target) {
        $targetChannel = !empty($target['subchannel_id']) ? \lib\Channel::getSub((int)$target['subchannel_id']) : \lib\Channel::get((int)$target['channel_id']);
        if (!$targetChannel || ($targetChannel['plugin'] ?? '') !== 'shouqianbaxd') continue;
        $config = shouqianbaxd_plugin::clientConfig($targetChannel);
        $fingerprint = hash('sha256', trim((string)$config['username']) . "\0" . md5((string)$config['password']));
        if (!isset($groups[$fingerprint])) $groups[$fingerprint] = ['channel' => $targetChannel, 'targets' => []];
        $groups[$fingerprint]['targets'][] = ['target' => $target, 'channel' => $targetChannel];
    }
    return array_values($groups);
}


function monitorShouqianbaxdGroup($group)
{
    global $channel;
    $activeTargets = []; $createdAt = time();
    foreach ($group['targets'] as $item) {
        $orders = activeShouqianbaxdOrders($item['target']); if (!$orders) continue;
        foreach ($orders as $row) $createdAt = min($createdAt, (int)$row['_created_at']);
        $item['orders'] = $orders; $activeTargets[] = $item;
    }
    if (!$activeTargets) return;
    $client = shouqianbaxd_plugin::createClient($group['channel']);
    $runtime = $client->getRuntime();
    $records = $client->findTransactions($createdAt - 60, time() + 60, $runtime);
    $orderCount = 0; foreach ($activeTargets as $item) $orderCount += count($item['orders']);
    echo '[' . date('H:i:s') . '] ' . shouqianbaxdGroupLabel($group) . ' 活动订单 ' . $orderCount . '，已完成流水 ' . count($records) . " 条\n";

    foreach ($activeTargets as $item) {
        PaymentMonitor::progress();
        $channel = $item['channel'];
        foreach ($item['orders'] as $row) {
            PaymentMonitor::progress();
            $data = shouqianbaxd_plugin::matchTransaction($row, $records); if ((int)($data['status'] ?? 0) !== 1) continue;
            $result = settleShouqianbaxdOrder($row, $data);
            if ($result === 'consumed') echo '[' . date('H:i:s') . '] ' . $row['trade_no'] . " 流水号已被其他订单使用，拒绝补单\n";
            elseif ($result === 'paid') echo '[' . date('H:i:s') . '] ' . $row['trade_no'] . ' 支付成功，order_sn=' . $data['api_trade_no'] . "\n";
        }
    }
}


function settleShouqianbaxdOrder($order, $data)
{
    global $DB;
    $flowId = trim((string)($data['api_trade_no'] ?? '')); $expected = readShouqianbaxdExt($order['ext'] ?? ''); $preOrderId = trim((string)($expected['pre_order_id'] ?? ''));
    if ($flowId === '' || $preOrderId === '') throw new RuntimeException('结算参数不完整');
    $lockName = 'sqbxd:settle:' . substr(hash('sha256', $flowId), 0, 47);
    if ((int)$DB->getColumn('SELECT GET_LOCK(:lock_name,2)', [':lock_name' => $lockName]) !== 1) return 'locked';
    try {
        $fresh = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => (string)$order['trade_no']]);
        if (!$fresh || (int)$fresh['status'] !== 0) return 'skipped';
        $context = readShouqianbaxdExt($fresh['ext'] ?? '');
        if (($context['provider'] ?? '') !== 'shouqianbaxd' || !hash_equals($preOrderId, trim((string)($context['pre_order_id'] ?? '')))) throw new RuntimeException('锁内订单上下文已改变，拒绝补单');
        if (shouqianbaxdFlowConsumed($flowId, (string)$fresh['trade_no'])) return 'consumed';
        $order['plugin'] = 'shouqianbaxd';
        \lib\Task\PaymentMonitor::notify($order, $flowId, $data['buyer'] ?? null, $data['bill_trade_no'] ?? null, $data['bill_mch_trade_no'] ?? null, $data['endtime'] ?? null);
        return (int)$DB->getColumn('SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no' => (string)$fresh['trade_no']]) === 1 ? 'paid' : 'skipped';
    } finally { $DB->getColumn('SELECT RELEASE_LOCK(:lock_name)', [':lock_name' => $lockName]); }
}


function activeShouqianbaxdOrders($target)
{
    global $DB; $channelId = (int)$target['channel_id']; $subchannelId = (int)$target['subchannel_id'];
    $rows = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='{$channelId}' AND A.subchannel='{$subchannelId}' AND A.status=0 AND A.addtime>=DATE_SUB(NOW(),INTERVAL 8 MINUTE) AND A.addtime<=NOW() ORDER BY A.addtime ASC");
    $active = [];
    foreach ($rows as $row) { $createdAt = strtotime((string)($row['addtime'] ?? '')); if ($createdAt === false || !shouqianbaxd_plugin::isQueryWindowOpen($createdAt)) continue; $context = readShouqianbaxdExt($row['ext'] ?? ''); if (($context['provider'] ?? '') !== 'shouqianbaxd' || trim((string)($context['pre_order_id'] ?? '')) === '') continue; $row['_created_at'] = $createdAt; $active[] = $row; }
    return $active;
}


function shouqianbaxdFlowConsumed($flowId, $tradeNo) { global $DB; $value = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND trade_no<>:trade_no AND status=1 LIMIT 1', [':api_trade_no' => $flowId, ':trade_no' => $tradeNo]); return $value !== false && $value !== null && $value !== ''; }

function readShouqianbaxdExt($value) { $data = is_string($value) && $value !== '' ? @unserialize($value) : []; return is_array($data) ? $data : []; }

function shouqianbaxdTargetLabel($target) { return '通道 ' . (int)$target['channel_id'] . (!empty($target['subchannel_id']) ? '/' . (int)$target['subchannel_id'] : ''); }

function shouqianbaxdGroupLabel($group) { $labels = []; foreach ($group['targets'] as $item) $labels[] = shouqianbaxdTargetLabel($item['target']); return implode('、', $labels); }
