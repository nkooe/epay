<?php

declare(strict_types=1);

/**
 * 商城调用的服务端桥接入口，只接收经过双向签名和防重放校验的 POST 请求。
 * 配置：两端后台“商城桥接配置 / 系统设置 → 易支付接入”，连接参数保存在各自数据库。
 * app_id、独立 secret、两站域名须一致；merchant_ids 为授权的易支付 PID 整数数组。
 * 商城后台可配置多个易支付；每组独立启停、密钥、商户名单和订单空间。
 * reserve：商品收银台展示时关联两端订单；ready：确认支付方式后允许调用原支付通道。
 * 本接口不接收浏览器伪造的“已付款”状态，不负责通道回调或实际收款。
 * 原通道回调由易支付处理，再通过商城 /api/bridge/paid 同步状态及累计销量。
 * 现有主进程下的商城同步子进程负责失败补同步；商城自身无需为中转运行队列或定时器。
 */
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$verified = false;
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$config = \lib\MallCheckout::config();
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('仅允许 POST 请求');
    }
    $input = fopen('php://input', 'rb');
    $body = stream_get_contents($input, \lib\bridge\Protocol::MAX_BODY + 1);
    fclose($input);
    if (strlen($body) > \lib\bridge\Protocol::MAX_BODY) {
        throw new RuntimeException('请求体过大');
    }
    \lib\bridge\Protocol::verify(
        '/mall/api.php',
        $headers,
        $body,
        $config,
        static function (string $nonce, int $expiry) use ($DB): bool {
            $DB->exec('DELETE FROM pre_mall_nonce WHERE expires_at<:now LIMIT 100', [':now' => time()]);
            return $DB->exec(
                'INSERT IGNORE INTO pre_mall_nonce (nonce_hash,expires_at) VALUES (:nonce,:expires)',
                [':nonce' => $nonce, ':expires' => $expiry]
            ) === 1;
        }
    );
    $verified = true;
    $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data) || substr(ltrim($body), 0, 1) !== '{') {
        throw new RuntimeException('桥接请求结构无效');
    }
    switch ($data['action'] ?? '') {
        case 'reserve':
            $result = \lib\MallCheckout::reserve($data);
            break;
        case 'ready':
            $result = \lib\MallCheckout::ready($data);
            break;
        default:
            throw new RuntimeException('未知桥接操作');
    }
    $status = 200;
    $response = json_encode(['code' => 0, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status = 400;
    $message = get_class($error) === RuntimeException::class ? $error->getMessage() : '桥接暂时不可用，请稍后重试';
    $response = json_encode(['code' => -1, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
http_response_code($status);
if ($verified) {
    foreach (\lib\bridge\Protocol::responseHeaders('/mall/api.php', $headers['x-bridge-nonce'], $status, $response, $config) as $name => $value) {
        header($name . ': ' . $value);
    }
}
echo $response;
