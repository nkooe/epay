<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$checkout = mallSession();
if (!$checkout['ready']) {
    http_response_code(409);
    exit('请先在商品收银台确认订单');
}
try {
    $checkout = \lib\MallLifecycle::start($checkout['token']);
} catch (Throwable $error) {
    http_response_code(409);
    exit(mallEscape($error->getMessage()));
}
$order = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade' => $checkout['trade_no']]);
if ((int) $order['status'] !== 0) {
    header('Location: result.php?t=' . $checkout['token'], true, 303);
    exit;
}
// Native submit2.php retains channel selection, merchant fees and upstream plugins.
$_GET['trade_no'] = $checkout['trade_no'];
$_GET['typeid'] = (string) $checkout['type_id'];
unset($_GET['retry']);
chdir(dirname(__DIR__));
require ROOT . 'submit2.php';
