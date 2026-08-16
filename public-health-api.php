<?php
/**
 * 独立页公开只读接口（无需 access_token）
 *
 * 仅返回插件设置中勾选的分类；敏感字段已裁剪。
 *
 * GET 参数：
 * - days=N            最近 N 天列表（默认 30，最大 120）
 * - start=YYYY-MM-DD  与 end 联用：日期范围
 * - end=YYYY-MM-DD
 * - date=YYYY-MM-DD   单日详情
 * - latest=1          最近有数据的一天
 * - backfill=1        若索引为空则从 raw/daily 回填（只读场景的懒加载）
 */

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: public, max-age=60');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/lib/HealthDataHelper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => '只允许 GET',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($_GET['backfill'])) {
        healthData_ensureHealthIndexFromRaw();
    } else {
        healthData_ensureHealthIndexFromRaw();
    }

    $enabled = healthData_getPublicCategories();
    $sleepMap = in_array('sleep', $enabled, true) ? healthData_loadSleepSummaryMap() : [];

    $date = isset($_GET['date']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['date']) : '';
    $latest = !empty($_GET['latest']);
    $start = isset($_GET['start']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['start']) : '';
    $end = isset($_GET['end']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['end']) : '';
    $days = isset($_GET['days']) ? (int) $_GET['days'] : 0;

    $buildItem = function ($dayPayload) use ($enabled, $sleepMap) {
        $d = $dayPayload['date'] ?? null;
        $health = isset($dayPayload['health']) && is_array($dayPayload['health']) ? $dayPayload['health'] : [];
        $sleepRow = null;
        // 优先用 wakeTimeISO 对齐 sleep_data（起床日）
        if (!empty($health['sleep']['wakeTimeISO'])) {
            $wakeDt = healthData_parseDateTime($health['sleep']['wakeTimeISO']);
            if ($wakeDt) {
                try {
                    $tz = healthData_preferredTimezone();
                    $wakeDate = $wakeDt->setTimezone($tz)->format('Y-m-d');
                } catch (Exception $e) {
                    $wakeDate = $wakeDt->format('Y-m-d');
                }
                if (isset($sleepMap[$wakeDate])) {
                    $sleepRow = $sleepMap[$wakeDate];
                }
            }
        }
        if ($sleepRow === null && $d && isset($sleepMap[$d])) {
            $sleepRow = $sleepMap[$d];
        }
        return healthData_filterDayPublic($dayPayload, $enabled, $sleepRow);
    };

    if ($date !== '') {
        $day = healthData_loadHealthDay($date);
        if ($day === null) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => '未找到该日健康摘要',
                'date' => $date,
                'enabled' => $enabled,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'mode' => 'date',
            'enabled' => $enabled,
            'item' => $buildItem($day),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($latest) {
        $day = healthData_getLatestHealthDay();
        if ($day === null) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => '尚无健康摘要。请先用 Health.md 导出，或在插件目录执行回填。',
                'enabled' => $enabled,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'mode' => 'latest',
            'enabled' => $enabled,
            'item' => $buildItem($day),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 列表：优先日期范围，否则 days
    $index = healthData_loadHealthIndex(400);
    if ($start !== '' && $end !== '') {
        $filtered = [];
        foreach ($index as $row) {
            $d = $row['date'] ?? '';
            if ($d === '' || $d < $start || $d > $end) {
                continue;
            }
            $filtered[] = $row;
        }
        $index = $filtered;
    } else {
        if ($days <= 0) {
            $days = 30;
        }
        $days = min(120, max(1, $days));
        $index = array_slice($index, 0, $days);
    }

    // 按日期升序便于画趋势
    usort($index, function ($a, $b) {
        return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
    });

    $items = [];
    $sumSteps = 0;
    $nSteps = 0;
    $sumSleep = 0;
    $nSleep = 0;
    $sumRhr = 0;
    $nRhr = 0;
    $workoutTotal = 0;

    foreach ($index as $row) {
        $d = $row['date'] ?? '';
        if ($d === '') {
            continue;
        }
        $full = healthData_loadHealthDay($d);
        if ($full === null) {
            // 仅有索引时也返回裁剪亮点
            $item = healthData_filterHighlightsPublic($row, $enabled);
            if (in_array('sleep', $enabled, true) && isset($sleepMap[$d])) {
                $sr = $sleepMap[$d];
                $item['sleep_total_minutes'] = isset($sr['total_sleep_minutes']) ? (int) $sr['total_sleep_minutes'] : ($item['sleep_total_minutes'] ?? null);
                $item['sleep_score'] = isset($sr['sleep_score']) ? (int) $sr['sleep_score'] : null;
            }
            $items[] = $item;
        } else {
            $item = $buildItem($full);
            $items[] = $item;
        }

        if (isset($item['steps']) && $item['steps'] !== null) {
            $sumSteps += (float) $item['steps'];
            $nSteps++;
        }
        $sleepMin = $item['sleep_total_minutes'] ?? ($item['sleep']['total_sleep_minutes'] ?? null);
        if ($sleepMin !== null) {
            $sumSleep += (float) $sleepMin;
            $nSleep++;
        }
        if (isset($item['resting_heart_rate']) && $item['resting_heart_rate'] !== null) {
            $sumRhr += (float) $item['resting_heart_rate'];
            $nRhr++;
        }
        if (!empty($item['workout_count'])) {
            $workoutTotal += (int) $item['workout_count'];
        }
    }

    $summary = [
        'days' => count($items),
        'avg_steps' => $nSteps ? (int) round($sumSteps / $nSteps) : null,
        'avg_sleep_minutes' => $nSleep ? (int) round($sumSleep / $nSleep) : null,
        'avg_resting_heart_rate' => $nRhr ? round($sumRhr / $nRhr, 1) : null,
        'workout_count' => $workoutTotal,
    ];

    echo json_encode([
        'status' => 'success',
        'mode' => 'list',
        'enabled' => $enabled,
        'range' => [
            'start' => $start !== '' ? $start : ($items[0]['date'] ?? null),
            'end' => $end !== '' ? $end : ($items ? $items[count($items) - 1]['date'] : null),
            'days_param' => $days > 0 ? $days : null,
        ],
        'summary' => $summary,
        'count' => count($items),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    error_log('HealthData public-health-api Error: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
