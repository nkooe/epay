<?php
$is_defend=true;
include("../includes/common.php");

if(isset($_GET['invite'])){
    $invite_code = trim($_GET['invite']);
    $uid = get_invite_uid($invite_code);
    if($uid && is_numeric($uid)){
        $_SESSION['invite_uid'] = intval($uid);
    }
}

if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

if(!$conf['reg_input_settle'] && (empty($userrow['account']) || empty($userrow['username']))){
	exit("<script language='javascript'>window.location.href='./completeinfo.php';</script>");
}

if($userrow['status']==0){
	$status = '<font color="red">已封禁</font>';
}elseif($userrow['pay']==0 && $userrow['settle']==0){
	$status = '<font color="red">关闭支付、结算</font>';
}elseif($userrow['pay']==0){
	$status = '<font color="red">关闭支付</font>';
}elseif($userrow['settle']==0){
	$status = '<font color="red">关闭结算</font>';
}elseif($conf['cert_force']==1 && $userrow['cert']==0){
	$status = '<a href="certificate.php"><font color="red">未实名认证</font></a>';
}elseif($userrow['pay']==2){
	$status = '<font color="orange">待审核</font>';
}else{
	$status = '<font color="green">正常</font>';
}
$title='商户总览';
include './head.php';
?>
<?php
$rs=$DB->query("SELECT * FROM pre_settle WHERE uid={$uid} AND status=1 ORDER BY id DESC LIMIT 9");
$settle_labels=[];
$settle_values=[];
while($row = $rs->fetch())
{
	array_unshift($settle_labels, date('m-d', strtotime($row['addtime'])));
	array_unshift($settle_values, (float)$row['money']);
}

$list = $DB->getAll("SELECT * FROM pre_anounce WHERE status=1 ORDER BY sort ASC");
?>
 <div id="content" class="app-content" role="main">
    <div class="app-content-body ">
		<div class="modal inmodal fade" id="myModal" tabindex="-1" role="dialog" aria-hidden="true">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">关闭</span>
						</button>
						<h4 class="modal-title">欢迎回来</h4>
					</div>
					<div class="modal-body">
<?php echo $conf['modal']?>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-white" data-dismiss="modal">关闭</button>
					</div>
				</div>
			</div>
		</div>

<div class="bg-light lter b-b wrapper-md hidden-print">
  <h1 class="m-n font-thin h3">商户总览</h1>
  <small class="text-muted">欢迎使用<?php echo $conf['sitename']?></small>
</div>
<div class="wrapper-md control merchant-overview">
<!-- stats -->
<?php
if($conf['cert_force']==1 && $userrow['cert']==0){
	echo '<div class="alert alert-danger"><span class="btn-sm btn-danger">重要</span>&nbsp;请完成实名认证，否则您的商户无法正常收款！ <a href="./certificate.php" class="btn btn-default btn-xs">立即实名认证</a></div>';
}
if($conf['verifytype']==1 && empty($userrow['phone'])){
	echo '<div class="alert alert-warning"><span class="btn-sm btn-warning">提示</span>&nbsp;您还没有绑定密保手机，请&nbsp;<a href="editinfo.php" class="btn btn-default btn-xs">尽快绑定</a></div>';
}elseif($conf['verifytype']==0 && empty($userrow['email'])){
	echo '<div class="alert alert-warning"><span class="btn-sm btn-warning">提示</span>&nbsp;您还没有绑定密保邮箱，请&nbsp;<a href="editinfo.php" class="btn btn-default btn-xs">尽快绑定</a></div>';
}
if(empty($userrow['pwd'])){
	echo '<div class="alert alert-warning"><span class="btn-sm btn-warning">提示</span>&nbsp;您还没有设置登录密码，请&nbsp;<a href="userinfo.php?mod=account" class="btn btn-default btn-xs">点此设置</a>，设置登录密码之后你就可以使用手机号/邮箱+密码登录</div>';
}
?>

