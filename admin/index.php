<?php
include("../includes/common.php");
$title='支付管理中心';
$admin_dashboard=true;
include './head.php';
if($islogin!=1) exit("<script>window.location.href='./login.php';</script>");
$msg=[];
if($conf['admin_pwd']==='123456'){
    $msg[]='请及时修改网站默认管理员密码！';
}elseif(strlen($conf['admin_pwd'])<6 || is_numeric($conf['admin_pwd']) && strlen($conf['admin_pwd'])<=10 || $conf['admin_pwd']===$conf['kfqq'] || $conf['admin_user']===$conf['admin_pwd']){
    $msg[]='网站管理员密码过于简单，请及时修改密码！';
}
?>
<main class="container admin-dashboard" data-today="<?php echo date('Y-m-d'); ?>">
  <?php foreach($msg as $notice){ ?>
  <div class="alert alert-danger" role="alert"><i class="fa fa-info-circle" aria-hidden="true"></i> <?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?> <a href="./set.php?mod=account">修改密码 <i class="fa fa-angle-right" aria-hidden="true"></i></a></div>
  <?php } ?>
  <div id="dashboard-error" class="alert alert-danger" role="alert" hidden></div>
  <div class="dashboard-overview" aria-busy="true" id="dashboard-overview">
    <section class="dashboard-summary panel">
      <div class="summary-label"><span class="summary-icon"><i class="fa fa-rmb" aria-hidden="true"></i></span> 今日成交金额（元）<a href="./order.php">订单记录 <i class="fa fa-angle-right" aria-hidden="true"></i></a></div>
      <div class="summary-amount" id="today-amount">—</div>
      <div class="summary-comparison" id="amount-comparison">正在加载今日成交数据</div>
      <div class="summary-details"><div><span>今日订单成功率</span><strong><span id="success_rate">—</span><small> %</small></strong></div><div><span>今日手续费利润（元）</span><strong id="today-profit">—</strong></div></div>
    </section>
    <section class="dashboard-metrics panel" aria-label="平台累计数据">
      <a class="dashboard-metric" href="./order.php"><span><i class="fa fa-file-text-o" aria-hidden="true"></i> 订单总数</span><strong id="count1">—</strong><small>全部收款订单 <i class="fa fa-angle-right" aria-hidden="true"></i></small></a>
      <a class="dashboard-metric" href="./ulist.php"><span><i class="fa fa-user-o" aria-hidden="true"></i> 商户数量</span><strong id="count2">—</strong><small>平台商户 <i class="fa fa-angle-right" aria-hidden="true"></i></small></a>
      <div class="dashboard-metric"><span><i class="fa fa-credit-card" aria-hidden="true"></i> 商户总余额（元）</span><strong id="usermoney">—</strong><small>每小时更新</small></div>
      <div class="dashboard-metric"><span><i class="fa fa-check-square-o" aria-hidden="true"></i> 累计结算（元）</span><strong id="settlemoney">—</strong><small>每小时更新</small></div>
    </section>
  </div>
  <div class="dashboard-content">
    <section class="panel dashboard-trend">
      <div class="panel-heading dashboard-section-heading"><h2 class="panel-title">成交趋势</h2><span class="section-note">最近 7 天 · 元</span></div>
      <div class="panel-body">
        <div class="trend-state" id="trend-state" role="status">正在加载成交趋势…</div>
        <div class="trend-scroll" id="trend-scroll" hidden><div class="trend-canvas"><canvas id="transaction-trend" role="img" aria-label="每日成交金额趋势，下方明细可查看具体数值"></canvas></div></div>
        <div class="trend-footer"><span><i class="trend-dot" aria-hidden="true"></i> 实付成交金额 · 按支付日期</span><span id="dashboard-updated" aria-live="polite">等待更新</span></div>
      </div>
    </section>
    <aside class="panel dashboard-shortcuts">
      <div class="panel-heading"><h2 class="panel-title">常用功能</h2></div>
      <div class="shortcut-list">
        <a href="./order.php"><span class="shortcut-icon"><i class="fa fa-list-alt" aria-hidden="true"></i></span><span>订单管理<small>查询与处理收款订单</small></span><i class="fa fa-angle-right" aria-hidden="true"></i></a>
        <a href="./ulist.php"><span class="shortcut-icon"><i class="fa fa-user-o" aria-hidden="true"></i></span><span>商户管理<small>商户资料与账户管理</small></span><i class="fa fa-angle-right" aria-hidden="true"></i></a>
        <a href="./pay_channel.php"><span class="shortcut-icon"><i class="fa fa-credit-card" aria-hidden="true"></i></span><span>支付通道<small>通道配置与运行状态</small></span><i class="fa fa-angle-right" aria-hidden="true"></i></a>
        <a href="./set.php?mod=site"><span class="shortcut-icon"><i class="fa fa-sliders" aria-hidden="true"></i></span><span>系统设置<small>平台配置与消息通知</small></span><i class="fa fa-angle-right" aria-hidden="true"></i></a>
      </div>
    </aside>
  </div>
  <section class="panel dashboard-statistics">
    <div class="panel-heading dashboard-section-heading"><h2 class="panel-title">收入与利润明细</h2><span class="section-note">与成交趋势同步，刷新查看最新数据</span></div>
    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation" class="active"><a href="#stats-payment" aria-controls="stats-payment" role="tab" data-toggle="tab">支付方式收入</a></li>
      <li role="presentation"><a href="#stats-channel" aria-controls="stats-channel" role="tab" data-toggle="tab">支付通道收入</a></li>
      <li role="presentation"><a href="#stats-profit" aria-controls="stats-profit" role="tab" data-toggle="tab">手续费利润</a></li>
    </ul>
    <div class="tab-content">
      <div role="tabpanel" class="tab-pane active" id="stats-payment"><div class="table-responsive"><table class="table table-hover"><caption class="sr-only">每日各支付方式实付成交金额，单位元</caption><thead><tr id="paytype_head"><th scope="col">日期</th></tr></thead><tbody id="paytype_list"></tbody></table></div></div>
      <div role="tabpanel" class="tab-pane" id="stats-channel"><div class="table-responsive"><table class="table table-hover"><caption class="sr-only">每日各通道实付成交金额，单位元</caption><thead><tr id="channel_head"><th scope="col">日期</th></tr></thead><tbody id="channel_list"></tbody></table></div></div>
      <div role="tabpanel" class="tab-pane" id="stats-profit"><div class="table-responsive"><table class="table table-hover"><caption class="sr-only">每日各支付方式手续费利润，已扣除通道成本，单位元</caption><thead><tr id="profit_paytype_head"><th scope="col">日期</th></tr></thead><tbody id="profit_paytype_list"></tbody></table></div></div>
    </div>
  </section>
  <p class="dashboard-footnote">按支付日期统计已支付与冻结订单的实付金额 · 北京时间</p>
</main>
<script src="../assets/js/chart.js"></script>
<script src="../assets/js/admin-dashboard.js?v=1.0.1"></script>
</body>
</html>
