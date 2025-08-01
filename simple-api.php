<?php

/**
 * 睡眠数据API处理文件
 * 直接接收和处理POST请求，同时保存到文件和尝试保存到数据库
 */

// --- 强制输出控制，解决 ERR_CONTENT_DECODING_FAILED ---
// 尝试禁用服务器端的gzip压缩
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);

// 清理所有可能存在的输出缓冲区
while (ob_get_level() > 0) {
    ob_end_clean();
}
// --- 结束强制输出控制 ---

// 配置错误处理：在生产环境中关闭错误显示，只记录日志
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 检查是否为AJAX请求或直接访问此API
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isDirectApiCall = basename($_SERVER['SCRIPT_NAME']) === 'simple-api.php';

// 仅在AJAX请求或直接访问API时设置JSON响应头
if ($isAjax || $isDirectApiCall) {
    header('Content-Type: application/json');
}

/**
 * 将 "X小时Y分钟" 格式的字符串解析为总分钟数
 * @param string $durationStr
 * @return int
 */
function parseDurationToMinutes($durationStr)
{
    $minutes = 0;
    preg_match('/(\d+)\s*小时/', $durationStr, $hourMatches);
    preg_match('/(\d+)\s*分钟/', $durationStr, $minuteMatches);

    if (!empty($hourMatches[1])) {
        $minutes += intval($hourMatches[1]) * 60;
    }
    if (!empty($minuteMatches[1])) {
        $minutes += intval($minuteMatches[1]);
    }
    return $minutes;
}