<div class="merchant-metrics" aria-label="商户数据概览">
  <section class="merchant-metric is-balance">
    <div class="merchant-metric-label">可用余额<i class="fa fa-credit-card" aria-hidden="true"></i></div>
    <div class="merchant-metric-value"><small>¥</small><?php echo number_format((float)$userrow['money'], 2)?></div>
  </section>
  <section class="merchant-metric">
    <div class="merchant-metric-label">已结算金额<i class="fa fa-check-circle-o" aria-hidden="true"></i></div>
    <div class="merchant-metric-value"><small>¥</small><span id="settle_money">—</span></div>
  </section>
  <section class="merchant-metric">
    <div class="merchant-metric-label">累计订单<i class="fa fa-bar-chart" aria-hidden="true"></i></div>
    <div class="merchant-metric-value"><span id="orders">—</span><small>笔</small></div>
  </section>
  <section class="merchant-metric">
    <div class="merchant-metric-label">今日订单<i class="fa fa-file-text-o" aria-hidden="true"></i></div>
    <div class="merchant-metric-value"><span id="orders_today">—</span><small>笔</small></div>
  </section>
</div>
<div class="merchant-overview-layout">
  <div class="merchant-overview-main">
    <section class="panel merchant-chart merchant-overview-chart" aria-labelledby="overview-trend-title">
      <div class="panel-heading merchant-section-heading">
        <h2 id="overview-trend-title">收款趋势</h2>
        <span class="merchant-section-note" id="merchant-chart-period">近 7 天</span>
      </div>
      <ul class="nav nav-tabs" role="tablist" aria-label="趋势统计类型">
        <li role="presentation" class="active"><a id="order-tab-link" href="#order-tab" aria-controls="order-tab" aria-selected="true" role="tab" data-toggle="tab">订单金额</a></li>
        <li role="presentation"><a id="settle-tab-link" href="#settle-tab" aria-controls="settle-tab" aria-selected="false" role="tab" data-toggle="tab">结算金额</a></li>
      </ul>
      <div class="tab-content">
        <div role="tabpanel" class="tab-pane active" id="order-tab" aria-labelledby="order-tab-link">
          <div id="order-chart-container" class="merchant-trend-canvas"><div class="merchant-chart-status" role="status">统计数据加载中…</div></div>
        </div>
        <div role="tabpanel" class="tab-pane" id="settle-tab" aria-labelledby="settle-tab-link">
          <div id="settle-chart-container" class="merchant-trend-canvas"><div class="merchant-chart-status" role="status">结算统计加载中…</div></div>
        </div>
      </div>
    </section>

    <section class="panel merchant-channel-panel" aria-labelledby="overview-channel-title">
      <div class="panel-heading merchant-section-heading">
        <h2 id="overview-channel-title">通道概况</h2><span class="merchant-section-note">收入与费率</span>
      </div>
      <div class="table-responsive">
        <table class="table merchant-channel-table">
          <caption class="sr-only">各支付通道的今日收入、昨日收入、今日成功率和费率</caption>
          <thead><tr><th scope="col">支付方式</th><th scope="col">今日收入</th><th scope="col">昨日收入</th><th scope="col">成功率</th><th scope="col">费率</th></tr></thead>
          <tbody id="merchant-channel-rows"><tr><td colspan="5" class="merchant-data-status" role="status">统计数据加载中…</td></tr></tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="merchant-overview-side" aria-label="账户与公告">
    <section class="panel merchant-account-panel" aria-label="商户账户">
      <div class="panel-heading merchant-account-heading">
        <div><strong><?php echo htmlspecialchars($userrow['username'], ENT_QUOTES, 'UTF-8')?></strong><small>商户 ID · <?php echo intval($uid)?></small></div>
        <span class="merchant-account-status"><?php echo $status?></span>
      </div>
      <dl class="merchant-income-grid">
        <div><dt>今日收入</dt><dd><small>¥</small><span id="order_today_all">—</span></dd></div>
        <div><dt>昨日收入</dt><dd><small>¥</small><span id="order_lastday_all">—</span></dd></div>
        <?php if($conf['user_transfer']==1){?>
        <div><dt>今日支出</dt><dd><small>¥</small><span id="transfer_today_all">—</span></dd></div>
        <div><dt>昨日支出</dt><dd><small>¥</small><span id="transfer_lastday_all">—</span></dd></div>
        <?php }?>
      </dl>
      <div class="merchant-account-actions"><a class="btn btn-default btn-sm" href="./userinfo.php?mod=api"><i class="fa fa-lock" aria-hidden="true"></i> API 信息</a><a class="btn btn-default btn-sm" href="./editinfo.php"><i class="fa fa-cog" aria-hidden="true"></i> 修改资料</a></div>
    </section>

    <section class="panel merchant-notices" aria-labelledby="overview-notice-title">
      <div class="panel-heading merchant-section-heading"><h2 id="overview-notice-title">公告通知</h2></div>
      <?php if(empty($list)){?>
      <div class="merchant-notice-empty"><i class="fa fa-bell-o" aria-hidden="true"></i><span>暂无公告</span></div>
      <?php }else{?>
      <div class="merchant-notice-list">
        <?php foreach($list as $row){?>
        <article class="merchant-notice-item">
          <div class="merchant-notice-content"><font color="<?php echo $row['color']?$row['color']:null?>"><?php echo $row['content']?></font></div>
          <time class="merchant-notice-date"><?php echo htmlspecialchars($row['addtime'], ENT_QUOTES, 'UTF-8')?></time>
        </article>
        <?php }?>
      </div>
      <?php }?>
    </section>
  </aside>
