<?php
namespace EpayMonitor\shouqianba;
use \shouqianba_plugin;
use \Throwable;
use \RuntimeException;
use \lib\Task\{PaymentMonitor,ServiceFault};

function pollTarget($target)
{
    // \lib\Task\PaymentMonitor::notify() 及系统回调链通过全局 $channel 读取当前通道配置。
    global $DB, $channel;
    $channel = !empty($target['subchannel_id']) ? \lib\Channel::getSub($target['subchannel_id']) : \lib\Channel::get($target['channel_id']);
    if (!$channel || $channel['plugin'] !== 'shouqianba') return;
    $label = '通道 ' . (int)$target['channel_id'] . (!empty($target['subchannel_id']) ? '/' . (int)$target['subchannel_id'] : '');
    $config = getSqbConfig($channel);
    $merchant = json_decode($config['merchant_info'], true);
    if ($config['store_sn'] === '' || $config['terminal_sn'] === '' || !is_array($merchant) || (string)($merchant['selected_store_sn']??'') !== $config['store_sn'] || (string)($merchant['selected_terminal_sn']??'') !== $config['terminal_sn']) throw new ServiceFault('请先完成门店终端绑定');
    $subchannel = (int)($target['subchannel_id'] ?? 0);
    $expired = $DB->getAll("SELECT * FROM pre_order WHERE channel='" . (int)$target['channel_id'] . "' AND subchannel='" . $subchannel . "' AND status=0 AND addtime<DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (ext IS NULL OR ext='' OR ext NOT LIKE '%shouqianba_expired%')");
    foreach ($expired as $row) { PaymentMonitor::checkpoint(); shouqianba_plugin::markExpired($row); }
    $pending = $DB->getAll("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.channel='" . (int)$target['channel_id'] . "' AND A.subchannel='" . $subchannel . "' AND A.status=0 AND A.addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE) AND (A.ext IS NULL OR A.ext='' OR A.ext NOT LIKE '%shouqianba_expired%') ORDER BY A.addtime ASC");
    // 没有待支付订单时不请求收钱吧接口；有订单时直接使用配置中缓存的 Token。
    if (!$pending) return;
    $token = $config['token'];
    if ($token === '') throw new ServiceFault('登录态为空，请先同步');
    $result = shouqianba_plugin::queryTransactions($token);
    if ($result['code'] !== 0 && !empty($result['token_invalid'])) {
        if (!shouqianba_plugin::recoverRejectedToken($channel, $token, $newToken, $refreshError)) {
            throw new ServiceFault('收钱吧令牌刷新失败：'.$refreshError);
        }
        $token = $newToken;
        $result = shouqianba_plugin::queryTransactions($token);
    }
    if ((int)$result['code'] !== 0) throw new RuntimeException((string)$result['msg']);
    $payways = [2 => 'alipay', 3 => 'wxpay'];
    $selectedTerminalSn = (string)($merchant['selected_terminal_sn'] ?? $config['terminal_sn']);
    $selectedClientSn = (string)($merchant['selected_terminal_client_sn'] ?? '');
    if ($selectedClientSn === '' && is_array($merchant['terminals'] ?? null)) {
        foreach ($merchant['terminals'] as $terminalRow) {
            if ((string)($terminalRow['terminal_sn'] ?? '') === $selectedTerminalSn) {
                $selectedClientSn = (string)($terminalRow['client_sn'] ?? '');
                break;
            }
        }
    }
    if ($selectedTerminalSn === '' || $selectedClientSn === '') throw new ServiceFault('已绑定终端缺少 sn/client_sn');
    static $unmatchedLogged = [];
    foreach ($result['items'] as $item) {
        $payType = transactionPayType($item, $payways);
        $thirdId = transactionValue($item, ['order_sn', 'orderNo', 'transaction_no', 'transactionNo', 'id']);
        $amount = transactionAmount($item);
        if ($payType === '' || $thirdId === '' || $amount <= 0 || !transactionIsPaid($item)) continue;
        if (!transactionMatchesTerminal($item, $selectedTerminalSn, $selectedClientSn) || flowConsumed($thirdId)) {
            $diagnosticKey = $label . ':' . $thirdId;
            if (empty($unmatchedLogged[$diagnosticKey])) {
                if (count($unmatchedLogged) >= 512) array_shift($unmatchedLogged);
                $unmatchedLogged[$diagnosticKey] = true;
                echo '[' . date('H:i:s') . '] ' . $label . ' 流水未匹配：流水=' . $thirdId . ' 金额=' . number_format($amount, 2, '.', '') . ' 支付方式=' . ($payType ?: '未知') . ' 终端=' . transactionTerminalNo($item) . '/' . transactionClientSn($item) . '，配置终端=' . $selectedTerminalSn . '/' . $selectedClientSn . "\n";
            }
            continue;
        }
        $candidates = [];
        foreach ($pending as $candidate) {
            if (abs((float)$candidate['realmoney'] - $amount) > 0.001 || strtolower((string)$candidate['typename']) !== $payType || !flowAfterOrder($item, $candidate)) continue;
            $candidates[] = $candidate;
        }
        if (count($candidates) !== 1) {
            $diagnosticKey = $label . ':' . $thirdId . ':candidate';
            if (empty($unmatchedLogged[$diagnosticKey])) {
                if (count($unmatchedLogged) >= 512) array_shift($unmatchedLogged);
                $unmatchedLogged[$diagnosticKey] = true;
                echo '[' . date('H:i:s') . '] ' . $label . ' 流水未匹配订单：流水=' . $thirdId . ' 金额=' . number_format($amount, 2, '.', '') . ' 支付方式=' . ($payType ?: '未知') . '，候选订单数=' . count($candidates) . "\n";
            }
            continue;
        }
        foreach ($candidates as $candidate) {
            $fresh = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE A.trade_no=:trade_no LIMIT 1", [':trade_no' => $candidate['trade_no']]);
            if (!$fresh || (int)$fresh['status'] !== 0) continue;
            $paidAt = transactionTime($item);
            $candidate['plugin'] = 'shouqianba'; \lib\Task\PaymentMonitor::notify($candidate, $thirdId, null, null, null, date('Y-m-d H:i:s', $paidAt));
            $processed = (int)$DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1", [':trade_no' => $fresh['trade_no']]);
            echo '[' . date('H:i:s') . '] ' . $label . ' 订单 ' . $fresh['trade_no'] . ($processed === 1 ? ' 支付成功' : ' 回调处理未确认') . "\n";
            break;
        }
    }
}


