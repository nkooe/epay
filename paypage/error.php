<?php
if (!defined('IN_CRONLITE')) exit();
$checkoutEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>付款提示</title>
    <link rel="stylesheet" href="/assets/css/checkout.css?v=1">
</head>
<body class="checkout-page">
<main class="checkout-shell checkout-result">
    <section class="checkout-card">
        <div class="checkout-result-icon is-error" aria-hidden="true"><svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="20"/><path d="M24 13v13m0 7v1"/></svg></div>
        <h1>暂时无法付款</h1>
        <?php /* Existing error messages may contain the merchant-binding link. */ ?>
        <div class="checkout-result-message" role="alert"><?php echo $msg; ?></div>
        <button type="button" class="checkout-button" id="Close">关闭页面</button>
    </section>
</main>
<script src="<?php echo $checkoutEscape($cdnpublic); ?>jquery/1.12.4/jquery.min.js"></script>
<script src="/paypage/js/close.js"></script>
</body>
</html>
