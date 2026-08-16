<?php
/**
 * 全日健康数据查询接口
 *
 * GET 参数（需 Bearer / access_token）：
 * - date=YYYY-MM-DD  指定日
 * - latest=1         最近一日
 * - days=N           最近 N 天亮点列表（默认 14，最大 90）
 * - full=1           与 date/latest 联用时返回完整日摘要（含各分类对象）
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
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/lib/SleepDataHelper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => '只允许 GET。写入请用 healthmd-api.php。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $queryToken = [];
    if (!empty($_GET['access_token'])) {
        $queryToken['access_token'] = $_GET['access_token'];
    } elseif (!empty($_GET['token'])) {
        $queryToken['token'] = $_GET['token'];
    }
    sleepData_requireValidToken($queryToken);

    $wantFull = !empty($_GET['full']) && $_GET['full'] !== '0';
    $date = isset($_GET['date']) ? preg_replace('/[^0-9\\-]/', '', (string) $_GET['date']) : '';
    $latest = !empty($_GET['latest']);
    $days = isset($_GET['days']) ? (int) $_GET['days'] : 0;

    if ($date !== '') {
        $day = sleepData_loadHealthDay($date);
        if ($day === null) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => '未找到该日健康摘要',
                'date' => $date,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'mode' => 'date',
            'date' => $date,
            'highlights' => $day['highlights'] ?? null,
            'data' => $wantFull ? $day : ['highlights' => $day['highlights'] ?? null],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($latest) {
        $day = sleepData_getLatestHealthDay();
        if ($day === null) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => '尚无健康摘要，请先用 Health.md 导出到 healthmd-api.php',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'mode' => 'latest',
            'date' => $day['date'] ?? null,
            'highlights' => $day['highlights'] ?? null,
            'data' => $wantFull ? $day : ['highlights' => $day['highlights'] ?? null],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($days <= 0) {
        $days = 14;
    }
    $days = min(90, max(1, $days));
    $index = sleepData_loadHealthIndex($days);

    echo json_encode([
        'status' => 'success',
        'mode' => 'list',
        'count' => count($index),
        'days' => $days,
        'items' => $index,
        'paths' => [
            'health_index' => sleepData_healthIndexFile(),
            'health_daily_dir' => sleepData_ensureDataDirs() . '/health/daily',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    $code = (strpos($e->getMessage(), '令牌') !== false || strpos($e->getMessage(), 'token') !== false) ? 401 : 500;
    if ($code === 401) {
        // sleepData_requireValidToken 已设状态码时保持
        if (http_response_code() < 400) {
            http_response_code(401);
        }
    } else {
        http_response_code(500);
    }
    error_log('SleepData health-api Error: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
