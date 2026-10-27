<?php
if (!defined('IN_CRONLITE')) exit();
$checkoutEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <title>支付成功</title>
    <link rel="stylesheet" href="/assets/css/checkout.css?v=1">
</head>
<body class="checkout-page">
<main class="checkout-shell checkout-result">
    <section class="checkout-card">
        <div class="checkout-result-icon" aria-hidden="true"><svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="20"/><path d="m14 24 7 7 14-14"/></svg></div>
        <h1>支付成功</h1>
        <p class="checkout-amount"><span>¥</span><?php echo $checkoutEscape(number_format((float)$row['money'], 2, '.', '')); ?></p>
        <dl class="checkout-facts">
            <div><dt>收款方</dt><dd><?php echo $checkoutEscape($codename); ?></dd></div>
            <div><dt>完成时间</dt><dd><?php echo $checkoutEscape($row['endtime']); ?></dd></div>
            <div><dt>订单号</dt><dd><?php echo $checkoutEscape($trade_no); ?></dd></div>
        </dl>
        <button type="button" class="checkout-button" id="Close">完成</button>
    </section>
    <footer class="checkout-footer"><?php echo $checkoutEscape($conf['sitename']); ?> · 收款服务</footer>
</main>
<script src="<?php echo $checkoutEscape($cdnpublic); ?>jquery/1.12.4/jquery.min.js"></script>
<script src="//open.mobile.qq.com/sdk/qqapi.js?_bid=152"></script>
<script src="/paypage/js/close.js"></script>
</body>
</html>
