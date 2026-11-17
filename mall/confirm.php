<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/view.php';
$checkout = mallSession(false, true);
try {
    $checkout = \lib\MallCheckout::confirm($checkout['token']);
    $url = \lib\MallCashier::url($checkout);
    header('Location: ' . $url, true, 303);
} catch (Throwable $error) {
    http_response_code(400);
    mallUnavailable($error->getMessage());
}
exit;
