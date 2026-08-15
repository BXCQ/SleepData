<?php
/**
 * 快捷指令 / Apple Health 睡眠数据接收接口
 *
 * 推荐用法（最简单）：POST 原始睡眠样本，由服务端自动汇总一夜数据。
 * 也支持直接 POST 已汇总的分钟数字段。
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
 * 读取请求 JSON；兼容快捷指令偶发的「单键包一层」结构
 */
function shortcut_readJsonBody()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        // 兼容表单 POST
        if (!empty($_POST)) {
            return $_POST;
        }
        return null;
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    // 快捷指令有时会把整个字典再包进一个字符串键里
    if (is_array($data) && count($data) === 1) {
        $onlyKey = array_key_first($data);
        if (is_string($onlyKey) && is_string($data[$onlyKey])) {
            $inner = json_decode($data[$onlyKey], true);
            if (is_array($inner)) {
                $data = $inner;
            }
        } elseif (is_string($onlyKey) && is_array($data[$onlyKey]) && !isset($data['date']) && !isset($data['samples'])) {
            $data = $data[$onlyKey];
        }
    }

    return $data;
}

/**
 * 从多种字段名提取整型分钟数
 */
function shortcut_pickMinutes(array $data, array $keys)
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
            return sleepData_parseDurationToMinutes($data[$key]);
        }
    }
    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => '只允许 POST。请用 iOS 快捷指令「获取 URL 内容」以 JSON 提交。',
            'docs' => '见插件目录 SHORTCUTS.md',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = shortcut_readJsonBody();
    if ($data === null || !is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => '无效的 JSON 数据',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokenInfo = sleepData_requireValidToken($data);

    $aggregated = null;
    $source = 'fields';

    // 快捷指令简化格式：每行 stage|start|end
    if (empty($data['samples']) && !empty($data['samples_text']) && is_string($data['samples_text'])) {
        $parsedSamples = [];
        foreach (preg_split('/\r\n|\r|\n/', $data['samples_text']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line);
            if (count($parts) < 3) {
                continue;
            }
            $parsedSamples[] = [
                'stage' => trim($parts[0]),
                'start' => trim($parts[1]),
                'end' => trim($parts[2]),
            ];
        }
        if (!empty($parsedSamples)) {
            $data['samples'] = $parsedSamples;
        }
    }

    // 方式一：原始样本（推荐）
    $samples = $data['samples'] ?? $data['sleep_samples'] ?? $data['sleep'] ?? null;
    if (is_array($samples) && !empty($samples)) {
        $targetDate = $data['date'] ?? $data['wake_date'] ?? null;
        $aggregated = sleepData_aggregateSamples($samples, $targetDate);
        $source = 'samples';
        if ($aggregated === null) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => '无法从 samples 汇总出有效睡眠数据，请检查 start/end/stage 字段',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // 方式二：已汇总字段
        $deep = shortcut_pickMinutes($data, ['deep_sleep_minutes', 'deep_minutes', 'deep', 'deep_sleep']);
        $light = shortcut_pickMinutes($data, ['light_sleep_minutes', 'core_minutes', 'core_sleep_minutes', 'light_minutes', 'light', 'light_sleep', 'core']);
        $rem = shortcut_pickMinutes($data, ['rem_sleep_minutes', 'rem_minutes', 'rem', 'rem_sleep']);
        $awake = shortcut_pickMinutes($data, ['awake_minutes', 'awake', 'awake_sleep']);
        $total = shortcut_pickMinutes($data, ['total_sleep_minutes', 'asleep_minutes', 'total_sleep', 'total']);

        if ($deep === null && $light === null && $rem === null && $total === null) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => '请提供 samples 数组，或 deep/core/rem/total 等分钟数字段',
                'example' => [
                    'access_token' => 'your-token',
                    'samples' => [
                        ['stage' => 'Deep', 'start' => '2026-08-14T23:10:00+08:00', 'end' => '2026-08-15T00:05:00+08:00'],
                        ['stage' => 'Core', 'start' => '2026-08-15T00:05:00+08:00', 'end' => '2026-08-15T02:00:00+08:00'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $deep = $deep ?? 0;
        $light = $light ?? 0;
        $rem = $rem ?? 0;
        $awake = $awake ?? 0;
        if ($total === null) {
            $total = $deep + $light + $rem;
        }

        $date = $data['date'] ?? $data['wake_date'] ?? date('Y-m-d');
        $sleepTime = $data['sleep_time'] ?? $data['bedtime'] ?? $data['asleep_start'] ?? null;
        $wakeTime = $data['wake_up_time'] ?? $data['wake_time'] ?? $data['asleep_end'] ?? null;

        $aggregated = [
            'date' => $date,
            'sleep_time' => $sleepTime ? substr((string) $sleepTime, 0, 5) : null,
            'wake_up_time' => $wakeTime ? substr((string) $wakeTime, 0, 5) : null,
            'deep_sleep_minutes' => $deep,
            'light_sleep_minutes' => $light,
            'rem_sleep_minutes' => $rem,
            'awake_minutes' => $awake,
            'total_sleep_minutes' => $total,
            'wakeups' => isset($data['wakeups']) ? (int) $data['wakeups'] : 0,
            'sample_count' => 0,
        ];
    }

    // 评分：优先用请求里的；苹果原生 Sleep Score / OPPO 分数通常进不了快捷指令
    $scoreProvided = false;
    $sleepScore = null;
    foreach (['sleep_score', 'score', 'sleepScore'] as $scoreKey) {
        if (isset($data[$scoreKey]) && $data[$scoreKey] !== '' && is_numeric($data[$scoreKey])) {
            $sleepScore = (int) round((float) $data[$scoreKey]);
            $scoreProvided = true;
            break;
        }
    }

    $scoreEstimated = false;
    if (!$scoreProvided) {
        $recent = sleepData_getRecentSleepTimes($aggregated['date'] ?? null);
        $sleepScore = sleepData_estimateScore($aggregated, $recent);
        $scoreEstimated = true;
    }

    $saveData = [
        'date' => $aggregated['date'],
        'sleep_time' => $aggregated['sleep_time'],
        'wake_up_time' => $aggregated['wake_up_time'],
        'sleep_score' => $sleepScore,
        'deep_sleep_minutes' => (int) $aggregated['deep_sleep_minutes'],
        'light_sleep_minutes' => (int) $aggregated['light_sleep_minutes'],
        'rem_sleep_minutes' => (int) $aggregated['rem_sleep_minutes'],
        'awake_minutes' => (int) $aggregated['awake_minutes'],
        'total_sleep_minutes' => (int) $aggregated['total_sleep_minutes'],
        'created_at' => date('Y-m-d H:i:s'),
        'source' => 'shortcuts',
        'score_estimated' => $scoreEstimated,
    ];

    if (empty($saveData['date'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '缺少日期 date'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = sleepData_saveRecord($saveData);

    echo json_encode([
        'status' => 'success',
        'message' => '快捷指令数据已保存' . ($result['db_save'] ? ' (文件 & 数据库)' : ' (仅文件)'),
        'saved_data' => $result['save_data'],
        'meta' => [
            'source' => $source,
            'score_estimated' => $scoreEstimated,
            'sample_count' => $aggregated['sample_count'] ?? 0,
            'wakeups' => $aggregated['wakeups'] ?? 0,
            'token_source' => $tokenInfo['from_system'] ? 'typecho_config' : (empty($tokenInfo['token']) ? 'none' : 'config_file'),
            'data_file' => $result['data_file'],
            'db_save' => $result['db_save'] ? 'success' : 'failed',
            'db_error' => $result['db_error'],
            'note' => $scoreEstimated
                ? '未收到 sleep_score，已按时长/中断/入睡规律估算。苹果 Sleep Score 与 OPPO 健康分数通常无法经快捷指令读取。'
                : null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    error_log('SleepData Shortcut API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器错误: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
