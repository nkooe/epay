<?php
if (!defined('IN_CRONLITE')) exit();

function epay_docs_catalog()
{
    return [
        '开始接入' => ['index' => '接口说明', 'sign_note' => '签名规则', 'paytype' => '支付方式列表'],
        '支付接口' => ['pay_submit' => '页面跳转支付', 'pay_create' => '统一下单接口', 'pay_query' => '订单查询', 'pay_notify' => '支付结果通知', 'pay_refund' => '订单退款', 'pay_refundquery' => '订单退款查询', 'pay_close' => '关闭订单'],
        '商户接口' => ['merchant_info' => '查询商户信息', 'merchant_orders' => '查询订单列表'],
        '代付接口' => ['transfer_submit' => '转账发起', 'transfer_query' => '转账查询', 'transfer_balance' => '可用余额查询'],
        '接入资源' => ['sdk' => 'SDK 下载', 'legacy' => 'V1 旧版文档'],
    ];
}

function epay_docs_link($page)
{
    return $page === 'legacy' ? '/doc_old.html' : '/doc/'.$page.'.html';
}

function epay_docs_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function epay_docs_header($page)
{
    global $conf;
    $title = '开发文档';
    $category = '开始接入';
    foreach (epay_docs_catalog() as $group => $items) {
        if (isset($items[$page])) { $title = $items[$page]; $category = $group; break; }
    }
    $site = epay_docs_escape($conf['sitename']);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo epay_docs_escape($title)?> · 开发文档 - <?php echo $site?></title>
  <link rel="stylesheet" href="/assets/doc/css/wechat.css?v=1">
  <script src="/assets/doc/js/wechat.js?v=1" defer></script>
</head>
<body class="docs-site">
  <a class="docs-skip" href="#article-content">跳到文档正文</a>
  <header class="docs-topbar">
    <button class="docs-icon-button" id="docs-menu-toggle" type="button" aria-label="展开文档目录" aria-controls="docs-sidebar" aria-expanded="false">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <a href="/doc/index.html" class="docs-brand"><span class="docs-brand-mark" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m8 7-5 5 5 5m8-10 5 5-5 5M14 4l-4 16"/></svg></span><span><?php echo $site?></span></a>
    <span class="docs-brand-label">开发文档</span>
    <nav class="docs-top-links" aria-label="平台入口"><a href="/">平台首页</a><a class="docs-merchant-link" href="/user/">商户中心 <span aria-hidden="true">↗</span></a></nav>
  </header>

  <aside class="docs-sidebar" id="docs-sidebar" aria-label="开发文档目录">
    <div class="docs-search"><label class="docs-sr-only" for="docs-search">搜索文档目录</label><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg><input id="docs-search" type="search" placeholder="搜索接口或签名…" autocomplete="off"><kbd>/</kbd></div>
    <nav id="docs-directory" aria-label="接口分类">
      <?php foreach (epay_docs_catalog() as $group => $items) { ?>
      <details class="docs-nav-group"<?php echo ($group === $category || $group === '开始接入' || $group === '接入资源') ? ' open' : ''?>>
        <summary><?php echo $group?><span aria-hidden="true">›</span></summary>
        <ul>
          <?php foreach ($items as $key => $label) { ?>
          <li><a href="<?php echo epay_docs_link($key)?>"<?php echo $key === $page ? ' aria-current="page"' : ''?> data-doc-key="<?php echo $key?>"><?php echo $label?><?php if ($key === 'legacy') { ?><span class="docs-nav-badge">MD5</span><?php } ?></a></li>
          <?php } ?>
        </ul>
      </details>
      <?php } ?>
    </nav>
    <p class="docs-search-empty" id="docs-search-empty" role="status" hidden>没有匹配的文档，换个关键词试试</p>
    <div class="docs-sidebar-footer"><span class="docs-status-dot" aria-hidden="true"></span>接口参考 <span><?php echo $page === 'legacy' ? 'V1 · MD5' : 'V2 · RSA'?></span></div>
  </aside>
  <button class="docs-backdrop" id="docs-backdrop" type="button" aria-label="关闭文档目录" hidden></button>

  <div class="docs-workspace">
    <main class="docs-main" id="docs-main">
      <div class="docs-page-meta"><span>开发文档 <span aria-hidden="true">/</span> <?php echo $category?></span><span class="docs-version"><?php echo $page === 'legacy' ? 'V1 · MD5' : 'V2 · RSA'?></span></div>
      <details class="docs-mobile-toc" id="docs-mobile-toc"><summary>本页目录</summary><nav id="docs-mobile-toc-links" aria-label="本页目录（移动端）"></nav></details>
      <article class="docs-article" id="article-content" tabindex="-1">
<?php
}

function epay_docs_footer($page)
{
    $pages = [];
    foreach (epay_docs_catalog() as $items) $pages = array_merge($pages, $items);
    $keys = array_keys($pages);
    $index = array_search($page, $keys, true);
?>
      </article>
      <nav class="docs-pagination" aria-label="相邻文档">
        <?php if ($index !== false && $index > 0) { $previous = $keys[$index - 1]; ?>
        <a href="<?php echo epay_docs_link($previous)?>"><small>← 上一篇</small><span><?php echo $pages[$previous]?></span></a>
        <?php } else { ?><span></span><?php } ?>
        <?php if ($index !== false && isset($keys[$index + 1])) { $next = $keys[$index + 1]; ?>
        <a class="docs-next" href="<?php echo epay_docs_link($next)?>"><small>下一篇 →</small><span><?php echo $pages[$next]?></span></a>
        <?php } ?>
      </nav>
      <footer class="docs-footer"><?php global $conf; echo epay_docs_escape($conf['sitename'])?> · 开发文档 <span>© <?php echo date('Y')?></span></footer>
    </main>
    <aside class="docs-toc" aria-label="本页目录"><h2>本页目录</h2><nav id="docs-toc-links"></nav><a class="docs-back-top" href="#article-content">返回顶部 ↑</a></aside>
  </div>
  <div class="docs-toast" id="docs-toast" role="status" aria-live="polite" hidden></div>
  <dialog class="docs-download-dialog" id="docs-download-dialog" aria-labelledby="docs-download-title">
    <form method="dialog"><h2 id="docs-download-title">确认下载</h2><p>是否下载 <strong id="docs-download-name"></strong>？</p><div><button value="cancel" class="docs-button" autofocus>取消</button><button value="download" class="docs-button docs-button-primary">下载</button></div></form>
  </dialog>
</body>
</html>
<?php
}
