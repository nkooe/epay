<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/view.php';
$checkout = mallSession(false, true);
$url = 'confirm.php?t=' . $checkout['token'];
mallPageStart('确认付款');
?>
<section class="card flow-card entry-card">
    <div class="status-art"><?= mallIcon('phone') ?></div>
    <h1>继续确认付款</h1>
    <p class="subtitle">核对商品和金额后，选择支付方式完成付款</p>
    <a class="button" href="<?= mallEscape($url) ?>">进入收银台 <?= mallIcon('arrow') ?></a>
</section>
<?php mallPageEnd(); ?>
