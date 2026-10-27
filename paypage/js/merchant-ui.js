(function () {
    'use strict';
    var modal = document.getElementById('myModal');
    var input = document.getElementById('remarkInput');
    var content = document.getElementById('remark-content');
    var actions = document.querySelector('.remark_operate');
    var count = document.getElementById('remarkCount');
    var lastFocus;
    function closeRemark() {
        modal.hidden = true;
        if (lastFocus) lastFocus.focus();
    }
    function openRemark(event) {
        event.preventDefault();
        lastFocus = event.currentTarget;
        input.value = content.textContent;
        document.getElementById('remarkTitle').textContent = input.value ? '编辑备注' : '添加备注';
        count.textContent = input.value.length + ' / 30';
        modal.hidden = false;
        input.focus();
    }
    document.querySelector('.remark_add').addEventListener('click', openRemark);
    document.querySelector('.remark_edit').addEventListener('click', openRemark);
    document.querySelector('.remark_clear_away').addEventListener('click', function (event) {
        event.preventDefault();
        content.textContent = '';
        input.value = '';
        actions.classList.remove('yes');
        document.querySelector('.remark_add').focus();
    });
    document.getElementById('modal-close').addEventListener('click', closeRemark);
    document.getElementById('remarkSave').addEventListener('click', function () {
        var value = input.value.trim().slice(0, 30);
        content.textContent = value;
        actions.classList.toggle('yes', value.length > 0);
        lastFocus = document.querySelector(value ? '.remark_edit' : '.remark_add');
        closeRemark();
    });
    input.addEventListener('input', function () { count.textContent = input.value.length + ' / 30'; });
    modal.addEventListener('click', function (event) { if (event.target === modal) closeRemark(); });
    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { event.preventDefault(); closeRemark(); }
        if (event.key !== 'Tab') return;
        var first = document.getElementById('modal-close');
        var last = document.getElementById('remarkSave');
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    var keyboardRoot = document.getElementById('keyboard');
    var payButton = document.getElementById('payBtn');
    var clearButton = document.getElementById('clearBtn');
    // Pointer taps are handled by the existing payment script's Hammer bindings.
    // Native keyboard/screen-reader clicks have detail=0 and need the same handlers.
    keyboardRoot.addEventListener('click', function (event) {
        if (event.detail === 0 && event.target.hasAttribute('data-value') && typeof keypress === 'function') keypress(event);
    });
    payButton.addEventListener('click', function (event) { if (event.detail === 0 && typeof submitFun === 'function') submitFun(); });
    clearButton.addEventListener('click', function (event) { if (event.detail === 0 && typeof clearFun === 'function') clearFun(); });
    document.addEventListener('keydown', function (event) {
        if (!modal.hidden || event.ctrlKey || event.metaKey || event.altKey || keyboardRoot.hidden) return;
        if (/INPUT|TEXTAREA|SELECT/.test(event.target.tagName)) return;
        var value = /^\d$/.test(event.key) ? event.key : event.key === '.' ? 'dot' : event.key === 'Backspace' ? 'delete' : null;
        if (value && typeof keypress === 'function') {
            var key = keyboardRoot.querySelector('[data-value="' + value + '"]');
            keypress({ target: key, preventDefault: function () { event.preventDefault(); } });
        }
    });
    function updatePaymentState() {
        var busy = document.querySelector('.circle-box:not(.none)') !== null;
        var disabled = payButton.classList.contains('disable') || busy;
        payButton.setAttribute('aria-disabled', String(disabled));
        payButton.disabled = disabled;
        payButton.textContent = busy ? '正在发起支付…' : '确认支付';
        document.querySelector('.merchant-card').setAttribute('aria-busy', String(busy));
    }
    new MutationObserver(updatePaymentState).observe(document.body, {subtree: true, attributes: true, attributeFilter: ['class']});
    updatePaymentState();
    if (!document.getElementById('txAmount')) clearButton.classList.add('none');
}());
