(function () {
  'use strict';
  const root = document.getElementById('task-manager');
  const byId = id => document.getElementById(id);
  const statusNames = { idle: '未执行', running: '运行中', success: '成功', failed: '失败', interrupted: '异常中断' };
  const origins = { admin: '管理员', cron: '指定任务', scheduler: '自动调度' };
  let rows = [];
  let definitions = {};
  let editing = null;
  let running = null;
  let historyId = null;
  let historyBefore = 0;
  let listSequence = 0;
  let historySequence = 0;
  let loadingList = false;
  let serviceBusy = false;
  let view = 'all';
  let pluginServices = [];
  let executions = [];
  let serviceLogDays = [];
  let serviceLogDate = null;
  let serviceLogSequence = 0;
  let serviceLogDeleting = false;
  const expandedGroups = new Set();
  const serviceNames = {stopped:'已停止',starting:'启动中',waiting:'等待业务就绪',running:'业务运行中',draining:'停止中',backoff:'重试等待',attention:'需人工核查'};

  function groupHeading(body, key, label, count, colspan) {
    const tr = node('tr', 'task-group');
    const td = node('td'); td.colSpan = colspan;
    const toggle = node('button', 'task-group-toggle', (expandedGroups.has(key) ? '▾ ' : '▸ ') + label + ' · ' + count);
    toggle.type = 'button'; toggle.setAttribute('aria-expanded', String(expandedGroups.has(key)));
    toggle.addEventListener('click', () => { if (expandedGroups.has(key)) expandedGroups.delete(key); else expandedGroups.add(key); renderRows(); renderPluginServices(); });
    td.append(toggle); tr.append(td); body.append(tr);
  }
  async function pluginAction(row, action) {
    if (['stop','restart','refresh'].includes(action) && !window.confirm(
      action === 'refresh' ? '已核查插件和业务结果？将排空旧实例并应用当前配置。' : '确定'+(action === 'stop' ? '停止' : '重启')+'“'+row.name+'”？会先结束在途工作。')) return;
    const operationId = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2,'0')).join('');
    try {
      await request('plugin_service', {id:row.id,revision:row.revision,action,operation_id:operationId});
      feedback('操作已排队，业务就绪后才显示运行正常。', false);
    } catch (error) { feedback(error.message, true); }
    await loadList();
  }
  async function pluginDetail(row) {
    byId('task-service-title').textContent = row.name+' · 实例详情';
    byId('task-service-log').textContent = '读取中…'; $('#task-service-modal').modal('show');
    try {
      const data = await request('plugin_service_detail', undefined, {id:row.id});
      const unit = data.unit;
      byId('task-service-log').textContent = '配置版本 '+data.service.revision+' · 代次 '+data.service.generation+'\n'+
        (unit ? 'PID '+unit.pid+' · 重启 '+unit.restarts+' · 内存 '+unit.memory+' · 进程数 '+unit.tasks+'\n' : '')+
        (data.service.last_error ? data.service.last_error+'\n' : '')+'\n'+data.text;
    } catch (error) { byId('task-service-log').textContent = error.message; }
  }
  function renderServiceLogDays() {
    const target = byId('task-service-log-days');
    target.replaceChildren();
    if (!serviceLogDays.length) {
      target.append(node('p', 'task-log-empty', '暂无服务日志'));
      return;
    }
    serviceLogDays.forEach(day => {
      const button = node('button', 'task-log-day', day);
      button.type = 'button';
      button.disabled = serviceLogDeleting;
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', String(day === serviceLogDate));
      button.classList.toggle('is-selected', day === serviceLogDate);
      button.addEventListener('click', () => loadServiceLog(day));
      target.append(button);
    });
  }
  function showServiceLogMessage(message, error) {
    byId('task-service-log-date').textContent = serviceLogDate || '当天日志';
    byId('task-service-log-content').textContent = message;
    byId('task-service-log-delete').disabled = Boolean(error) || !serviceLogDate;
  }
  async function loadServiceLog(day) {
    if (serviceLogDeleting) return;
    const sequence = ++serviceLogSequence;
    serviceLogDate = day;
    renderServiceLogDays();
    byId('task-service-log-date').textContent = day;
    byId('task-service-log-delete').disabled = true;
    byId('task-service-log-content').textContent = '读取中…';
    try {
      const data = await request('service_log', undefined, {date: day});
      if (sequence !== serviceLogSequence) return;
      byId('task-service-log-content').textContent = data.text || '当天暂无日志';
      byId('task-service-log-delete').disabled = false;
    } catch (error) {
      if (sequence !== serviceLogSequence) return;
      showServiceLogMessage(error.message, true);
    }
  }
  async function loadServiceLogDays(preferred) {
    if (serviceLogDeleting) return;
    const sequence = ++serviceLogSequence;
    serviceLogDate = null;
    renderServiceLogDays();
    byId('task-service-log-date').textContent = '当天日志';
    byId('task-service-log-delete').disabled = true;
    byId('task-service-log-content').textContent = '读取日期…';
    try {
      const data = await request('service_log_days');
      if (sequence !== serviceLogSequence) return;
      serviceLogDays = Array.isArray(data.days) ? data.days : [];
      const target = preferred && serviceLogDays.includes(preferred) ? preferred :
        (data.today && serviceLogDays.includes(data.today) ? data.today : serviceLogDays[0]);
      serviceLogDate = target || null;
      renderServiceLogDays();
      if (target) await loadServiceLog(target);
      else showServiceLogMessage('暂无服务日志', true);
    } catch (error) {
      if (sequence !== serviceLogSequence) return;
      serviceLogDays = [];
      renderServiceLogDays();
      showServiceLogMessage(error.message, true);
    }
  }
  async function deleteServiceLogDay() {
    const date = serviceLogDate;
    if (serviceLogDeleting || !date || !window.confirm('确定删除 '+date+' 的日志文件吗？删除后无法恢复。')) return;
    serviceLogDeleting = true;
    const sequence = ++serviceLogSequence;
    const button = byId('task-service-log-delete');
    button.disabled = true;
    byId('task-service-log-refresh').disabled = true;
    renderServiceLogDays();
    try {
      const data = await request('service_log_delete', {date});
      if (sequence !== serviceLogSequence) return;
      serviceLogDays = Array.isArray(data.days) ? data.days : serviceLogDays.filter(item => item !== date);
      serviceLogDate = null;
      serviceLogDeleting = false;
      renderServiceLogDays();
      const next = serviceLogDays[0];
      if (next) await loadServiceLog(next);
      else showServiceLogMessage('暂无服务日志', true);
    } catch (error) {
      if (sequence !== serviceLogSequence) return;
      showServiceLogMessage(error.message, true);
    } finally {
      serviceLogDeleting = false;
      byId('task-service-log-refresh').disabled = false;
      renderServiceLogDays();
      if (serviceLogDate === date) button.disabled = false;
    }
  }
  function renderPluginServices() {
    const target = byId('task-plugin-services'); target.replaceChildren();
    if (!pluginServices.length) {
      target.append(node('p','task-empty','启用带常驻能力的通道后，进程会自动接管；外部上报插件继续使用原收款方式。')); return;
    }
    const groups = new Map();
    pluginServices.forEach(row => { if (!groups.has(row.plugin)) groups.set(row.plugin,[]); groups.get(row.plugin).push(row); });
    groups.forEach((items, plugin) => {
      const group = node('details','task-service-group'); group.open = expandedGroups.has('s:'+plugin);
      group.addEventListener('toggle', () => { if(group.open)expandedGroups.add('s:'+plugin);else expandedGroups.delete('s:'+plugin); });
      group.append(node('summary','',plugin+' · '+items.length+' 项服务'));
      items.forEach(row => {
        const entry = node('article','task-resident-row'); const info = node('div');
        info.append(node('strong','',row.name),node('span','task-secondary',(row.channels || []).map(c => '#'+c.id+' '+c.name).join('、') || '#'+row.channel_id));
        if (row.automatic) info.append(node('span','task-secondary','随通道启停自动接管 · 默认每 3 秒处理'));
        const state = node('div','task-resident-state');
        state.append(node('span','task-status task-status-'+(row.healthy ? 'success' : row.observed === 'attention' ? 'failed':'idle'),
          row.observed === 'running' && !row.healthy ? '业务心跳异常' : (serviceNames[row.observed] || '状态未知')));
        state.append(node('span','task-secondary',Number(row.desired) ? '期望：启用' : '期望：停用'));
        if (row.changed) state.append(node('span','task-secondary task-lock','配置或插件版本有变化'));
        if (row.operation && ['pending','starting'].includes(row.operation.state)) state.append(node('span','task-secondary',row.operation.state === 'pending' ? '操作已排队' : '等待业务就绪'));
        if (row.last_error) state.append(node('span','task-secondary task-error-excerpt',row.last_error));
        const buttons = node('div','task-row-actions');
        const pending = row.operation && ['pending','starting'].includes(row.operation.state);
        const unavailable = pending || !workerState || !workerState.healthy;
        if (!row.automatic) buttons.append(actionButton(Number(row.desired) ? 'stop':'play',(Number(row.desired) ? '停止':'启动')+row.name,
          () => pluginAction(row,Number(row.desired) ? 'stop':'start'),unavailable || (!Number(row.desired) && (row.changed || row.observed === 'attention'))));
        const more = node('details','task-resident-more'); more.append(node('summary','','更多'));
        const menu = node('div','task-resident-menu');
        [['重启','restart'],['应用配置 / 核查恢复','refresh']].forEach(([label,action]) => {
          const button = node('button','',label);button.type='button';button.disabled=unavailable;
          button.addEventListener('click',() => { more.open=false;pluginAction(row,action); });menu.append(button);
        });
        const detail=node('button','','实例与日志');detail.type='button';detail.addEventListener('click',() => { more.open=false;pluginDetail(row); });menu.append(detail);more.append(menu);
        buttons.append(more);entry.append(info,state,buttons);group.append(entry);
      });
      target.append(group);
    });
    if(view==='services')byId('task-overview').textContent=pluginServices.length+' 项服务 · 运行 '+pluginServices.filter(row=>row.healthy).length+' · 需处理 '+pluginServices.filter(row=>row.observed==='attention').length;
  }

  function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  }

  function feedback(message, error) {
    const box = byId('task-feedback');
    box.textContent = message;
    box.classList.toggle('task-feedback-error', Boolean(error));
    box.hidden = false;
  }

  function formError(id, message) {
    const box = byId(id);
    box.textContent = message;
    box.hidden = !message;
  }


  let serviceState = null;
  let workerState = null;
  let pluginJobs = [];
  let pendingRequests = [];

  function renderService(service, worker) {
    if (service) serviceState = service;
    if (worker) workerState = worker;
    const s = serviceState || {};
    const w = workerState || {};
    const active = s.active_state === 'active';
    const transitioning = ['activating', 'deactivating', 'reloading'].includes(s.active_state);
    const retrying = s.sub_state === 'auto-restart';
    const names = {active:'服务运行中',inactive:'服务已停止',failed:'需人工核查',activating:'服务启动中',deactivating:'正在等待任务结束',reloading:'服务重载中'};
    const healthy = active && w.healthy;
    byId('task-service-state').textContent = !s.installed ? '服务未安装' :
      (!s.available ? '无法读取服务状态' : (retrying ? '重试等待' : (active && !w.healthy ? (w.state === 'draining' ? '正在等待任务结束' : '心跳超时') : (names[s.active_state] || '服务状态未知'))));
    const currentPid = s.available && s.installed && (active || transitioning) && Number.isSafeInteger(s.pid) && s.pid > 0 ? s.pid : null;
    byId('task-service-pid').textContent = '当前 PID：' + (currentPid === null ? '—' : currentPid);
    const failures = {watchdog:'30秒无主循环进度，已触发看门狗',timeout:'服务启动或停止超时','start-limit-hit':'连续异常达到重试上限，请查看服务日志','exit-code':'进程异常退出，请查看服务日志',signal:'进程被信号终止','oom-kill':'超过内存限制'};
    const reason = retrying ? '5秒后重试；连续异常达到上限后暂停' : (s.active_state === 'failed' ? (failures[s.result] || '服务异常，请查看服务日志后恢复') : (active && !w.healthy ? w.reason : ''));
    byId('task-service-detail').textContent = s.message || [s.service, s.unit_file_state === 'enabled' ? '开机自启' : '自启关闭',reason].filter(Boolean).join(' · ');
    const activeIds = (w.active || []).map(item => '#' + item.task_id).join('、');
    const memory = s.budget && /^\d+$/.test(s.budget.MemoryCurrent) ? ' · 内存 '+Math.ceil(Number(s.budget.MemoryCurrent)/1048576)+' MB' : '';
    const recovery = Number(s.restarts) > 0 ? ' · 已重启 '+s.restarts+' 次' : '';
    const watchdog = Number(s.watchdog_seconds) > 0 ? ' · 看门狗 '+s.watchdog_seconds+'秒' : '';
    byId('task-worker-detail').textContent = (w.heartbeat_at ? '心跳 ' + w.heartbeat_at + (activeIds ? ' · 执行中 ' + activeIds : ' · 当前空闲') : '尚无调度心跳')+memory+watchdog+recovery;
    byId('task-service-dot').className = 'task-service-dot ' + (healthy ? 'is-running' : (s.installed ? 'is-stopped' : 'is-unknown'));
    const main = byId('task-service-main');
    main.dataset.serviceAction = active ? 'stop' : 'start';
    main.textContent = serviceBusy ? '提交中…' : (active ? '停止服务' : '启动服务');
    document.querySelectorAll('[data-service-action]').forEach(button => {
      const action = button.dataset.serviceAction;
      button.disabled = serviceBusy || transitioning || !s.available || !s.installed ||
        (action === 'enable' && s.unit_file_state === 'enabled') ||
        (action === 'disable' && s.unit_file_state !== 'enabled');
    });
    byId('task-service-logs').disabled = serviceBusy || !s.available || !s.installed;
  }
  async function serviceAction(action) {
    if (serviceBusy) return;
    serviceBusy = true;
    renderService();
    try {
      const data = await request('service', {action});
      renderService(data.status);
      feedback('服务指令已提交，正在读取实际状态。停止或重启会等待当前任务结束。', false);
    } catch (error) { feedback(error.message, true); }
    finally { serviceBusy = false; renderService(); await loadList(); }
  }
  function updatePluginJobs() {
    const select = byId('task-job');
    const selected = editing ? editing.job_key : select.value;
    select.replaceChildren();
    const channelId = Number(byId('task-channel').value);
    pluginJobs.filter(job => !job.error && Number(job.channel_id) === channelId).forEach(job => {
      const option = node('option', '', job.name + ' · ' + job.key);
      option.value = job.key;
      select.append(option);
    });
    if (editing && !Array.from(select.options).some(option => option.value === selected)) {
      const option = node('option', '', selected + '（声明已失效）');
      option.value = selected;
      select.append(option);
    }
    if (selected) select.value = selected;
    select.disabled = editing !== null;
  }

  async function request(action, data, query) {
    const url = new URL(root.dataset.api, location.href);
    url.searchParams.set('act', action);
    if (query) Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
    const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
    if (data !== undefined) {
      options.method = 'POST';
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = root.dataset.csrf;
      options.body = JSON.stringify(data);
    }
    let response;
    try { response = await fetch(url, options); }
    catch (error) { throw new Error('连接已中断，执行状态未知。请刷新列表核查。'); }
    let payload;
    try { payload = await response.json(); }
    catch (error) { throw new Error('服务器未返回有效 JSON，请检查登录状态或服务器日志。'); }
    if (!response.ok || payload.code !== 0) {
      throw new Error(typeof payload.msg === 'string' ? payload.msg : '请求失败，HTTP '+response.status);
    }
    return payload.data;
  }

  function status(value) {
    if (!Object.prototype.hasOwnProperty.call(statusNames, value)) throw new Error('服务器返回了未定义的任务状态');
    return node('span', 'task-status task-status-'+value, statusNames[value]);
  }

  function moment(value) {
    if (value === null) return '—';
    return value.slice(5);
  }

  function actionButton(icon, title, handler, disabled) {
    const button = node('button', 'btn btn-default task-icon');
    button.type = 'button';
    button.title = title;
    button.setAttribute('aria-label', title);
    button.disabled = Boolean(disabled);
    const image = node('i', 'fa fa-'+icon);
    image.setAttribute('aria-hidden', 'true');
    button.append(image);
    button.addEventListener('click', handler);
    return button;
  }

  function renderRows() {
    const mode = byId('task-filter').value;
    const filtered = rows.filter(row => (view === 'all' || (view === 'plugin' ? row.action === 'plugin' : row.action !== 'plugin')) && (mode === 'all' ||
      (mode === 'enabled' && Number(row.enabled) === 1) ||
      (mode === 'error' && (row.last_status === 'failed' || row.overdue_lock))));
    const body = byId('task-rows');
    body.replaceChildren();
    const setupActions = mode === 'all' ? ['complain', 'plugin'].filter(action => definitions[action] &&
      !rows.some(row => row.action === action) &&
      (view === 'all' || (view === 'plugin' ? action === 'plugin' : action !== 'plugin'))) : [];
    byId('task-overview').textContent = rows.length+' 项任务 · 自动 '+rows.filter(row => Number(row.enabled) === 1).length+
      ' · 异常 '+rows.filter(row => row.last_status === 'failed' || row.overdue_lock).length;
    if (!filtered.length && !setupActions.length) {
      const tr = node('tr');
      const cell = node('td', 'task-empty', '没有符合条件的任务');
      cell.colSpan = 5;
      tr.append(cell);
      body.append(tr);
      return;
    }
    const groups = new Set();
    filtered.forEach(row => {
      if (view === 'plugin') {
        const key = 'p:'+row.plugin;
        if (!groups.has(key)) { groups.add(key);groupHeading(body,key,row.plugin || '归属失效',filtered.filter(item => item.plugin === row.plugin).length,5); }
        if (!expandedGroups.has(key)) return;
      }
      const tr = node('tr');
      tr.dataset.id = row.id;
      const name = node('td');
      name.append(node('span', 'task-name', row.name));
      const identity = Number(row.channel_id) > 0 ? '#'+row.channel_id+' · '+row.channel_name + (row.job_key ? ' · ' + row.job_key : '') : row.action;
      name.append(node('span', 'task-secondary', identity));
      if (row.automatic) name.append(node('span', 'task-secondary', row.action === 'complain' ? '随投诉接口启停自动管理' : '随支付通道启停自动管理'));
      if (row.action === 'cleanup_logs') name.append(node('span', 'task-secondary',
        '成功保留 '+row.retention_success_days+' 天 · 失败/中断保留 '+row.retention_failure_days+' 天'));
      const queued = pendingRequests.find(item => Number(item.task_id) === Number(row.id));
      if (queued) name.append(node('span', 'task-secondary', queued.state === 'pending' ? '已排队' : '执行请求处理中'));
      const execution = executions.find(item => Number(item.task_id) === Number(row.id));
      if (execution) name.append(node('span','task-secondary',execution.state === 'queued' ? '实例已派发，等待领取' : '独立实例执行中'));
      if (row.unavailable) name.append(node('span', 'task-secondary task-error-excerpt', row.unavailable));
      const plan = node('td');
      plan.append(node('span', '', row.schedule_type === 'daily' ? '每天 '+row.daily_at : '每 '+row.interval_seconds+' 秒'));
      plan.append(node('span', 'task-secondary', Number(row.enabled) === 1 ? '自动执行' : '自动已关闭'));
      const next = node('td', '', moment(row.next_run_at));
      if (row.next_run_at !== null) next.title = row.next_run_at+' 北京时间';
      const recent = node('td');
      recent.append(status(row.last_status));
      const time = row.last_status === 'running' ? row.started_at : row.finished_at;
      if (time !== null) recent.append(node('span', 'task-secondary', moment(time)));
      if (row.lock_token !== null) {
        const lock = node('span', 'task-secondary task-lock', '持锁 '+row.lock_token.slice(0, 10)+(row.overdue_lock ? ' · 已超时，需核查' : ''));
        lock.title = '归属令牌：'+row.lock_token+'\n超时标记：'+row.lock_expires_at;
        recent.append(lock);
      }
      if (row.last_error !== null) {
        const error = node('span', 'task-secondary task-error-excerpt', row.last_error);
        error.title = row.last_error;
        recent.append(error);
      }
      const actions = node('td');
      const buttons = node('div', 'task-row-actions');
      buttons.append(
        actionButton('play', '执行'+row.name, () => openRun(row), row.lock_token !== null || row.last_status === 'failed' || row.unavailable || queued || !workerState || !workerState.healthy),
        actionButton('pencil', '编辑'+row.name, () => openEdit(row), false),
        actionButton('history', '查看'+row.name+'执行记录', () => openHistory(row), false)
      );
      actions.append(buttons);
      tr.append(name, plan, next, recent, actions);
      body.append(tr);
    });
    setupActions.forEach(action => {
      const definition = definitions[action];
      const unavailable = definition.unavailable;
      const tr = node('tr', 'task-setup-row'); tr.dataset.action = action;
      const name = node('td');
      name.append(node('span', 'task-name', definition.name), node('span', 'task-secondary',
        action === 'plugin' ? '通道启用后自动接管；停用后自动停止' : '在投诉管理添加并启用微信 / 支付宝接口后自动接管'));
      if (unavailable) name.append(node('span', 'task-secondary', unavailable));
      const plan = node('td', '', action === 'plugin' ? '默认每 3 秒' : '默认每天 03:00');
      plan.append(node('span', 'task-secondary', '由进程自动管理'));
      const state = node('td'); state.append(node('span', 'task-status task-status-idle', action === 'plugin' ? (pluginServices.length ? '已识别 '+pluginServices.length+' 项插件服务' : '等待启用通道；外部上报插件保持原收款方式') : '等待投诉接口'));
      const actions = node('td'); const buttons = node('div', 'task-row-actions');
      if (action === 'plugin') buttons.append(actionButton('list', '查看插件服务', () => document.querySelector('[data-task-view="services"]').click(), false));
      else {
        const link = node('a', 'btn btn-default', '配置投诉接口'); link.href = 'complaints.php';
        buttons.append(link);
      }
      actions.append(buttons); tr.append(name, plan, node('td', '', '—'), state, actions); body.append(tr);
    });
  }

  async function loadList(clearError = false) {
    const sequence = ++listSequence;
    loadingList = true;
    byId('task-refresh').disabled = true;
    try {
      const data = await request('list');
      if (sequence !== listSequence) return;
      if (!Array.isArray(data.rows) || typeof data.definitions !== 'object' || data.definitions === null) throw new Error('任务列表数据格式不正确');
      rows = data.rows;
      pluginServices = Array.isArray(data.plugin_services) ? data.plugin_services : [];
      executions = Array.isArray(data.executions) ? data.executions : [];
      pluginJobs = Array.isArray(data.plugin_jobs) ? data.plugin_jobs : [];
      pendingRequests = Array.isArray(data.requests) ? data.requests : [];
      definitions = data.definitions;
      renderService(data.service, data.worker);
      renderRows();
      renderPluginServices();
      if (clearError) byId('task-feedback').hidden = true;

      byId('task-add').disabled = false;
    } catch (error) {
      feedback(error.message, true);
      if (!rows.length) {
        byId('task-overview').textContent = '读取失败';
        byId('task-rows').replaceChildren();
        const tr = node('tr');
        const td = node('td', 'task-empty', '任务未加载，请点击刷新重试');
        td.colSpan = 5;
        tr.append(td);
        byId('task-rows').append(tr);
      }
    } finally {
      if (sequence === listSequence) { loadingList = false; byId('task-refresh').disabled = false; }
    }
  }

  function updateFields() {
    const action = byId('task-action').value;
    const definition = definitions[action];
    const channel = Boolean(definition && definition.channel);
    byId('task-channel-field').hidden = !channel;
    byId('task-channel').required = channel;
    byId('task-source-field').hidden = action !== 'complain';
    byId('task-job-field').hidden = action !== 'plugin';
    byId('task-job').required = action === 'plugin';
    const cleanup = action === 'cleanup_logs';
    byId('task-retention-fields').hidden = !cleanup;
    ['task-success-days', 'task-failure-days'].forEach(id => {
      byId(id).required = cleanup;
      byId(id).disabled = !cleanup;
    });
    if (action === 'plugin') updatePluginJobs();
    byId('task-action').disabled = editing !== null;
    byId('task-channel').disabled = editing !== null;
    byId('task-interval').min = action === 'plugin' ? '3' : '10';
    byId('task-enabled').disabled = Boolean(editing && editing.automatic);
    byId('task-source').disabled = editing !== null;
    const daily = byId('task-schedule').value === 'daily';
    byId('task-daily-field').hidden = !daily;
    byId('task-daily').required = daily;
    byId('task-interval-field').hidden = daily;
    byId('task-interval').required = !daily;
  }

  function editBusy(busy) {
    byId('task-edit-form').querySelectorAll('input, select, button').forEach(control => { control.disabled = busy; });
    byId('task-save').textContent = busy ? '保存中…' : '保存';
    if (!busy) updateFields();
  }

  function openEdit(row, presetAction = null) {
    editing = row;
    const form = byId('task-edit-form');
    form.reset();
    formError('task-edit-error', '');
    byId('task-edit-title').textContent = row === null ? '新增通道任务' : row.name;
    const select = byId('task-action');
    select.replaceChildren();
    Object.entries(definitions).forEach(([key, definition]) => {
      if (row === null && (!definition.channel || definition.unavailable)) return;
      const option = node('option', '', definition.name);
      option.value = key;
      select.append(option);
    });
    if (row !== null) select.value = row.action;
    else if (presetAction !== null) select.value = presetAction;
    byId('task-channel').value = row === null ? '' : row.channel_id;
    byId('task-source').value = row === null || Number(row.source) === 0 ? '1' : row.source;
    byId('task-success-days').value = row && row.action === 'cleanup_logs' ? row.retention_success_days : 30;
    byId('task-failure-days').value = row && row.action === 'cleanup_logs' ? row.retention_failure_days : 90;
    byId('task-enabled').checked = row !== null && Number(row.enabled) === 1;
    byId('task-schedule').value = row === null ? (select.value === 'complain' ? 'daily' : 'interval') : row.schedule_type;
    byId('task-interval').value = row === null || row.interval_seconds === null ? (select.value === 'plugin' ? '3' : '60') : row.interval_seconds;
    byId('task-daily').value = row === null || row.daily_at === null ? '03:00' : row.daily_at;
    const recover = row !== null && (row.last_status === 'failed' || row.lock_token !== null);
    byId('task-rebind').hidden = !row || !row.rebind_available;
    byId('task-recovery').hidden = !recover;
    byId('task-last-error').textContent = recover ? (row.lock_token !== null ? '上次执行未写入结束状态。仍在运行的进程不能解除锁。' : row.last_error) : '';
    editBusy(false);
    $('#task-edit-modal').modal({ backdrop: 'static', keyboard: false });
  }

  byId('task-edit-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!event.target.reportValidity()) return;
    const action = byId('task-action').value;
    const params = {};
    if (definitions[action].channel) params.channel = byId('task-channel').value;
    if (action === 'plugin') params.job = byId('task-job').value;
    if (action === 'complain') params.source = byId('task-source').value;
    if (action === 'cleanup_logs') {
      params.success_days = byId('task-success-days').value;
      params.failure_days = byId('task-failure-days').value;
    }
    const daily = byId('task-schedule').value === 'daily';
    const data = { id: editing === null ? 0 : editing.id, revision: editing === null ? 0 : editing.revision,
      action, params, enabled: byId('task-enabled').checked ? 1 : 0,
      schedule_type: byId('task-schedule').value, interval_seconds: daily ? null : byId('task-interval').value,
      daily_at: daily ? byId('task-daily').value : null, acknowledge_failure: byId('task-acknowledge').checked ? 1 : 0 };
    formError('task-edit-error', '');
    editBusy(true);
    try {
      await request('save', data);
      $('#task-edit-modal').modal('hide');
      feedback('任务配置已保存', false);
      await loadList();
    } catch (error) { formError('task-edit-error', error.message); }
    finally { editBusy(false); }
  });

  function openRun(row) {
    running = row;
    formError('task-run-error', '');
    byId('task-run-description').textContent = row.action === 'cleanup_logs' ?
      '确认清理超过 '+row.retention_success_days+' 天的成功记录、超过 '+row.retention_failure_days+
      ' 天的失败与中断记录？最近一次、运行中及待核查异常记录保留，不影响订单数据。' :
      '立即执行“'+row.name+'”？本次执行会进行实际业务处理。';
    byId('task-run-modal').querySelectorAll('button').forEach(button => { button.disabled = false; });
    byId('task-confirm-run').textContent = '执行';
    $('#task-run-modal').modal({ backdrop: 'static', keyboard: false });
  }

  byId('task-confirm-run').addEventListener('click', async () => {
    const task = running;
    byId('task-run-modal').querySelectorAll('button').forEach(button => { button.disabled = true; });
    byId('task-confirm-run').textContent = '执行中…';
    try {
      await request('run', { id: task.id, revision: task.revision });
      $('#task-run-modal').modal('hide');
      feedback(task.name+'已加入执行队列，请查看状态和执行记录。', false);
    } catch (error) { formError('task-run-error', error.message); }
    finally {
      byId('task-confirm-run').textContent = '已提交';
      byId('task-run-modal').querySelector('[data-dismiss="modal"]').disabled = false;
      await loadList();
    }
  });

  async function historyPage(append) {
    const id = historyId;
    const sequence = ++historySequence;
    byId('task-history-more').disabled = true;
    try {
      const data = await request('history', undefined, { id, before: historyBefore });
      if (id !== historyId || sequence !== historySequence) return;
      if (!Array.isArray(data.rows)) throw new Error('执行记录格式不正确');
      const target = byId('task-history');
      if (!append) target.replaceChildren();
      if (!data.rows.length && !append) target.append(node('p', 'task-muted', '暂无执行记录'));
      data.rows.forEach(row => {
        const entry = node('article', 'task-run-entry');
        const header = node('header');
        header.append(status(row.status), node('span', 'task-run-origin', '来源：'+origins[row.triggered_by]));
        entry.append(header);
        const meta = node('dl', 'task-run-meta');
        [['开始', row.started_at], ['结束', row.finished_at]].forEach(([label, value]) => {
          if (value === null) return;
          const item = node('div');
          const content = node('dd');
          const time = node('time', '', value);
          time.dateTime = value.replace(' ', 'T')+'+08:00';
          content.append(time);
          item.append(node('dt', '', label), content);
          meta.append(item);
        });
        entry.append(meta);
        if (row.error) entry.append(node('pre', 'task-run-error', row.error));
        if (row.summary && (row.summary.length > 240 || row.summary.split('\n').length > 4)) {
          const details = node('details');
          details.append(node('summary', '', '执行输出'));
          details.append(node('pre', '', row.summary));
          entry.append(details);
        } else if (row.summary) entry.append(node('p', 'task-run-summary', row.summary));
        target.append(entry);
      });
      byId('task-history-more').hidden = data.rows.length < 30;
      if (data.rows.length) historyBefore = data.rows[data.rows.length - 1].id;
    } catch (error) {
      if (id !== historyId || sequence !== historySequence) return;
      if (!append) byId('task-history').replaceChildren(node('p', 'task-form-error', error.message));
      else feedback(error.message, true);
    } finally { if (sequence === historySequence) byId('task-history-more').disabled = false; }
  }

  function openHistory(row) {
    historyId = row.id;
    historyBefore = 0;
    byId('task-history-name').textContent = row.name;
    byId('task-history').replaceChildren(node('p', 'task-muted', '读取中…'));
    byId('task-history-more').hidden = true;
    $('#task-history-modal').modal('show');
    historyPage(false);
  }

  byId('task-action').addEventListener('change', () => {
    if (editing === null) {
      byId('task-schedule').value = byId('task-action').value === 'complain' ? 'daily' : 'interval';
      byId('task-interval').value = byId('task-action').value === 'plugin' ? '3' : '60';
      byId('task-daily').value = '03:00';
    }
    updateFields();
  });
  byId('task-rebind').addEventListener('click', async () => {
    const row = editing;
    if (!row || !row.rebind_available) return;
    editBusy(true);
    try {
      const updated = await request('rebind', {id:row.id, revision:row.revision});
      feedback('已接入当前通道插件，自动执行保持关闭。核查后可重新启用。', false);
      await loadList();
      openEdit(rows.find(item => Number(item.id) === Number(updated.id)) || updated);
    } catch (error) { formError('task-edit-error', error.message); }
    finally { editBusy(false); }
  });
  byId('task-schedule').addEventListener('change', updateFields);
  byId('task-add').addEventListener('click', () => openEdit(null));
  byId('task-add').disabled = true;
  byId('task-refresh').addEventListener('click', () => loadList(true));
  byId('task-filter').addEventListener('change', renderRows);
  document.querySelectorAll('[data-task-view]').forEach(button => button.addEventListener('click', () => {
    view=button.dataset.taskView;
    root.dataset.view=view;
    document.querySelectorAll('[data-task-view]').forEach(item => { item.classList.toggle('is-selected',item===button);item.setAttribute('aria-pressed',String(item===button)); });
    byId('task-plugin-services').hidden = view !== 'services';
    byId('task-rows').closest('.task-surface').hidden = view === 'services';
    byId('task-filter').hidden = view === 'services';byId('task-add').hidden = view === 'services';
    renderRows();renderPluginServices();
  }));
  document.querySelectorAll('[data-service-action]').forEach(button => button.addEventListener('click', () => serviceAction(button.dataset.serviceAction)));
  byId('task-service-refresh').addEventListener('click', () => loadList(true));
  byId('task-channel').addEventListener('input', updatePluginJobs);
  byId('task-service-logs').addEventListener('click', async () => {
    $('#task-service-logs-modal').modal('show');
    loadServiceLogDays(serviceLogDate);
  });
  byId('task-service-log-refresh').addEventListener('click', () => loadServiceLogDays(serviceLogDate));
  byId('task-service-log-delete').addEventListener('click', deleteServiceLogDay);
  byId('task-plugin-sync').addEventListener('click', async () => {
    const button = byId('task-plugin-sync');
    button.disabled = true;
    try { const data = await request('sync_plugins', {}); feedback('新增 ' + data.created + ' 项插件任务（默认关闭）' + (data.errors.length ? '，' + data.errors.length + ' 个声明错误，请检查插件 tasks.json' : ''), Boolean(data.errors.length)); await loadList(); }
    catch (error) { feedback(error.message, true); }
    finally { button.disabled = false; }
  });
  byId('task-history-more').addEventListener('click', () => historyPage(true));
  $('#task-history-modal').on('hidden.bs.modal', () => { historyId = null; historySequence++; });
  $('#task-service-logs-modal').on('hidden.bs.modal', () => { serviceLogSequence++; serviceLogDate = null; });
  setInterval(() => {
    if (!document.hidden && !loadingList && !document.querySelector('.task-modal.in')) loadList();
  }, 5000);
  loadList();
}());
