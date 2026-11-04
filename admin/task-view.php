<?php
if (!defined('IN_CRONLITE')) { http_response_code(404); exit; }
?>
<link rel="stylesheet" href="../assets/css/admin-tasks.css?v=7"/>
<main class="container task-page" id="task-manager"
      data-api="<?php echo htmlspecialchars($taskApi, ENT_QUOTES, 'UTF-8'); ?>"
      data-csrf="<?php echo htmlspecialchars($taskCsrf, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="task-toolbar">
    <div class="task-overview" id="task-overview" aria-live="polite">正在读取任务…</div>
    <div class="task-toolbar-actions">
      <label class="sr-only" for="task-filter">筛选任务</label>
      <select id="task-filter" class="form-control input-sm"><option value="all">全部任务</option><option value="enabled">自动执行</option><option value="error">异常任务</option></select>
      <button type="button" class="btn btn-default task-icon" id="task-refresh" title="刷新任务" aria-label="刷新任务"><i class="fa fa-refresh" aria-hidden="true"></i></button>
      <button type="button" class="btn btn-primary btn-sm" id="task-add"><i class="fa fa-plus" aria-hidden="true"></i> 通道任务</button>
    </div>
  </div>
  <section class="task-service-bar" id="task-service" aria-label="调度服务">
    <div class="task-service-info"><span class="task-service-dot" id="task-service-dot" aria-hidden="true"></span><div><div class="task-service-heading"><strong id="task-service-state">读取调度服务…</strong><span class="task-service-pid" id="task-service-pid" aria-live="polite">当前 PID：—</span></div><span class="task-service-detail" id="task-service-detail"></span><span class="task-service-detail" id="task-worker-detail"></span></div></div>
    <div class="task-service-actions">
      <button type="button" class="btn btn-primary btn-sm" id="task-service-main" data-service-action="start" disabled>启动服务</button>
      <div class="dropdown">
        <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">更多 <span class="caret"></span></button>
        <ul class="dropdown-menu dropdown-menu-right">
          <li><button type="button" data-service-action="restart">重启服务</button></li>
          <li><button type="button" data-service-action="enable">开机自启</button></li>
          <li><button type="button" data-service-action="disable">取消自启</button></li>
          <li role="separator" class="divider"></li>
          <li><button type="button" id="task-service-logs">服务日志</button></li>
          <li><button type="button" id="task-plugin-sync">同步插件任务</button></li>
        </ul>
      </div>
      <button type="button" class="btn btn-default task-icon" id="task-service-refresh" title="刷新服务状态" aria-label="刷新服务状态"><i class="fa fa-refresh" aria-hidden="true"></i></button>
    </div>
  </section>
  <div id="task-feedback" role="status" aria-live="polite" hidden></div>
  <nav class="task-tabs" aria-label="后台能力分类">
    <button type="button" class="is-selected" data-task-view="all" aria-pressed="true">全部任务</button>
    <button type="button" data-task-view="system" aria-pressed="false">系统任务</button>
    <button type="button" data-task-view="plugin" aria-pressed="false">插件任务</button>
    <button type="button" data-task-view="services" aria-pressed="false">常驻服务</button>
  </nav>
  <section class="task-surface" id="task-plugin-services" aria-label="常驻插件服务" hidden></section>
  <section class="task-surface" aria-label="任务列表">
    <div class="table-responsive">
      <table class="table task-table">
        <thead><tr><th scope="col">任务</th><th scope="col">执行计划</th><th scope="col">下次执行</th><th scope="col">最近执行</th><th scope="col" class="task-actions-heading">操作</th></tr></thead>
        <tbody id="task-rows"><tr><td colspan="5" class="task-empty">正在加载…</td></tr></tbody>
      </table>
    </div>
    <p class="task-footnote">记录保留天数和清理周期可在“清理过期记录”任务中设置；最近一次、运行中及待核查异常记录保留。时间均为北京时间。</p>
  </section>
  <details class="task-connection">
    <summary><i class="fa fa-terminal" aria-hidden="true"></i> 安装与运行说明</summary>
    <p>服务器先升级数据库，再从项目根目录运行 <code>php bin/install-task-worker.php --print</code> 检测环境，确认后以 root 运行 <code>php bin/install-task-worker.php --install</code> 安装。</p>
    <p>项目目录、PHP CLI、运行用户自动识别；环境存在歧义时明确报错，可用 <code>--php=绝对路径</code>、<code>--user=用户</code> 指定。旧 cron 必须停用，定时 URL 不再执行业务。</p>
    <p>停止或重启会先停止领取新任务，等待执行中的任务结束。支付插件随通道启停自动接管，默认每 3 秒处理；投诉管理中启用的接口自动生成每日 03:00 同步任务，时间均为北京时间。</p>
  </details>
</main>

