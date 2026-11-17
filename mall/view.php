<?php

declare(strict_types=1);

function mallIcon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . mallEscape($class) . '" aria-hidden="true"><use href="icons.svg#'
        . mallEscape($name) . '"></use></svg>';
}

function mallPageStart(string $title): void
{
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <base href="<?= mallEscape(rtrim(\lib\MallCheckout::config()['gateway_url'], '/') . '/mall/') ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#f5f6f3">
    <title><?= mallEscape($title) ?> · shop</title>
    <link rel="icon" href="brand.png" type="image/png">
    <link rel="stylesheet" href="style.css?v=cashier-20261116">
</head>
<body>
<main class="page flow-page">
    <div class="topbar"><div class="brand"><img src="brand.png" alt="" width="38" height="38"><span class="brand-word">shop</span><span class="brand-divider" aria-hidden="true"></span><span class="brand-caption">收银台</span></div><span class="top-note"><?= mallIcon('lock') ?>自助付款</span></div>
    <div class="flow-body">
    <?php
}

function mallPageEnd(): void
{
    ?>
    </div>
    <footer class="flow-bottom">shop · 便捷付款</footer>
</main>
</body>
</html>
    <?php
}

function mallUnavailable(string $message): void
{
    mallPageStart('暂时无法继续');
    ?>
    <section class="card flow-card">
        <div class="status-art neutral"><?= mallIcon('alert') ?></div>
        <h1>暂时无法继续</h1>
        <p class="subtitle"><?= mallEscape($message) ?></p>
        <p class="result-info">请返回发起付款的页面，重新发起。</p>
        <button class="button secondary" type="button" onclick="location.reload()">重新加载</button>
    </section>
    <?php
    mallPageEnd();
}

