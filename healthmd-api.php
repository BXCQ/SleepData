<?php
/**
 * Health.md API Export 接收接口
 *
 * 对应 Health.md App：Export → Export Target → API Endpoint
 * - URL: https://blog.ybyq.wang/usr/plugins/SleepData/healthmd-api.php
 * - Token: 插件访问令牌（Bearer，由 App 写入 Authorization 头）
 * - 按天 upsert：同一 date 重复导出会覆盖更新
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/lib/SleepDataHelper.php';

/**
 * Health.md 睡眠时长字段为秒，转为分钟
 */
function healthmd_to_minutes($value)
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_string($value) && !is_numeric($value)) {
        return sleepData_parseDurationToMinutes($value);
    }
    return (int) round(((float) $value) / 60);
}

/**
 * 从 HH:mm / ISO8601 提取 HH:mm
 */
function healthmd_to_hhmm($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim((string) $value);
    if (preg_match('/^(\d{1,2}:\d{2})/', $value, $m)) {
        $parts = explode(':', $m[1]);
        return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
    }
    $dt = sleepData_parseDateTime($value);
    if ($dt) {
        return $dt->setTimezone(sleepData_preferredTimezone())->format('H:i');
    }
    return null;
}

/**
 * 将单日 Health.md health_data 记录映射为插件存储结构
 */
