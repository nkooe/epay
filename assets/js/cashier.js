(function () {
    'use strict';
    var form = document.getElementById('checkoutForm');
    var button = document.getElementById('checkoutSubmit');
    if (!form || !button) return;
    var original = button.innerHTML;
    var pending = false;
    form.addEventListener('submit', function (event) {
        if (pending || !form.querySelector('input[name="typeid"]:checked')) {
            event.preventDefault();
            return;
        }
        pending = true;
        button.disabled = true;
        button.textContent = '正在前往支付…';
        form.setAttribute('aria-busy', 'true');
    });
    window.addEventListener('pageshow', function () {
        pending = false;
        button.innerHTML = original;
        button.disabled = !form.querySelector('input[name="typeid"]:checked');
        form.removeAttribute('aria-busy');
    });
}());
