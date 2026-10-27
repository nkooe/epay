(() => {
  "use strict";
  const root = document.getElementById("complaint-app");
  if (!root) return;
  const $ = (id) => document.getElementById(id),
    state = {
      status: "",
      page: 1,
      pages: 1,
      accounts: [],
      channels: [],
      detail: null,
      uploads: [],
      syncing: false,
      loadSequence: 0,
      busyCount: 0,
    };
  const labels = ["待处理", "处理中", "已结束"],
    providers = { wechat: "微信", alipay: "支付宝" },
    actionLabels = {
      reply: "回复用户",
      supplement: "补充凭证",
      feedback: "提交处理结果",
      complete: "反馈处理完成",
      "refund-progress": "反馈退款审批",
    };
  const node = (tag, text, cls) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (cls) el.className = cls;
    return el;
  };
  function notice(text, ok = false) {
    const dialog = document.querySelector(".cp-dialog[open]");
    if (dialog) {
      let box = dialog.querySelector(".cp-message");
      if (!box) {
        box = node("div", undefined, "cp-message");
        box.setAttribute("role", "alert");
        dialog.querySelector(".cp-dialog-body").prepend(box);
      }
      box.textContent = text;
      box.dataset.ok = String(ok);
      box.hidden = false;
      box.scrollIntoView({ block: "nearest" });
    } else {
      $("cp-error").hidden = ok;
      $("cp-error").textContent = ok ? "" : text;
      $("cp-status").textContent = ok ? text : "";
    }
  }
  async function api(act, data, options = {}) {
    const url = new URL("complaint-api.php", location.href);
    url.searchParams.set("act", act);
    const init = {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    };
    if (options.get) {
      for (const [key, value] of Object.entries(data || {}))
        if (value !== "" && value !== undefined)
          url.searchParams.set(key, value);
    } else {
      init.method = "POST";
      init.headers["X-CSRF-Token"] = root.dataset.csrf;
      if (data instanceof FormData) init.body = data;
      else {
        init.headers["Content-Type"] = "application/json";
        init.body = JSON.stringify(data || {});
      }
    }
    let response;
    try {
      response = await fetch(url, init);
    } catch (error) {
      throw new Error("网络请求失败，请检查连接后重试");
    }
    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error("服务器响应不正确，请刷新后重试");
    }
    if (!response.ok || result.code !== 0)
      throw new Error(result.msg || "投诉操作失败");
    return result.data;
  }
  async function busy(button, task) {
    if (button.disabled) return;
    state.busyCount++;
    button.disabled = true;
    try {
      await task();
    } catch (error) {
      notice(error.message);
    } finally {
      state.busyCount--;
      button.disabled = false;
    }
  }
  function requestId() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), (value) =>
      value.toString(16).padStart(2, "0"),
    ).join("");
  }
  function open(id) {
    $(id)
      .querySelectorAll(".cp-message")
      .forEach((el) => el.remove());
    $(id).showModal();
  }
  root
    .querySelectorAll("[data-close]")
    .forEach((button) =>
      button.addEventListener("click", () => button.closest("dialog").close()),
    );
  function option(select, value, text) {
    const el = node("option", text);
    el.value = value;
    select.append(el);
  }
  async function schema() {
    const data = await api("schema", {}, { get: true });
    state.accounts = data.accounts;
    state.channels = data.channels;
    state.codes = data.feedback_codes;
    const filter = $("cp-account-filter"),
      selected = filter.value;
    filter.replaceChildren();
    option(filter, "", "全部接口");
    $("cp-sync-account").replaceChildren();
    for (const account of state.accounts) {
      option(filter, account.id, account.name);
      if (Number(account.enabled))
        option($("cp-sync-account"), account.id, account.name);
    }
    filter.value = selected;
    renderAccounts();
  }
  function orderLink(order) {
    const link = node("a", order.trade_no, "cp-order-link");
    link.href =
      "order.php?column=trade_no&value=" + encodeURIComponent(order.trade_no);
    link.target = "_blank";
    link.rel = "noopener";
    return link;
  }
  function badge(status) {
    return node(
      "span",
      labels[status] || "待核实",
      "cp-badge cp-badge-" + status,
    );
  }
  async function load() {
    const sequence = ++state.loadSequence;
    $("cp-reload").disabled = true;
    try {
      const result = await api(
        "list",
        {
          page: state.page,
          status: state.status,
          account_id: $("cp-account-filter").value,
          uid: $("cp-uid").value.trim(),
          q: $("cp-query").value.trim(),
        },
        { get: true },
      );
      if (sequence !== state.loadSequence) return;
      state.pages = result.pages;
      const list = $("cp-list");
      list.replaceChildren();
      if (!result.rows.length) {
        const empty = node("div", undefined, "cp-empty");
        empty.append(
          node(
            "div",
            state.accounts.length
              ? "暂无符合条件的投诉"
              : "配置投诉接口后，同步微信、支付宝的交易投诉",
          ),
        );
        if (!state.accounts.length) {
          const button = node("button", "配置接口");
          button.type = "button";
          button.addEventListener("click", () => open("cp-accounts-dialog"));
          empty.append(button);
        }
        list.append(empty);
      } else {
        const table = node("table", undefined, "cp-table"),
          head = node("thead"),
          tr = node("tr");
        for (const title of [
          "投诉信息",
          "商户 / 订单",
          "接口",
          "金额",
          "状态",
          "创建时间",
          "操作",
        ])
          tr.append(node("th", title));
        head.append(tr);
        table.append(head);
        const body = node("tbody");
        for (const item of result.rows) {
          const row = node("tr");
          const cell = (label) => {
            const td = node("td");
            if (label) td.dataset.label = label;
            row.append(td);
            return td;
          };
          const info = cell();
          info.append(
            node("strong", item.title || item.type || "交易投诉"),
            node("span", item.thirdid, "cp-sub"),
          );
          const merchant = cell("商户");
          merchant.append(
            node("span", Number(item.uid) ? String(item.uid) : "未关联"),
          );
          if (item.trade_no) merchant.append(orderLink(item));
          const account = cell("接口");
          account.append(
            node("span", item.account_name),
            node("span", providers[item.provider], "cp-sub"),
          );
          cell("金额").textContent = "¥" + Number(item.money).toFixed(2);
          cell("状态").append(badge(Number(item.status)));
          cell("创建时间").textContent = item.created_at;
          const button = node("button", "查看");
          button.type = "button";
          button.addEventListener("click", () =>
            busy(button, () => showDetail(item.id)),
          );
          cell().append(button);
          body.append(row);
        }
        table.append(body);
        list.append(table);
      }
      $("cp-total").textContent = "共 " + result.total + " 条";
      $("cp-page-number").textContent = state.page + " / " + state.pages;
      $("cp-prev").disabled = state.page <= 1;
      $("cp-next").disabled = state.page >= state.pages;
    } catch (error) {
      if (sequence !== state.loadSequence) return;
      notice(error.message);
      throw error;
    } finally {
      if (sequence === state.loadSequence) $("cp-reload").disabled = false;
    }
  }
  $("cp-filter").addEventListener("submit", (event) => {
    event.preventDefault();
    state.page = 1;
    load().catch(() => {});
  });
  $("cp-reload").addEventListener("click", () => load().catch(() => {}));
  root.querySelectorAll("[data-status]").forEach((button) =>
    button.addEventListener("click", () => {
      state.status = button.dataset.status;
      state.page = 1;
      root
        .querySelectorAll("[data-status]")
        .forEach((el) => el.classList.toggle("active", el === button));
      load().catch(() => {});
    }),
  );
  $("cp-prev").addEventListener("click", () => {
    if (state.page > 1) {
      state.page--;
      load().catch(() => {});
    }
  });
  $("cp-next").addEventListener("click", () => {
    if (state.page < state.pages) {
      state.page++;
      load().catch(() => {});
    }
  });
  function images(items, id) {
    const strip = node("div", undefined, "cp-images");
    for (const image of items || []) {
      const url =
        image.kind === "wechat"
          ? "complaint-api.php?act=image&id=" +
            encodeURIComponent(id) +
            "&media=" +
            encodeURIComponent(image.id)
          : image.url;
      if (!url) continue;
      const link = node("a"),
        img = node("img");
      link.href = url;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      img.src = url;
      img.alt = "投诉凭证";
      img.loading = "lazy";
      img.referrerPolicy = "no-referrer";
      link.append(img);
      strip.append(link);
    }
    return strip;
  }
  async function showDetail(id) {
    const result = await api("detail", { id }, { get: true });
    state.detail = result;
    state.uploads = [];
    renderDetail();
    open("cp-detail-dialog");
  }
  function renderDetail() {
    const { record, detail, actions, account_enabled: enabled } = state.detail,
      body = $("cp-detail-body");
    body.replaceChildren();
    const summary = node("dl", undefined, "cp-summary");
    for (const [label, value] of [
      ["投诉单号", record.thirdid],
      ["支付方式", providers[record.provider]],
      ["状态", labels[record.status]],
      ["订单金额", "¥" + Number(record.money).toFixed(2)],
      ["商户", Number(record.uid) ? record.uid : "未关联本站商户"],
      ["创建时间", record.created_at],
      ["最新同步", record.snapshot_at],
      ["上游状态", record.remote_status],
    ]) {
      const pair = node("div");
      pair.append(node("dt", label), node("dd", value));
      summary.append(pair);
    }
    body.append(
      summary,
      node("h3", record.title || "投诉内容"),
      node("p", record.content || "无文字说明", "cp-content"),
      images(detail.images, record.id),
    );
    if (detail.full_refunded)
      body.append(node("p", "上游显示已全额退款", "cp-full-refund"));
    if (detail.local_orders.length) {
      const orderbox = node("div");
      orderbox.append(node("h3", "关联订单"));
      detail.local_orders.forEach((order) => orderbox.append(orderLink(order)));
      body.append(orderbox);
    }
    if (detail.history.length) {
      body.append(node("h3", "协商记录"));
      for (const entry of detail.history) {
        const item = node("div", undefined, "cp-history-item");
        item.append(
          node("div", entry.actor + " · " + entry.time),
          node("p", entry.content),
          images(entry.images, record.id),
        );
        body.append(item);
      }
    } else body.append(node("p", "暂无协商记录", "cp-hint"));
    if (Number(record.status) !== 2 && enabled)
      body.append(processForm(record, detail, actions));
    else
      body.append(
        node(
          "p",
          !enabled ? "接口已停用，启用后可同步和处理。" : "该投诉已结束。",
          "cp-hint",
        ),
      );
    const audit = node("details", undefined, "cp-action-history");
    audit.append(node("summary", "操作记录（" + actions.length + "）"));
    for (const action of actions) {
      const row = node("div", undefined, "cp-audit-row");
      row.append(
        node(
          "strong",
          (actionLabels[action.action] || action.action) +
            " · " +
            ({
              sending: "提交中",
              success: "已提交",
              failed: "上游拒绝",
              unknown: "结果待核实",
              verified: "已核实",
            }[action.state] || action.state),
        ),
        node("div", action.message),
        node("small", action.created_at),
      );
      if (["unknown", "sending"].includes(action.state)) {
        const button = node("button", "核实后解除限制");
        button.type = "button";
        button.addEventListener("click", () => {
          if (
            !confirm(
              "请先核实上游协商记录和处理状态。确认已核实，并解除本次操作的重试限制？",
            )
          )
            return;
          busy(button, async () => {
            state.detail = await api("resolve", {
              id: record.id,
              action_id: action.id,
            });
            renderDetail();
            notice("已解除重试限制", true);
          });
        });
        row.append(button);
      }
      audit.append(row);
    }
    body.append(audit);
  }
  function processForm(record, detail, actions) {
    let requestAttempt = null;
    const blocked = actions.some((item) =>
      ["sending", "unknown"].includes(item.state),
    );
    const form = node("form", undefined, "cp-process"),
      label = node("label", "处理操作"),
      select = node("select");
    select.id = "cp-action";
    const available =
      record.provider === "wechat"
        ? [
            "reply",
            "complete",
            ...(detail.problem_type === "REFUND" ? ["refund-progress"] : []),
          ]
        : ["reply", "supplement", "feedback"];
    available.forEach((key) => option(select, key, actionLabels[key]));
    label.append(select);
    form.append(label);
    const extra = node("div"),
      contentLabel = node("label", "处理说明"),
      textarea = node("textarea");
    textarea.maxLength = 1000;
    textarea.rows = 3;
    textarea.id = "cp-action-content";
    contentLabel.append(textarea);
    form.append(extra, contentLabel);
    const uploadLabel = node("label", "图片凭证（最多 4 张，每张不超过 2 MB）"),
      file = node("input");
    file.type = "file";
    file.accept = "image/png,image/jpeg";
    file.id = "cp-evidence";
    uploadLabel.append(file);
    const pick = node("button", "上传图片");
    pick.type = "button";
    pick.className = "cp-pick-image";
    pick.disabled = blocked;
    pick.addEventListener("click", () => file.click());
    uploadLabel.append(pick);
    const files = node("div", undefined, "cp-upload-list");
    form.append(uploadLabel, files);
    const footer = node("footer"),
      submit = node("button", "提交");
    submit.type = "submit";
    submit.className = "cp-primary";
    footer.append(submit);
    form.append(footer);
    let decision = null,
      code = null;
    const update = () => {
      extra.replaceChildren();
      decision = null;
      code = null;
      contentLabel.hidden = select.value === "complete";
      uploadLabel.hidden = select.value === "complete";
      textarea.required = select.value !== "complete";
      if (select.value === "feedback") {
        const label = node("label", "处理结果");
        code = node("select");
        code.id = "cp-feedback-code";
        for (const [key, value] of Object.entries(state.codes))
          option(code, key, value);
        code.value = "04";
        label.append(code);
        extra.append(label);
      }
      if (select.value === "refund-progress") {
        const label = node("label", "审批结果");
        decision = node("select");
        decision.id = "cp-refund-decision";
        option(decision, "APPROVE", "同意退款申请");
        option(decision, "REJECT", "拒绝退款申请");
        label.append(decision);
        extra.append(
          label,
          node(
            "p",
            "此操作只反馈审批结果。实际退款请前往关联订单办理。",
            "cp-hint",
          ),
        );
        const required = () => {
          textarea.required = decision.value === "REJECT";
        };
        decision.addEventListener("change", required);
        required();
      }
    };
    select.addEventListener("change", update);
    update();
    const renderFiles = () => {
      files.replaceChildren();
      state.uploads.forEach((item, index) => {
        const tag = node("span", "凭证 " + (index + 1)),
          remove = node("button", "移除");
        remove.type = "button";
        remove.addEventListener("click", () => {
          state.uploads.splice(index, 1);
          renderFiles();
        });
        tag.append(remove);
        files.append(tag);
      });
    };
    file.addEventListener("change", async () => {
      if (blocked) return;
      const selected = file.files[0];
      if (!selected) return;
      if (state.uploads.length >= 4) {
        notice("最多上传 4 张图片");
        file.value = "";
        return;
      }
      if (
        selected.size > 2097152 ||
        !["image/png", "image/jpeg"].includes(selected.type)
      ) {
        notice("请选择不超过 2 MB 的 JPG、PNG 图片");
        file.value = "";
        return;
      }
      submit.disabled = true;
      file.disabled = true;
      pick.disabled = true;
      try {
        const data = new FormData();
        data.set("id", record.id);
        data.set("file", selected);
        state.uploads.push(await api("upload", data));
        renderFiles();
        notice("凭证已上传", true);
      } catch (error) {
        notice(error.message);
      } finally {
        file.value = "";
        file.disabled = blocked;
        pick.disabled = blocked;
        submit.disabled = blocked;
      }
    });
    file.disabled = blocked;
    if (blocked) {
      submit.disabled = true;
      form.append(
        node("p", "有操作结果待核实，请查看下方操作记录。", "cp-hint"),
      );
    }
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      busy(submit, async () => {
        if (
          select.value === "complete" &&
          !confirm("确认已与用户沟通处理，向微信反馈处理完成？")
        )
          return;
        const input = {
          id: record.id,
          revision: record.revision,
          action: select.value,
          content: textarea.value.trim(),
          images:
            select.value === "complete"
              ? []
              : state.uploads.map((item) => item.id),
        };
        if (code) input.code = code.value;
        if (decision) input.decision = decision.value;
        const body = JSON.stringify(input);
        if (!requestAttempt || requestAttempt.body !== body)
          requestAttempt = { body, id: requestId() };
        input.request_id = requestAttempt.id;
        const result = await api("action", input);
        state.detail = await api("detail", { id: record.id }, { get: true });
        state.uploads = [];
        renderDetail();
        notice(result.message, result.state === "success");
        await load();
      });
    });
    return form;
  }
  $("cp-detail-refresh").addEventListener("click", () =>
    busy($("cp-detail-refresh"), async () => {
      state.detail = await api("refresh", { id: state.detail.record.id });
      state.uploads = [];
      renderDetail();
      notice("上游状态已更新", true);
      await load();
    }),
  );
  function channelOptions() {
    const select = $("cp-config-channel"),
      selected = select.value;
    select.replaceChildren();
    option(select, "", "请选择支付通道");
    for (const channel of state.channels) {
      const bound = state.accounts.some((a) => Number(a.channel) === Number(channel.id));
      option(select, channel.id, (channel.type === "wxpay" ? "微信 · " : "支付宝 · ") + channel.name + "（" + channel.id + "）" + (bound ? " · 已绑定" : channel.bindable ? "" : " · 不支持投诉"));
      select.lastElementChild.disabled = !channel.bindable || bound;
    }
    select.value = selected;
    channelHint();
  }
  function channelHint() {
    const channel = state.channels.find((c) => Number(c.id) === Number($("cp-config-channel").value));
    const account = state.accounts.find((a) => Number(a.id) === Number($("cp-config-id").value));
    $("cp-channel-hint").textContent = account?.credential_source === "legacy"
      ? "此接口保留原有凭证配置。"
      : channel?.config_message || (channel ? "首次绑定后在后台同步，之后默认每天北京时间 03:00 自动同步。" : "请选择支持官方投诉接口的支付宝或微信通道。");
    $("cp-channel-check").disabled = !channel?.bindable;
    $("cp-channel-config").hidden = !channel;
    if (channel) $("cp-channel-config").href = "pay_channel.php?complaint_channel=" + encodeURIComponent(channel.id);
  }
  $("cp-config-channel").addEventListener("change", channelHint);
  function resetAccount() {
    const form = $("cp-account-form");
    form.reset();
    $("cp-config-id").value = "0";
    $("cp-config-revision").value = "0";
    $("cp-config-channel").disabled = false;
    $("cp-account-title").textContent = "绑定支付通道";
    channelOptions();
  }
  function renderAccounts() {
    const list = $("cp-account-list");
    list.replaceChildren();
    if (!state.accounts.length)
      list.append(node("p", "尚未配置投诉接口", "cp-hint"));
    for (const account of state.accounts) {
      const row = node("div", undefined, "cp-account-row"),
        info = node("div");
      info.append(
        node("strong", account.name),
        node(
          "small",
          providers[account.provider] +
            " · " +
            (account.channel_name || "通道已删除") +
            " · " +
            (Number(account.enabled) ? "已启用" : "已停用"),
        ),
      );
      const channel = state.channels.find((c) => Number(c.id) === Number(account.channel));
      if (account.credential_source === "channel" && channel?.config_message)
        info.append(node("small", channel.config_message));
      const sync = account.sync || {};
      info.append(node("small", "同步状态：" + (sync.label || "等待同步")));
      info.append(node("small", "最近成功：" + (sync.last_success_at || "尚未同步成功") + (sync.last_success_at ? " · " + sync.last_count + " 条" : "")));
      if (sync.next_at) info.append(node("small", "下次执行：" + sync.next_at));
      if (["running", "pending", "waiting"].includes(sync.state) && sync.processed)
        info.append(node("small", "本轮已同步 " + sync.processed + " 条"));
      if (sync.error) info.append(node("small", "同步原因：" + sync.error, "cp-sync-error"));
      if (sync.checked_at) info.append(node("small", "连接检测：" + sync.check_message + "（" + sync.checked_at + "）"));
      const buttons = node("div"),
        edit = node("button", "编辑");
      edit.type = "button";
      edit.addEventListener("click", () => {
        resetAccount();
        $("cp-config-id").value = account.id;
        $("cp-config-revision").value = account.revision;
        channelOptions();
        if (![...$("cp-config-channel").options].some((o) => Number(o.value) === Number(account.channel)))
          option($("cp-config-channel"), account.channel, account.channel_name || "通道已删除");
        $("cp-config-channel").value = account.channel;
        $("cp-config-enabled").checked = Boolean(Number(account.enabled));
        $("cp-config-channel").disabled = true;
        channelHint();
        $("cp-account-title").textContent = "编辑接口";
        $("cp-account-form").scrollIntoView({ block: "nearest" });
      });
      buttons.append(edit);
      const check = node("button", "检测连接");check.type = "button";
      check.addEventListener("click", () => busy(check, async () => {
        const result = await api("check-connection", {id: account.id});
        await schema();notice(result.message, result.ok);
      }));buttons.append(check);
      if (Number(account.enabled)) {
        const queue = node("button", "后台同步");queue.type = "button";queue.disabled = sync.state === "running";
        queue.addEventListener("click", () => busy(queue, async () => {
          const result = await api("queue-sync", {id: account.id});
          await schema();notice(result.message, true);
        }));buttons.append(queue);
      }
      if (channel) {const config = node("a", "通道配置", "cp-channel-link");config.href = "pay_channel.php?complaint_channel=" + encodeURIComponent(account.channel);buttons.append(config);}
      if (account.provider === "wechat" && Number(account.enabled)) {
        const subscribe = node("button", "设置通知");
        subscribe.type = "button";
        subscribe.addEventListener("click", () => {
          if (
            !confirm(
              "将该微信商户的投诉通知地址设置为当前平台。确认替换已有通知地址？",
            )
          )
            return;
          busy(subscribe, async () => {
            const result = await api("subscribe", { id: account.id });
            notice("通知地址已设置：" + result.url, true);
          });
        });
        buttons.append(subscribe);
      }
      row.append(info, buttons);
      list.append(row);
    }
  }
  $("cp-accounts-open").addEventListener("click", () =>
    open("cp-accounts-dialog"),
  );
  $("cp-account-reset").addEventListener("click", resetAccount);
  $("cp-channel-check").addEventListener("click", (event) => busy(event.currentTarget, async () => {
    const id = Number($("cp-config-id").value);
    const result = await api("check-connection", id ? {id} : {channel: $("cp-config-channel").value});
    await schema();channelHint();notice(result.message, result.ok);
  }));
  $("cp-accounts-refresh").addEventListener("click", (event) => busy(event.currentTarget, async () => {await schema();channelHint();}));
  setInterval(() => {
    if (!document.hidden && !state.busyCount && $("cp-accounts-dialog").open && $("cp-config-id").value === "0" && !$("cp-config-channel").value)
      schema().catch((error) => notice(error.message));
  }, 15000);
  $("cp-account-form").addEventListener("submit", (event) => {
    event.preventDefault();
    const button = event.submitter;
    busy(button, async () => {
      const input = {
        id: Number($("cp-config-id").value),
        revision: Number($("cp-config-revision").value),
        channel: $("cp-config-channel").value,
        enabled: $("cp-config-enabled").checked ? 1 : 0,
      };
      await api("save-account", input);
      await schema();
      resetAccount();
      notice(input.enabled ? "投诉接口已保存，后台同步已接管" : "投诉接口已停用", true);
      await load();
    });
  });
  $("cp-sync-open").addEventListener("click", () => {
    if (!state.accounts.some((account) => Number(account.enabled))) {
      open("cp-accounts-dialog");
      notice("请先添加并启用投诉接口");
      return;
    }
    const today = new Date(),
      before = new Date(today);
    before.setDate(before.getDate() - 7);
    const date = (d) =>
      [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, "0"),
        String(d.getDate()).padStart(2, "0"),
      ].join("-");
    $("cp-sync-from").value = date(before);
    $("cp-sync-to").value = date(today);
    $("cp-sync-to").max = date(today);
    $("cp-sync-progress").textContent = "";
    open("cp-sync-dialog");
  });
  $("cp-sync-dialog").addEventListener("close", () => {
    state.syncing = false;
  });
  $("cp-sync-form").addEventListener("submit", (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      state.syncing = true;
      let page = 1,
        total = 0;
      try {
        do {
          const batch = await api("sync", {
            account_id: $("cp-sync-account").value,
            from: $("cp-sync-from").value,
            to: $("cp-sync-to").value,
            page,
          });
          total += batch.processed;
          page = batch.next_page;
          $("cp-sync-progress").textContent =
            "已核对 " + total + " 条" + (page ? "，继续同步…" : "，同步完成");
        } while (page && state.syncing && $("cp-sync-dialog").open);
        await load();
      } finally {
        state.syncing = false;
      }
    });
  });
  (async () => {
    try {
      await schema();
      resetAccount();
      await load();
    } catch (error) {
      notice(error.message);
    }
  })();
})();
