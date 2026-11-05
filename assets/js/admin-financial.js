/* Retry only after a server-side pre-operation payment-password challenge. */
(function ($) {
    'use strict';
    $.ajaxPrefilter(function (options) {
        if (!/ajax_(order|settle|transfer|user|profitsharing|pay)\.php(?:\?|$)/.test(options.url || '')) return;
        var success = options.success;
        options.success = function (data) {
            var context = this, args = arguments;
            if (data && data.code === -9 && !options.epayFinancialRetry) {
                layer.closeAll('loading');
                layer.prompt({title: '验证管理员支付密码', formType: 1}, function (password, index) {
                    $.ajax({type: 'POST', url: 'ajax_settle.php?act=paypwd_input', dataType: 'json', data: {paypwd: password},
                        success: function (reply) {
                            if (reply.code === 0) {
                                layer.close(index);
                                $.ajax($.extend({}, options, {epayFinancialRetry: true, success: success}));
                            } else layer.alert(reply.msg || '验证失败');
                        }
                    });
                });
                return;
            }
            if ($.isFunction(success)) success.apply(context, args);
        };
    });
})(jQuery);
