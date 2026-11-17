'use strict';

const $ = selector => document.querySelector(selector);
const contextElement = document.querySelector('#cashier-context');
const cashierContext = contextElement ? JSON.parse(contextElement.textContent) : null;
const token = cashierContext ? cashierContext.token : new URLSearchParams(location.search).get('t') || '';
const state = {quote: null, busy: false, loading: false, waiting: false, checking: false, qrcode: '', closed: false,
    pollTimer: null, pollFailures: 0, paymentStartedAt: 0};
history.replaceState(null, '', cashierContext ? cashierContext.canonical_url : location.pathname + '?t=' + encodeURIComponent(token));

async function api(path, data) {
    const response = await fetch(cashierContext ? cashierContext.api_url : '/api/' + path, {
        method: cashierContext || data ? 'POST' : 'GET',
        headers: {'Content-Type': 'application/json'},
        body: cashierContext ? JSON.stringify({...data, token, action: path.split('/').pop()}) : data ? JSON.stringify(data) : undefined,
        cache: 'no-store',
        signal: AbortSignal.timeout(25000)
    });
    const result = await response.json();
    if (!response.ok || result.code !== 0) throw new Error(result.message || '请求失败，请稍后重试');
    return result.data;
}

function showError(error) {
    $('#error').textContent = error.message || '网络连接失败，请重试';
    $('#error').hidden = false;
}

function updatePay() {
    if (!state.quote) return;
    const expired = state.quote.expires_at <= Date.now() / 1000;
    $('#pay').disabled = state.busy || expired || state.quote.paid || state.closed;
    $('#pay').classList.toggle('is-busy', state.busy);
    $('#pay').setAttribute('aria-busy', String(state.busy));
    $('#pay-label').textContent = state.busy ? '正在打开支付…' : state.quote.paid ? '支付成功'
        : state.closed ? '订单已取消' : expired ? '付款已过期' : state.qrcode ? '查看付款二维码' : state.waiting ? '重新打开支付' : '立即支付';
}

function showPaymentCode(url) {
    if (typeof url !== 'string' || !url || url.length > 2048) throw new Error('付款二维码内容无效');
    const container = $('#native-code');
    container.replaceChildren();
    new QRCode(container, {text: url, width: 232, height: 232, colorDark: '#182721', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M});
    const canvas = container.querySelector('canvas');
    if (!canvas) throw new Error('付款二维码生成失败，请重试');
    const image = document.createElement('img');
    image.src = canvas.toDataURL('image/png');
    image.alt = '微信付款二维码';
    image.width = 232;
    image.height = 232;
    const selected = state.quote.methods.find(method => method.id === Number(document.querySelector('input[name=method]:checked').value));
    const wechat = selected.name === 'wxpay';
    $('#native-title').textContent = wechat ? '微信扫码付款' : '支付宝扫码付款';
    $('#native-payment .scan-guide img').src = wechat ? 'wxpay.svg' : 'alipay.svg';
    $('#native-instructions').textContent = wechat ? '使用微信扫一扫付款；同机可尝试长按二维码识别' : '使用支付宝扫一扫完成付款';
    image.alt = wechat ? '微信付款二维码' : '支付宝付款二维码';
    container.replaceChildren(image);
    container.removeAttribute('title');
    container.hidden = false;
    state.qrcode = url;
    $('#native-amount').textContent = '¥' + state.quote.price;
    $('#native-status').textContent = '等待付款，正在自动查询结果…';
    $('#payment-notice').hidden = true;
    $('#receipt-details').prepend($('#purchase-card'));
    $('#checkout-details').open = false;
    $('#details-title').textContent = '商品与订单详情';
    $('.checkout-page').classList.add('is-paying');
    $('#checkout-title').textContent = '扫码付款';
    document.title = '扫码付款 · shop';
    $('#native-payment').hidden = false;
    $('#native-payment').focus({preventScroll: true});
    window.scrollTo({top: 0});
}

function renderMethods(quote) {
    $('#methods').replaceChildren();
    for (const [index, method] of quote.methods.entries()) {
        const label = document.createElement('label');
        label.className = 'method';
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'method';
        input.value = method.id;
        input.checked = quote.locked_method ? quote.locked_method === method.id
            : !$('#methods input:checked');
        input.disabled = !!quote.locked_method;
        const icon = document.createElement('img');
        icon.className = 'method-icon';
        icon.width = 35;
        icon.height = 35;
        icon.alt = '';
        icon.src = method.name === 'wxpay' ? 'wxpay.svg' : 'alipay.svg';
        const copy = document.createElement('span');
        copy.className = 'method-copy';
        const title = document.createElement('span');
        title.className = 'method-title';
        title.textContent = method.label || (method.name === 'wxpay' ? '微信支付' : '支付宝');
        const description = document.createElement('span');
        description.className = 'method-description';
        description.textContent = method.name === 'wxpay' ? '使用微信完成付款' : '使用支付宝完成付款';
        copy.append(title, description);
        const mark = document.createElement('span');
        mark.className = 'radio-mark';
        mark.setAttribute('aria-hidden', 'true');
        mark.innerHTML = '<svg class="icon"><use href="icons.svg#check"></use></svg>';
        label.append(input, icon, copy, mark);
        $('#methods').append(label);
        input.addEventListener('change', syncMethods);
    }
    syncMethods();
    const hasAlipay = quote.methods.some(method => method.name === 'alipay');
    $('#method-tip').hidden = !hasAlipay;
    $('#method-tip').textContent = hasAlipay ? '如在微信中无法打开支付宝，请按付款页面提示继续。' : '';
}

