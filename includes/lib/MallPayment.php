<?php

declare(strict_types=1);

namespace lib;

use RuntimeException;

final class MallPayment
{
    public static function cors(): void
    {
        $url = parse_url(MallCheckout::config()['mall_url']);
        $origin = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        header('Vary: Origin');
        header('Content-Type: application/json; charset=utf-8');
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $gateway = rtrim(MallCheckout::config()['gateway_url'], '/');
        if (!in_array($requestOrigin, [$origin, $gateway], true)) {
            self::error('支付请求来源无效', 403);
        }
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST, OPTIONS');
            self::error('仅允许 POST 请求', 405);
        }
    }

    public static function respond(array $result): void
    {
        $payload = self::payload($result);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'data' => $payload], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /** Replace only the intermediate order's shared WeChat page, keeping plugin payment data. */
    public static function wechatCheckout(array $result): ?array
    {
        global $order;
        if (($result['type'] ?? '') !== 'page' || ($result['page'] ?? '') !== 'wxpay_jspay') return null;
        $checkout = MallCheckout::mapping((string) ($order['trade_no'] ?? ''));
        if (!$checkout || !$checkout['ready'] || $checkout['canceled']) return null;
        $config = MallCheckout::config();
        return [
            'query_url' => rtrim($config['gateway_url'], '/') . '/mall/query.php?t=' . $checkout['token'],
            'result_url' => rtrim($config['gateway_url'], '/') . '/mall/result.php?t=' . $checkout['token'],
            'checkout_url' => MallCashier::url($checkout + ['trade_no' => (string) $order['trade_no']]),
        ];
    }

    public static function usesNative(array $channel, array $order): bool
    {
        $types = $channel['apptype'];
        $hasJsapi = checkwechat() && in_array('2', $types, true) && ($channel['appwxmp'] > 0 || $channel['appwxa'] > 0);
        $hasH5 = !checkwechat() && checkmobile() && in_array('3', $types, true);
        return $order['typename'] === 'wxpay' && in_array($channel['plugin'], ['wxpay', 'wxpayn'], true)
            && in_array('1', $types, true) && !$hasJsapi && !$hasH5;
    }

    public static function native(string $tradeNo, callable $create): array
    {
        $data = Payment::lockPayData($tradeNo, static function () use ($create): array {
            $result = self::payload($create());
            if ($result['type'] !== 'qrcode' || !self::isNativeUrl($result['url'])) {
                throw new RuntimeException('Native 通道未返回真实微信付款二维码');
            }
            return ['qrcode', $result['url']];
        });
        if (($data[0] ?? '') !== 'qrcode' || !self::isNativeUrl((string) ($data[1] ?? ''))) {
            throw new RuntimeException('订单已有的付款信息与 Native 二维码不一致，请重新发起订单');
        }
        return ['type' => 'qrcode', 'url' => $data[1]];
    }

    private static function isNativeUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'weixin' && parse_url($url, PHP_URL_HOST) === 'wxpay'
            && parse_url($url, PHP_URL_PATH) === '/bizpayurl' && (string) parse_url($url, PHP_URL_QUERY) !== '';
    }

    public static function payload(array $result): array
    {
        if (($result['type'] ?? '') === 'error') {
            throw new RuntimeException(strip_tags((string) ($result['msg'] ?? '支付发起失败')));
        }
        global $order;
        if (($result['type'] ?? '') === 'jsapi' && ($order['typename'] ?? '') === 'wxpay') {
            return ['type' => 'wechat_jsapi', 'parameters' => self::wechatParameters($result['data'] ?? null)];
        }
        if (($result['type'] ?? '') === 'page' && ($result['page'] ?? '') === 'wxpay_jspay') {
            return ['type' => 'wechat_jsapi', 'parameters' => self::wechatParameters($result['data']['jsApiParameters'] ?? null)];
        }
        if (in_array($result['type'] ?? '', ['html', 'page', 'jsapi', 'app', 'scan', 'wxplugin', 'wxapp', 'return'], true)) {
            return self::continuation();
        }
        if (!in_array($result['type'] ?? '', ['jump', 'scheme', 'qrcode'], true)) {
            throw new RuntimeException('当前通道未返回付款二维码或支付链接');
        }
        $url = (string) ($result['url'] ?? '');
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $url)) {
            throw new RuntimeException('通道返回的支付内容无效');
        }
        if (substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//') {
            $url = rtrim(MallCheckout::config()['gateway_url'], '/') . $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (
            in_array($scheme, ['javascript', 'data', 'vbscript', 'file', 'blob', 'about'], true)
            || ($result['type'] !== 'qrcode' && $scheme === '')
        ) {
            throw new RuntimeException('通道返回的支付链接无效');
        }
        return ['type' => $result['type'], 'url' => $url];
    }

    public static function wechatParameters($input): array
    {
        $parameters = is_string($input) ? json_decode($input, true, 16, JSON_THROW_ON_ERROR) : $input;
        $clean = [];
        foreach (['appId', 'timeStamp', 'nonceStr', 'package', 'signType', 'paySign'] as $field) {
            if (!is_array($parameters) || !isset($parameters[$field])
                || (!is_string($parameters[$field]) && !is_int($parameters[$field]))
                || (string) $parameters[$field] === '' || strlen((string) $parameters[$field]) > 4096) {
                throw new RuntimeException('微信支付参数不完整，请在收银台重试');
            }
            $clean[$field] = (string) $parameters[$field];
        }
        if (!preg_match('/^[0-9]+$/D', $clean['timeStamp']) || !in_array($clean['signType'], ['RSA', 'MD5', 'HMAC-SHA256'], true)
            || strpos($clean['package'], 'prepay_id=') !== 0) {
            throw new RuntimeException('微信支付参数格式无效');
        }
        return $clean;
    }

    private static function continuation(): array
    {
        global $order;
        $checkout = MallCheckout::mapping((string) ($order['trade_no'] ?? ''));
        if (!$checkout || !$checkout['ready'] || $checkout['canceled']) {
            throw new RuntimeException('请先在商品收银台确认订单');
        }
        // Browser pages and SDK flows stay with the gateway's original submit chain.
        return ['type' => 'jump', 'url' => rtrim(MallCheckout::config()['gateway_url'], '/')
            . '/mall/continue.php?t=' . $checkout['token']];
    }

    public static function error(string $message, int $status = 400): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
