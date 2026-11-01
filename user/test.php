<?php
$is_defend=true;
include("../includes/common.php");
if(!$conf['test_open'])sysmsg("未开启测试支付");
if(isset($_GET['ok']) && isset($_GET['trade_no'])){
	$trade_no=daddslashes($_GET['trade_no']);
	$row=$DB->getRow("SELECT * FROM pre_order WHERE trade_no='{$trade_no}' AND uid='{$conf['test_pay_uid']}' limit 1");
	if(!$row)sysmsg('订单号不存在');
	if($row['status']!=1)sysmsg('订单未完成支付');
	$money = $row['money'];
}else{
	$trade_no=date("YmdHis").rand(111,999);
	$userrow = $DB->getRow("SELECT uid,gid FROM pre_user WHERE uid='{$conf['test_pay_uid']}' limit 1");
	if(!$userrow)sysmsg("测试支付商户不存在");
	$paytype = \lib\Channel::getTypes($userrow['uid'], $userrow['gid']);
	$csrf_token = md5(mt_rand(0,999).time());
	$_SESSION['csrf_token'] = $csrf_token;
	$money = 1;
}
?>
<?php
$testPaid = isset($_GET['ok'], $_GET['trade_no']);
$testEscape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$testTypes = array_values($paytype ?? []);
$testCaptcha = !$testPaid && $conf['captcha_open_test'] == 1;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <title>测试支付 · <?php echo $testEscape($conf['sitename']); ?></title>
    <link rel="stylesheet" href="/user/assets/css/test-pay.css?v=2">
</head>
<body class="test-pay-page">
<main class="test-pay-shell<?php echo $testPaid ? ' test-pay-shell--result' : ''; ?>">
    <header class="test-pay-topbar">
        <a class="test-pay-brand" href="/"><span class="test-pay-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 9V7a4 4 0 0 1 8 0v2M9 13l2 2 4-4"/></svg></span><span><?php echo $testEscape($conf['sitename']); ?></span></a>
        <nav aria-label="平台入口"><a href="/doc/index.html">开发文档</a><a href="/">返回首页</a></nav>
    </header>
    <section class="test-pay-card<?php echo !$testPaid ? ' test-pay-card--payment' : ''; ?>" aria-labelledby="test-pay-title">
        <?php if ($testPaid): ?>
        <div class="test-pay-result">
            <span class="test-pay-success-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
            <h1 id="test-pay-title">支付成功</h1>
            <p class="test-pay-result-amount"><span>¥</span><?php echo $testEscape(number_format((float)$money, 2, '.', '')); ?></p>
        </div>
        <dl class="test-pay-facts">
            <div><dt>商品名称</dt><dd><?php echo $testEscape($row['name'] ?? '支付测试'); ?></dd></div>
            <div><dt>订单号</dt><dd><?php echo $testEscape($trade_no); ?></dd></div>
        </dl>
        <a class="test-pay-submit" href="test.php">继续测试</a>
        <?php else: ?>
        <form class="test-pay-form" name="alipayment" id="testPaymentForm" action="ajax.php?act=testpay" method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $testEscape($csrf_token); ?>">
            <div class="test-pay-summary">
                <label class="test-pay-label" for="testMoney">付款金额</label>
                <div class="test-pay-amount"><span aria-hidden="true">¥</span><input id="testMoney" name="money" type="text" inputmode="decimal" autocomplete="off" value="<?php echo $testEscape(number_format((float)$money, 2, '.', '')); ?>" placeholder="0.00" aria-describedby="testAmountHint testAmountError" required></div>
                <p class="test-pay-field-error" id="testAmountError" role="alert" hidden></p>
                <p class="test-pay-product">支付测试</p>
                <p class="test-pay-amount-hint" id="testAmountHint">点击金额可修改</p>
                <details class="test-pay-details" open>
                    <summary>订单信息<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></summary>
                    <dl class="test-pay-facts">
                        <div><dt>商品名称</dt><dd>支付测试</dd></div>
                        <div><dt>参考订单号</dt><dd><?php echo $testEscape($trade_no); ?></dd></div>
                    </dl>
                </details>
            </div>
            <div class="test-pay-selection">
            <header class="test-pay-heading"><h1 id="test-pay-title">选择支付方式</h1></header>
            <fieldset class="test-pay-methods">
                <legend class="test-pay-sr-only">支付方式</legend>
                <?php foreach ($testTypes as $index => $method): ?>
                <label class="test-pay-method">
                    <input type="radio" name="typeid" value="<?php echo (int)$method['id']; ?>"<?php echo $index === 0 ? ' checked' : ''; ?> required>
                    <img src="/assets/icon/<?php echo rawurlencode((string)$method['name']); ?>.ico" alt="" width="28" height="28">
                    <span><?php echo $testEscape($method['showname']); ?></span>
                    <span class="test-pay-radio" aria-hidden="true"></span>
                </label>
                <?php endforeach; ?>
                <?php if (!$testTypes): ?><p class="test-pay-empty" role="status">暂无可用支付方式，请稍后再试。</p><?php endif; ?>
            </fieldset>
            <?php if ($testCaptcha && $testTypes): ?>
            <div class="test-pay-captcha" aria-label="安全验证">
                <div id="captcha"><p id="captcha_text" role="status">正在加载验证码…</p></div>
                <div id="captchaform"></div>
                <button type="button" class="test-pay-retry" id="testCaptchaRetry" hidden>重新加载验证码</button>
            </div>
            <?php endif; ?>
            <p class="test-pay-message" id="testPaymentMessage" role="alert" hidden></p>
            <button class="test-pay-submit" id="testPaymentSubmit" type="submit" disabled>确认支付 ¥<?php echo $testEscape(number_format((float)$money, 2, '.', '')); ?></button>
            <p class="test-pay-notice">测试支付会产生真实交易，请核对金额后付款。</p>
            <noscript><p class="test-pay-message">请启用 JavaScript 后使用测试支付。</p></noscript>
            </div>
        </form>
        <?php endif; ?>
    </section>
    <footer class="test-pay-footer"><?php echo $testEscape($conf['sitename']); ?> · 支付服务 <span>© <?php echo date('Y'); ?></span></footer>
</main>
<?php if (!$testPaid): ?>
<script src="<?php echo $testEscape($cdnpublic); ?>jquery/3.4.1/jquery.min.js"></script>
<?php if ($testCaptcha && $testTypes): ?>
<script src="//static.geetest.com/static/tools/gt.js"></script>
<script>
window.appendChildOrg = Element.prototype.appendChild;
Element.prototype.appendChild = function () {
    if (arguments[0] && arguments[0].tagName === 'SCRIPT') arguments[0].setAttribute('referrerpolicy', 'no-referrer');
    return window.appendChildOrg.apply(this, arguments);
};
</script>
<script src="//static.geetest.com/v4/gt4.js"></script>
<?php endif; ?>
<script src="/user/assets/js/test-pay.js?v=2" defer></script>
<?php endif; ?>
</body>
</html>
