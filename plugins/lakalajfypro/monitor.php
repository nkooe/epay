<?php
namespace EpayMonitor\lakalajfypro;
use \lakalajfypro_plugin;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function monitorTarget($target)
{
    global $DB, $channel;
    $channel = !empty($target['subchannel_id']) ? \lib\Channel::getSub((int)$target['subchannel_id']) : \lib\Channel::get((int)$target['channel_id']);
    if (!$channel || ($channel['plugin'] ?? '') !== 'lakalajfypro') return;
    $channelId = (int)$target['channel_id'];
    $subSql = " AND subchannel='" . (int)$target['subchannel_id'] . "'";
    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='{$channelId}'{$subSql} AND status=0 AND addtime < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%lakalajfypro_expired%')");
    foreach ($expired as $row) { PaymentMonitor::checkpoint(); lakalajfypro_plugin::markExpired($row); }
    $orders = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='{$channelId}'{$subSql} AND A.status=0 AND A.addtime >= DATE_SUB(NOW(), INTERVAL 8 MINUTE) ORDER BY A.addtime ASC");
    $active = [];
    foreach ($orders as $row) {
        $ext = readOrderExt($row['ext'] ?? '');
        if (($ext['provider'] ?? '') !== 'lakalajfypro' || empty($ext['pay_url'])) continue;
        $createdAt = strtotime((string)($row['addtime'] ?? ''));
        if ($createdAt === false || $createdAt <= 0 || $createdAt + lakalajfypro_plugin::MATCH_SECONDS <= time()) continue;
        $active[] = $row;
    }
    if (!$active) return;
    foreach ($active as $row) {
        PaymentMonitor::progress();
        $data = lakalajfypro_plugin::query($row);
        if ((int)($data['status'] ?? 0) !== 1) {
            $message = trim((string)($data['msg'] ?? ''));
            if ($message !== '') throw new RuntimeException($message);
            continue;
        }
        $fresh = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => (string)$row['trade_no']]);
        if (!$fresh || (int)$fresh['status'] !== 0) continue;
        if (flowAlreadyConsumed($data['api_trade_no'])) continue;
        $row['plugin'] = 'lakalajfypro';
        \lib\Task\PaymentMonitor::notify($row, $data['api_trade_no'], $data['buyer'] ?? null, $data['bill_trade_no'] ?? null, $data['bill_mch_trade_no'] ?? null, $data['endtime'] ?? null);
        $status = (int)$DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1", [':trade_no' => (string)$fresh['trade_no']]);
        if ($status === 1) echo '[' . date('H:i:s') . '] ' . targetLabel($target) . ' 订单 ' . $fresh['trade_no'] . ' (' . formatAmount($fresh['realmoney']) . ") 支付成功，回调已处理\n";
    }
}


function readOrderExt($value) { $data = is_string($value) && $value !== '' ? @unserialize($value) : []; return is_array($data) ? $data : []; }

function flowAlreadyConsumed($flowId) { global $DB; $value = $DB->getColumn('SELECT trade_no FROM pre_order WHERE api_trade_no=:api_trade_no AND status=1 LIMIT 1', [':api_trade_no' => (string)$flowId]); return $value !== false && $value !== null && $value !== ''; }

function formatAmount($amount) { $text = number_format((float)$amount, 2, '.', ''); return rtrim(rtrim($text, '0'), '.'); }

function targetLabel($target) { return '通道 ' . (int)$target['channel_id'] . (!empty($target['subchannel_id']) ? '/' . (int)$target['subchannel_id'] : ''); }