<div class="modal fade task-modal" id="task-edit-modal" tabindex="-1" role="dialog" aria-labelledby="task-edit-title">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="task-edit-form">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button><h2 class="modal-title" id="task-edit-title">编辑任务</h2></div>
      <div class="modal-body">
        <div class="task-field"><label for="task-action">任务类型</label><select id="task-action" class="form-control" required></select></div>
        <div class="task-field" id="task-channel-field" hidden><label for="task-channel">通道 ID</label><input id="task-channel" type="number" min="1" max="2147483647" step="1" class="form-control"/></div>
        <div class="task-field" id="task-job-field" hidden><label for="task-job">插件任务</label><select id="task-job" class="form-control"></select></div>
        <div class="task-field" id="task-source-field" hidden><label for="task-source">投诉来源</label><select id="task-source" class="form-control"><option value="1">微信 / 支付宝标准投诉</option></select></div>
        <div id="task-retention-fields" hidden>
          <div class="task-field"><label for="task-success-days">成功记录</label><div class="input-group"><input id="task-success-days" aria-label="成功记录保留天数" type="number" min="1" max="3650" step="1" class="form-control"/><span class="input-group-addon">天</span></div></div>
          <div class="task-field"><label for="task-failure-days">失败/中断</label><div class="input-group"><input id="task-failure-days" aria-label="失败与中断记录保留天数" type="number" min="1" max="3650" step="1" class="form-control"/><span class="input-group-addon">天</span></div></div>
          <p class="task-footnote">按保留天数清理任务记录，运行中及待核查异常保留。订单数据不受影响。</p>
        </div>
        <div class="task-field"><label for="task-schedule">执行周期</label><select id="task-schedule" class="form-control"><option value="interval">固定间隔</option><option value="daily">每天</option></select></div>
        <div class="task-field" id="task-interval-field"><label for="task-interval">间隔秒数</label><input id="task-interval" type="number" min="10" max="86400" step="1" class="form-control"/></div>
        <div class="task-field" id="task-daily-field" hidden><label for="task-daily">每日时间</label><input id="task-daily" type="time" step="60" class="form-control"/></div>
        <label class="task-checkbox"><input id="task-enabled" type="checkbox"/> 开启自动执行</label>
        <div class="task-recovery" id="task-recovery" hidden><p id="task-last-error"></p><label class="task-checkbox"><input id="task-acknowledge" type="checkbox"/> 已核查上次业务结果，解除异常并允许再次执行</label></div>
        <p class="task-form-error" id="task-edit-error" role="alert" hidden></p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" id="task-rebind" hidden>接入当前插件</button><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="submit" class="btn btn-primary" id="task-save">保存</button></div>
    </form>
  </div></div>
</div>

<div class="modal fade task-modal" id="task-run-modal" tabindex="-1" role="dialog" aria-labelledby="task-run-title">
  <div class="modal-dialog task-run-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title" id="task-run-title">立即执行</h2></div>
    <div class="modal-body"><p id="task-run-description"></p><p class="task-form-error" id="task-run-error" role="alert" hidden></p></div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="button" class="btn btn-primary" id="task-confirm-run">执行</button></div>
  </div></div>
</div>

<div class="modal fade task-modal" id="task-history-modal" tabindex="-1" role="dialog" aria-labelledby="task-history-title">
  <div class="modal-dialog task-history-dialog" role="document"><div class="modal-content">
    <div class="modal-header task-history-heading"><div class="task-dialog-heading"><h2 class="modal-title" id="task-history-title">执行记录</h2><span class="task-history-name" id="task-history-name"></span></div><button type="button" class="close" data-dismiss="modal" aria-label="关闭执行记录"><span aria-hidden="true">&times;</span></button></div>
    <div class="modal-body"><div id="task-history" aria-live="polite"></div><button type="button" class="btn btn-default btn-sm" id="task-history-more" hidden>更早记录</button></div>
  </div></div>
</div>
<div class="modal fade task-modal" id="task-service-modal" tabindex="-1" role="dialog" aria-labelledby="task-service-title">
  <div class="modal-dialog task-history-dialog" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="关闭服务日志">&times;</button><h2 class="modal-title" id="task-service-title">服务日志</h2></div>
    <div class="modal-body"><pre id="task-service-log" class="task-service-log"></pre></div>
  </div></div>
</div>
<div class="modal fade task-modal" id="task-service-logs-modal" tabindex="-1" role="dialog" aria-labelledby="task-service-logs-title">
  <div class="modal-dialog task-service-logs-dialog" role="document"><div class="modal-content">
    <div class="modal-header task-log-modal-heading">
      <div class="task-dialog-heading"><h2 class="modal-title" id="task-service-logs-title">服务日志</h2><span class="task-history-name">按日期查看</span></div>
      <button type="button" class="close" data-dismiss="modal" aria-label="关闭服务日志"><span aria-hidden="true">&times;</span></button>
    </div>
    <div class="modal-body">
      <div class="task-log-browser">
        <aside class="task-log-days-panel" aria-label="日志日期">
          <div class="task-log-days-heading"><strong>日志日期</strong><button type="button" class="btn btn-default task-icon" id="task-service-log-refresh" title="刷新日期" aria-label="刷新日期"><i class="fa fa-refresh" aria-hidden="true"></i></button></div>
          <div id="task-service-log-days" class="task-log-days" role="listbox" aria-label="选择日志日期"></div>
        </aside>
        <section class="task-log-content-panel" aria-live="polite">
          <div class="task-log-content-heading"><h3 id="task-service-log-date">当天日志</h3><button type="button" class="btn btn-default btn-sm" id="task-service-log-delete" disabled><i class="fa fa-trash-o" aria-hidden="true"></i> 删除当天</button></div>
          <pre id="task-service-log-content" class="task-service-log"></pre>
        </section>
      </div>
    </div>
  </div></div>
</div>
<script src="../assets/js/admin-tasks.js?v=11"></script>