</div>
      <!-- / stats -->
</div>
    </div>
  </div>

<script src="/assets/js/chart.js"></script>
<?php include 'foot.php';?>
<script>
$(document).ready(function(){
  var orderLoading = false;
  var orderLoaded = false;
  var settlementData = <?php echo json_encode(['labels'=>$settle_labels, 'datasets'=>[['label'=>'结算金额', 'data'=>$settle_values]]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
  var settleChart;
  var chartPalette = ['#078744', '#3b82b1', '#b18432', '#8d78b0', '#c16e5b', '#4f8d83', '#6e7f9c'];

  function displayMoney(value) {
    var number = Number(String(value).replace(/,/g, ''));
    return Number.isFinite(number) ? number.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—';
  }

  function countError() {
    $('#merchant-channel-rows').empty().append($('<tr>').append($('<td>', {colspan: 5, 'class': 'merchant-data-status', role: 'status'})
      .append($('<span>').text('统计数据加载失败 ')).append($('<button>', {type: 'button', 'class': 'btn btn-default btn-sm'}).text('重新加载').on('click', loadCounts))));
  }

  function loadCounts() {
    $('#merchant-channel-rows').html('<tr><td colspan="5" class="merchant-data-status" role="status">统计数据加载中…</td></tr>');
    $.ajax({
      type: 'GET', url: 'ajax2.php?act=getcount', dataType: 'json', timeout: 20000,
      success: function(data) {
        if (!data || (data.code != null && data.code !== 0) || !Array.isArray(data.channels)) { countError(); return; }
        $('#orders').text(data.orders == null ? '—' : data.orders);
        $('#orders_today').text(data.orders_today == null ? '—' : data.orders_today);
        ['settle_money', 'order_today_all', 'order_lastday_all', 'transfer_today_all', 'transfer_lastday_all'].forEach(function(key) {
          $('#' + key).text(displayMoney(data[key]));
        });
        var rows = $('#merchant-channel-rows').empty();
        if (!data.channels.length) {
          rows.append('<tr><td colspan="5" class="merchant-data-status" role="status">暂无可用支付通道</td></tr>');
          return;
        }
        data.channels.forEach(function(item) {
          var row = $('<tr>');
          var name = $('<th>', {scope: 'row', 'class': 'merchant-channel-name'});
          if (/^[a-zA-Z0-9_-]+$/.test(item.name)) name.append($('<img>', {src: '/assets/icon/' + item.name + '.ico', alt: '', width: 18, height: 18}));
          name.append($('<span>').text(item.showname));
          row.append(name);
          row.append($('<td>', {'data-label': '今日收入'}).text('¥' + displayMoney(item.order_today)));
          row.append($('<td>', {'data-label': '昨日收入'}).text('¥' + displayMoney(item.order_lastday)));
          row.append($('<td>', {'data-label': '成功率'}).text(item.success_rate + '%'));
          row.append($('<td>', {'data-label': '费率'}).text(item.rate + '%'));
          rows.append(row);
        });
      },
      error: countError
    });
  }

  function chartStatus(container, message, retry) {
    var status = $('<div>', {'class': 'merchant-chart-status', role: 'status'}).append($('<p>').text(message));
    if (retry) status.append($('<button>', {type: 'button', 'class': 'btn btn-default btn-sm'}).text('重新加载').on('click', retry));
    $(container).empty().append(status);
  }

  function createChart(container, data, label) {
    if (typeof Chart === 'undefined') {
      chartStatus(container, '图表组件加载失败', function() { window.location.reload(); });
      return null;
    }
    var canvas = $('<canvas>', {role: 'img', 'aria-label': label});
    $(container).empty().append(canvas);
    var series = data.datasets.map(function(item, index) {
      return $.extend({}, item, {
        borderColor: chartPalette[index % chartPalette.length],
        backgroundColor: chartPalette[index % chartPalette.length] + '10',
        borderWidth: 2, pointRadius: data.labels.length === 1 ? 4 : 2, pointHoverRadius: 5,
        tension: 0.35, cubicInterpolationMode: 'monotone', fill: index === 0,
        hidden: index > 0 && item.data.every(function(value) { return Number(value) === 0; })
      });
    });
    return new Chart(canvas[0].getContext('2d'), {
      type: 'line', data: {labels: data.labels, datasets: series},
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : {duration: 200},
        interaction: {mode: 'index', intersect: false},
        scales: {
          x: {grid: {display: false}, ticks: {color: '#707974', maxRotation: 0, font: {size: 11}}},
          y: {beginAtZero: true, border: {display: false}, grid: {color: '#edf1ee'}, ticks: {maxTicksLimit: 5, color: '#707974', font: {size: 11}, callback: function(value) { return '¥' + value; }}}
        },
        plugins: {
          legend: {position: 'bottom', labels: {usePointStyle: true, boxWidth: 7, boxHeight: 7, padding: 14, color: '#58665e', font: {size: 11}, filter: function(item) { return !series[item.datasetIndex].hidden; }}},
          tooltip: {callbacks: {label: function(context) { return context.dataset.label + '：¥' + displayMoney(context.parsed.y); }}}
        }
      }
    });
  }

  function loadOrderChart() {
    if (orderLoading || orderLoaded) return;
    orderLoading = true;
    chartStatus('#order-chart-container', '统计数据加载中…');
    $.ajax({
      type: 'GET', url: 'ajax2.php?act=orderCount', dataType: 'json', timeout: 20000,
      success: function(data) {
        if (!data || (data.code != null && data.code !== 0) || !Array.isArray(data.labels) || !Array.isArray(data.datasets) ||
          data.datasets.some(function(series) { return !series || !Array.isArray(series.data); })) {
          chartStatus('#order-chart-container', '统计数据加载失败', loadOrderChart); return;
        }
        if (!data.labels.length || !data.datasets.length || !data.datasets.some(function(series) { return series.data.some(function(value) { return Number(value) > 0; }); })) {
          chartStatus('#order-chart-container', '近 7 天暂无收款数据'); orderLoaded = true; return;
        }
        window.orderChartInstance = createChart('#order-chart-container', data, '近 7 天订单收款金额趋势，单位元');
        orderLoaded = !!window.orderChartInstance;
      },
      error: function() { chartStatus('#order-chart-container', '统计数据加载失败', loadOrderChart); },
      complete: function() { orderLoading = false; }
    });
  }

  $('.merchant-overview-chart a[data-toggle="tab"]').on('shown.bs.tab', function(event) {
    var orderTab = event.target.hash === '#order-tab';
    $('.merchant-overview-chart a[data-toggle="tab"]').attr('aria-selected', 'false');
    $(event.target).attr('aria-selected', 'true');
    $('#merchant-chart-period').text(orderTab ? '近 7 天' : '最近 9 笔');
    $('#overview-trend-title').text(orderTab ? '收款趋势' : '结算趋势');
    if (orderTab) {
      loadOrderChart();
      if (window.orderChartInstance) window.orderChartInstance.resize();
    } else if (!settlementData.labels.length) {
      chartStatus('#settle-chart-container', '暂无已完成的结算记录');
    } else if (!settleChart) {
      settleChart = createChart('#settle-chart-container', settlementData, '最近 9 笔已完成结算金额，单位元');
    } else settleChart.resize();
  });

  loadCounts();
  loadOrderChart();
  <?php if(!empty($conf['modal'])){?>
  $('#myModal').modal('show');
  <?php }?>
});
</script>
