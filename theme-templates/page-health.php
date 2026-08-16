<?php
/**
 * 健康数据
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/* HealthData-page-health@2.1.0 */

$pluginOk = class_exists('HealthData_Plugin');
$highlights = [];
$latest = null;
$chartPayload = [
    'dates' => [],
    'steps' => [],
    'active_calories' => [],
    'resting_heart_rate' => [],
    'hrv' => [],
    'sleep_minutes' => [],
];

if ($pluginOk) {
    $highlights = HealthData_Plugin::getHealthHighlights(30);
    $latest = HealthData_Plugin::getLatestHealthData();
    if (!$latest && !empty($highlights[0])) {
        $latest = $highlights[0];
    }
    $chrono = array_reverse($highlights);
    foreach ($chrono as $row) {
        $chartPayload['dates'][] = isset($row['date']) ? substr((string) $row['date'], 5) : '';
        $chartPayload['steps'][] = isset($row['steps']) ? (int) $row['steps'] : null;
        $chartPayload['active_calories'][] = isset($row['active_calories']) ? (float) $row['active_calories'] : null;
        $chartPayload['resting_heart_rate'][] = isset($row['resting_heart_rate']) ? (float) $row['resting_heart_rate'] : null;
        $chartPayload['hrv'][] = isset($row['hrv']) ? (float) $row['hrv'] : null;
        $chartPayload['sleep_minutes'][] = isset($row['sleep_total_minutes']) ? (int) $row['sleep_total_minutes'] : null;
    }
}

function healthPage_fmtNum($v, $digits = 0)
{
    if ($v === null || $v === '') {
        return '—';
    }
    if (is_numeric($v)) {
        return $digits > 0 ? number_format((float) $v, $digits) : number_format((float) $v, 0);
    }
    return htmlspecialchars((string) $v);
}

function healthPage_fmtSleep($minutes)
{
    $minutes = (int) $minutes;
    if ($minutes <= 0) {
        return '—';
    }
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) {
        return $h . 'h ' . $m . 'm';
    }
    if ($h > 0) {
        return $h . 'h';
    }
    return $m . 'm';
}

// Handsome 使用 component/header；其它主题回退 header.php
if (file_exists(__DIR__ . '/component/header.php')) {
    $this->need('component/header.php');
} else {
    $this->need('header.php');
}
?>

