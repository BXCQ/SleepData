<?php
/**
 * 健康数据独立页（Handsome 主题）
 *
 * @package custom
 * @xuan
 * @version 2.1.0
 *
 * Template Name: 健康数据
 *
 * HealthData-health-stats@2.1.0
 *
 * 使用方法：
 * 1. 将本文件复制到 Handsome 主题根目录（启用插件时也会自动安装）
 * 2. 后台新建页面，自定义模板选择「健康数据」
 * 3. 在插件设置中勾选要公开展示的分类
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

$this->need('component/header.php');

$pluginUrl = rtrim(Helper::options()->siteUrl, '/') . '/usr/plugins/HealthData';
$publicApi = $pluginUrl . '/public-health-api.php';
?>

<script>
function loadScriptWithTimeout(src, timeout) {
    timeout = timeout || 2000;
    return new Promise(function (resolve, reject) {
        var timer = setTimeout(function () { reject(new Error('Timeout')); }, timeout);
        var script = document.createElement('script');
        script.src = src;
        script.onload = function () { clearTimeout(timer); resolve(); };
        script.onerror = function () { clearTimeout(timer); reject(new Error('Failed')); };
        document.head.appendChild(script);
    });
}
function loadEChartsWithFallback() {
    return loadScriptWithTimeout('https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js', 2000)
        .catch(function () {
            return loadScriptWithTimeout('<?php echo htmlspecialchars($pluginUrl); ?>/js/echarts.min.js', 3000);
        })
        .then(function () { window.echartsReady = true; })
        .catch(function () { window.echartsReady = false; });
}
loadEChartsWithFallback();
</script>

<?php $this->need('component/aside.php'); ?>

<a class="off-screen-toggle hide"></a>
<main class="app-content-body <?php echo Content::returnPageAnimateClass($this); ?>">
    <div class="hbox hbox-auto-xs hbox-auto-sm">
        <div class="col center-part gpu-speed" id="post-panel">
            <div class="wrapper-md">
                <div id="postpage" class="blog-post">
                    <article class="single-post panel">
                        <div id="post-content" class="wrapper-lg">
                            <div class="health-stats-container" id="healthStatsApp"
                                 data-api="<?php echo htmlspecialchars($publicApi); ?>">

                                <div class="hs-date-filter">
                                    <span class="hs-label">日期范围</span>
                                    <input type="date" id="hsStart">
                                    <span>—</span>
                                    <input type="date" id="hsEnd">
                                    <button type="button" id="hsFilterBtn" class="hs-btn primary">查询</button>
                                    <button type="button" class="hs-btn" data-range="7">近7天</button>
                                    <button type="button" class="hs-btn" data-range="30">近30天</button>
                                    <button type="button" class="hs-btn" data-range="90">近90天</button>
                                </div>

                                <p id="hsStatus" class="hs-status">正在加载健康数据…</p>

                                <div class="hs-summary" id="hsSummary"></div>

                                <div class="hs-chart-card">
                                    <div class="hs-chart-head">
                                        <h3>趋势</h3>
                                        <div class="hs-chart-tabs">
                                            <button type="button" class="hs-tab active" data-chart="steps">步数</button>
                                            <button type="button" class="hs-tab" data-chart="sleep">睡眠</button>
                                            <button type="button" class="hs-tab" data-chart="heart">心率</button>
                                            <button type="button" class="hs-tab" data-chart="calories">活动热量</button>
                                        </div>
                                    </div>
                                    <div id="hsChart" class="hs-chart"></div>
                                </div>

                                <div class="hs-chart-card">
                                    <h3>睡眠结构（深睡 / 核心 / REM）</h3>
                                    <div id="hsSleepStack" class="hs-chart"></div>
                                </div>

                                <div class="hs-list-card">
                                    <h3>日明细</h3>
                                    <div id="hsDayList" class="hs-day-list"></div>
                                </div>

                                <p class="hs-footnote">
                                    数据来自 Health.md 导出；睡眠评分可能为估算值。
                                    展示分类由插件后台「独立页公开展示」控制。
                                </p>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </div>
        <?php $this->need('component/sidebar.php'); ?>
    </div>
</main>

<style>
.health-stats-container { max-width: 960px; margin: 0 auto; }
.hs-date-filter {
    display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
    padding: 12px 14px; background: #f7f8fa; border-radius: 8px; margin-bottom: 14px;
}
.hs-label { font-weight: 600; color: #555; margin-right: 4px; }
.hs-date-filter input[type="date"] {
    border: 1px solid #ddd; border-radius: 6px; padding: 4px 8px; background: #fff;
}
.hs-btn {
    border: 1px solid #d0d5dd; background: #fff; border-radius: 6px;
    padding: 5px 10px; cursor: pointer; font-size: 13px;
}
.hs-btn.primary { background: #1c65d7; color: #fff; border-color: #1c65d7; }
.hs-btn.active { background: #e8f0fe; border-color: #1c65d7; color: #1c65d7; }
.hs-status { color: #666; font-size: 13px; margin: 0 0 12px; }
.hs-summary {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px;
}
.hs-card {
    background: #fff; border: 1px solid #eee; border-radius: 10px; padding: 14px 12px;
    text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.hs-card .n { font-size: 22px; font-weight: 700; color: #1c65d7; line-height: 1.2; }
.hs-card .t { font-size: 12px; color: #888; margin-top: 4px; }
.hs-chart-card {
    background: #fff; border: 1px solid #eee; border-radius: 10px;
    padding: 14px; margin-bottom: 16px;
}
.hs-chart-card h3 { margin: 0 0 10px; font-size: 16px; }
.hs-chart-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.hs-chart-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
.hs-tab {
    border: 1px solid #ddd; background: #fafafa; border-radius: 999px;
    padding: 3px 10px; font-size: 12px; cursor: pointer;
}
.hs-tab.active { background: #1c65d7; color: #fff; border-color: #1c65d7; }
.hs-chart { width: 100%; height: 320px; }
.hs-list-card { background: #fff; border: 1px solid #eee; border-radius: 10px; padding: 14px; }
.hs-list-card h3 { margin: 0 0 10px; font-size: 16px; }
.hs-day {
    border-top: 1px solid #f0f0f0; padding: 12px 0;
}
.hs-day:first-child { border-top: 0; }
.hs-day-head {
    display: flex; justify-content: space-between; align-items: center;
    cursor: pointer; gap: 8px;
}
.hs-day-head strong { font-size: 15px; }
.hs-day-meta { color: #666; font-size: 13px; }
.hs-day-body { display: none; margin-top: 10px; font-size: 13px; color: #444; }
.hs-day.open .hs-day-body { display: block; }
.hs-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 16px;
}
.hs-workout {
    margin-top: 8px; padding: 8px 10px; background: #f7f8fa; border-radius: 6px;
}
.hs-footnote { margin-top: 16px; font-size: 12px; color: #999; }
.dark .hs-date-filter, .theme-dark .hs-date-filter { background: #1f1f1f; }
.dark .hs-card, .dark .hs-chart-card, .dark .hs-list-card,
.theme-dark .hs-card, .theme-dark .hs-chart-card, .theme-dark .hs-list-card {
    background: #1a1a1a; border-color: #333;
}
.dark .hs-btn, .theme-dark .hs-btn { background: #222; color: #ddd; border-color: #444; }
.dark .hs-day-meta, .theme-dark .hs-day-meta { color: #aaa; }
@media (max-width: 768px) {
    .hs-summary { grid-template-columns: repeat(2, 1fr); }
    .hs-chart { height: 260px; }
    .hs-grid { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    var app = document.getElementById('healthStatsApp');
    if (!app) return;
    var API = app.getAttribute('data-api');
    var state = { items: [], enabled: [], chartType: 'steps', chart: null, sleepChart: null };

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function fmtDate(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    function fmtMin(m) {
        if (m == null || isNaN(m)) return '—';
        m = Math.round(m);
        var h = Math.floor(m / 60), r = m % 60;
        if (h <= 0) return r + '分';
        return r ? (h + '小时' + r + '分') : (h + '小时');
    }
    function setRangeDays(n) {
        var end = new Date();
        var start = new Date();
        start.setDate(end.getDate() - (n - 1));
        document.getElementById('hsStart').value = fmtDate(start);
        document.getElementById('hsEnd').value = fmtDate(end);
        document.querySelectorAll('.hs-btn[data-range]').forEach(function (b) {
            b.classList.toggle('active', String(b.getAttribute('data-range')) === String(n));
        });
    }
    function enabledHas(cat) {
        return state.enabled.indexOf(cat) !== -1;
    }

    function renderSummary(summary) {
        var el = document.getElementById('hsSummary');
        var cards = [];
        if (enabledHas('activity')) {
            cards.push({ t: '平均步数', n: summary.avg_steps != null ? summary.avg_steps : '—' });
        }
        if (enabledHas('sleep')) {
            cards.push({ t: '平均睡眠', n: fmtMin(summary.avg_sleep_minutes) });
        }
        if (enabledHas('heart')) {
            cards.push({
                t: '平均静息心率',
                n: summary.avg_resting_heart_rate != null ? (summary.avg_resting_heart_rate + ' bpm') : '—'
            });
        }
        if (enabledHas('workouts')) {
            cards.push({ t: '锻炼次数', n: summary.workout_count != null ? summary.workout_count : 0 });
        }
        if (!cards.length) {
            el.innerHTML = '<p class="hs-status">当前未勾选可公开展示的分类，请到插件设置中开启。</p>';
            return;
        }
        el.innerHTML = cards.map(function (c) {
            return '<div class="hs-card"><div class="n">' + c.n + '</div><div class="t">' + c.t + '</div></div>';
        }).join('');
    }

    function waitEcharts(cb) {
        var tries = 0;
        (function tick() {
            if (typeof echarts !== 'undefined') return cb();
            if (++tries > 40) {
                document.getElementById('hsStatus').textContent = '图表库加载失败，列表数据仍可用。';
                return cb(false);
            }
            setTimeout(tick, 100);
        })();
    }

    function seriesFor(type) {
        var dates = [], values = [], name = '';
        state.items.forEach(function (it) {
            dates.push(it.date);
            if (type === 'steps') {
                values.push(it.steps != null ? it.steps : null);
                name = '步数';
            } else if (type === 'sleep') {
                var sm = it.sleep_total_minutes;
                if (sm == null && it.sleep) sm = it.sleep.total_sleep_minutes;
                values.push(sm != null ? +(sm / 60).toFixed(2) : null);
                name = '睡眠(小时)';
            } else if (type === 'heart') {
                values.push(it.resting_heart_rate != null ? it.resting_heart_rate : null);
                name = '静息心率';
            } else if (type === 'calories') {
                values.push(it.active_calories != null ? Math.round(it.active_calories) : null);
                name = '活动热量';
            }
        });
        return { dates: dates, values: values, name: name };
    }

    function renderMainChart() {
        if (typeof echarts === 'undefined') return;
        var el = document.getElementById('hsChart');
        if (!state.chart) state.chart = echarts.init(el);
        var s = seriesFor(state.chartType);
        state.chart.setOption({
            tooltip: { trigger: 'axis' },
            grid: { left: 48, right: 20, top: 30, bottom: 40 },
            xAxis: { type: 'category', data: s.dates, axisLabel: { rotate: 40, fontSize: 10 } },
            yAxis: { type: 'value', scale: true },
            series: [{
                name: s.name,
                type: 'line',
                smooth: true,
                showSymbol: s.dates.length < 40,
                data: s.values,
                areaStyle: { opacity: 0.08 },
                lineStyle: { width: 2, color: '#1c65d7' },
                itemStyle: { color: '#1c65d7' }
            }]
        }, true);
    }

    function renderSleepStack() {
        if (typeof echarts === 'undefined' || !enabledHas('sleep')) {
            document.getElementById('hsSleepStack').parentElement.style.display = 'none';
            return;
        }
        document.getElementById('hsSleepStack').parentElement.style.display = '';
        var el = document.getElementById('hsSleepStack');
        if (!state.sleepChart) state.sleepChart = echarts.init(el);
        var dates = [], deep = [], core = [], rem = [];
        state.items.forEach(function (it) {
            dates.push(it.date);
            var sl = it.sleep || {};
            deep.push(sl.deep_sleep_minutes != null ? +(sl.deep_sleep_minutes / 60).toFixed(2) : (it.sleep_deep_minutes != null ? +(it.sleep_deep_minutes / 60).toFixed(2) : null));
            core.push(sl.light_sleep_minutes != null ? +(sl.light_sleep_minutes / 60).toFixed(2) : (it.sleep_core_minutes != null ? +(it.sleep_core_minutes / 60).toFixed(2) : null));
            rem.push(sl.rem_sleep_minutes != null ? +(sl.rem_sleep_minutes / 60).toFixed(2) : (it.sleep_rem_minutes != null ? +(it.sleep_rem_minutes / 60).toFixed(2) : null));
        });
        state.sleepChart.setOption({
            tooltip: { trigger: 'axis' },
            legend: { data: ['深睡', '核心/浅睡', 'REM'] },
            grid: { left: 48, right: 20, top: 40, bottom: 40 },
            xAxis: { type: 'category', data: dates, axisLabel: { rotate: 40, fontSize: 10 } },
            yAxis: { type: 'value', name: '小时' },
            series: [
                { name: '深睡', type: 'bar', stack: 's', data: deep, color: '#3b5bdb' },
                { name: '核心/浅睡', type: 'bar', stack: 's', data: core, color: '#748ffc' },
                { name: 'REM', type: 'bar', stack: 's', data: rem, color: '#91a7ff' }
            ]
        }, true);
    }

    function renderList() {
        var box = document.getElementById('hsDayList');
        if (!state.items.length) {
            box.innerHTML = '<p class="hs-status">该范围内暂无数据。</p>';
            return;
        }
        var html = state.items.slice().reverse().map(function (it) {
            var meta = [];
            if (enabledHas('activity') && it.steps != null) meta.push(it.steps + ' 步');
            if (enabledHas('sleep')) {
                var sm = it.sleep_total_minutes || (it.sleep && it.sleep.total_sleep_minutes);
                if (sm != null) meta.push('睡 ' + fmtMin(sm));
                if (it.sleep && it.sleep.sleep_score != null) meta.push('分 ' + it.sleep.sleep_score);
            }
            if (enabledHas('heart') && it.resting_heart_rate != null) meta.push('静息 ' + Math.round(it.resting_heart_rate));
            if (enabledHas('workouts') && it.workout_count) meta.push('锻炼 ' + it.workout_count);

            var details = [];
            if (it.sleep) {
                details.push('<div><strong>睡眠</strong> ' +
                    (it.sleep.sleep_time || '—') + ' → ' + (it.sleep.wake_up_time || '—') +
                    ' · 深睡 ' + fmtMin(it.sleep.deep_sleep_minutes) +
                    ' · 核心 ' + fmtMin(it.sleep.light_sleep_minutes) +
                    ' · REM ' + fmtMin(it.sleep.rem_sleep_minutes) +
                    (it.sleep.sleep_score != null ? (' · 评分 ' + it.sleep.sleep_score + (it.sleep.score_estimated ? '（估）' : '')) : '') +
                    '</div>');
            }
            if (enabledHas('activity')) {
                details.push('<div><strong>活动</strong> 步数 ' + (it.steps != null ? it.steps : '—') +
                    ' · 活动热量 ' + (it.active_calories != null ? Math.round(it.active_calories) + ' kcal' : '—') +
                    (it.distance_km != null ? (' · ' + Number(it.distance_km).toFixed(2) + ' km') : '') +
                    (it.flights_climbed != null ? (' · 爬楼 ' + it.flights_climbed) : '') +
                    '</div>');
            }
            if (enabledHas('heart')) {
                details.push('<div><strong>心率</strong> 静息 ' + (it.resting_heart_rate != null ? Math.round(it.resting_heart_rate) : '—') +
                    ' · 平均 ' + (it.average_heart_rate != null ? Math.round(it.average_heart_rate) : '—') +
                    (it.heart_rate_min != null ? (' · 区间 ' + Math.round(it.heart_rate_min) + '-' + Math.round(it.heart_rate_max || it.heart_rate_min)) : '') +
                    '</div>');
            }
            if (enabledHas('vitals') && it.respiratory_rate != null) {
                details.push('<div><strong>呼吸</strong> ' + Number(it.respiratory_rate).toFixed(1) + ' 次/分</div>');
            }
            if (it.workouts && it.workouts.length) {
                details.push('<div><strong>锻炼</strong></div>' + it.workouts.map(function (w) {
                    return '<div class="hs-workout">' +
                        (w.name || w.type || '锻炼') +
                        (w.duration_formatted ? (' · ' + w.duration_formatted) : '') +
                        (w.calories != null ? (' · ' + Math.round(w.calories) + ' kcal') : '') +
                        (w.start_time ? (' · ' + w.start_time) : '') +
                        '</div>';
                }).join(''));
            }

            return '<div class="hs-day">' +
                '<div class="hs-day-head"><strong>' + it.date + '</strong><span class="hs-day-meta">' + meta.join(' · ') + '</span></div>' +
                '<div class="hs-day-body"><div class="hs-grid">' + details.join('') + '</div></div>' +
                '</div>';
        }).join('');
        box.innerHTML = html;
        box.querySelectorAll('.hs-day-head').forEach(function (head) {
            head.addEventListener('click', function () {
                head.parentElement.classList.toggle('open');
            });
        });
    }

    function loadData() {
        var start = document.getElementById('hsStart').value;
        var end = document.getElementById('hsEnd').value;
        var status = document.getElementById('hsStatus');
        status.textContent = '加载中…';
        var url = API + '?start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    status.textContent = (data && data.message) ? data.message : '加载失败';
                    return;
                }
                state.items = data.items || [];
                state.enabled = data.enabled || [];
                status.textContent = '共 ' + (data.count || 0) + ' 天有数据' +
                    (data.range && data.range.start ? ('（' + data.range.start + ' ~ ' + data.range.end + '）') : '');
                renderSummary(data.summary || {});
                waitEcharts(function () {
                    renderMainChart();
                    renderSleepStack();
                    window.addEventListener('resize', function () {
                        if (state.chart) state.chart.resize();
                        if (state.sleepChart) state.sleepChart.resize();
                    });
                });
                renderList();

                // 按 enabled 隐藏不可用的趋势 tab
                document.querySelectorAll('.hs-tab').forEach(function (tab) {
                    var t = tab.getAttribute('data-chart');
                    var show = true;
                    if (t === 'steps' || t === 'calories') show = enabledHas('activity');
                    if (t === 'sleep') show = enabledHas('sleep');
                    if (t === 'heart') show = enabledHas('heart');
                    tab.style.display = show ? '' : 'none';
                });
            })
            .catch(function (e) {
                status.textContent = '请求失败：' + e.message;
            });
    }

    document.getElementById('hsFilterBtn').addEventListener('click', loadData);
    document.querySelectorAll('.hs-btn[data-range]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setRangeDays(parseInt(btn.getAttribute('data-range'), 10));
            loadData();
        });
    });
    document.querySelectorAll('.hs-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.hs-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            state.chartType = tab.getAttribute('data-chart');
            renderMainChart();
        });
    });

    setRangeDays(30);
    loadData();
})();
</script>

<?php $this->need('component/footer.php'); ?>
