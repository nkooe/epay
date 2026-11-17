<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = \lib\MallCheckout::config();
$origin = parse_url($config['mall_url']);
$allowedOrigin = $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
header('Vary: Origin');
if (($_SERVER['HTTP_ORIGIN'] ?? '') === $allowedOrigin) header('Access-Control-Allow-Origin: ' . $allowedOrigin);

$checkout = mallSession(true);
$order = $DB->getRow('SELECT status FROM pre_order WHERE trade_no=:trade', [':trade' => $checkout['trade_no']]);
$status = (int) ($order['status'] ?? 0);
$paid = (bool) $checkout['paid_confirmed'];
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['paid' => $paid, 'synced' => (bool) $checkout['synced'],
    'canceled' => !$paid && (bool) $checkout['canceled'],
    'refunded' => $paid && (int)$checkout['refund_cents'] > 0,
    'expired' => (int) $checkout['expires_at'] <= time(),
    'result_url' => $config['gateway_url'] . '/mall/result.php?t=' . $checkout['token']], JSON_THROW_ON_ERROR);
