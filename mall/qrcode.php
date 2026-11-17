<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/view.php';
$checkout = mallSession(false, true);
$entry = rtrim(\lib\MallCheckout::config()['gateway_url'], '/') . '/mall/entry.php?t=' . $checkout['token'];
if (checkwechat()) {
    header('Location: ' . $entry, true, 303);
    exit;
}
mallPageStart('扫码付款');
?>
<section class="card flow-card">
    <p class="flow-kicker">扫码付款</p>
    <h1>扫码或直接继续</h1>
    <p class="subtitle">扫码后在手机上确认并完成付款</p>
    <p class="amount">¥<?= mallEscape(\lib\bridge\Money::decimal((int) $checkout['amount_cents'])) ?></p>
    <div class="qr-shell">
        <div class="qr" id="qr" aria-label="扫描此二维码进入收银台"></div>
        <div class="qr-expired" id="qr-expired" hidden><?= mallIcon('clock') ?><span>二维码已过期</span></div>
    </div>
    <p class="scan-guide"><img src="wxpay.svg" alt="" width="22" height="22"><span id="status" role="status">正在生成二维码</span></p>
    <p class="timer-badge"><?= mallIcon('clock') ?><span id="countdown" role="timer"></span></p>
    <p class="qr-caption">扫码后请核对付款信息，确认后支付</p>
    <a class="button" href="<?= mallEscape($entry) ?>">在当前浏览器继续 <?= mallIcon('arrow') ?></a>
</section>
<p class="page-note"><?= mallIcon('lock') ?>付款后，本页会自动更新</p>
<noscript><p class="error">请启用 JavaScript 后重新打开二维码。</p></noscript>
<script src="../assets/vendor/jquery/3.4.1/jquery.min.js"></script>
<script src="../assets/vendor/jquery.qrcode/1.0/jquery.qrcode.min.js"></script>
<script>
const checkout=<?= json_encode(['entry' => $entry, 'token' => $checkout['token'], 'expires' => (int) $checkout['expires_at']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
if (window.jQuery && jQuery.fn.qrcode) {
    jQuery('#qr').qrcode({width:220, height:220, text:checkout.entry, background:'#ffffff', foreground:'#182721'});
    document.querySelector('#status').textContent = '使用手机扫码进入收银台';
} else {
    document.querySelector('#status').textContent = '二维码加载失败，请刷新页面';
}
let stopped = false;
let pollFailures = 0;
let pollTimer;
function tick() {
    const seconds = Math.max(0, checkout.expires - Math.floor(Date.now() / 1000));
    document.querySelector('#countdown').textContent = seconds
        ? '请在 ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') + ' 内扫码'
        : '请返回发起页面，重新获取二维码';
    document.querySelector('#qr-expired').hidden = seconds > 0;
    document.querySelector('#qr').classList.toggle('expired', !seconds);
}
tick();
setInterval(tick, 1000);
async function poll() {
    clearTimeout(pollTimer);
    if (stopped || document.hidden) return;
    try {
        const response = await fetch('query.php?t=' + encodeURIComponent(checkout.token), {cache:'no-store', signal:AbortSignal.timeout(15000)});
        if (!response.ok) throw new Error('Status request failed');
        const data = await response.json();
        pollFailures = 0;
        if (data.paid) { stopped = true; location.replace(data.result_url); return; }
        document.querySelector('#status').textContent = data.expired ? '如已付款，请等待结果更新' : '使用手机扫码进入收银台';
    } catch (error) {
        pollFailures++;
        document.querySelector('#status').textContent = '连接暂时中断，正在重试…';
    }
    const delay = pollFailures ? Math.min(30000, 2500 * 2 ** Math.min(pollFailures, 4)) : 3500;
    pollTimer = setTimeout(poll, delay + Math.floor(Math.random() * 1000));
}
document.addEventListener('visibilitychange', () => {
    clearTimeout(pollTimer);
    if (!document.hidden) poll();
});
poll();
</script>
<?php mallPageEnd(); ?>
