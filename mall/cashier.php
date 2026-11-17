<?php

declare(strict_types=1);

// Entered through /pay/jspay/{trade}/?cashier={token}, on the original JSAPI payment directory.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/view.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
try {
    $session = \lib\MallCheckout::token((string) ($_GET['cashier'] ?? ''));
    if (
        !preg_match('~^jspay/([0-9]{10,64})/?$~D', $s, $match) || $session['trade_no'] !== $match[1]
        || $session['canceled']
    ) {
        throw new RuntimeException('收银台会话与订单不一致');
    }
    $quote = null;
    // Refresh/OAuth return only reads the existing mirror; first display creates both local orders.
    if (!$quote || empty($session['mall_order_id'])) {
        $quote = \lib\MallCashier::request($session, empty($session['mall_order_id']) ? 'reserve' : 'quote');
    }
    if (!$quote['paid'] && !$quote['refunded'] && checkwechat()) {
        $application = \lib\MallCashier::application($session, $quote);
        if ($application !== null) {
            \lib\MallCashier::authorize($session, $application);
        }
    }
    $config = \lib\MallCheckout::config();
    $base = htmlspecialchars(rtrim($config['gateway_url'], '/') . '/mall/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $context = json_encode(
        ['token' => $session['token'], 'canonical_url' => \lib\MallCashier::url($session),
        'api_url' => rtrim($config['gateway_url'], '/') . '/mall/cashier-api.php',
        'payment_url' => rtrim($config['gateway_url'], '/') . '/mall/pay.php?t=' . $session['token'],
        'mall_url' => $config['mall_url'], 'quote' => $quote],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    $html = file_get_contents(ROOT . 'includes/pages/mall_checkout.html');
    $image = (string) ($quote['product']['image'] ?? '');
    if (substr($image, 0, 1) === '/' && substr($image, 0, 2) !== '//') {
        $image = rtrim($config['mall_url'], '/') . $image;
    }
    $preload = preg_match('~^https?://~i', $image)
        ? '<link rel="preload" as="image" href="' . htmlspecialchars($image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" fetchpriority="high">' : '';
    $html = str_replace('<head>', '<head><base href="' . $base . '">' . $preload . '<script id="cashier-context" type="application/json">' . $context . '</script>', $html);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
} catch (Throwable $error) {
    http_response_code(400);
    mallUnavailable(get_class($error) === RuntimeException::class ? $error->getMessage() : '收银台暂时无法打开，请重新扫码');
}
