<?php
if (!defined('IN_CRONLITE')) exit();
$checkoutEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$checkoutName = !empty($sitename) ? html_entity_decode((string)$sitename, ENT_QUOTES, 'UTF-8') : ($conf['sitename'] ?? '收银台');
$checkoutAmount = !empty($row['realmoney']) ? $row['realmoney'] : $row['money'];
$checkoutFee = round((float)$checkoutAmount - (float)$row['money'], 2);
$checkoutTypes = array_values($paytype ?: []);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <title>收银台 · <?php echo $checkoutEscape($checkoutName); ?></title>
    <link rel="stylesheet" href="/assets/css/checkout.css?v=2">
    <script src="/assets/js/cashier.js?v=1" defer></script>
</head>
<body class="checkout-page">
<main class="checkout-shell">
    <header class="checkout-header">
        <span class="checkout-brand"><span class="checkout-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 9V7a4 4 0 0 1 8 0v2M9 13l2 2 4-4"/></svg></span><span><?php echo $checkoutEscape($checkoutName); ?></span></span>
        <span class="checkout-header-label">收银台</span>
    </header>
    <?php if ($other): ?>
    <p class="checkout-notice" role="status">当前支付方式暂不可用，请选择其他方式继续付款。</p>
    <?php endif; ?>
    <section class="checkout-card checkout-payment">
        <div class="checkout-summary">
            <p class="checkout-eyebrow">待支付金额</p>
            <h1 class="checkout-amount"><span>¥</span><?php echo $checkoutEscape(number_format((float)$checkoutAmount, 2, '.', '')); ?></h1>
            <p class="checkout-product"><?php echo $checkoutEscape($row['name']); ?></p>
            <?php if ($checkoutFee > 0): ?><p class="checkout-muted checkout-fee">含手续费 ¥<?php echo $checkoutEscape(number_format($checkoutFee, 2, '.', '')); ?></p><?php endif; ?>
            <details class="checkout-details" open>
                <summary>订单信息<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></summary>
                <dl class="checkout-facts">
                    <div><dt>订单号</dt><dd><?php echo $checkoutEscape($trade_no); ?></dd></div>
                    <div><dt>创建时间</dt><dd><?php echo $checkoutEscape($row['addtime']); ?></dd></div>
                    <div><dt>商品金额</dt><dd>¥<?php echo $checkoutEscape(number_format((float)$row['money'], 2, '.', '')); ?></dd></div>
                </dl>
            </details>
        </div>
        <form class="checkout-methods" id="checkoutForm" action="./submit2.php" method="get">
            <input type="hidden" name="trade_no" value="<?php echo $checkoutEscape($trade_no); ?>">
            <fieldset>
                <legend>选择支付方式</legend>
                <?php foreach ($checkoutTypes as $index => $method): ?>
                <label class="checkout-method">
                    <input type="radio" name="typeid" value="<?php echo (int)$method['id']; ?>"<?php echo $index === 0 ? ' checked' : ''; ?> required>
                    <span class="checkout-method-icon" aria-hidden="true"><img src="/assets/icon/<?php echo rawurlencode((string)$method['name']); ?>.ico" alt="" width="28" height="28"></span>
                    <span class="checkout-method-name"><?php echo $checkoutEscape($method['showname']); ?></span>
                    <span class="checkout-radio" aria-hidden="true"></span>
                </label>
                <?php endforeach; ?>
                <?php if (!$checkoutTypes): ?><p class="checkout-empty" role="status">暂无可用的支付方式<br><span>请联系商户或稍后重新尝试。</span></p><?php endif; ?>
            </fieldset>
            <button class="checkout-button" type="submit" id="checkoutSubmit"<?php echo !$checkoutTypes ? ' disabled' : ''; ?>>确认支付 <span>¥<?php echo $checkoutEscape(number_format((float)$checkoutAmount, 2, '.', '')); ?></span></button>
            <p class="checkout-hint">请核对收款方及金额后付款</p>
        </form>
    </section>
    <footer class="checkout-footer"><?php echo $checkoutEscape($conf['sitename'] ?? $checkoutName); ?> · 收款服务</footer>
</main>
</body>
</html>
