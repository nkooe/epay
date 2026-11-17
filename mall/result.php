<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/view.php';
$checkout = mallSession(true, true);
$order = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade', [':trade' => $checkout['trade_no']]);
$order = $order ?: ['status' => 0];
$paid = (bool) $checkout['paid_confirmed'];
$refunded = $paid && (int)$checkout['refund_cents'] > 0;

$returnUrl = '';
if ($paid && (int) $order['status'] !== 2) {
    $callback = creat_callback($order);
    if (filter_var($callback['return'], FILTER_VALIDATE_URL) && in_array(parse_url($callback['return'], PHP_URL_SCHEME), ['http', 'https'], true)) {
        $returnUrl = $callback['return'];
    }
}

$complete = $paid;
$title = $refunded ? '订单已退款' : ($paid ? '支付成功' : '正在确认付款');
$icon = $refunded ? 'refund' : ($paid ? 'check' : 'clock');
$description = $refunded ? '退款已受理，请留意原付款账户的到账情况。'
    : ($paid ? '付款已完成，感谢你的购买。'
        : '正在查询付款状态，完成付款后将自动更新。');
mallPageStart($title);
?>
<section class="card flow-card" aria-live="polite">
    <div class="status-art <?= $refunded ? 'neutral' : ($paid ? '' : 'pending') ?>"><?= mallIcon($icon) ?></div>
    <p class="flow-kicker"><?= $refunded ? '退款结果' : ($paid ? '付款完成' : '付款结果') ?></p>
    <h1><?= mallEscape($title) ?></h1>
    <p class="subtitle"><?= mallEscape($description) ?></p>
    <p class="amount">¥<?= mallEscape(\lib\bridge\Money::decimal((int) $checkout['amount_cents'])) ?></p>
    <dl class="result-order"><div class="row"><dt>支付订单号</dt><dd><?= mallEscape($checkout['trade_no']) ?></dd></div><div class="row"><dt>当前状态</dt><dd><?= $refunded ? '已退款' : ($complete ? '已完成' : '等待确认') ?></dd></div></dl>
    <?php if ($returnUrl) : ?>
        <a class="button" href="<?= mallEscape($returnUrl) ?>">返回商户 <?= mallIcon('arrow') ?></a>
        <p class="result-info">即将自动返回，请稍候</p>
        <script>setTimeout(() => location.replace(<?= json_encode($returnUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>), 2400);</script>
    <?php elseif (!$complete) : ?>
        <button type="button" class="button secondary" onclick="location.reload()"><?= mallIcon('refresh') ?>刷新付款结果</button>
        <p class="result-info">如已扣款，请勿重复支付</p>
        <script>setTimeout(() => location.reload(), 4000);</script>
    <?php else : ?>
        <p class="result-info"><?= $refunded ? '具体到账时间以付款平台为准' : '本次付款已完成，可关闭此页面' ?></p>
    <?php endif; ?>
</section>
<?php mallPageEnd(); ?>