function healthmd_mapDailyRecord(array $record)
{
    $date = $record['date'] ?? null;
    if (!$date) {
        return null;
    }

    $sleep = [];
    if (!empty($record['sleep']) && is_array($record['sleep'])) {
        $sleep = $record['sleep'];
    }

    // 若无汇总字段但有 sleepStages，走样本汇总
    $hasSummary = isset($sleep['deepSleep']) || isset($sleep['coreSleep']) || isset($sleep['remSleep'])
        || isset($sleep['totalDuration']) || isset($sleep['total_sleep']);

    if (!$hasSummary && !empty($sleep['sleepStages']) && is_array($sleep['sleepStages'])) {
        $samples = [];
        foreach ($sleep['sleepStages'] as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $samples[] = [
                'stage' => $stage['stage'] ?? $stage['value'] ?? '',
                'start' => $stage['startDate'] ?? $stage['start'] ?? null,
                'end' => $stage['endDate'] ?? $stage['end'] ?? null,
            ];
        }
        $aggregated = sleepData_aggregateSamples($samples, $date);
        if ($aggregated) {
            return [
                'date' => $aggregated['date'],
                'sleep_time' => $aggregated['sleep_time'],
                'wake_up_time' => $aggregated['wake_up_time'],
                'deep_sleep_minutes' => (int) $aggregated['deep_sleep_minutes'],
                'light_sleep_minutes' => (int) $aggregated['light_sleep_minutes'],
                'rem_sleep_minutes' => (int) $aggregated['rem_sleep_minutes'],
                'awake_minutes' => (int) $aggregated['awake_minutes'],
                'total_sleep_minutes' => (int) $aggregated['total_sleep_minutes'],
                'wakeups' => (int) ($aggregated['wakeups'] ?? 0),
                'score_hint' => null,
            ];
        }
    }

    if (empty($sleep) && !$hasSummary) {
        return null;
    }

    $deep = healthmd_to_minutes($sleep['deepSleep'] ?? $sleep['deep_sleep'] ?? $sleep['deep'] ?? 0);
    $light = healthmd_to_minutes($sleep['coreSleep'] ?? $sleep['lightSleep'] ?? $sleep['light_sleep'] ?? $sleep['core'] ?? 0);
    $rem = healthmd_to_minutes($sleep['remSleep'] ?? $sleep['rem_sleep'] ?? $sleep['rem'] ?? 0);
    $awake = healthmd_to_minutes($sleep['awakeTime'] ?? $sleep['awake'] ?? $sleep['awake_minutes'] ?? 0);
    $total = healthmd_to_minutes($sleep['totalDuration'] ?? $sleep['total_sleep'] ?? $sleep['asleep'] ?? null);
    if ($total <= 0) {
        $total = $deep + $light + $rem;
    }

    $sleepTime = healthmd_to_hhmm($sleep['bedtime'] ?? $sleep['sleep_time'] ?? $sleep['bedtimeISO'] ?? null);
    $wakeTime = healthmd_to_hhmm($sleep['wakeTime'] ?? $sleep['wake_up_time'] ?? $sleep['wakeTimeISO'] ?? null);

    // 从 ISO 补全本地时区时间
    if (!$sleepTime && !empty($sleep['bedtimeISO'])) {
        $sleepTime = healthmd_to_hhmm($sleep['bedtimeISO']);
    }
    if (!$wakeTime && !empty($sleep['wakeTimeISO'])) {
        $wakeTime = healthmd_to_hhmm($sleep['wakeTimeISO']);
    }

    $score = null;
    foreach (['sleep_score', 'score', 'sleepScore'] as $k) {
        if (isset($record[$k]) && is_numeric($record[$k])) {
            $score = (int) round((float) $record[$k]);
            break;
        }
        if (isset($sleep[$k]) && is_numeric($sleep[$k])) {
            $score = (int) round((float) $sleep[$k]);
            break;
        }
    }

    return [
        'date' => $date,
        'sleep_time' => $sleepTime,
        'wake_up_time' => $wakeTime,
        'deep_sleep_minutes' => $deep,
        'light_sleep_minutes' => $light,
        'rem_sleep_minutes' => $rem,
        'awake_minutes' => $awake,
        'total_sleep_minutes' => $total,
        'wakeups' => isset($sleep['wakeups']) ? (int) $sleep['wakeups'] : 0,
        'score_hint' => $score,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => '只允许 POST。请在 Health.md 中将 API Endpoint 指向本地址。',
            'endpoint' => 'healthmd-api.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => '无效的 JSON：Health.md 应 POST application/json envelope',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Health.md 用 Authorization: Bearer；body 一般不含 token
    $tokenInfo = sleepData_requireValidToken($data);

    $records = [];
    if (isset($data['records']) && is_array($data['records'])) {
        $records = $data['records'];
    } elseif (isset($data['schema']) && ($data['schema'] === 'healthmd.health_data' || isset($data['sleep']))) {
        // 兼容单日文档直接 POST
        $records = [$data];
    } else {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => '未找到 records[]。请确认 Health.md API Export 已勾选睡眠指标。',
            'hint' => '期望 schema=healthmd.api_export，且 records 为每日 health_data 文档',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (count($records) === 0) {
        // Health.md 文档：空批次也可能合法；返回 200 避免重试风暴
        echo json_encode([
            'status' => 'success',
            'message' => '空 records，已忽略',
            'saved' => [],
            'skipped' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $saved = [];
    $skipped = [];

    foreach ($records as $index => $record) {
        if (!is_array($record)) {
            $skipped[] = ['index' => $index, 'reason' => 'record 不是对象'];
            continue;
        }

        $mapped = healthmd_mapDailyRecord($record);
        if ($mapped === null) {
            $skipped[] = [
                'index' => $index,
                'date' => $record['date'] ?? null,
                'reason' => '无可用 sleep 汇总（请在 Health.md 勾选 Sleep 指标）',
            ];
            continue;
        }

        if ($mapped['total_sleep_minutes'] <= 0
            && $mapped['deep_sleep_minutes'] <= 0
            && $mapped['light_sleep_minutes'] <= 0
            && $mapped['rem_sleep_minutes'] <= 0) {
            $skipped[] = [
                'index' => $index,
                'date' => $mapped['date'],
                'reason' => '当天无睡眠时长',
            ];
            continue;
        }

        $scoreEstimated = false;
        $sleepScore = $mapped['score_hint'];
        if ($sleepScore === null) {
            $recent = sleepData_getRecentSleepTimes($mapped['date']);
            $sleepScore = sleepData_estimateScore($mapped, $recent);
            $scoreEstimated = true;
        }

        $saveData = [
            'date' => $mapped['date'],
            'sleep_time' => $mapped['sleep_time'],
            'wake_up_time' => $mapped['wake_up_time'],
            'sleep_score' => $sleepScore,
            'deep_sleep_minutes' => $mapped['deep_sleep_minutes'],
            'light_sleep_minutes' => $mapped['light_sleep_minutes'],
            'rem_sleep_minutes' => $mapped['rem_sleep_minutes'],
            'awake_minutes' => $mapped['awake_minutes'],
            'total_sleep_minutes' => $mapped['total_sleep_minutes'],
            'created_at' => date('Y-m-d H:i:s'),
            'source' => 'healthmd',
            'score_estimated' => $scoreEstimated,
        ];

        $result = sleepData_saveRecord($saveData);
        $saved[] = [
            'date' => $saveData['date'],
            'sleep_score' => $saveData['sleep_score'],
            'total_sleep_minutes' => $saveData['total_sleep_minutes'],
            'score_estimated' => $scoreEstimated,
            'db_save' => $result['db_save'],
        ];
    }

    $ok = count($saved) > 0 || count($skipped) === count($records);
    http_response_code(200);
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => sprintf(
            'Health.md 导入完成：保存 %d 天，跳过 %d 天',
            count($saved),
            count($skipped)
        ),
        'schema' => $data['schema'] ?? null,
        'schema_version' => $data['schema_version'] ?? null,
        'exported_at' => $data['exported_at'] ?? null,
        'saved' => $saved,
        'skipped' => $skipped,
        'meta' => [
            'token_source' => $tokenInfo['from_system'] ? 'typecho_config' : (empty($tokenInfo['token']) ? 'none' : 'config_file'),
            'note' => '同一 date 重复导出将 upsert 覆盖。苹果 Sleep Score 通常不可用，未提供分数时会估算。',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    error_log('SleepData Health.md API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器错误: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
