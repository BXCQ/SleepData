<?php
/**
 * 独立页公开只读接口（无需 access_token）
 *
 * 仅返回插件设置中勾选的分类；敏感字段已裁剪。
 *
 * 性能说明：
 * - 列表模式只读 index.json + sleep_data.json（O(1) 文件），不逐日打开 daily
 * - 单日详情 / latest 才读取对应 daily 文件
 *
 * GET 参数：
 * - days=N            最近 N 天列表（默认 30，最大 120）
 * - start=YYYY-MM-DD  与 end 联用：日期范围
 * - end=YYYY-MM-DD
 * - date=YYYY-MM-DD   单日详情
 * - latest=1          最近有数据的一天
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

    // 仅在索引缺失时回填，避免每次请求扫 raw 目录
    healthData_ensureHealthIndexFromRaw();

    $enabled = healthData_getPublicCategories();
    $wantSleep = in_array('sleep', $enabled, true);
    $sleepMap = $wantSleep ? healthData_loadSleepSummaryMap() : [];

    $attachSleepFromMap = function (array $item, $date) use ($sleepMap, $wantSleep) {
        if (!$wantSleep || $date === null || $date === '') {
            return $item;
        }
        // 优先同日；若无则尝试次日（起床日）
        $sr = null;
        if (isset($sleepMap[$date])) {
            $sr = $sleepMap[$date];
        } else {
            try {
                $next = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
                if (isset($sleepMap[$next])) {
                    $sr = $sleepMap[$next];
                }
            } catch (Exception $e) {
                // ignore
            }
        }
        if ($sr === null) {
            return $item;
        }
        $item['sleep'] = [
            'date' => $sr['date'] ?? null,
            'sleep_time' => isset($sr['sleep_time']) ? substr((string) $sr['sleep_time'], 0, 5) : null,
            'wake_up_time' => isset($sr['wake_up_time']) ? substr((string) $sr['wake_up_time'], 0, 5) : null,
            'sleep_score' => isset($sr['sleep_score']) ? (int) $sr['sleep_score'] : null,
            'score_estimated' => !empty($sr['score_estimated']),
            'deep_sleep_minutes' => isset($sr['deep_sleep_minutes']) ? (int) $sr['deep_sleep_minutes'] : null,
            'light_sleep_minutes' => isset($sr['light_sleep_minutes']) ? (int) $sr['light_sleep_minutes'] : null,
            'rem_sleep_minutes' => isset($sr['rem_sleep_minutes']) ? (int) $sr['rem_sleep_minutes'] : null,
            'awake_minutes' => isset($sr['awake_minutes']) ? (int) $sr['awake_minutes'] : null,
            'total_sleep_minutes' => isset($sr['total_sleep_minutes']) ? (int) $sr['total_sleep_minutes'] : null,
            'alignment' => 'wake_day',
        ];
        if ($item['sleep']['total_sleep_minutes'] !== null) {
            $item['sleep_total_minutes'] = $item['sleep']['total_sleep_minutes'];
        }
        if ($item['sleep']['sleep_score'] !== null) {
            $item['sleep_score'] = $item['sleep']['sleep_score'];
        }
        return $item;
    };

    $buildItemFromFullDay = function ($dayPayload) use ($enabled, $sleepMap) {
        $d = $dayPayload['date'] ?? null;
        $health = isset($dayPayload['health']) && is_array($dayPayload['health']) ? $dayPayload['health'] : [];
        $sleepRow = null;
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

    $date = isset($_GET['date']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['date']) : '';
    $latest = !empty($_GET['latest']);
    $start = isset($_GET['start']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['start']) : '';
    $end = isset($_GET['end']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['end']) : '';
    $days = isset($_GET['days']) ? (int) $_GET['days'] : 0;

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
            'item' => $buildItemFromFullDay($day),
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
            'item' => $buildItemFromFullDay($day),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // —— 列表：只读索引，不逐日打开 daily ——
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
        $item = healthData_filterHighlightsPublic($row, $enabled);
        $item = $attachSleepFromMap($item, $d);
        $items[] = $item;

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