function flowAfterOrder($item, $order)
{
    $paidAt = transactionTime($item);
    $created = !empty($order['addtime']) ? strtotime($order['addtime']) : 0;
    return $paidAt > 0 && $created > 0 && $paidAt >= $created && $paidAt <= $created + shouqianba_plugin::MATCH_SECONDS;
}


function transactionTime($item)
{
    $value = transactionValue($item, ['finish_time', 'finishTime', 'channel_finish_time', 'channelFinishTime', 'paid_at', 'paidAt', 'pay_time', 'payment_time', 'transaction_time', 'transactionTime', 'trade_time', 'updated_at', 'update_time', 'mtime', 'create_time', 'ctime', 'timestamp']);
    if (is_numeric($value)) {
        $time = (int)$value;
        return $time > 20000000000 ? (int)floor($time / 1000) : $time;
    }
    return $value !== '' ? (int)strtotime((string)$value) : 0;
}


function transactionTerminalNo($item)
{
    return transactionValue($item, ['terminal_sn', 'terminal_no', 'terminalNo', 'terminal_number', 'terminalNumber', 'sn']);
}


function transactionClientSn($item)
{
    return transactionValue($item, ['terminal_client_sn', 'terminalClientSn', 'client_sn', 'clientSn']);
}


function transactionMatchesTerminal($item, $terminalNo, $clientSn)
{
    return $terminalNo !== '' && $clientSn !== ''
        && transactionTerminalNo($item) === (string)$terminalNo
        && transactionClientSn($item) === (string)$clientSn;
}


