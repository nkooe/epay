<?php
if (!defined('IN_CRONLITE')) exit();

$siteName = trim((string)($conf['sitename'] ?? '')); 
$siteName = $siteName !== '' ? $siteName : '支付平台';
$title = trim((string)($conf['title'] ?? ''));
$keywords = trim((string)($conf['keywords'] ?? ''));
$description = trim((string)($conf['description'] ?? ''));
$safe = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$contactUrl = !empty($conf['kfqq'])
    ? 'https://wpa.qq.com/msgrd?v=3&uin=' . rawurlencode((string)$conf['kfqq']) . '&site=pay&menu=yes'
    : '/user/';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="<?php echo $safe($description !== '' ? $description : $siteName . '提供稳定、安全、易接入的支付服务。'); ?>">
    <meta name="keywords" content="<?php echo $safe($keywords); ?>">
    <title><?php echo $safe($title !== '' ? $title : $siteName); ?></title>
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="<?php echo STATIC_ROOT; ?>css/style.css">
</head>
<body>
<header class="site-header" data-header>
    <div class="shell nav-wrap">
        <a class="brand" href="/" aria-label="<?php echo $safe($siteName); ?>首页">
            <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="brand-name"><?php echo $safe($siteName); ?></span>
        </a>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="main-nav" data-nav-toggle>
            <span></span><span></span><span></span><b class="sr-only">打开导航</b>
        </button>
        <nav class="main-nav" id="main-nav" data-nav>
            <a class="active" href="/">首页</a>
            <?php if (!empty($conf['test_open'])) { ?><a href="/user/test.php">支付测试</a><?php } ?>
            <a href="/doc.html">开发文档</a>
            <a href="<?php echo $safe($contactUrl); ?>" target="_blank" rel="noopener">联系我们</a>
            <a class="nav-login" href="/user/">商户登录</a>
        </nav>
    </div>
</header>

