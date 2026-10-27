<?php if (!defined('IN_CRONLITE') || $islogin != 1) exit; ?>
<link rel="stylesheet" href="/assets/css/merchant-policy.css">
<main id="merchant-policy" class="mp-page" data-uid="<?=htmlspecialchars($uid, ENT_QUOTES, 'UTF-8')?>" data-csrf="<?=htmlspecialchars($_SESSION['merchant_policy_csrf'], ENT_QUOTES, 'UTF-8')?>">
  <header class="mp-heading"><p id="mp-merchant">正在加载…</p><a class="btn btn-default" href="./uset.php?my=edit&amp;uid=<?=(int)$uid?>">返回资料</a></header>
  <p id="mp-error" class="mp-error" role="alert" hidden></p>
  <p id="mp-status" role="status" aria-live="polite"></p>
  <button class="btn btn-default" type="button" id="mp-retry" hidden>重新加载</button>
  <form id="mp-form" hidden>
    <section class="mp-panel">
      <label class="mp-mode-label" for="mp-mode">选路模式</label>
      <select id="mp-mode"><option value="group">跟随用户组</option><option value="custom">独立配置</option></select>
      <p class="mp-hint" id="mp-mode-hint"></p>
    </section>
    <div id="mp-rules"></div>
    <footer class="mp-footer"><button class="btn btn-primary" type="submit" id="mp-save">保存策略</button><button class="btn btn-default" type="button" id="mp-reload">重新读取</button></footer>
  </form>
</main>
<script src="/assets/js/merchant-policy.js"></script>