function syncMethods() {
    document.querySelectorAll('.method').forEach(label => {
        const input = label.querySelector('input');
        label.classList.toggle('selected', input.checked);
        label.classList.toggle('locked', input.disabled);
    });
}

async function invokeWechat(parameters) {
    if (!/MicroMessenger/i.test(navigator.userAgent)) throw new Error('请在微信中打开收银台');
    if (!window.WeixinJSBridge || typeof WeixinJSBridge.invoke !== 'function') {
        await new Promise((resolve, reject) => {
            const ready = () => {
                if (!window.WeixinJSBridge || typeof WeixinJSBridge.invoke !== 'function') return;
                clearTimeout(timer); document.removeEventListener('WeixinJSBridgeReady', ready); resolve();
            };
            const timer = setTimeout(() => { document.removeEventListener('WeixinJSBridgeReady', ready); reject(new Error('微信支付未就绪，请点击支付重试')); }, 15000);
            document.addEventListener('WeixinJSBridgeReady', ready); ready();
        });
    }
    return new Promise((resolve, reject) => {
        try {
            WeixinJSBridge.invoke('getBrandWCPayRequest', parameters, async result => {
                const message = (result || {}).err_msg;
                if (message === 'get_brand_wcpay_request:ok') {
                    $('#payment-notice strong').textContent = '正在确认付款结果';
                    $('#payment-notice p').textContent = '以实际到账为准，系统正在查询，请勿重复付款。';
                    await checkPayment(true);
                    resolve();
                } else if (message === 'get_brand_wcpay_request:cancel') {
                    $('#payment-notice strong').textContent = '已取消本次付款';
                    $('#payment-notice p').textContent = '商品和订单已保留，可点击下方按钮重新付款。';
                    resolve();
                } else {
                    reject(new Error('微信支付未完成，请在当前收银台重试'));
                }
            });
        } catch (error) { reject(new Error('微信支付未能打开，请重试')); }
    });
}

$('#retry').addEventListener('click', load);
$('#image').addEventListener('error', () => {
    if (!$('#image').src.endsWith('/image-unavailable.svg')) $('#image').src = 'image-unavailable.svg';
});
async function manualCheck(button) {
    button.disabled = true;
    button.textContent = '正在查询付款结果…';
    try {
        await checkPayment(true);
    } finally {
        button.disabled = false;
        button.textContent = '我已付款，查询结果';
    }
}
$('#check-status').addEventListener('click', event => manualCheck(event.currentTarget));
$('#native-check-status').addEventListener('click', event => manualCheck(event.currentTarget));

$('#pay').addEventListener('click', async () => {
    if (state.busy || !state.quote || state.quote.paid || state.closed || state.quote.expires_at <= Date.now() / 1000) return;
    if (state.qrcode) {
        $('#native-payment').focus({preventScroll: true});
        $('#native-payment').scrollIntoView({block: 'start'});
        return;
    }
    const method = document.querySelector('input[name=method]:checked');
    if (!method) { showError(new Error('请选择支付方式')); return; }
    const selected = state.quote.methods.find(item => item.id === Number(method.value));
    state.busy = true;
    $('#error').hidden = true;
    updatePay();
    try {
        // The gateway confirms the signed mirror and invokes the original plugin in one browser request.
        const data = cashierContext && cashierContext.payment_url ? {url: cashierContext.payment_url}
            : await api('bridge/order', {token, type_id: Number(method.value)});
        state.quote.locked_method = Number(method.value);
        document.querySelectorAll('input[name=method]').forEach(input => { input.disabled = true; });
        syncMethods();
        state.waiting = true;
        state.paymentStartedAt = state.paymentStartedAt || Date.now();
        schedulePoll();
        $('#payment-notice').hidden = false;
        const response = await fetch(data.url, {
            method: 'POST', credentials: new URL(data.url).origin === location.origin ? 'same-origin' : 'omit', cache: 'no-store',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
            body: JSON.stringify({type_id: Number(method.value)}), signal: AbortSignal.timeout(25000),
            referrerPolicy: 'no-referrer'
        });
        const payment = await response.json();
        if (!response.ok || payment.code !== 0) throw new Error(payment.message || '支付发起失败，请重试');
        if (payment.data.type === 'qrcode') {
            showPaymentCode(payment.data.url);
        } else if (payment.data.type === 'wechat_jsapi') {
            await invokeWechat(payment.data.parameters);
        } else if (['jump', 'scheme'].includes(payment.data.type)) {
            location.assign(payment.data.url);
        } else {
            throw new Error('支付接口返回了无法识别的付款方式');
        }
    } catch (error) {
        showError(error);
    } finally {
        state.busy = false;
        updatePay();
    }
});

