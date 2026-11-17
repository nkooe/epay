<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

\lib\MallPayment::cors();
try {
    $checkout = \lib\MallCheckout::token((string) ($_GET['t'] ?? ''));
    \lib\MallPaymentGuard::acquire($checkout);
    $body = file_get_contents('php://input', false, null, 0, 1025);
    if (strlen($body) > 1024) {
        throw new RuntimeException('支付请求过大');
    }
    $input = $body === '' ? [] : json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    $typeId = $input['type_id'] ?? (int) $checkout['type_id'];
    if (!is_int($typeId) || $typeId <= 0) {
        throw new RuntimeException('请选择支付方式');
    }
    $typeName = $DB->getColumn('SELECT name FROM pre_type WHERE id=:id', [':id' => $typeId]);
    if ($checkout['canceled']) {
        throw new RuntimeException('请先在商品收银台确认订单');
    }
    $order = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade' => $checkout['trade_no']]);
    if (!$order) {
        throw new RuntimeException('支付订单不存在');
    }
    if ((int) $order['status'] !== 0) {
        echo json_encode(['code' => 0, 'data' => ['type' => 'jump', 'url' => \lib\MallCheckout::config()['gateway_url']
            . '/mall/result.php?t=' . $checkout['token']]], JSON_THROW_ON_ERROR);
        exit;
    }
    // Every attempt revalidates the live mall order; no cached quote may authorize payment.
    \lib\MallCashier::request($checkout, 'order', $typeId);
    $checkout = \lib\MallCheckout::token($checkout['token']);
    if (!$checkout['ready']) {
        throw new RuntimeException('请先在商品收银台确认订单');
    }
    \lib\MallLifecycle::start($checkout['token']);
    global $device, $mdevice, $method;
    $device = checkmobile() ? 'mobile' : 'pc';
    $mdevice = checkwechat() ? 'wechat' : (checkalipay() ? 'alipay' : '');
    $method = '';
    $mallPaymentApi = true;
    $_GET['trade_no'] = $checkout['trade_no'];
    $_GET['typeid'] = (string) $checkout['type_id'];
    unset($_GET['retry']);
    chdir(dirname(__DIR__));
    require ROOT . 'submit2.php';
} catch (Throwable $error) {
    \lib\MallPayment::error($error->getMessage());
}
