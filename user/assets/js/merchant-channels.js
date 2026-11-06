(() => {
  'use strict';
  const $id = id => document.getElementById(id);
  let metadata = {types: [], plugins: [], sources: []}, rows = [], page = 1, keySchema = null, pluginSchema = null, testId = null, keyRequest = 0, editorRequest = 0, keyOperation = null, qrTimer = null;
  const pageSize = 15;
  const node = (tag, text, cls) => { const e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; };
  const option = (value, text) => { const e = node('option', text); e.value = value; return e; };
  const error = (id, message) => { const e = $id(id); e.textContent = message || ''; e.hidden = !message; };
  function notice(message, bad = false) { const e = $id('channelNotice'); e.className = 'alert ' + (bad ? 'alert-danger' : 'alert-success'); error('channelNotice', message); }
  async function api(action, values, read = false) {
    const query = new URLSearchParams({act: action});
    if (read) Object.entries(values || {}).forEach(([k, v]) => query.set(k, v));
    const response = await fetch('channel_ajax.php?' + query, {
      method: read ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'X-CSRF-Token': window.merchantChannelCsrf, ...(read ? {} : {'Content-Type': 'application/json'})},
      ...(read ? {} : {body: JSON.stringify(values || {})})
    });
    let result; try { result = await response.json(); } catch (_) { throw new Error('服务响应异常，请刷新后重试'); }
    if (!response.ok || result.code !== 0) throw new Error(result.msg || '操作失败');
    return result.data;
  }
  async function busy(button, fn) {
    if (button.disabled) return;
    button.disabled = true; const original = Array.from(button.childNodes); button.textContent = '处理中…';
    try { await fn(); } finally { button.disabled = false; button.replaceChildren(...original); }
  }
  // Wait for Bootstrap's previous hide transition before showing another record.
  function modalLifecycle(id, invalidate, clear = () => {}) {
    const modal = window.jQuery('#' + id); let closing = null, release = null;
    modal.on('hide.bs.modal', () => { invalidate(); closing = new Promise(resolve => { release = resolve; }); });
    modal.on('hidden.bs.modal', () => { clear(); const done = release; closing = null; release = null; if (done) done(); });
    return {ready: async () => { while (closing) await closing; }, hide: async () => { modal.modal('hide'); if (closing) await closing; }};
  }
  const editorModal = modalLifecycle('channelModal', () => { ++editorRequest; });
  const keysModal = modalLifecycle('channelKeysModal', () => { ++keyRequest; clearTimeout(qrTimer); }, () => {
    $id('channelKeyFields').replaceChildren(); $id('channelKeyNote').textContent = ''; $id('channelPluginActions').replaceChildren(); $id('channelPluginResult').replaceChildren(); keySchema = null; pluginSchema = null;
  });
  async function keysBusy(button, fn) {
    const current = keyRequest;
    if (!keySchema || keyOperation === current) return;
    keyOperation = current;
    const controls = Array.from($id('channelKeysForm').querySelectorAll('button:not([data-dismiss])')).map(b => [b, b.disabled]);
    try { await busy(button, async () => { controls.forEach(([b]) => { b.disabled = true; }); await fn(current); }); }
    finally { controls.forEach(([b, disabled]) => { b.disabled = disabled; }); if (keyOperation === current) keyOperation = null; }
  }
  function populatePlugins(selected) {
    const type = metadata.types.find(t => String(t.id) === $id('channelType').value);
    const select = $id('channelPlugin'); select.replaceChildren();
    const available = metadata.plugins.filter(p => type && String(p.types || '').split(',').includes(type.name));
    available.forEach(p => select.append(option(p.name, p.showname ? p.showname + ' · ' + p.name : p.name)));
    if (!available.length) select.append(option('', '暂无可用插件'));
    if (selected && available.some(p => p.name === selected)) select.value = selected;
  }
  async function refresh() { rows = await api('list', {}, true); render(); }
  function render() {
    const q = $id('channelSearch').value.trim().toLowerCase(), type = $id('channelTypeFilter').value, plugin = $id('channelPluginFilter').value, status = $id('channelStatusFilter').value;
    const list = rows.filter(r => (!q || String(r.id) === q || r.name.toLowerCase().includes(q)) && (!type || String(r.type) === type) && (!plugin || r.plugin === plugin) && (status === '' || String(r.status) === status));
    const pages = Math.max(1, Math.ceil(list.length / pageSize)); page = Math.min(page, pages);
    const body = $id('channelRows'); body.replaceChildren();
    for (const row of list.slice((page - 1) * pageSize, page * pageSize)) {
      const tr = node('tr');
      [row.id, row.name, Number(row.mode) === 1 ? '商户直清' : '平台代收'].forEach(v => tr.append(node('td', v)));
      const typeCell = node('td'), typeLabel = node('span', null, 'channel-paytype'), typeName = metadata.types.find(t => String(t.id) === String(row.type))?.name;
      if (['alipay', 'wxpay', 'qqpay', 'bank', 'jdpay', 'douyinpay'].includes(typeName)) { const icon = node('img'); icon.src = '../assets/icon/' + typeName + '.ico'; icon.alt = ''; icon.addEventListener('error', () => icon.remove()); typeLabel.append(icon); }
      typeLabel.append(document.createTextNode(row.typename || row.type)); typeCell.append(typeLabel); tr.append(typeCell);
      tr.append(node('td', row.plugin), node('td', '¥' + row.today, 'channel-amount'), node('td', '¥' + row.yesterday, 'channel-amount'));
      const state = node('td'); const toggle = node('button', Number(row.status) === 1 ? '已开启' : '已关闭', 'btn channel-status ' + (Number(row.status) === 1 ? 'btn-primary' : 'btn-warning'));
      toggle.type = 'button'; toggle.setAttribute('aria-label', row.name + '：' + toggle.textContent + '，点击切换');
      toggle.addEventListener('click', () => busy(toggle, async () => {
        try { const d = await api('detail', {id: row.id}, true); await api('status', {id: row.id, revision: d.revision, status: Number(d.status) === 1 ? 0 : 1}); await refresh(); notice('通道状态已更新'); } catch (e) { notice(e.message, true); }
      })); state.append(toggle); if (Number(row.daystatus) === 1) state.append(node('small', '已达单日限制', 'channel-status-note')); tr.append(state);
      const actions = node('td'), group = node('div', null, 'channel-actions');
      for (const [label, action] of [['配置密钥', 'keys'], ['编辑', 'edit'], ['删除', 'delete'], ['订单', 'orders'], ['测试', 'test']]) {
        if (action === 'orders') { const a = node('a', label, 'btn btn-xs btn-default'); a.href = 'order.php?channel=' + encodeURIComponent(row.id); group.append(a); continue; }
        const b = node('button', label, 'btn btn-xs ' + ({keys: 'btn-primary', edit: 'btn-info', delete: 'btn-danger channel-delete'}[action] || 'btn-default')); b.type = 'button';
        b.addEventListener('click', () => busy(b, async () => {
          try {
            if (action === 'keys') await openKeys(row);
            else if (action === 'edit') await openEditor(row.id);
            else if (action === 'test') { testId = row.id; error('channelTestError', ''); $id('channelTestName').textContent = row.name + ' · #' + row.id; $id('channelTestForm').reset(); window.jQuery('#channelTestModal').modal('show'); }
            else if (window.confirm('直接删除“' + row.name + '”？配置将被移除，历史订单保留，但后续查单、退款可能无法调用此通道。')) {
              const d = await api('detail', {id: row.id}, true); await api('delete', {id: row.id, revision: d.revision}); await refresh(); notice('通道已删除');
            }
          } catch (e) { notice(e.message, true); }
        })); group.append(b);
      }
      actions.append(group); tr.append(actions); body.append(tr);
    }
    if (!list.length) { const tr = node('tr'), td = node('td', rows.length ? '没有匹配的通道' : '还没有自有通道，点击“新增通道”开始配置。', 'channel-empty'); td.colSpan = 9; tr.append(td); body.append(tr); }
    const pagination = $id('channelPagination'); pagination.replaceChildren(node('span', '共 ' + list.length + ' 条 · 第 ' + page + ' / ' + pages + ' 页'));
    for (const [label, delta] of [['上一页', -1], ['下一页', 1]]) { const b = node('button', label, 'btn btn-default btn-sm'); b.type = 'button'; b.disabled = delta < 0 ? page <= 1 : page >= pages; b.addEventListener('click', () => { page += delta; render(); }); pagination.append(b); }
  }
  async function openEditor(id) {
    const request = ++editorRequest; await editorModal.ready();
    if (request !== editorRequest) return;
    let d;
    try { if (id) d = await api('detail', {id}, true); }
    catch (e) { if (request === editorRequest) throw e; return; }
    if (request !== editorRequest) return;
    const form = $id('channelForm'); form.reset(); error('channelFormError', '');
    form.elements.id.value = ''; form.elements.revision.value = '';
    $id('channelType').replaceChildren(...metadata.types.map(t => option(t.id, t.showname))); populatePlugins();
    $id('channelModalTitle').textContent = id ? '编辑通道' : '新增通道';
    if (d) { for (const k of ['id', 'revision', 'name', 'type', 'daytop', 'daymaxorder', 'paymin', 'paymax', 'timestart', 'timestop']) form.elements[k].value = d[k] == null ? '' : d[k]; populatePlugins(d.plugin); }
    window.jQuery('#channelModal').modal('show');
  }
  async function openKeys(row) {
    const request = ++keyRequest; clearTimeout(qrTimer);
    await keysModal.ready(); if (request !== keyRequest) return false;
    let schema, capabilities;
    try { [schema, capabilities] = await Promise.all([api('credentials', {id: row.id}, true), api('plugin-schema', {id: row.id}, true)]); }
    catch (e) { if (request === keyRequest) throw e; return false; }
    if (request !== keyRequest) return false;
    keySchema = schema; pluginSchema = capabilities; $id('channelKeysForm').dataset.plugin = schema.plugin; error('channelKeysError', '');
    $id('channelKeysTitle').textContent = '配置对接密钥 · ' + row.name;
    const fields = $id('channelKeyFields'); fields.replaceChildren();
    for (const field of keySchema.fields) {
      const wrapper = node('div', null, 'form-group'); wrapper.dataset.field = field.key;
      const label = node('label', field.label); label.htmlFor = 'channel-key-' + field.key; wrapper.append(label);
      if (field.type === 'checkbox') {
        const choices = node('div', null, 'checkbox'); choices.id = label.htmlFor;
        for (const [value, title] of Object.entries(field.options || {})) { const l = node('label'), input = node('input'); input.type = 'checkbox'; input.name = field.key; input.value = value; input.checked = Array.isArray(field.value) && field.value.map(String).includes(value); l.append(input, document.createTextNode(' ' + title)); choices.append(l); } wrapper.append(choices);
      } else {
        const input = node(field.type === 'textarea' ? 'textarea' : field.type === 'select' ? 'select' : 'input', null, 'form-control'); input.name = field.key; input.id = label.htmlFor;
        if (field.type === 'select') Object.entries(field.options || {}).forEach(([value, title]) => input.append(option(value, title)));
        else { if (input.tagName === 'INPUT') input.type = 'text'; input.maxLength = 65536; input.autocomplete = 'off'; input.spellcheck = false; input.placeholder = field.hint || ''; if (input.tagName === 'TEXTAREA') input.rows = 2; }
        input.value = field.value == null ? '' : field.value; wrapper.append(input);
      }
      if (field.hint && ['select', 'checkbox'].includes(field.type)) wrapper.append(node('p', field.hint, 'help-block')); fields.append(wrapper);
    }
    $id('channelKeyNote').innerHTML = keySchema.note_html || '';
    renderPluginActions(row); updateConditions(); window.jQuery('#channelKeysModal').modal('show'); return true;
  }
  async function reloadKeys(row, message) {
    if (!await openKeys(row)) return;
    $id('channelPluginResult').replaceChildren(node('p', message)); $id('channelPluginResult').hidden = false;
  }
  function keyValues() {
    const data = new FormData($id('channelKeysForm')), values = {};
    for (const f of keySchema.fields) values[f.key] = f.type === 'checkbox' ? data.getAll(f.key) : String(data.get(f.key) || '');
    return values;
  }
  async function saveKeys() {
    const current = keyRequest, schema = keySchema;
    const result = await api('credentials-save', {id: schema.id, revision: schema.revision, values: keyValues()});
    if (current === keyRequest && keySchema === schema) keySchema = {...schema, revision: result.revision};
  }
  function renderPluginActions(row) {
    const box = $id('channelPluginActions'), result = $id('channelPluginResult'); box.replaceChildren(); result.replaceChildren(); result.hidden = true;
    function button(label, callback, parent = box) {
      const b = node('button', label, 'btn btn-default'); b.type = 'button';
      b.addEventListener('click', () => keysBusy(b, async current => { error('channelKeysError', ''); try { await callback(); } catch (e) { if (current === keyRequest) error('channelKeysError', e.message); } })); parent.append(b); return b;
    }
    if (pluginSchema.automatic_config) {
      const a = node('a', '自动配置', 'btn btn-info'); a.href = '#'; a.target = '_blank'; a.rel = 'noopener noreferrer';
      a.addEventListener('click', e => {
        try { const gateway = String($id('channelKeysForm').elements.namedItem('gateway')?.value || '').trim().replace(/\/+$/, ''); const url = new URL(gateway + '/alipayopen/login'); if (!/^https?:$/.test(url.protocol) || !url.hostname || url.username || url.password) throw new Error(); a.href = url.href; error('channelKeysError', ''); }
        catch (_) { e.preventDefault(); error('channelKeysError', '请先填写有效的网关地址'); }
      }); box.append(a);
      const input = $id('channelKeysForm').elements.namedItem('app_data_url');
      if (input) { const wrap = node('div', null, 'channel-field-action'); input.replaceWith(wrap); wrap.append(input);
        button('获取', async () => { const current = keyRequest; const r = await api('plugin-run', {id: keySchema.id, action: 'application-fetch', revision: keySchema.revision, url: input.value.trim()}); if (current !== keyRequest) return; for (const [k, v] of Object.entries(r.values)) { const field = $id('channelKeysForm').elements.namedItem(k); if (field) field.value = v; } result.replaceChildren(node('span', r.message)); result.hidden = false; }, wrap);
      }
    }
    async function run(action, extra = {}, save = true) {
      const current = keyRequest, id = keySchema.id;
      if (save) {
        await saveKeys(); if (current !== keyRequest) return null;
        const [schema, capabilities] = await Promise.all([api('credentials', {id}, true), api('plugin-schema', {id}, true)]);
        if (current !== keyRequest) return null;
        keySchema = schema; pluginSchema = capabilities;
      }
      if (current !== keyRequest) return null;
      const r = await api('plugin-run', {id, action, revision: pluginSchema.revision, ...extra});
      return current === keyRequest ? r : null;
    }
    if (pluginSchema.qr_login) button('扫码登录', async () => {
      const r = await run('qr-start'); if (!r) return;
      const current = keyRequest, started = Date.now(), ticket = r.ticket;
      result.replaceChildren(node('p', r.message)); result.hidden = false;
      const qr = node('div'); result.append(qr);
      if (r.qrcode_type === 'image') { const img = node('img'); img.src = r.qrcode; img.alt = '支付插件登录二维码'; qr.append(img); }
      else if (window.jQuery.fn.qrcode) window.jQuery(qr).qrcode({width: 200, height: 200, text: r.qrcode});
      else throw new Error('二维码组件加载失败，请刷新重试');
      const state = node('p', '等待扫码确认'); result.append(state);
      async function poll() {
        if (current !== keyRequest || Date.now() - started > 600000) return;
        try { const reply = await run('qr-poll', {ticket}, false); if (!reply || current !== keyRequest) return; state.textContent = reply.message;
          if (reply.state === 'done') { await reloadKeys(row, reply.message); return; }
          qrTimer = setTimeout(poll, 2500);
        } catch (e) { if (current === keyRequest) state.textContent = e.message; }
      }
      qrTimer = setTimeout(poll, 2500);
    });
    for (const [available, label, action] of [[pluginSchema.account_login, '同步登录', 'account-login'], [pluginSchema.shared_session, '同步登录态', 'shared-session-sync']]) {
      if (available) button(label, async () => { const r = await run(action); if (r) await reloadKeys(row, r.message); });
    }
    if (pluginSchema.stores) button('选择门店', async () => {
      const r = await run('stores'); if (!r) return; result.replaceChildren(node('p', r.message)); result.hidden = false;
      const select = node('select', null, 'form-control'); select.setAttribute('aria-label', '选择门店与终端'); select.append(option('', '请选择门店与终端')); r.rows.forEach((v, i) => select.append(option(i, v.store_name + ' / ' + v.terminal_name))); result.append(select);
      button('绑定门店', async () => { const selected = r.rows[select.value]; if (!selected) throw new Error('请选择门店与终端'); const reply = await run('store-bind', {ticket: r.ticket, store_sn: selected.store_sn, terminal_sn: selected.terminal_sn}, false); if (reply) await reloadKeys(row, reply.message); }, result);
    });
    if (pluginSchema.receipt_shops) {
      const parent = Array.from($id('channelKeyFields').children).find(e => e.dataset.field === 'receipt_shop_id');
      if (parent) {
        button('获取全部店铺', async () => {
          const r = await run('receipt-shops'); if (!r) return;
          result.replaceChildren(node('p', r.message)); result.hidden = false;
          const select = node('select', null, 'form-control'); select.setAttribute('aria-label', '选择收款店铺与账号'); select.append(option('', '请选择店铺与账号')); r.rows.forEach(v => select.append(option(v.selection, (v.shop_name || v.shop_id) + ' / ' + (v.account_name || v.account_id || '默认账号')))); result.append(select);
          button('绑定店铺', async () => { if (select.value === '') throw new Error('请选择店铺与账号'); const reply = await run('receipt-bind', {ticket: r.ticket, selection: select.value}, false); if (reply) await reloadKeys(row, reply.message); }, result);
        }, parent);
        button('清除手填 SID', async () => { const r = await run('receipt-clear-sid'); if (r) await reloadKeys(row, r.message); }, parent);
      }
    }
    if (pluginSchema.config_generator && !['jktongypc', 'jktongyx'].includes(keySchema.plugin)) button('配置信息', async () => {
      const r = await run('config-generator', {}, false); if (!r) return;
      const text = JSON.stringify({site: window.location.origin, pid: r.pid, key: r.key}), bytes = new TextEncoder().encode(text); let binary = ''; bytes.forEach(v => { binary += String.fromCharCode(v); });
      const output = node('textarea', null, 'form-control'); output.readOnly = true; output.value = btoa(binary); output.setAttribute('aria-label', '客户端 Base64 配置'); result.replaceChildren(node('p', '本人商户的客户端配置信息，复制到客户端使用。'), output); result.hidden = false;
    });
    if (pluginSchema.monitor) button('在线状态', async () => { const r = await run('monitor', {}, false); if (r) { result.replaceChildren(node('p', r.reason || (r.online ? '在线' : '离线'))); result.hidden = false; } });
  }
  function updateConditions() {
    if (!keySchema) return;
    for (const field of keySchema.fields) {
      const wrapper = Array.from($id('channelKeyFields').children).find(e => e.dataset.field === field.key);
      const matches = Object.entries(field.show_when || {}).every(([name, values]) => { const input = $id('channelKeysForm').elements.namedItem(name); return input && values.map(String).includes(String(input.value)); });
      wrapper.hidden = !matches;
    }
  }
  function renderSources() {
    const box = $id('channelSources'); box.replaceChildren();
    for (const type of metadata.types) {
      const wrapper = node('div', null, 'channel-source'), label = node('label', type.showname), select = node('select', null, 'form-control'); label.htmlFor = select.id = 'channel-source-' + type.id;
      select.append(option('platform', '跟随平台'), option('owned', '自有通道')); select.value = metadata.sources.find(s => String(s.type) === String(type.id))?.source || 'platform';
      select.disabled = !!metadata.routing_locked;
      let previous = select.value; select.addEventListener('change', async () => { select.disabled = true; try { await api('source', {type: String(type.id), source: select.value}); previous = select.value; notice(type.showname + '收款来源已保存'); } catch (e) { select.value = previous; notice(e.message, true); } finally { select.disabled = !!metadata.routing_locked; } }); wrapper.append(label, select); box.append(wrapper);
    }
    if (!metadata.types.length) box.append(node('p', '平台暂未开放支付方式。', 'text-muted'));
    if (metadata.routing_locked) box.append(node('p', '管理员已设置独立通道策略，实际收款按该策略执行；调整来源请联系管理员。', 'text-muted'));
  }
  $id('channelAdd').addEventListener('click', () => openEditor().catch(e => notice(e.message, true)));
  $id('channelType').addEventListener('change', () => populatePlugins());
  $id('channelFilters').addEventListener('submit', e => { e.preventDefault(); page = 1; busy($id('channelRefresh'), () => refresh().catch(err => notice(err.message, true))); });
  for (const id of ['channelSearch', 'channelTypeFilter', 'channelPluginFilter', 'channelStatusFilter']) $id(id).addEventListener(id === 'channelSearch' ? 'input' : 'change', () => { page = 1; render(); });
  $id('channelReset').addEventListener('click', () => { $id('channelFilters').reset(); page = 1; render(); notice(''); });
  $id('channelForm').addEventListener('submit', e => { e.preventDefault(); busy(e.submitter || e.target.querySelector('[type=submit]'), async () => {
    const current = editorRequest;
    try { const data = Object.fromEntries(new FormData(e.target)); await api('save', data); if (current === editorRequest) await editorModal.hide(); await refresh(); notice(data.id ? '通道已保存' : '新增成功，请配置密钥后启用'); } catch (err) { if (current === editorRequest) error('channelFormError', err.message); else notice(err.message, true); }
  }); });
  $id('channelKeysForm').addEventListener('change', updateConditions);
  $id('channelKeysForm').addEventListener('submit', e => { e.preventDefault(); keysBusy(e.submitter || e.target.querySelector('[type=submit]'), async current => {
    try { await saveKeys(); if (current === keyRequest) await keysModal.hide(); await refresh(); notice('密钥配置已保存'); } catch (err) { if (current === keyRequest) error('channelKeysError', err.message); else notice(err.message, true); }
  }); });
  $id('channelTestForm').addEventListener('submit', e => { e.preventDefault(); busy(e.submitter || e.target.querySelector('[type=submit]'), async () => {
    let testWindow;
    try {
      error('channelTestError', '');
      // Open during the user gesture so a delayed API response cannot trigger popup blocking.
      testWindow = window.open('about:blank', '_blank');
      if (!testWindow) throw new Error('浏览器已拦截测试窗口，请允许弹出窗口后重试');
      testWindow.opener = null;
      testWindow.document.title = '测试支付'; testWindow.document.body.textContent = '正在创建测试订单…';
      const data = Object.fromEntries(new FormData(e.target)); const result = await api('test', {...data, id: testId});
      if (testWindow.closed) {
        error('channelTestError', '测试订单已创建，');
        const link = node('a', '点击打开测试支付'); link.href = result.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
        $id('channelTestError').append(link); return;
      }
      testWindow.location.replace(result.url); window.jQuery('#channelTestModal').modal('hide');
    } catch (err) { if (testWindow && !testWindow.closed) testWindow.close(); error('channelTestError', err.message); }
  }); });
  window.jQuery('#channelModal,#channelKeysModal,#channelTestModal').on('shown.bs.modal', function () { this.querySelector('input:not([type=hidden]),select,textarea,button[type=submit]')?.focus(); });
  (async () => {
    try { metadata = await api('metadata', {}, true); renderSources(); metadata.types.forEach(t => $id('channelTypeFilter').append(option(t.id, t.showname))); metadata.plugins.forEach(p => $id('channelPluginFilter').append(option(p.name, p.showname || p.name))); await refresh(); }
    catch (e) { notice(e.message, true); $id('channelRows').replaceChildren(); const tr = node('tr'), td = node('td', '加载失败，请点击刷新重试。', 'channel-empty'); td.colSpan = 9; tr.append(td); $id('channelRows').append(tr); }
  })();
})();
