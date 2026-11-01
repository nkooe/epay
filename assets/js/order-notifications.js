document.addEventListener('DOMContentLoaded', function () {
(function (window, $) {
    'use strict';
    var states = {unrecorded:'暂无发送记录', not_required:'无需商户回调', sending:'发送中 / 结果待确认', succeeded:'商户已确认', failed:'发送失败', unknown:'接收结果未确认', exhausted:'自动重试已结束'};
    var sources = {payment:'支付完成', cron:'自动重试', cron_exhausted:'失败重发', admin:'管理员补发', merchant:'商户补发', app:'App 补发'};
    function escape(value) { return $('<span>').text(value == null ? '' : String(value)).html(); }
    function summary(data, trade) {
        if (!data) return '';
        var html = '<div class="order-notify-summary"><span>'+escape(states[data.state] || data.state)+'</span><span class="order-notify-muted">累计 '+Number(data.attempts)+' 次</span>';
        html += '<button type="button" class="btn btn-xs btn-default order-notify-history" data-trade="'+escape(trade)+'">发送记录</button></div>';
        if (data.next_retry_at) html += '<div class="order-notify-muted">下次重试 '+escape(data.next_retry_at)+'</div>';
        if (data.last_error) html += '<div class="order-notify-error">'+escape(data.last_error)+'</div>';
        return html;
    }
    function open(trade) {
        var root;
        var active = true;
        var index = layer.open({type:1, title:'回调发送记录', resize:false, area:[Math.min(520, window.innerWidth - 24)+'px'],
            content:'<div class="order-notify-panel"><div class="order-notify-items"></div><div class="order-notify-footer"></div></div>',
            success:function (layerNode, layerIndex) { root=layerNode.find('.order-notify-panel'); index=layerIndex; load(0); },
            end:function () { active = false; $(window).off('resize.orderNotify'+index); }});
        function fit() {
            if (!active) return;
            var titleHeight = $('#layui-layer'+index+' .layui-layer-title').outerHeight() || 42;
            var contentHeight = Math.min(root[0].scrollHeight, Math.floor(window.innerHeight * 0.65));
            var height = contentHeight + titleHeight;
            var dialog = $('#layui-layer'+index);
            dialog.css({height:height+'px', top:Math.max(12, (window.innerHeight-height)/2)+'px'});
            dialog.children('.layui-layer-content').css('height',contentHeight+'px');
        }
        $(window).on('resize.orderNotify'+index, fit);
        function load(before) {
            var footer = root.find('.order-notify-footer').empty().text('正在读取…');
            fit();
            $.getJSON(window.orderNotifyHistoryUrl, {trade_no:trade, before:before || 0}).done(function (response) {
                if (!active) return;
                footer.empty();
                if (response.code !== 0) { footer.text(response.msg || '读取失败'); fit(); return; }
                var data = response.data;
                if (!before && !data.history.length) root.find('.order-notify-items').text('暂无发送记录，历史订单不会补造明细。');
                data.history.forEach(function (row) {
                    var item = $('<section class="order-notify-entry">');
                    item.append($('<div class="order-notify-entry-head">').append($('<strong>').text(states[row.status] || row.status), $('<span>').text(sources[row.source] || row.source)));
                    item.append($('<div class="order-notify-muted">').text(row.started_at));
                    var meta = [];
                    if (row.http_status) meta.push('HTTP '+row.http_status);
                    if (row.duration_ms != null) meta.push(row.duration_ms+' ms');
                    if (meta.length) item.append($('<div class="order-notify-muted">').text(meta.join(' · ')));
                    if (row.error_summary) item.append($('<div class="order-notify-error">').text(row.error_summary));
                    root.find('.order-notify-items').append(item);
                });
                if (data.has_more) $('<button type="button" class="btn btn-sm btn-default">').text('加载更早记录').appendTo(footer).on('click', function () { load(data.next_cursor); });
                fit();
            }).fail(function () {
                if (!active) return;
                footer.empty().text('读取失败 ');
                $('<button type="button" class="btn btn-xs btn-default">').text('重试').appendTo(footer).on('click', function () { load(before); });
                fit();
            });
        }
    }
    $(document).on('click', '.order-notify-history', function () { open($(this).attr('data-trade')); });
    window.OrderNotifications = {summary:summary, open:open};
})(window, jQuery);
});
