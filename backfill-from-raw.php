<?php
/**
 * 从 data/raw/daily/ 回填 data/health/
 *
 * 用法：
 *   CLI:  php backfill-from-raw.php
 *   HTTP: .../backfill-from-raw.php?access_token=你的令牌
 */

if (PHP_SAPI !== 'cli') {
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    @ini_set('zlib.output_compression', 0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/lib/HealthDataHelper.php';

try {
    if (PHP_SAPI !== 'cli') {
        $tokenBag = [];
        if (!empty($_GET['access_token'])) {
            $tokenBag['access_token'] = $_GET['access_token'];
        } elseif (!empty($_GET['token'])) {
            $tokenBag['token'] = $_GET['token'];
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $tokenBag['access_token'] = $m[1];
        }
        healthData_requireValidToken($tokenBag);
    }

    $result = healthData_backfillFromRaw();
    $payload = [
        'status' => 'success',
        'message' => sprintf('回填完成：扫描 %d，写入 %d，跳过 %d', $result['scanned'], $result['saved'], $result['skipped']),
        'result' => $result,
        'index_file' => healthData_healthIndexFile(),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
} catch (Exception $e) {
    if (PHP_SAPI !== 'cli') {
        $code = (strpos($e->getMessage(), '令牌') !== false || stripos($e->getMessage(), 'token') !== false) ? 401 : 500;
        http_response_code($code);
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
        exit(1);
    }
}
