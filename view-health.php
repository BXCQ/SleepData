<?php
/**
 * 全日健康数据查看页（无需主题模板）
 * 访问：/usr/plugins/HealthData/view-health.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/lib/HealthDataHelper.php';

$highlights = [];
$latest = null;
$error = '';

try {
    $highlights = healthData_loadHealthIndex(60);
    if (!empty($highlights[0])) {
        $latest = $highlights[0];
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function viewHealth_fmt($v, $digits = 0)
{
    if ($v === null || $v === '') {
        return '—';
    }
    return $digits > 0 ? number_format((float) $v, $digits) : number_format((float) $v, 0);
}

function viewHealth_sleep($minutes)
{
    $minutes = (int) $minutes;
    if ($minutes <= 0) {
        return '—';
    }
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return ($h > 0 ? $h . '小时' : '') . ($m > 0 ? $m . '分钟' : ($h > 0 ? '' : '0分钟'));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>健康数据</title>
    <style>
        :root {
            --ink: #1a2e28;
            --muted: #5c726a;
            --line: rgba(26, 46, 40, 0.12);
            --accent: #0f7a5f;
            --bg: linear-gradient(165deg, #e8f2ee 0%, #f3efe6 48%, #eef4f1 100%);
            --panel: rgba(255,255,255,0.78);
        }
        body {
            margin: 0;
            font-family: "Source Han Sans SC", "PingFang SC", "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--bg);
            min-height: 100vh;
            padding: 24px 16px 48px;
        }
        .wrap { max-width: 960px; margin: 0 auto; }
        h1 {
            font-family: "Source Han Serif SC", "Noto Serif SC", Georgia, serif;
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            margin: 0 0 0.4rem;
        }
        .lead { color: var(--muted); margin: 0 0 1.5rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 720px) { .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .metric {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px 16px;
        }
        .metric .label { display: block; font-size: 0.78rem; color: var(--muted); margin-bottom: 6px; }
        .metric .value { font-size: 1.4rem; font-weight: 650; color: var(--accent); }
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            font-size: 0.9rem;
        }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--line); }
        th { background: rgba(15,122,95,0.07); color: var(--muted); }
        .empty { padding: 24px; border: 1px dashed var(--line); border-radius: 10px; color: var(--muted); background: var(--panel); }
        .hint { margin-top: 1.5rem; font-size: 0.85rem; color: var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <h1>健康数据</h1>
    <p class="lead">
        <?php if ($latest && !empty($latest['date'])): ?>
            最近同步：<?php echo htmlspecialchars($latest['date']); ?>
        <?php else: ?>
            Health.md 全日健康亮点
        <?php endif; ?>
    </p>

    <?php if ($error): ?>
        <div class="empty">读取失败：<?php echo htmlspecialchars($error); ?></div>
    <?php elseif (empty($latest)): ?>
        <div class="empty">暂无数据。请先用 Health.md 导出到 healthmd-api.php。</div>
    <?php else: ?>
        <div class="grid">
            <div class="metric"><span class="label">步数</span><span class="value"><?php echo viewHealth_fmt($latest['steps'] ?? null); ?></span></div>
            <div class="metric"><span class="label">活动热量 (kcal)</span><span class="value"><?php echo viewHealth_fmt($latest['active_calories'] ?? null); ?></span></div>
            <div class="metric"><span class="label">静息心率</span><span class="value"><?php echo viewHealth_fmt($latest['resting_heart_rate'] ?? null); ?></span></div>
            <div class="metric"><span class="label">HRV (ms)</span><span class="value"><?php echo viewHealth_fmt($latest['hrv'] ?? null); ?></span></div>
            <div class="metric"><span class="label">血氧 (%)</span><span class="value"><?php echo viewHealth_fmt($latest['blood_oxygen'] ?? null, 1); ?></span></div>
            <div class="metric"><span class="label">睡眠</span><span class="value"><?php echo viewHealth_sleep($latest['sleep_total_minutes'] ?? 0); ?></span></div>
        </div>

        <table>
            <thead>
            <tr>
                <th>日期</th><th>步数</th><th>热量</th><th>静息心率</th><th>HRV</th><th>睡眠</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($highlights as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['date'] ?? ''); ?></td>
                    <td><?php echo viewHealth_fmt($row['steps'] ?? null); ?></td>
                    <td><?php echo viewHealth_fmt($row['active_calories'] ?? null); ?></td>
                    <td><?php echo viewHealth_fmt($row['resting_heart_rate'] ?? null); ?></td>
                    <td><?php echo viewHealth_fmt($row['hrv'] ?? null); ?></td>
                    <td><?php echo viewHealth_sleep($row['sleep_total_minutes'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="hint">建议在主题中使用独立页面模板「健康数据」（page-health.php）获得带导航的完整页。</p>
</div>
</body>
</html>
