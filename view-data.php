<?php
// 显示所有错误
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * 将总分钟数格式化为 "X小时Y分钟"
 * @param int $totalMinutes
 * @return string
 */
function formatMinutesToDuration($totalMinutes)
{
    if ($totalMinutes <= 0) {
        return '0分钟';
    }
    $hours = floor($totalMinutes / 60);
    $minutes = $totalMinutes % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . '小时';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . '分钟';
    }
    return implode('', $parts);
}

// 数据文件路径
$dataFile = '';
$configFile = __DIR__ . '/data_config.php';

// 调试信息
$debug = [
    'config_file' => $configFile,
    'config_exists' => file_exists($configFile) ? 'Yes' : 'No',
    'directory' => __DIR__,
    'directory_contents' => array_diff(scandir(__DIR__), ['.', '..']),
];

// 尝试从配置文件中获取数据文件路径
if (file_exists($configFile)) {
    include $configFile;
    if (defined('SLEEP_DATA_FILE')) {
        $dataFile = SLEEP_DATA_FILE;
        $debug['data_file_from_config'] = $dataFile;
    } else {
        $debug['config_error'] = 'SLEEP_DATA_FILE not defined in config';
    }
} else {
    $debug['config_error'] = 'Config file not found';
}

// 如果配置文件不存在，尝试在几个可能的位置查找数据文件
if (empty($dataFile)) {
    $possibleDirs = [
        sys_get_temp_dir(),                   // 系统临时目录
        '/tmp',                               // Linux临时目录
        dirname(__DIR__) . '/uploads',        // Typecho上传目录
        dirname(dirname(__DIR__)) . '/tmp'    // Typecho临时目录
    ];

    $debug['possible_dirs'] = $possibleDirs;

    foreach ($possibleDirs as $dir) {
        $testPath = $dir . '/sleep_data.json';
        if (file_exists($testPath)) {
            $dataFile = $testPath;
            $debug['data_file_found'] = $dataFile;
            break;
        }
    }
}

// 如果仍然没有找到数据文件，使用默认路径
if (empty($dataFile)) {
    $dataFile = __DIR__ . '/sleep_data.json';
    $debug['using_default_path'] = $dataFile;
}

$debug['final_data_file'] = $dataFile;
$debug['file_exists'] = file_exists($dataFile) ? 'Yes' : 'No';
$debug['is_readable'] = is_readable($dataFile) ? 'Yes' : 'No';
$debug['file_size'] = file_exists($dataFile) ? filesize($dataFile) . ' bytes' : 'N/A';

$data = [];

// 读取数据
if (file_exists($dataFile) && is_readable($dataFile)) {
    $jsonData = file_get_contents($dataFile);
    $debug['raw_content'] = substr($jsonData, 0, 1000); // 显示前1000个字符

    if (!empty($jsonData)) {
        $data = json_decode($jsonData, true);
        $debug['json_decode_error'] = json_last_error_msg();
    }
}

// 按日期排序（最新的在前）
if (!empty($data) && is_array($data)) {
    usort($data, function ($a, $b) {
        return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
    });
}
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>睡眠数据查看</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        h1,
        h2 {
            text-align: center;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px 12px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }

        .debug-section {
            margin-top: 50px;
            padding: 20px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .debug-item {
            margin-bottom: 10px;
        }

        .debug-key {
            font-weight: bold;
            margin-right: 10px;
        }

        pre {
            background-color: #f0f0f0;
            padding: 10px;
            border-radius: 5px;
            overflow: auto;
            max-height: 300px;
        }
    </style>
</head>

<body>
    <h1>睡眠数据记录 (文件)</h1>

    <?php if (empty($data) || !is_array($data)): ?>
        <div class="no-data">暂无数据</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>日期</th>
                    <th>分数</th>
                    <th>总睡眠</th>
                    <th>深睡</th>
                    <th>浅睡</th>
                    <th>快速眼动</th>
                    <th>清醒</th>
                    <th>记录时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['date'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item['sleep_score'] ?? ''); ?></td>
                        <td><?php echo formatMinutesToDuration($item['total_sleep_minutes'] ?? 0); ?></td>
                        <td><?php echo formatMinutesToDuration($item['deep_sleep_minutes'] ?? 0); ?></td>
                        <td><?php echo formatMinutesToDuration($item['light_sleep_minutes'] ?? 0); ?></td>
                        <td><?php echo formatMinutesToDuration($item['rem_sleep_minutes'] ?? 0); ?></td>
                        <td><?php echo formatMinutesToDuration($item['awake_minutes'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($item['created_at'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="debug-section">
        <h2>调试信息</h2>
        <?php foreach ($debug as $key => $value): ?>
            <div class="debug-item">
                <span class="debug-key"><?php echo htmlspecialchars($key); ?>:</span>
                <?php if (in_array($key, ['directory_contents', 'raw_content', 'possible_dirs'])): ?>
                    <pre><?php print_r(is_array($value) ? $value : htmlspecialchars($value)); ?></pre>
                <?php else: ?>
                    <?php echo htmlspecialchars(is_array($value) ? json_encode($value) : $value); ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</body>

</html>