<main>
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero-grid" aria-hidden="true"></div>
        <div class="hero-ring ring-one" aria-hidden="true"></div>
        <div class="hero-ring ring-two" aria-hidden="true"></div>
        <div class="shell hero-layout">
            <div class="hero-copy">
                <div class="eyebrow"><span class="eyebrow-star">*</span> 企业级支付平台</div>
                <h1 id="hero-title">专业可靠的<br><strong>码支付解决方案</strong></h1>
                <p class="hero-lead">为企业和个人开发者提供安全、高效、便捷的支付接入服务<br class="desktop-only">快速集成，稳定回调，持续为业务提供可靠支撑。</p>
                <div class="hero-points" aria-label="平台特点">
                    <span><i class="point-icon shield" aria-hidden="true">✓</i>安全稳定</span>
                    <span><i class="point-icon clock" aria-hidden="true">◷</i>快速到账</span>
                    <span><i class="point-icon headset" aria-hidden="true">◌</i>专业服务</span>
                </div>
                <div class="hero-actions">
                    <a class="button button-primary" href="<?php echo !empty($conf['reg_open']) ? '/user/reg.php' : '/user/'; ?>"><span aria-hidden="true">→</span> 立即接入</a>
                    <a class="text-link" href="#features">了解更多 <span aria-hidden="true">↓</span></a>
                </div>
            </div>
            <div class="hero-art" aria-label="支付控制台示意图">
                <div class="dashboard">
                    <div class="dashboard-topbar"><span class="window-dot dot-red"></span><span class="window-dot dot-yellow"></span><span class="window-dot dot-green"></span><span class="dashboard-title">码支付控制台</span><span class="dashboard-lock">▣</span></div>
                    <div class="dashboard-body">
                        <div class="stats-row">
                            <div class="stat-card"><span class="stat-value">¥2,847,392</span><span class="stat-label">今日交易额</span><span class="stat-up">+12.5%</span></div>
                            <div class="stat-card"><span class="stat-value">15,847</span><span class="stat-label">交易笔数</span><span class="stat-up">+8.3%</span></div>
                        </div>
                        <div class="chart-panel"><div class="chart-label">近七日交易趋势 <span>实时更新</span></div><div class="chart-bars"><i style="height:38%"></i><i style="height:56%"></i><i style="height:46%"></i><i style="height:73%"></i><i style="height:61%"></i><i style="height:86%"></i><i style="height:67%"></i><i style="height:94%"></i></div><div class="chart-axis"><span>周一</span><span>周三</span><span>周五</span><span>今天</span></div></div>
                        <div class="dashboard-footer"><span><b class="status-dot"></b> 支付服务正常</span><span>多渠道在线</span></div>
                    </div>
                </div>
                <span class="float-card float-phone" aria-hidden="true">▣</span><span class="float-card float-pay" aria-hidden="true">¥</span><span class="float-card float-safe" aria-hidden="true">✓</span>
            </div>
        </div>
    </section>

    <section class="features" id="features" aria-labelledby="features-title">
        <div class="shell">
            <div class="section-heading"><span class="section-kicker">PAYMENT PLATFORM</span><h2 id="features-title">为业务而生的支付能力</h2><p>从接入到回调，提供清晰、稳定、易维护的支付基础设施。</p></div>
            <div class="feature-grid">
                <article class="feature-card"><div class="feature-icon blue">↗</div><h3>多渠道接入</h3><p>灵活组合微信、支付宝及更多支付通道，统一管理订单。</p><a href="/doc.html">查看接入文档 <span aria-hidden="true">→</span></a></article>
                <article class="feature-card"><div class="feature-icon green">✓</div><h3>稳定回调</h3><p>订单状态清晰可追踪，支付完成后及时通知你的业务系统。</p><a href="/doc.html">了解回调规则 <span aria-hidden="true">→</span></a></article>
                <article class="feature-card"><div class="feature-icon orange">▣</div><h3>安全可靠</h3><p>细粒度的商户权限、签名校验和订单校验，守住每一笔交易。</p><a href="/user/">进入商户中心 <span aria-hidden="true">→</span></a></article>
            </div>
        </div>
    </section>

    <section class="workflow">
        <div class="shell workflow-layout"><div><span class="section-kicker">SIMPLE WORKFLOW</span><h2>几步完成支付接入</h2><p>注册商户、配置通道、接入接口，清晰的流程让上线更快。</p></div><div class="workflow-steps"><div><b>01</b><span>注册商户</span></div><div><b>02</b><span>配置通道</span></div><div><b>03</b><span>接入接口</span></div><div><b>04</b><span>开始收款</span></div></div></div>
    </section>

    <section class="closing-cta"><div class="shell closing-inner"><div><span class="section-kicker">READY TO START</span><h2>现在开始使用 <?php echo $safe($siteName); ?></h2><p>把更多时间留给业务，把支付交给稳定的基础设施。</p></div><div class="closing-actions"><a class="button button-primary" href="<?php echo !empty($conf['reg_open']) ? '/user/reg.php' : '/user/'; ?>">创建商户账号</a><a class="button button-outline" href="/user/">登录商户中心</a></div></div></section>
</main>

<footer class="site-footer"><div class="shell footer-layout"><div><a class="brand footer-brand" href="/"><span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span><span class="brand-name"><?php echo $safe($siteName); ?></span></a><p class="footer-desc">安全、高效、便捷的支付接入服务。</p></div><div class="footer-links"><div><b>产品服务</b><a href="/user/test.php">支付测试</a><a href="/doc.html">开发文档</a><a href="/user/">商户中心</a></div><div><b>联系我们</b><a href="<?php echo $safe($contactUrl); ?>" target="_blank" rel="noopener">客服支持</a><?php if (!empty($conf['email'])) { ?><a href="mailto:<?php echo $safe($conf['email']); ?>"><?php echo $safe($conf['email']); ?></a><?php } ?></div></div></div><div class="shell footer-bottom"><span>© <?php echo date('Y'); ?> <?php echo $safe($siteName); ?>. All rights reserved.</span><span><?php echo $conf['footer'] ?? ''; ?></span></div></footer>

<script>
(function () {
    var header = document.querySelector('[data-header]');
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-nav]');
    window.addEventListener('scroll', function () { if (header) header.classList.toggle('is-scrolled', window.scrollY > 12); }, { passive: true });
    if (toggle && nav) toggle.addEventListener('click', function () { var open = nav.classList.toggle('is-open'); toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    if (nav) nav.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', function () { nav.classList.remove('is-open'); if (toggle) toggle.setAttribute('aria-expanded', 'false'); }); });
}());
</script>
</body>
</html>
