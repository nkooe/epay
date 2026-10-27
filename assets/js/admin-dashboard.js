(function ($) {
    'use strict';
    var chart, loading = false, hasData = false;
    var today = $('.admin-dashboard').attr('data-today');

    function number(value) {
        var result = Number(value);
        return isFinite(result) ? result : 0;
    }
    function money(value) {
        return number(value).toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function rows(data) {
        var history = [], day = new Date(today + 'T12:00:00Z');
        for (var offset = 6; offset >= 0; offset--) {
            var date = new Date(day.getTime() - offset * 86400000).toISOString().slice(0, 10);
            history.push({date: date, values: offset === 0 ? data.order_today : (data.order || {})[date.replace(/-/g, '')] || null});
        }
        return history;
    }
    function renderTable(prefix, columns, list, key, total) {
        var head = $('#' + prefix + '_head').empty().append($('<th scope="col">').text('日期'));
        Object.keys(columns || {}).forEach(function (id) {
            head.append($('<th scope="col">').text(columns[id]));
        });
        head.append($('<th scope="col">').text('合计（元）'));
        var body = $('#' + prefix + '_list').empty();
        list.slice().reverse().forEach(function (row) {
            var tr = $('<tr>');
            tr.append($('<td>').text(row.date === today ? row.date + ' · 今日' : row.date));
            Object.keys(columns || {}).forEach(function (id) {
                tr.append($('<td>').text(row.values ? money((row.values[key] || {})[id]) : '—'));
            });
            tr.append($('<td class="statistics-total">').text(row.values ? money(row.values[total]) : '暂无统计'));
            body.append(tr);
        });
    }
    function renderTrend(list) {
        if (typeof Chart === 'undefined') {
            $('#trend-state').text('趋势图暂不可用，可在下方明细查看成交金额。').prop('hidden', false);
            return;
        }
        $('#trend-state').prop('hidden', true);
        $('#trend-scroll').prop('hidden', false);
        var amounts = list.map(function (row) { return row.values ? number(row.values.all) : null; });
        var available = amounts.filter(function (value) { return value !== null; });
        if (!available.length) {
            $('#trend-state').text('暂无成交统计，请刷新重试。').prop('hidden', false);
            $('#trend-scroll').prop('hidden', true);
            return;
        }
        var min = Math.min.apply(null, available), max = Math.max.apply(null, available);
        var padding = Math.max((max - min) * 0.18, max * 0.02, 1);
        var labels = list.map(function (row) { return row.date.slice(5).replace('-', '/'); });
        var dataset = {label: '实付成交金额', data: amounts, borderColor: '#078744', backgroundColor: 'rgba(7,193,96,0.07)', borderWidth: 2.5, fill: true, tension: 0.4, cubicInterpolationMode: 'monotone', pointRadius: 3, pointHoverRadius: 5, pointHitRadius: 22, pointBackgroundColor: '#ffffff', pointBorderWidth: 2};
        if (chart) {
            chart.data.labels = labels;
            chart.data.datasets = [dataset];
            chart.options.scales.y.min = Math.max(0, min - padding);
            chart.options.scales.y.max = max + padding;
            chart.update();
        } else {
            chart = new Chart(document.getElementById('transaction-trend'), {
                type: 'line', data: {labels: labels, datasets: [dataset]},
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : {duration: 220},
                    interaction: {intersect: false, mode: 'index'},
                    plugins: {legend: {display: false}, tooltip: {backgroundColor: '#26332d', padding: 12, displayColors: false, callbacks: {title: function (items) {return list[items[0].dataIndex].date;}, label: function (item) {return '成交金额  ¥' + money(item.parsed.y);}}}},
                    scales: {x: {grid: {display: false}, border: {display: false}, ticks: {color: '#6b6b6b', font: {size: 12}, autoSkip: false, maxRotation: 0, padding: 10}}, y: {min: Math.max(0, min - padding), max: max + padding, border: {display: false}, grid: {color: '#eff1ef', drawTicks: false}, ticks: {color: '#6b6b6b', font: {size: 11}, maxTicksLimit: 5, padding: 12, callback: function (value) {return number(value).toLocaleString('zh-CN', {maximumFractionDigits: 2});}}}}
                }
            });
        }
        chart.options.plugins.tooltip.callbacks.title = function (items) {return list[items[0].dataIndex].date;};
        $('#transaction-trend').attr('aria-label', list.map(function (row) {return row.date + '：' + (row.values ? money(row.values.all) + '元' : '暂无统计');}).join('；'));
    }
    function fail(message) {
        $('#dashboard-error').text(message + '，请点击“刷新数据”重试。').prop('hidden', false);
        if (!hasData) {
            $('#trend-state').text('数据加载失败，请刷新重试。');
            $('#amount-comparison').text('数据暂不可用');
        }
    }
    window.getData = function (fresh) {
        if (loading) return;
        loading = true;
        $('#dashboard-refresh').prop('disabled', true).attr('aria-busy', 'true');
        $('#dashboard-overview').attr('aria-busy', 'true');
        $.ajax({url: 'ajax.php?act=getcount' + (fresh ? '&getnew=1' : ''), dataType: 'json', cache: false, timeout: 30000})
            .done(function (data) {
                if (!data || data.code !== 0 || !data.order_today) {fail((data && data.msg) || '统计数据获取失败'); return;}
                hasData = true;
                today = data.today || today;
                $('#dashboard-error').prop('hidden', true);
                $('#count1').text(number(data.count1).toLocaleString('zh-CN'));
                $('#count2').text(number(data.count2).toLocaleString('zh-CN'));
                $('#usermoney').text(money(data.usermoney));
                $('#settlemoney').text(money(data.settlemoney));
                $('#success_rate').text(number(data.success_rate));
                $('#today-amount').text(money(data.order_today.all));
                $('#today-profit').text(money(data.order_today.profit_all));
                var list = rows(data), previousKey = list[list.length - 2].date.replace(/-/g, '');
                var previous = (data.order || {})[previousKey];
                var comparison = '昨日暂无统计';
                if (previous) {
                    var change = (number(data.order_today.all) / number(previous.all) - 1) * 100;
                    comparison = number(previous.all) > 0 ? '较昨日 ' + (change >= 0 ? '+' : '') + change.toFixed(1) + '%' : '昨日成交 ¥' + money(previous.all);
                }
                $('#amount-comparison').text(comparison);
                renderTable('paytype', data.paytype, list, 'paytype', 'all');
                renderTable('channel', data.channel, list, 'channel', 'all');
                renderTable('profit_paytype', data.paytype, list, 'profit_paytype', 'profit_all');
                renderTrend(list);
                $('#dashboard-updated').text('更新于 ' + new Date().toLocaleTimeString('zh-CN', {hour12: false}));
            })
            .fail(function () {fail('统计数据加载失败');})
            .always(function () {
                loading = false;
                $('#dashboard-refresh').prop('disabled', false).attr('aria-busy', 'false');
                $('#dashboard-overview').attr('aria-busy', 'false');
            });
    };
    $(function () {
        $('#dashboard-refresh').on('click', function () {window.getData(true);});
        window.getData(false);
    });
}(jQuery));