function transactionValue($item, $keys)
{
    foreach ($keys as $key) {
        if (is_array($item) && isset($item[$key]) && $item[$key] !== '') return trim((string)$item[$key]);
    }
    foreach (['transaction', 'terminal', 'order'] as $container) {
        if (!empty($item[$container]) && is_array($item[$container])) {
            foreach ($keys as $key) if (isset($item[$container][$key]) && $item[$container][$key] !== '') return trim((string)$item[$container][$key]);
        }
    }
    return '';
}

/**
 * 按字段语义解析流水金额。收钱吧历史接口常见整数“分”字段，部分接口/代理
 * 会返回带小数的“元”字段；不根据金额大小猜单位，避免 0.10 被再次除以 100。
 */

function transactionAmount($item)
{
    $yuanKeys = ['amount_yuan', 'paid_amount_yuan', 'original_amount_yuan', 'transaction_amount_yuan', 'total_amount_yuan', 'amountYuan', 'paidAmountYuan', 'transactionAmountYuan'];
    foreach ([$item, $item['transaction'] ?? null, $item['order'] ?? null] as $row) {
        if (!is_array($row)) continue;
        foreach ($yuanKeys as $key) {
            if (isset($row[$key]) && $row[$key] !== '' && is_numeric($row[$key])) return round((float)$row[$key], 2);
        }
    }

    $centKeys = ['original_amount', 'paid_amount', 'transaction_amount', 'total_amount', 'amount', 'originalAmount', 'paidAmount', 'transactionAmount', 'totalAmount'];
    foreach ([$item, $item['transaction'] ?? null, $item['order'] ?? null] as $row) {
        if (!is_array($row)) continue;
        foreach ($centKeys as $key) {
            if (!isset($row[$key]) || $row[$key] === '' || !is_numeric($row[$key])) continue;
            $raw = trim((string)$row[$key]);
            // 带小数的金额按元处理；整数金额字段按收钱吧惯例按分处理。
            return round(strpos($raw, '.') !== false ? (float)$raw : ((float)$raw / 100), 2);
        }
    }
    return 0.0;
}


function transactionPayType($item, $payways)
{
    $code = transactionValue($item, ['payway', 'payWay', 'payment_way', 'paymentWay']);
    if ($code !== '' && isset($payways[(int)$code])) return $payways[(int)$code];
    $value = strtolower(transactionValue($item, ['payment_type', 'paymentType', 'pay_type', 'payType', 'payment_method', 'paymentMethod']));
    if (strpos($value, 'alipay') !== false || strpos($value, '支付宝') !== false) return 'alipay';
    if (strpos($value, 'wxpay') !== false || strpos($value, 'wechat') !== false || strpos($value, '微信') !== false) return 'wxpay';
    return '';
}


function transactionIsPaid($item)
{
    $values = [];
    foreach (['status', 'state', 'pay_state', 'payState', 'trade_state', 'tradeState', 'payment_status', 'paymentStatus', 'transaction_status', 'transactionStatus', 'order_status', 'orderStatus'] as $key) {
        $value = transactionValue($item, [$key]);
        if ($value !== '') $values[] = strtolower(trim($value));
    }
    if (!$values) return false;
    foreach ($values as $value) {
        if (is_numeric($value) && (int)$value === 2000) return true;
        if (in_array($value, ['success', 'paid', 'pay_success', 'trade_success', 'payment_success', 'succeed', '已支付', '支付成功'], true)) return true;
    }
    return false;
}


function getSqbConfig($channel)
{
    $out = []; foreach (['username', 'token', 'store_sn', 'terminal_sn', 'terminal_device_fingerprint', 'merchant_info'] as $key) $out[$key] = trim((string)($channel[$key] ?? '')); return $out;
}


function flowConsumed($id)
{
    global $DB; return (bool)$DB->getColumn("SELECT trade_no FROM pre_order WHERE api_trade_no=:id AND status=1 LIMIT 1", [':id' => $id]);
}