try {
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => '只允许POST请求']);
        exit;
    }

    // 获取原始POST数据
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);

    // 检查JSON解析
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => '无效的JSON数据: ' . json_last_error_msg(),
        ]);
        exit;
    }

    // 尝试获取访问令牌 - 优先从Typecho系统配置读取，再从配置文件读取
    $configuredToken = '';
    $rootDir = dirname(dirname(dirname(__DIR__))); // 修正路径，向上三层到博客根目录
    $tokenFromSystem = false;
    
    // 1. 首先尝试从Typecho数据库配置读取
    if (file_exists($rootDir . '/config.inc.php')) {
        try {
            require_once $rootDir . '/config.inc.php';
            if (class_exists('Typecho_Db')) {
                $db = Typecho_Db::get();
                $options = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:SleepData'));
                if ($options && !empty($options['value'])) {
                    $pluginOptions = unserialize($options['value']);
                    if (isset($pluginOptions['accessToken']) && !empty($pluginOptions['accessToken'])) {
                        $configuredToken = $pluginOptions['accessToken'];
                        $tokenFromSystem = true;
                        error_log('从Typecho配置读取访问令牌成功');
                    }
                }
            }
        } catch (Exception $e) {
            error_log('从Typecho配置读取访问令牌失败: ' . $e->getMessage());
        }
    }
    
    // 2. 如果从系统配置读取失败，再尝试从配置文件中读取
    if (empty($configuredToken)) {
        $configFile = __DIR__ . '/data_config.php';
        if (file_exists($configFile)) {
            include_once $configFile;
            if (defined('API_ACCESS_TOKEN') && !empty(API_ACCESS_TOKEN)) {
                $configuredToken = API_ACCESS_TOKEN;
                error_log('从配置文件读取访问令牌成功');
            }
        }
    }
    
    // 如果配置了访问令牌，则验证请求中的令牌
    if (!empty($configuredToken)) {
        $requestToken = isset($data['access_token']) ? $data['access_token'] : '';
        if (empty($requestToken) || $requestToken !== $configuredToken) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => '访问令牌无效或缺失',
            ]);
            exit;
        }
        // 验证成功后从数据中移除令牌
        if (isset($data['access_token'])) {
            unset($data['access_token']);
        }
    }

    // 检查必要字段
    if (!isset($data['date']) || !isset($data['sleep_score'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => '缺少必要字段',
        ]);
        exit;
    }

    // 准备要保存的数据 (时长转换为分钟)
    $saveData = [
        'date' => $data['date'] ?? null,
        'sleep_time' => $data['sleep_time'] ?? null,
        'wake_up_time' => $data['wake_up_time'] ?? null,
        'sleep_score' => $data['sleep_score'] ?? null,
        'total_sleep_minutes' => parseDurationToMinutes($data['total_sleep'] ?? '0分钟'),
        'deep_sleep_minutes' => parseDurationToMinutes($data['deep_sleep'] ?? '0分钟'),
        'light_sleep_minutes' => parseDurationToMinutes($data['light_sleep'] ?? '0分钟'),
        'rem_sleep_minutes' => parseDurationToMinutes($data['rem_sleep'] ?? '0分钟'),
        'awake_minutes' => parseDurationToMinutes($data['awake'] ?? '0分钟'),
        'created_at' => date('Y-m-d H:i:s')
    ];

    // 尝试从配置文件中获取数据文件路径
    $configFile = __DIR__ . '/data_config.php';
    $dataFile = '';

    if (file_exists($configFile)) {
        include $configFile;
        if (defined('SLEEP_DATA_FILE')) {
            $dataFile = SLEEP_DATA_FILE;
        }
    }

    // 如果配置文件不存在或不包含路径，尝试在几个可能有写入权限的目录中保存数据
    if (empty($dataFile)) {
        $possibleDirs = [
            sys_get_temp_dir(),                   // 系统临时目录
            '/tmp',                               // Linux临时目录
            dirname(__DIR__) . '/uploads',        // Typecho上传目录
            dirname(dirname(__DIR__)) . '/tmp'    // Typecho临时目录
        ];

        foreach ($possibleDirs as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                $dataFile = $dir . '/sleep_data.json';
                break;
            }
        }

        // 如果仍然没有找到可写目录，使用默认路径
        if (empty($dataFile)) {
            $dataFile = __DIR__ . '/sleep_data.json';
        }

        // 创建一个指向数据文件的配置文件
        $configContent = "<?php\ndefine('SLEEP_DATA_FILE', '$dataFile');\n";
        @file_put_contents($configFile, $configContent);
    }

    // 读取现有数据
    $existingData = [];
    $dataFoundIndex = -1;
    if (file_exists($dataFile)) {
        $existingDataJson = file_get_contents($dataFile);
        if (!empty($existingDataJson)) {
            $existingData = json_decode($existingDataJson, true) ?: [];
        }
    }

    // 检查是否存在相同日期的数据
    foreach ($existingData as $index => $record) {
        if (isset($record['date']) && $record['date'] === $saveData['date']) {
            $dataFoundIndex = $index;
            break;
        }
    }

    if ($dataFoundIndex !== -1) {
        // 更新现有数据，但保留原始的创建时间
        $saveData['created_at'] = $existingData[$dataFoundIndex]['created_at'] ?? $saveData['created_at'];
        $existingData[$dataFoundIndex] = $saveData;
    } else {
        // 添加新数据
        $existingData[] = $saveData;
    }

    // 保存回文件
    $result = file_put_contents($dataFile, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if ($result === false) {
        throw new Exception('无法写入数据文件: ' . $dataFile);
    }

    // 尝试同时保存到Typecho数据库
    $dbSaveSuccess = false;
    $dbError = '开始尝试数据库操作。'; // 初始调试信息

    try {
        // 尝试加载Typecho配置
        $rootDir = dirname(dirname(dirname(__DIR__))); // 修正路径，向上三层到博客根目录
        if (file_exists($rootDir . '/config.inc.php')) {
            $dbError = '找到 config.inc.php，正在加载。';
            require_once $rootDir . '/config.inc.php';

            if (class_exists('Typecho_Db')) {
                $dbError = 'Typecho_Db 类存在，正在获取数据库实例。';
                $db = Typecho_Db::get();
                $prefix = $db->getPrefix();
                $tableName = $prefix . 'sleep_data';
                $dbError = "数据库实例已获取，表名为 {$tableName}。";

                // 检查当天的数据是否已存在
                $existingRecord = $db->fetchRow($db->select()->from($tableName)->where('date = ?', $saveData['date']));

                // 准备要操作的数据
                $dbData = $saveData;

                if ($existingRecord) {
                    // 更新现有记录，不修改date和created_at
                    unset($dbData['date']);
                    unset($dbData['created_at']);

                    $dbError = "找到日期 {$saveData['date']} 的记录，执行更新。";
                    $db->query($db->update($tableName)->rows($dbData)->where('date = ?', $saveData['date']));
                    $dbSaveSuccess = true;
                    $dbError = ''; // 成功后清空
                } else {
                    // 插入新记录
                    $dbError = "未找到日期 {$saveData['date']} 的记录，执行插入。";
                    $db->query($db->insert($tableName)->rows($dbData));
                    $dbSaveSuccess = true;
                    $dbError = ''; // 成功后清空
                }
            } else {
                $dbError = 'config.inc.php 加载后，Typecho_Db 类不存在。';
            }
        } else {
            $dbError = '未找到 config.inc.php。';
        }
    } catch (Exception $e) {
        $dbError = "数据库操作顶层捕获失败: " . $e->getMessage();
    }

    // 返回成功响应
    echo json_encode([
        'status' => 'success',
        'message' => '数据已成功保存' . ($dbSaveSuccess ? ' (文件 & 数据库)' : ' (仅文件)'),
        'saved_data' => $saveData,
        'data_file' => $dataFile,
        'db_save' => $dbSaveSuccess ? 'success' : 'failed',
        'db_error' => $dbError,
        'token_source' => $tokenFromSystem ? 'typecho_config' : (empty($configuredToken) ? 'none' : 'config_file')
    ]);
} catch (Exception $e) {
    // 记录错误到日志
    error_log('SleepData Simple API Error: ' . $e->getMessage());

    // 返回错误响应
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '服务器错误: ' . $e->getMessage(),
    ]);
}
