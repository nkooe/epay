(function () {
    'use strict';
    var form = document.getElementById('testPaymentForm');
    if (!form) return;
    var amount = document.getElementById('testMoney');
    var amountError = document.getElementById('testAmountError');
    var message = document.getElementById('testPaymentMessage');
    var submit = document.getElementById('testPaymentSubmit');
    var methods = Array.from(form.querySelectorAll('input[name="typeid"]'));
    var captcha = document.getElementById('captcha');
    var retry = document.getElementById('testCaptchaRetry');
    var busy = false;
    var captchaReady = !captcha;
    var captchaResult = null;
    var captchaObject = null;
    var captchaTimer;
    var captchaGeneration = 0;
    var dependenciesReady = false;

    function showMessage(text) {
        message.textContent = text;
        message.hidden = !text;
    }
    function updateSubmit() {
        var value = amount.value.trim();
        var valid = /^\d+(?:\.\d{1,2})?$/.test(value) && Number.isFinite(Number(value)) && Number(value) > 0;
        submit.disabled = busy || methods.length === 0 || !captchaReady || !dependenciesReady;
        submit.textContent = busy ? '正在发起支付…' : '确认支付' + (valid ? ' ¥' + Number(value).toFixed(2) : '');
        amount.style.width = Math.min(Math.max(value.length, 4), 12) + 'ch';
        submit.setAttribute('aria-busy', String(busy));
        form.setAttribute('aria-busy', String(busy));
        amount.readOnly = busy;
        methods.forEach(function (method) { method.disabled = busy; });
    }
    function resetCaptcha() {
        captchaResult = null;
        if (captchaObject && typeof captchaObject.reset === 'function') captchaObject.reset();
    }
    amount.addEventListener('input', function () {
        amount.removeAttribute('aria-invalid');
        amountError.hidden = true;
        updateSubmit();
    });

    if (!window.jQuery) {
        showMessage('页面组件加载失败，请刷新后重试。');
        return;
    }
    var $ = window.jQuery;
    dependenciesReady = true;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (busy) return;
        showMessage('');
        var money = amount.value.trim();
        if (!/^\d+(?:\.\d{1,2})?$/.test(money) || !Number.isFinite(Number(money)) || Number(money) <= 0) {
            amountError.textContent = money ? '请输入大于 0 的金额，最多保留两位小数。' : '请输入付款金额。';
            amountError.hidden = false;
            amount.setAttribute('aria-invalid', 'true');
            amount.focus();
            return;
        }
        var selected = form.querySelector('input[name="typeid"]:checked');
        if (!selected) { showMessage('请选择可用的支付方式。'); return; }
        if (captcha && (!captchaReady || !captchaResult)) { showMessage('请先完成安全验证。'); return; }
        var payload = Object.assign({}, captchaResult || {}, {
            money: money,
            typeid: selected.value,
            csrf_token: form.querySelector('input[name="csrf_token"]').value
        });
        busy = true;
        updateSubmit();
        $.ajax({
            type: 'POST',
            dataType: 'json',
            data: payload,
            url: 'ajax.php?act=testpay',
            timeout: 20000,
            success: function (data) {
                if (data && data.code == 0 && typeof data.url === 'string' && data.url) {
                    window.location.assign(data.url);
                    return;
                }
                busy = false;
                updateSubmit();
                showMessage(data && data.msg ? data.msg : '创建订单失败，请稍后重试。');
                resetCaptcha();
            },
            error: function (xhr, status) {
                busy = false;
                updateSubmit();
                showMessage(status === 'timeout' ? '请求超时，请稍后核对订单后再试。' : '网络请求失败，请稍后重试。');
                resetCaptcha();
            }
        });
    });

    function loadCaptcha() {
        var generation = ++captchaGeneration;
        clearTimeout(captchaTimer);
        if (captchaObject && typeof captchaObject.destroy === 'function') captchaObject.destroy();
        captchaObject = null;
        captchaResult = null;
        captchaReady = false;
        retry.hidden = true;
        captcha.textContent = '';
        var loading = document.createElement('p');
        loading.id = 'captcha_text';
        loading.textContent = '正在加载验证码…';
        loading.setAttribute('role', 'status');
        captcha.appendChild(loading);
        updateSubmit();

        function failed() {
            if (generation !== captchaGeneration) return;
            clearTimeout(captchaTimer);
            captchaReady = false;
            captchaResult = null;
            loading.hidden = false;
            loading.textContent = '验证码加载失败，请重新加载。';
            retry.hidden = false;
            updateSubmit();
        }
        function embedded(object) {
            if (generation !== captchaGeneration) return;
            captchaObject = object;
            object.appendTo('#captcha');
            object.onReady(function () {
                if (generation !== captchaGeneration) return;
                clearTimeout(captchaTimer);
                loading.hidden = true;
                captchaReady = true;
                retry.hidden = true;
                updateSubmit();
            });
            object.onSuccess(function () {
                if (generation !== captchaGeneration) return;
                captchaResult = object.getValidate();
                if (captchaResult) showMessage('');
                else showMessage('请完成安全验证后再支付。');
            });
            if (typeof object.onError === 'function') object.onError(failed);
            if (typeof object.onClose === 'function') object.onClose(function () {
                if (generation === captchaGeneration) captchaResult = object.getValidate() || null;
            });
        }
        captchaTimer = setTimeout(failed, 20000);
        $.ajax({
            url: 'ajax.php?act=captcha',
            type: 'GET',
            cache: false,
            dataType: 'json',
            timeout: 15000,
            success: function (data) {
                if (generation !== captchaGeneration) return;
                try {
                    if (!data || !data.gt) { failed(); return; }
                    if (data.version == 1) {
                        if (typeof window.initGeetest4 !== 'function') { failed(); return; }
                        window.initGeetest4({captchaId: data.gt, product: 'popup', protocol: 'https://', riskType: 'ai', hideSuccess: true, nativeButton: {width: '100%'}}, embedded);
                    } else {
                        if (typeof window.initGeetest !== 'function') { failed(); return; }
                        window.initGeetest({gt: data.gt, challenge: data.challenge, new_captcha: data.new_captcha, product: 'popup', width: '100%', offline: !data.success}, embedded);
                    }
                } catch (error) { failed(); }
            },
            error: failed
        });
    }
    if (captcha) { retry.addEventListener('click', loadCaptcha); loadCaptcha(); }
    else updateSubmit();
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            busy = false;
            updateSubmit();
            resetCaptcha();
        }
    });
}());
