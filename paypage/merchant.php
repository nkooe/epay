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
    <meta name="referrer" content="same-origin">
    <title>向商户付款 · <?php echo $checkoutEscape($codename); ?></title>
    <link rel="stylesheet" href="/assets/css/checkout.css?v=1">
</head>
<body class="checkout-page merchant-page">
<main class="checkout-shell merchant-shell">
    <header class="checkout-header"><span class="checkout-brand"><?php echo $checkoutEscape($conf['sitename']); ?></span><span class="checkout-header-label">商户收款</span></header>
    <section class="checkout-card merchant-card">
        <header class="merchant-heading">
            <span class="merchant-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 9h16v11H4V9Zm-1 0 2-5h14l2 5M8 20v-6h5v6M3 9a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2"/></svg></span>
            <div><p>付款给</p><h1><?php echo $checkoutEscape($codename); ?></h1></div>
        </header>
        <form name="payForm" action="dopay" method="post" onsubmit="return false;">
            <input type="hidden" name="uid" id="uid" value="<?php echo (int)$uid; ?>">
            <input type="hidden" name="token" id="token" value="<?php echo $checkoutEscape($csrf_token); ?>">
            <input type="hidden" name="paytype" id="paytype" value="<?php echo $checkoutEscape($type); ?>">
            <input type="hidden" name="direct" id="direct" value="<?php echo $checkoutEscape($direct); ?>">
            <input type="hidden" name="payer" id="payer" value="<?php echo $checkoutEscape($openId ?? ''); ?>">
            <input type="hidden" name="trade_no" id="trade_no" value="">
            <?php if ($money): ?><input type="hidden" name="txAmount" id="txAmount" value="<?php echo $checkoutEscape($money); ?>"><?php endif; ?>
            <div class="merchant-amount-section">
                <div class="merchant-amount-label" id="amountLabel">付款金额</div>
                <div class="merchant-amount-line">
                    <span class="merchant-currency" aria-hidden="true">¥</span>
                    <output id="amount" aria-labelledby="amountLabel" aria-live="polite"><?php if ($money) echo $checkoutEscape(number_format((float)$money, 2, '.', '')); ?></output>
                    <button type="button" class="merchant-clear<?php echo $money ? ' none' : ''; ?>" id="clearBtn" aria-label="清空金额"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg></button>
                </div>
            </div>
            <div class="merchant-remark">
                <span class="merchant-remark-copy">备注<span id="remark-content"></span></span>
                <div class="merchant-remark-actions remark_operate">
                    <a href="#myModal" class="remark_add" id="openModal">添加备注</a>
                    <a href="#myModal" class="remark_edit">编辑</a>
                    <a href="#" class="remark_clear_away">清除</a>
                </div>
            </div>
        </form>
        <div class="merchant-keyboard" id="keyboard" role="group" aria-label="金额数字键盘"<?php echo $money ? ' hidden' : ''; ?>>
            <?php foreach ([1,2,3,4,5,6,7,8,9] as $digit): ?><button type="button" class="key" data-value="<?php echo $digit; ?>"><?php echo $digit; ?></button><?php endforeach; ?>
            <button type="button" class="key" data-value="dot" aria-label="小数点">.</button>
            <button type="button" class="key" data-value="0">0</button>
            <button type="button" class="key key-delete" data-value="delete" aria-label="删除上一位"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5h12v14H9l-7-7 7-7Z"/><path d="m12 9 6 6m0-6-6 6"/></svg></button>
        </div>
        <?php if ($money): ?><p class="merchant-fixed">订单已指定付款金额，请核对收款方后继续。</p><?php endif; ?>
        <button type="button" class="checkout-button merchant-pay<?php echo !$money ? ' disable' : ''; ?>" id="payBtn" aria-disabled="<?php echo !$money ? 'true' : 'false'; ?>">确认支付</button>
        <p class="merchant-status">请确认收款商户及金额</p>
    </section>
    <footer class="checkout-footer">由 <?php echo $checkoutEscape($conf['sitename']); ?> 提供收款服务</footer>
</main>
<div class="merchant-modal" id="myModal" hidden>
    <section class="merchant-dialog" role="dialog" aria-modal="true" aria-labelledby="remarkTitle">
        <header class="merchant-dialog-header"><h2 id="remarkTitle">添加备注</h2><button type="button" id="modal-close" aria-label="取消编辑备注">×</button></header>
        <div id="remark-form">
            <label class="checkout-eyebrow" for="remarkInput">备注内容</label>
            <textarea name="remark" id="remarkInput" placeholder="最多 30 个字" maxlength="30" rows="3"></textarea>
            <div class="merchant-dialog-actions"><span class="merchant-dialog-count" id="remarkCount" aria-live="polite">0 / 30</span><button type="button" class="checkout-button" id="remarkSave">确定</button></div>
        </div>
    </section>
</div>
<script src="<?php echo $checkoutEscape($cdnpublic); ?>jquery/3.4.1/jquery.min.js"></script>
<script src="//open.mobile.qq.com/sdk/qqapi.js?_bid=152"></script>
<script src="js/hammer.js"></script>
<script src="js/common.js?v=2"></script>
<script>var tips = new Tips();</script>
<script src="js/merchant-ui.js?v=1"></script>
<script src="js/pay.js?v=1006"></script>
</body>
</html>