async function load() {
    if (state.loading) return;
    state.loading = true;
    $('#error').hidden = true;
    $('#retry').hidden = true;
    $('#loading').hidden = false;
    try {
        const quote = cashierContext && cashierContext.quote ? cashierContext.quote : await api('bridge/reserve', {token});
        if (!cashierContext && quote.cashier_url) {
            location.replace(quote.cashier_url);
            return;
        }
        state.quote = quote;
        state.waiting = !!quote.locked_method;
        state.closed = !!quote.canceled;
        if (state.waiting) state.paymentStartedAt = state.paymentStartedAt || Date.now();
        const image = new URL(quote.product.image, cashierContext ? cashierContext.mall_url : location.origin);
        if (!['http:', 'https:'].includes(image.protocol)) throw new Error('商品图片地址无效');
        $('#image').src = image.href;
        $('#image').alt = quote.product.name;
        $('#name').textContent = quote.product.name;
        $('#sku').textContent = quote.product.sku || '默认规格';
        $('#original').textContent = '¥' + quote.original_price;
        $('#discount').textContent = '−¥' + quote.discount;
        $('#saving').textContent = '¥' + quote.discount;
        $('#price').textContent = '¥' + quote.price;
        $('#pay-total').textContent = '¥' + quote.price;
        $('#trade').textContent = quote.gateway_order_id;
        $('#created').textContent = new Date(quote.created_at * 1000).toLocaleString('zh-CN', {hour12: false});
        $('#mall-row').hidden = !quote.mall_order_id;
        $('#mall-order').textContent = quote.mall_order_id;
        renderMethods(quote);
        $('#content').hidden = false;
        $('#payment-notice').hidden = !state.waiting;
        if (handleStatus(quote)) return;
        tick();
        schedulePoll();
    } catch (error) {
        showError(error);
        $('#retry').hidden = false;
        $('#countdown').textContent = '订单暂时未能加载，请重试';
    } finally {
        state.loading = false;
        $('#loading').hidden = true;
    }
}

function tick() {
    if (!state.quote) return;
    if (state.closed) {
        $('#countdown').textContent = '订单已取消';
        updatePay();
        return;
    }
    const seconds = Math.max(0, Math.ceil(state.quote.expires_at - Date.now() / 1000));
    $('#countdown').textContent = seconds
        ? '请在 ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') + ' 内完成付款'
        : '付款已过期，请返回重新发起';
    if (state.qrcode && !seconds) {
        $('#native-code').hidden = true;
        $('#native-status').textContent = '付款二维码已过期；如已付款，请等待结果确认。';
    }
    updatePay();
}

async function checkPayment(manual = false) {
    if ((!state.waiting && !manual) || state.checking || !state.quote || state.quote.paid || state.closed || (!manual && document.hidden)) return;
    clearTimeout(state.pollTimer);
    state.checking = true;
    try {
        const response = await fetch(state.quote.status_url, {credentials: 'omit', cache: 'no-store', signal: AbortSignal.timeout(15000), referrerPolicy: 'no-referrer'});
        if (!response.ok) throw new Error('付款状态查询失败');
        const status = await response.json();
        state.pollFailures = 0;
        if (!handleStatus(status) && manual) {
            const message = '暂未确认到账，系统会继续查询。如已扣款，请勿重复付款。';
            if (state.qrcode) $('#native-status').textContent = message;
            else $('#payment-notice p').textContent = message;
        }
    } catch (error) {
        state.pollFailures++;
        if (manual) showError(new Error('暂时无法查询付款结果，系统会继续重试，请勿重复付款。'));
    } finally {
        state.checking = false;
        schedulePoll();
    }
}

function handleStatus(status) {
    if (status.refunded || status.paid) {
        state.quote.paid = true;
        clearTimeout(state.pollTimer);
        updatePay();
        location.replace(status.result_url);
        return true;
    }
    if (status.canceled) {
        state.closed = true;
        clearTimeout(state.pollTimer);
        $('#native-code').hidden = true;
        $('#native-status').textContent = '订单已取消，请重新发起付款。';
        $('#payment-notice').hidden = true;
        showError(new Error('订单已取消，请返回重新下单。'));
        updatePay();
        return true;
    }
    return false;
}

function schedulePoll() {
    clearTimeout(state.pollTimer);
    if (!state.waiting || !state.quote || state.quote.paid || state.closed || document.hidden) return;
    const expired = state.quote.expires_at <= Date.now() / 1000;
    const normalDelay = expired ? 10000 : Date.now() - state.paymentStartedAt < 60000 ? 2500 : 6000;
    const delay = state.pollFailures ? Math.min(30000, 2000 * 2 ** Math.min(state.pollFailures, 4)) : normalDelay;
    state.pollTimer = setTimeout(() => checkPayment(), delay + Math.floor(Math.random() * 700));
}

document.addEventListener('visibilitychange', () => {
    clearTimeout(state.pollTimer);
    if (!document.hidden) checkPayment();
});
setInterval(tick, 1000);
load();