<style>
.hd-health-page {
  --hd-ink: #1a2e28;
  --hd-muted: #5c726a;
  --hd-line: rgba(26, 46, 40, 0.12);
  --hd-accent: #0f7a5f;
  --hd-accent-2: #c45c26;
  --hd-panel: rgba(255, 255, 255, 0.72);
  --hd-soft: linear-gradient(165deg, #e8f2ee 0%, #f3efe6 48%, #eef4f1 100%);
  margin: -1rem -1.25rem 0;
  padding: 1.5rem 1.25rem 2.5rem;
  background: var(--hd-soft);
  color: var(--hd-ink);
  border-radius: 0 0 12px 12px;
}
.hd-health-page * { box-sizing: border-box; }
.hd-health-hero {
  max-width: 960px;
  margin: 0 auto 1.75rem;
}
.hd-health-brand {
  font-family: "Source Han Serif SC", "Noto Serif SC", "Songti SC", Georgia, serif;
  font-size: clamp(1.85rem, 4vw, 2.6rem);
  font-weight: 700;
  letter-spacing: 0.02em;
  margin: 0 0 0.4rem;
  line-height: 1.15;
}
.hd-health-lead {
  margin: 0;
  color: var(--hd-muted);
  font-size: 0.98rem;
  max-width: 36em;
}
.hd-health-grid {
  max-width: 960px;
  margin: 0 auto 1.5rem;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
@media (min-width: 720px) {
  .hd-health-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
.hd-health-metric {
  background: var(--hd-panel);
  border: 1px solid var(--hd-line);
  border-radius: 10px;
  padding: 0.9rem 1rem;
  backdrop-filter: blur(6px);
}
.hd-health-metric .label {
  display: block;
  font-size: 0.78rem;
  color: var(--hd-muted);
  margin-bottom: 0.35rem;
}
.hd-health-metric .value {
  font-size: 1.45rem;
  font-weight: 650;
  letter-spacing: -0.02em;
  color: var(--hd-accent);
  line-height: 1.2;
}
.hd-health-metric .unit {
  font-size: 0.8rem;
  color: var(--hd-muted);
  font-weight: 500;
  margin-left: 0.15rem;
}
.hd-health-section {
  max-width: 960px;
  margin: 0 auto 1.5rem;
}
.hd-health-section h2 {
  font-size: 1.05rem;
  margin: 0 0 0.75rem;
  font-weight: 650;
}
.hd-health-chart {
  height: 280px;
  background: var(--hd-panel);
  border: 1px solid var(--hd-line);
  border-radius: 10px;
  padding: 0.5rem;
}
.hd-health-table-wrap {
  overflow-x: auto;
  background: var(--hd-panel);
  border: 1px solid var(--hd-line);
  border-radius: 10px;
}
.hd-health-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}
.hd-health-table th,
.hd-health-table td {
  padding: 0.55rem 0.7rem;
  text-align: left;
  border-bottom: 1px solid var(--hd-line);
  white-space: nowrap;
}
.hd-health-table th {
  color: var(--hd-muted);
  font-weight: 600;
  background: rgba(15, 122, 95, 0.06);
}
.hd-health-empty {
  max-width: 960px;
  margin: 1rem auto;
  padding: 1.25rem;
  border: 1px dashed var(--hd-line);
  border-radius: 10px;
  color: var(--hd-muted);
  background: var(--hd-panel);
}
.theme-dark .hd-health-page,
.dark-mode .hd-health-page,
body.theme-dark .hd-health-page {
  --hd-ink: #e7efeb;
  --hd-muted: #9bb0a8;
  --hd-line: rgba(231, 239, 235, 0.14);
  --hd-panel: rgba(18, 28, 25, 0.72);
  --hd-soft: linear-gradient(165deg, #121c19 0%, #1a2420 50%, #15201c 100%);
  --hd-accent: #3dba95;
}
</style>

<div class="hd-health-page">
  <div class="hd-health-hero">
    <h1 class="hd-health-brand"><?php $this->title(); ?></h1>
    <p class="hd-health-lead">
      <?php if (!empty($latest['date'])): ?>
        最近同步：<?php echo htmlspecialchars($latest['date']); ?> · 来自 Health.md / 苹果健康
      <?php else: ?>
        展示 Health.md 同步的步数、心率、睡眠等全日亮点
      <?php endif; ?>
    </p>
  </div>

  <?php if (!$pluginOk): ?>
    <div class="hd-health-empty">未检测到 HealthData 插件，请先启用 <code>usr/plugins/HealthData</code>。</div>
  <?php elseif (empty($latest)): ?>
    <div class="hd-health-empty">暂无健康数据。请在 Health.md 勾选 Activity / Heart / Sleep 等指标并导出到 <code>healthmd-api.php</code>。</div>
  <?php else: ?>
    <div class="hd-health-grid">
      <div class="hd-health-metric">
        <span class="label">步数</span>
        <span class="value"><?php echo healthPage_fmtNum($latest['steps'] ?? null); ?><span class="unit">步</span></span>
      </div>
      <div class="hd-health-metric">
        <span class="label">活动热量</span>
        <span class="value"><?php echo healthPage_fmtNum($latest['active_calories'] ?? null, 0); ?><span class="unit">kcal</span></span>
      </div>
      <div class="hd-health-metric">
        <span class="label">静息心率</span>
        <span class="value"><?php echo healthPage_fmtNum($latest['resting_heart_rate'] ?? null, 0); ?><span class="unit">bpm</span></span>
      </div>
      <div class="hd-health-metric">
        <span class="label">HRV</span>
        <span class="value"><?php echo healthPage_fmtNum($latest['hrv'] ?? null, 0); ?><span class="unit">ms</span></span>
      </div>
      <div class="hd-health-metric">
        <span class="label">血氧</span>
        <span class="value"><?php echo healthPage_fmtNum($latest['blood_oxygen'] ?? null, 1); ?><span class="unit">%</span></span>
      </div>
      <div class="hd-health-metric">
        <span class="label">睡眠（日历日）</span>
        <span class="value"><?php echo healthPage_fmtSleep($latest['sleep_total_minutes'] ?? 0); ?></span>
      </div>
    </div>

    <div class="hd-health-section">
      <h2>近 30 天趋势</h2>
      <div id="hd-health-chart" class="hd-health-chart"></div>
    </div>

    <div class="hd-health-section">
      <h2>最近记录</h2>
      <div class="hd-health-table-wrap">
        <table class="hd-health-table">
          <thead>
            <tr>
              <th>日期</th>
              <th>步数</th>
              <th>热量</th>
              <th>静息心率</th>
              <th>HRV</th>
              <th>睡眠</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($highlights as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['date'] ?? ''); ?></td>
                <td><?php echo healthPage_fmtNum($row['steps'] ?? null); ?></td>
                <td><?php echo healthPage_fmtNum($row['active_calories'] ?? null, 0); ?></td>
                <td><?php echo healthPage_fmtNum($row['resting_heart_rate'] ?? null, 0); ?></td>
                <td><?php echo healthPage_fmtNum($row['hrv'] ?? null, 0); ?></td>
                <td><?php echo healthPage_fmtSleep($row['sleep_total_minutes'] ?? 0); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script type="application/json" id="hd-health-chart-data"><?php echo json_encode($chartPayload, JSON_UNESCAPED_UNICODE); ?></script>
<script>
(function () {
  var dataEl = document.getElementById('hd-health-chart-data');
  var chartEl = document.getElementById('hd-health-chart');
  if (!dataEl || !chartEl) return;
  var data;
  try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
  if (!data.dates || !data.dates.length) {
    chartEl.innerHTML = '<div style="padding:1rem;color:#5c726a;font-size:0.9rem;">暂无趋势数据</div>';
    return;
  }

  function render(echarts) {
    var chart = echarts.init(chartEl);
    chart.setOption({
      color: ['#0f7a5f', '#c45c26', '#2f6fed'],
      tooltip: { trigger: 'axis' },
      legend: { data: ['步数', '活动热量', '睡眠(分)'], top: 0 },
      grid: { left: 40, right: 24, top: 40, bottom: 48 },
      dataZoom: [{ type: 'inside' }, { type: 'slider', height: 18, bottom: 8 }],
      xAxis: { type: 'category', data: data.dates, boundaryGap: true },
      yAxis: [
        { type: 'value', name: '步数', splitLine: { lineStyle: { type: 'dashed' } } },
        { type: 'value', name: 'kcal / 分', splitLine: { show: false } }
      ],
      series: [
        { name: '步数', type: 'bar', data: data.steps, barMaxWidth: 18 },
        { name: '活动热量', type: 'line', smooth: true, yAxisIndex: 1, data: data.active_calories },
        { name: '睡眠(分)', type: 'line', smooth: true, yAxisIndex: 1, data: data.sleep_minutes }
      ]
    });
    window.addEventListener('resize', function () { chart.resize(); });
  }

  if (typeof echarts !== 'undefined') {
    render(echarts);
  } else if (typeof window.loadECharts === 'function') {
    window.loadECharts(function (echarts) { render(echarts); });
  } else {
    var s = document.createElement('script');
    var base = (window.LocalConst && LocalConst.ECHART_CDN) ? LocalConst.ECHART_CDN : '';
    s.src = base ? (base.replace(/\/$/, '') + '/echarts.min.js') : 'https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js';
    s.onload = function () { if (window.echarts) render(window.echarts); };
    s.onerror = function () {
      chartEl.innerHTML = '<div style="padding:1rem;color:#5c726a;font-size:0.9rem;">图表库加载失败</div>';
    };
    document.head.appendChild(s);
  }
})();
</script>

<?php
if (file_exists(__DIR__ . '/component/footer.php')) {
    $this->need('component/footer.php');
} else {
    $this->need('footer.php');
}
