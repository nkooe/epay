'use strict';

(() => {
    const checkout = JSON.parse(document.querySelector('#wechat-checkout').textContent);
    const status = document.querySelector('#status');
    const fallback = document.querySelector('#fallback');
    let invoked = false;
    let polling = false;
    let finished = false;
    const deadline = Date.now() + 90000;
    const returnToCheckout = () => { finished = true; location.replace(checkout.checkout_url); };

    // A browser SDK success is only a hint; the gateway's verified callback confirms payment.
    async function checkPayment() {
        if (finished || polling) return;
        polling = true;
        try {
            const response = await fetch(checkout.query_url, {cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(10000)});
            if (!response.ok) throw new Error('Status unavailable');
            const result = await response.json();
            if (result.paid || result.refunded) {
                finished = true;
                location.replace(checkout.result_url);
                return;
            }
            if (result.canceled) { returnToCheckout(); return; }
        } catch (error) {
            status.textContent = '正在确认付款结果，请勿重复支付…';
        } finally {
            polling = false;
        }
        if (Date.now() < deadline && !finished) setTimeout(checkPayment, 2000);
        else { status.textContent = '付款结果仍在确认，请返回商品收银台查看。'; fallback.hidden = false; }
    }

    function launch() {
        if (invoked || finished || !window.WeixinJSBridge || typeof WeixinJSBridge.invoke !== 'function') return;
        invoked = true;
        clearTimeout(bridgeTimeout);
        try {
            WeixinJSBridge.invoke('getBrandWCPayRequest', checkout.parameters, result => {
                if ((result || {}).err_msg === 'get_brand_wcpay_request:ok') {
                    status.textContent = '正在确认付款结果…';
                    fallback.hidden = false;
                    checkPayment();
                } else {
                    returnToCheckout();
                }
            });
        } catch (error) {
            returnToCheckout();
        }
    }

    const bridgeTimeout = setTimeout(() => {
        if (!invoked) { status.textContent = '微信支付未能打开，请返回商品收银台重试。'; fallback.hidden = false; }
    }, 15000);
    document.addEventListener('WeixinJSBridgeReady', launch);
    window.addEventListener('pageshow', event => { if (event.persisted) returnToCheckout(); });
    launch();
})();
