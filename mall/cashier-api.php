<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $config = \lib\MallCheckout::config();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['gateway_url']) {
        throw new RuntimeException('收银台请求来源无效');
    }
    $body = file_get_contents('php://input', false, null, 0, 2049);
    if (strlen($body) > 2048) {
        throw new RuntimeException('收银台请求过大');
    }
    $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    $session = \lib\MallCheckout::token((string) ($input['token'] ?? ''));
    $data = \lib\MallCashier::request($session, (string) ($input['action'] ?? ''), (int) ($input['type_id'] ?? 0));
    echo json_encode(['code' => 0, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    \lib\MallPayment::error(get_class($error) === RuntimeException::class ? $error->getMessage() : '收银台请求失败，请稍后重试');
}
