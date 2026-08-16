<?php
/**
 * 睡眠数据公共逻辑：令牌校验、时长解析、文件/数据库存储
 */

if (!function_exists('sleepData_pluginDir')) {
    /** 插件根目录（SleepData/） */
    function sleepData_pluginDir()
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('sleepData_blogRoot')) {
    /** Typecho 博客根目录（usr/plugins/SleepData → 上三级） */
    function sleepData_blogRoot()
    {
        return dirname(dirname(dirname(sleepData_pluginDir())));
    }
}

if (!function_exists('sleepData_parseDurationToMinutes')) {
    /**
     * 将 "X小时Y分钟" 或纯数字（分钟）解析为总分钟数
     */
    function sleepData_parseDurationToMinutes($durationStr)
    {
        if ($durationStr === null || $durationStr === '') {
            return 0;
        }
        if (is_numeric($durationStr)) {
            return (int) round((float) $durationStr);
        }

        $minutes = 0;
        $durationStr = (string) $durationStr;
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
}

if (!function_exists('sleepData_getConfiguredToken')) {
    /**
     * 读取已配置的访问令牌
     * @return array{token:string, from_system:bool}
     */
    function sleepData_getConfiguredToken()
    {
        $configuredToken = '';
        $tokenFromSystem = false;
        $rootDir = sleepData_blogRoot();

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
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('SleepData: 从 Typecho 配置读取令牌失败: ' . $e->getMessage());
            }
        }

        if ($configuredToken === '') {
            $configFile = sleepData_pluginDir() . '/data_config.php';
            if (file_exists($configFile)) {
                include_once $configFile;
                if (defined('API_ACCESS_TOKEN') && API_ACCESS_TOKEN !== '') {
                    $configuredToken = API_ACCESS_TOKEN;
                }
            }
        }

        return ['token' => $configuredToken, 'from_system' => $tokenFromSystem];
    }
}

if (!function_exists('sleepData_extractRequestToken')) {
    /**
     * 从 JSON body 或 Authorization Bearer 头读取请求令牌
     */
    function sleepData_extractRequestToken(array $data)
    {
        if (!empty($data['access_token'])) {
            return (string) $data['access_token'];
        }
        if (!empty($data['token'])) {
            return (string) $data['token'];
        }

        $authHeader = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $authHeader = $value;
                    break;
                }
            }
        }

        if (preg_match('/^\s*Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}

if (!function_exists('sleepData_requireValidToken')) {
    /**
     * 校验令牌；失败时直接输出 JSON 并 exit
     * @return array{token:string, from_system:bool}
     */
    function sleepData_requireValidToken(array &$data)
    {
        $tokenInfo = sleepData_getConfiguredToken();
        $configuredToken = $tokenInfo['token'];

        if ($configuredToken !== '') {
            $requestToken = sleepData_extractRequestToken($data);
            if ($requestToken === '' || $requestToken !== $configuredToken) {
                http_response_code(401);
                echo json_encode([
                    'status' => 'error',
                    'message' => '访问令牌无效或缺失',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        unset($data['access_token'], $data['token']);
        return $tokenInfo;
    }
}

if (!function_exists('sleepData_ensureDataDirs')) {
    /**
     * 确保插件目录下 data / raw / health 可写
     * @return string data 目录绝对路径
     */
    function sleepData_ensureDataDirs()
    {
        $base = sleepData_pluginDir() . '/data';
        $dirs = [
            $base,
            $base . '/raw',
            $base . '/raw/daily',
            $base . '/raw/exports',
            $base . '/health',
            $base . '/health/daily',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception('无法创建数据目录: ' . $dir);
            }
        }
        return $base;
    }
}

if (!function_exists('sleepData_healthIndexFile')) {
    function sleepData_healthIndexFile()
    {
        return sleepData_ensureDataDirs() . '/health/index.json';
    }
}

if (!function_exists('sleepData_healthDailyFile')) {
    function sleepData_healthDailyFile($date)
    {
        $date = preg_replace('/[^0-9\\-]/', '', (string) $date);
        return sleepData_ensureDataDirs() . '/health/daily/' . $date . '.json';
    }
}

if (!function_exists('sleepData_stripHealthmdBulkyFields')) {
    /**
     * 去掉样本数组 / lossless 归档等大字段，保留日汇总便于主题与查询
     */
    function sleepData_stripHealthmdBulkyFields(array $record)
    {
        $out = $record;
        unset($out['healthkit_record_archive'], $out['diagnostics']);

        if (!empty($out['heart']) && is_array($out['heart'])) {
            unset(
                $out['heart']['heartRateSamples'],
                $out['heart']['hrvSamples'],
                $out['heart']['heartbeatSeries']
            );
        }
        if (!empty($out['vitals']) && is_array($out['vitals'])) {
            foreach (array_keys($out['vitals']) as $key) {
                if (substr($key, -7) === 'Samples' || substr($key, -7) === 'samples') {
                    unset($out['vitals'][$key]);
                }
            }
        }
        if (!empty($out['sleep']) && is_array($out['sleep'])) {
            unset($out['sleep']['sleepStages']);
        }
        if (!empty($out['workouts']) && is_array($out['workouts'])) {
            $cleaned = [];
            foreach ($out['workouts'] as $workout) {
                if (!is_array($workout)) {
                    continue;
                }
                unset(
                    $workout['route'],
                    $workout['routes'],
                    $workout['locations'],
                    $workout['routeLocations'],
                    $workout['workoutEvents'],
                    $workout['associatedSamples']
                );
                $cleaned[] = $workout;
            }
            $out['workouts'] = $cleaned;
        }
        if (!empty($out['medications']) && is_array($out['medications'])) {
            // doseEvents 可能较长，索引里不需要；日文件保留但限制条数
            if (!empty($out['medications']['doseEvents']) && is_array($out['medications']['doseEvents'])
                && count($out['medications']['doseEvents']) > 50) {
                $out['medications']['doseEvents'] = array_slice($out['medications']['doseEvents'], 0, 50);
                $out['medications']['doseEventsTruncated'] = true;
            }
        }

        return $out;
    }
}

if (!function_exists('sleepData_healthHighlights')) {
    /**
     * 从单日 health_data 提取主题/列表常用亮点（日历日，不做睡眠日换算）
     */
    function sleepData_healthHighlights(array $record)
    {
        $activity = (isset($record['activity']) && is_array($record['activity'])) ? $record['activity'] : [];
        $heart = (isset($record['heart']) && is_array($record['heart'])) ? $record['heart'] : [];
        $vitals = (isset($record['vitals']) && is_array($record['vitals'])) ? $record['vitals'] : [];
        $body = (isset($record['body']) && is_array($record['body'])) ? $record['body'] : [];
        $mind = (isset($record['mindfulness']) && is_array($record['mindfulness'])) ? $record['mindfulness'] : [];
        $sleep = (isset($record['sleep']) && is_array($record['sleep'])) ? $record['sleep'] : [];
        $workouts = (isset($record['workouts']) && is_array($record['workouts'])) ? $record['workouts'] : [];

        $categories = [];
        foreach (['sleep', 'activity', 'heart', 'vitals', 'body', 'nutrition', 'mindfulness', 'mobility', 'hearing', 'workouts', 'medications'] as $cat) {
            if (!empty($record[$cat]) && is_array($record[$cat])) {
                $categories[] = $cat;
            }
        }

        $sleepSeconds = $sleep['totalDuration'] ?? null;
        $sleepMinutes = null;
        if ($sleepSeconds !== null && $sleepSeconds !== '' && is_numeric($sleepSeconds)) {
            $sleepMinutes = (int) round(((float) $sleepSeconds) / 60);
        }

        return [
            'date' => $record['date'] ?? null,
            'steps' => isset($activity['steps']) ? (int) round((float) $activity['steps']) : null,
            'active_calories' => isset($activity['activeCalories']) ? (float) $activity['activeCalories'] : null,
            'exercise_minutes' => isset($activity['exerciseMinutes']) ? (int) round((float) $activity['exerciseMinutes']) : null,
            'stand_hours' => isset($activity['standHours']) ? (int) round((float) $activity['standHours']) : null,
            'distance_km' => isset($activity['walkingRunningDistanceKm'])
                ? (float) $activity['walkingRunningDistanceKm']
                : (isset($activity['walkingRunningDistance']) ? round(((float) $activity['walkingRunningDistance']) / 1000, 3) : null),
            'resting_heart_rate' => isset($heart['restingHeartRate']) ? (float) $heart['restingHeartRate'] : null,
            'average_heart_rate' => isset($heart['averageHeartRate']) ? (float) $heart['averageHeartRate'] : null,
            'hrv' => isset($heart['hrv']) ? (float) $heart['hrv'] : null,
            'blood_oxygen' => isset($vitals['bloodOxygenAvg'])
                ? (float) $vitals['bloodOxygenAvg']
                : (isset($vitals['bloodOxygen']) ? (float) $vitals['bloodOxygen'] : (isset($vitals['bloodOxygenPercent']) ? (float) $vitals['bloodOxygenPercent'] : null)),
            'weight' => isset($body['weight']) ? (float) $body['weight'] : null,
            'mindful_minutes' => isset($mind['mindfulMinutes']) ? (float) $mind['mindfulMinutes'] : null,
            'workout_count' => count($workouts),
            'sleep_total_minutes' => $sleepMinutes,
            'categories' => $categories,
        ];
    }
}

if (!function_exists('sleepData_saveHealthDay')) {
    /**
     * 保存单日健康摘要（去大字段）并更新 index.json
     * @return array{daily_file:string, highlights:array}
     */
    function sleepData_saveHealthDay(array $record)
    {
        $date = isset($record['date']) ? preg_replace('/[^0-9\\-]/', '', (string) $record['date']) : '';
        if ($date === '') {
            throw new Exception('健康日摘要缺少 date');
        }

        sleepData_ensureDataDirs();
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        $summary = sleepData_stripHealthmdBulkyFields($record);
        $highlights = sleepData_healthHighlights($summary);
        $highlights['date'] = $date;
        $highlights['updated_at'] = date('c');

        $payload = [
            'schema' => 'sleepdata.health_day',
            'schema_version' => 1,
            'source_schema' => $record['schema'] ?? null,
            'source_schema_version' => $record['schema_version'] ?? null,
            'date' => $date,
            'updated_at' => $highlights['updated_at'],
            'highlights' => $highlights,
            'health' => $summary,
        ];

        $dailyFile = sleepData_healthDailyFile($date);
        if (@file_put_contents($dailyFile, json_encode($payload, $flags)) === false) {
            throw new Exception('无法写入健康日摘要: ' . $dailyFile);
        }

        $indexFile = sleepData_healthIndexFile();
        $index = [];
        if (file_exists($indexFile)) {
            $decoded = json_decode((string) file_get_contents($indexFile), true);
            if (is_array($decoded)) {
                $index = $decoded;
            }
        }

        $found = false;
        foreach ($index as $i => $row) {
            if (isset($row['date']) && $row['date'] === $date) {
                $index[$i] = $highlights;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $index[] = $highlights;
        }

        usort($index, function ($a, $b) {
            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });

        // 索引保留最近 400 天亮点，完整日文件仍在 daily/
        if (count($index) > 400) {
            $index = array_slice($index, 0, 400);
        }

        if (@file_put_contents($indexFile, json_encode($index, $flags)) === false) {
            throw new Exception('无法写入健康索引: ' . $indexFile);
        }

        return [
            'daily_file' => $dailyFile,
            'highlights' => $highlights,
        ];
    }
}

if (!function_exists('sleepData_loadHealthDay')) {
    function sleepData_loadHealthDay($date)
    {
        $file = sleepData_healthDailyFile($date);
        if (!file_exists($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('sleepData_loadHealthIndex')) {
    function sleepData_loadHealthIndex($limit = 30)
    {
        $file = sleepData_healthIndexFile();
        if (!file_exists($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return [];
        }
        $limit = max(1, (int) $limit);
        return array_slice($decoded, 0, $limit);
    }
}

if (!function_exists('sleepData_getLatestHealthDay')) {
    function sleepData_getLatestHealthDay()
    {
        $index = sleepData_loadHealthIndex(1);
        if (empty($index[0]['date'])) {
            return null;
        }
        return sleepData_loadHealthDay($index[0]['date']);
    }
}

if (!function_exists('sleepData_resolveDataFile')) {
    /**
     * 解析睡眠摘要 JSON 路径（默认：插件目录/data/sleep_data.json）
     */
    function sleepData_resolveDataFile()
    {
        $dataDir = sleepData_ensureDataDirs();
        $preferred = $dataDir . '/sleep_data.json';
        $configFile = sleepData_pluginDir() . '/data_config.php';
        $dataFile = '';

        if (file_exists($configFile)) {
            include_once $configFile;
            if (defined('SLEEP_DATA_FILE') && SLEEP_DATA_FILE !== '') {
                $dataFile = SLEEP_DATA_FILE;
            }
        }

        // 旧默认 /tmp 迁到插件 data 目录
        if ($dataFile === '' || preg_match('#^/tmp/#', $dataFile) || $dataFile === '/tmp/sleep_data.json') {
            if ($dataFile !== '' && $dataFile !== $preferred && file_exists($dataFile) && !file_exists($preferred)) {
                @copy($dataFile, $preferred);
            }
            $dataFile = $preferred;

            $tokenLine = '';
            if (defined('API_ACCESS_TOKEN') && API_ACCESS_TOKEN !== '') {
                $tokenLine = "define('API_ACCESS_TOKEN', '" . addslashes(API_ACCESS_TOKEN) . "');\n";
            } elseif (file_exists($configFile)) {
                // 保留已有 token：重新 include 不安全，仅写路径时尽量读回
                $existing = @file_get_contents($configFile);
                if ($existing && preg_match("/define\\('API_ACCESS_TOKEN',\\s*'([^']*)'\\)/", $existing, $m)) {
                    $tokenLine = "define('API_ACCESS_TOKEN', '" . addslashes($m[1]) . "');\n";
                }
            }
            $configContent = "<?php\n"
                . "define('SLEEP_DATA_FILE', __DIR__ . '/data/sleep_data.json');\n"
                . $tokenLine;
            @file_put_contents($configFile, $configContent);
        }

        $dir = dirname($dataFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dataFile;
    }
}

if (!function_exists('sleepData_saveRawHealthmd')) {
    /**
     * 保存 Health.md 原始 JSON 到插件 data/raw 下
     * - 完整请求包：data/raw/exports/healthmd-时间戳.json
     * - 单日文档：data/raw/daily/YYYY-MM-DD.json（按日覆盖）
     *
     * @param array $data 已解析的 envelope
     * @param string|null $rawBody 原始请求体（优先原样落盘）
     * @return array{export_file:?string, daily_files:array<string>}
     */
    function sleepData_saveRawHealthmd(array $data, $rawBody = null)
    {
        sleepData_ensureDataDirs();
        $pluginDir = sleepData_pluginDir();
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        $exportName = 'healthmd-' . date('Ymd-His');
        if (!empty($data['exported_at'])) {
            $safe = preg_replace('/[^0-9A-Za-z\\-]/', '', str_replace([':', 'T', 'Z', '+'], ['', '-', '', '-'], (string) $data['exported_at']));
            if ($safe !== '') {
                $exportName = 'healthmd-' . substr($safe, 0, 32);
            }
        }
        $exportFile = $pluginDir . '/data/raw/exports/' . $exportName . '.json';

        if (is_string($rawBody) && trim($rawBody) !== '') {
            // 尽量保留原始正文；若不是漂亮格式也照存
            $written = @file_put_contents($exportFile, $rawBody);
        } else {
            $written = @file_put_contents($exportFile, json_encode($data, $flags));
        }
        if ($written === false) {
            throw new Exception('无法写入原始导出文件: ' . $exportFile);
        }

        $records = [];
        if (!empty($data['records']) && is_array($data['records'])) {
            $records = $data['records'];
        } elseif (!empty($data['date'])) {
            $records = [$data];
        }

        $dailyFiles = [];
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['date'])) {
                continue;
            }
            $date = preg_replace('/[^0-9\\-]/', '', (string) $record['date']);
            if ($date === '') {
                continue;
            }
            $dailyFile = $pluginDir . '/data/raw/daily/' . $date . '.json';
            $ok = @file_put_contents($dailyFile, json_encode($record, $flags));
            if ($ok === false) {
                throw new Exception('无法写入单日原始文件: ' . $dailyFile);
            }
            $dailyFiles[] = $dailyFile;
        }

        return [
            'export_file' => $exportFile,
            'daily_files' => $dailyFiles,
        ];
    }
}

if (!function_exists('sleepData_saveRecord')) {
    /**
     * 保存到 JSON 文件，并尽量写入 Typecho 数据库
     * @return array{save_data:array, data_file:string, db_save:bool, db_error:string}
     */
    function sleepData_saveRecord(array $saveData)
    {
        $dataFile = sleepData_resolveDataFile();
        $existingData = [];
        $dataFoundIndex = -1;

        if (file_exists($dataFile)) {
            $existingDataJson = file_get_contents($dataFile);
            if ($existingDataJson !== false && $existingDataJson !== '') {
                $existingData = json_decode($existingDataJson, true) ?: [];
            }
        }

        foreach ($existingData as $index => $record) {
            if (isset($record['date']) && $record['date'] === $saveData['date']) {
                $dataFoundIndex = $index;
                break;
            }
        }

        if ($dataFoundIndex !== -1) {
            $saveData['created_at'] = $existingData[$dataFoundIndex]['created_at'] ?? $saveData['created_at'];
            $existingData[$dataFoundIndex] = $saveData;
        } else {
            $existingData[] = $saveData;
        }

        $result = file_put_contents($dataFile, json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($result === false) {
            throw new Exception('无法写入数据文件: ' . $dataFile);
        }

        $dbSaveSuccess = false;
        $dbError = '开始尝试数据库操作。';

        try {
            $rootDir = sleepData_blogRoot();
            if (file_exists($rootDir . '/config.inc.php')) {
                require_once $rootDir . '/config.inc.php';
                if (class_exists('Typecho_Db')) {
                    $db = Typecho_Db::get();
                    $prefix = $db->getPrefix();
                    $tableName = $prefix . 'sleep_data';
                    $existingRecord = $db->fetchRow($db->select()->from($tableName)->where('date = ?', $saveData['date']));

                    // 仅写入数据表已有字段，忽略 source / score_estimated 等扩展元数据
                    $dbData = [
                        'date' => $saveData['date'] ?? null,
                        'sleep_time' => $saveData['sleep_time'] ?? null,
                        'wake_up_time' => $saveData['wake_up_time'] ?? null,
                        'sleep_score' => $saveData['sleep_score'] ?? null,
                        'deep_sleep_minutes' => $saveData['deep_sleep_minutes'] ?? 0,
                        'light_sleep_minutes' => $saveData['light_sleep_minutes'] ?? 0,
                        'rem_sleep_minutes' => $saveData['rem_sleep_minutes'] ?? 0,
                        'awake_minutes' => $saveData['awake_minutes'] ?? 0,
                        'total_sleep_minutes' => $saveData['total_sleep_minutes'] ?? 0,
                        'created_at' => $saveData['created_at'] ?? date('Y-m-d H:i:s'),
                    ];

                    if ($existingRecord) {
                        unset($dbData['date'], $dbData['created_at']);
                        $db->query($db->update($tableName)->rows($dbData)->where('date = ?', $saveData['date']));
                    } else {
                        $db->query($db->insert($tableName)->rows($dbData));
                    }
                    $dbSaveSuccess = true;
                    $dbError = '';
                } else {
                    $dbError = 'config.inc.php 加载后，Typecho_Db 类不存在。';
                }
            } else {
                $dbError = '未找到 config.inc.php。';
            }
        } catch (Exception $e) {
            $dbError = '数据库操作失败: ' . $e->getMessage();
        }

        return [
            'save_data' => $saveData,
            'data_file' => $dataFile,
            'db_save' => $dbSaveSuccess,
            'db_error' => $dbError,
        ];
    }
}

if (!function_exists('sleepData_normalizeStage')) {
    /**
     * 归一化 HealthKit / 快捷指令中的睡眠阶段名
     */
    function sleepData_normalizeStage($stage)
    {
        $stage = strtolower(trim((string) $stage));
        $stage = str_replace(['_', '-', ' '], '', $stage);

        $map = [
            'deep' => 'deep',
            'asleepdeep' => 'deep',
            'hkcategoryvaluesleepanalysisasleepdeep' => 'deep',
            '深睡' => 'deep',
            'core' => 'light',
            'light' => 'light',
            '浅睡' => 'light',
            'asleepcore' => 'light',
            'hkcategoryvaluesleepanalysisasleepcore' => 'light',
            'rem' => 'rem',
            'asleeprem' => 'rem',
            'hkcategoryvaluesleepanalysisasleeprem' => 'rem',
            '快速眼动' => 'rem',
            'awake' => 'awake',
            '清醒' => 'awake',
            'hkcategoryvaluesleepanalysisawake' => 'awake',
            'inbed' => 'inbed',
            '在床上' => 'inbed',
            'hkcategoryvaluesleepanalysisinbed' => 'inbed',
            'asleep' => 'asleep',
            'asleepunspecified' => 'asleep',
            'hkcategoryvaluesleepanalysisasleepunspecified' => 'asleep',
            'hkcategoryvaluesleepanalysisasleep' => 'asleep',
        ];

        return $map[$stage] ?? 'unknown';
    }
}

if (!function_exists('sleepData_preferredTimezone')) {
    /**
     * 插件默认按中国时区解释「昨晚 18:00」窗口；样本自带偏移时仍以样本为准
     */
    function sleepData_preferredTimezone()
    {
        $candidates = ['Asia/Shanghai', date_default_timezone_get(), 'UTC'];
        foreach ($candidates as $name) {
            if (!$name) {
                continue;
            }
            try {
                return new DateTimeZone($name);
            } catch (Exception $e) {
                continue;
            }
        }
        return new DateTimeZone('UTC');
    }
}

if (!function_exists('sleepData_parseDateTime')) {
    function sleepData_parseDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_numeric($value)) {
            $ts = (float) $value;
            if ($ts > 20000000000) {
                $ts = $ts / 1000;
            }
            return (new DateTimeImmutable('@' . (int) $ts))->setTimezone(sleepData_preferredTimezone());
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            // 带时区偏移的 ISO 字符串会保留其偏移；无偏移时按 Asia/Shanghai
            if (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $value)) {
                return new DateTimeImmutable($value);
            }
            return new DateTimeImmutable($value, sleepData_preferredTimezone());
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('sleepData_resolveWakeDate')) {
    /**
     * 将 Health.md noon-to-noon 日记日期规范为「醒来当天」日历日。
     *
     * Health.md 睡眠摘要按正午到次日正午归属一夜，夜眠常落在「昨晚」的 date 上；
     * 博客主题「今日睡眠」按日历日（起床日）查询，二者不一致时侧边栏会空。
     *
     * 优先级：wakeTimeISO → bedtimeISO+起床钟点 → 日记日+午前起床则 +1 天 → 原 date
     *
     * @param string|null $sourceDate Health.md records[].date
     * @param string|null $bedtimeISO
     * @param string|null $wakeTimeISO
     * @param string|null $sleepTime HH:mm
     * @param string|null $wakeTime HH:mm
     * @return string|null Y-m-d
     */
    function sleepData_resolveWakeDate(
        $sourceDate,
        $bedtimeISO = null,
        $wakeTimeISO = null,
        $sleepTime = null,
        $wakeTime = null
    ) {
        $tz = sleepData_preferredTimezone();

        $wakeDt = sleepData_parseDateTime($wakeTimeISO);
        if ($wakeDt) {
            return $wakeDt->setTimezone($tz)->format('Y-m-d');
        }

        $bedDt = sleepData_parseDateTime($bedtimeISO);
        if ($bedDt && is_string($wakeTime) && preg_match('/^(\d{1,2}):(\d{2})/', trim($wakeTime), $m)) {
            $localBed = $bedDt->setTimezone($tz);
            $candidate = $localBed->setTime((int) $m[1], (int) $m[2], 0);
            if ($candidate <= $localBed) {
                $candidate = $candidate->modify('+1 day');
            }
            return $candidate->format('Y-m-d');
        }

        $sourceDate = is_string($sourceDate) ? trim($sourceDate) : '';
        if ($sourceDate !== '' && is_string($wakeTime) && preg_match('/^(\d{1,2}):(\d{2})/', trim($wakeTime), $m)) {
            try {
                $base = new DateTimeImmutable($sourceDate, $tz);
                // noon-to-noon：起床在 00:00–11:59 属于次日日历
                if ((int) $m[1] < 12) {
                    return $base->modify('+1 day')->format('Y-m-d');
                }
                return $base->format('Y-m-d');
            } catch (Exception $e) {
                return $sourceDate;
            }
        }

        return $sourceDate !== '' ? $sourceDate : null;
    }
}

if (!function_exists('sleepData_aggregateSamples')) {
    /**
     * 从原始睡眠样本汇总一夜数据
     * @param array $samples [{stage|value, start|start_date, end|end_date}, ...]
     * @param string|null $targetDate Y-m-d，表示「醒来当天」
     * @return array|null
     */
    function sleepData_aggregateSamples(array $samples, $targetDate = null)
    {
        $parsed = [];
        foreach ($samples as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $stageRaw = $sample['stage'] ?? $sample['value'] ?? $sample['name'] ?? $sample['Value'] ?? '';
            $start = sleepData_parseDateTime($sample['start'] ?? $sample['start_date'] ?? $sample['Start Date'] ?? null);
            $end = sleepData_parseDateTime($sample['end'] ?? $sample['end_date'] ?? $sample['End Date'] ?? null);
            if (!$start || !$end || $end <= $start) {
                continue;
            }
            $parsed[] = [
                'stage' => sleepData_normalizeStage($stageRaw),
                'start' => $start,
                'end' => $end,
                'minutes' => (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60),
            ];
        }

        if (empty($parsed)) {
            return null;
        }

        usort($parsed, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $tz = $parsed[0]['start']->getTimezone() ?: sleepData_preferredTimezone();

        if ($targetDate) {
            $windowEnd = new DateTimeImmutable($targetDate . ' 15:00:00', $tz);
            $windowStart = (new DateTimeImmutable($targetDate . ' 18:00:00', $tz))->modify('-1 day');
        } else {
            $latestEnd = $parsed[count($parsed) - 1]['end']->setTimezone($tz);
            $targetDate = $latestEnd->format('Y-m-d');
            $windowEnd = new DateTimeImmutable($targetDate . ' 15:00:00', $tz);
            $windowStart = (new DateTimeImmutable($targetDate . ' 18:00:00', $tz))->modify('-1 day');
        }

        $inWindow = [];
        foreach ($parsed as $item) {
            if ($item['start'] < $windowEnd && $item['end'] > $windowStart) {
                $inWindow[] = $item;
            }
        }
        if (empty($inWindow)) {
            $inWindow = $parsed;
        }

        // 按间隔 > 90 分钟拆成多个睡眠会话，取「睡着时长」最长的一夜
        $sessions = [];
        $current = [];
        $lastEnd = null;
        foreach ($inWindow as $item) {
            if ($lastEnd !== null) {
                $gapMinutes = ($item['start']->getTimestamp() - $lastEnd->getTimestamp()) / 60;
                if ($gapMinutes > 90) {
                    if (!empty($current)) {
                        $sessions[] = $current;
                    }
                    $current = [];
                }
            }
            $current[] = $item;
            if ($lastEnd === null || $item['end'] > $lastEnd) {
                $lastEnd = $item['end'];
            }
        }
        if (!empty($current)) {
            $sessions[] = $current;
        }

        $bestSession = null;
        $bestAsleep = -1;
        foreach ($sessions as $session) {
            $asleep = 0;
            foreach ($session as $item) {
                if (in_array($item['stage'], ['deep', 'light', 'rem', 'asleep'], true)) {
                    $asleep += $item['minutes'];
                }
            }
            if ($asleep > $bestAsleep) {
                $bestAsleep = $asleep;
                $bestSession = $session;
            }
        }

        if ($bestSession === null) {
            return null;
        }

        $deep = $light = $rem = $awake = $asleepUnspecified = $inBed = 0;
        $asleepStart = null;
        $asleepEnd = null;
        $inBedStart = null;
        $inBedEnd = null;
        $wakeups = 0;

        foreach ($bestSession as $item) {
            switch ($item['stage']) {
                case 'deep':
                    $deep += $item['minutes'];
                    break;
                case 'light':
                    $light += $item['minutes'];
                    break;
                case 'rem':
                    $rem += $item['minutes'];
                    break;
                case 'awake':
                    $awake += $item['minutes'];
                    $wakeups++;
                    break;
                case 'asleep':
                    $asleepUnspecified += $item['minutes'];
                    break;
                case 'inbed':
                    $inBed += $item['minutes'];
                    break;
            }

            $localStart = $item['start']->setTimezone($tz);
            $localEnd = $item['end']->setTimezone($tz);

            if (in_array($item['stage'], ['deep', 'light', 'rem', 'asleep'], true)) {
                if ($asleepStart === null || $localStart < $asleepStart) {
                    $asleepStart = $localStart;
                }
                if ($asleepEnd === null || $localEnd > $asleepEnd) {
                    $asleepEnd = $localEnd;
                }
            } elseif ($item['stage'] === 'inbed') {
                if ($inBedStart === null || $localStart < $inBedStart) {
                    $inBedStart = $localStart;
                }
                if ($inBedEnd === null || $localEnd > $inBedEnd) {
                    $inBedEnd = $localEnd;
                }
            }
        }

        // OPPO 等设备有时只给 Asleep，无阶段；把未分类睡着计入浅睡，便于图表展示
        if ($deep + $light + $rem === 0 && $asleepUnspecified > 0) {
            $light = $asleepUnspecified;
            $asleepUnspecified = 0;
        } elseif ($asleepUnspecified > 0) {
            $light += $asleepUnspecified;
        }

        $totalSleep = $deep + $light + $rem;
        if ($totalSleep <= 0 && $inBed > 0) {
            $totalSleep = max(0, $inBed - $awake);
            if ($light === 0) {
                $light = $totalSleep;
            }
        }

        $sleepStart = $asleepStart ?: $inBedStart;
        $wakeEnd = $asleepEnd ?: $inBedEnd;
        if ($asleepEnd && $inBedEnd && $inBedEnd > $asleepEnd) {
            $wakeEnd = $inBedEnd;
        }

        if ($sleepStart === null || $wakeEnd === null) {
            return null;
        }

        return [
            'date' => $wakeEnd->format('Y-m-d'),
            'sleep_time' => $sleepStart->format('H:i'),
            'wake_up_time' => $wakeEnd->format('H:i'),
            'deep_sleep_minutes' => $deep,
            'light_sleep_minutes' => $light,
            'rem_sleep_minutes' => $rem,
            'awake_minutes' => $awake,
            'total_sleep_minutes' => $totalSleep,
            'wakeups' => $wakeups,
            'sample_count' => count($bestSession),
        ];
    }
}

if (!function_exists('sleepData_estimateScore')) {
    /**
     * 估算睡眠分数（苹果睡眠评分不可经 HealthKit/快捷指令读取时的兜底）
     * 粗略对齐：时长约 50 + 入睡规律约 30 + 中断约 20
     */
    function sleepData_estimateScore(array $record, array $recentSleepTimes = [])
    {
        $total = (int) ($record['total_sleep_minutes'] ?? 0);
        $awake = (int) ($record['awake_minutes'] ?? 0);
        $wakeups = (int) ($record['wakeups'] ?? 0);

        // 时长：约 8h=50，约 5h50m≈34
        if ($total >= 480) {
            $durationScore = 50;
        } elseif ($total <= 240) {
            $durationScore = 10;
        } else {
            $durationScore = (int) round(10 + ($total - 240) * (40 / 240));
        }

        // 中断
        $interruptPenalty = min(20, (int) round($awake / 3) + $wakeups);
        $interruptScore = max(0, 20 - $interruptPenalty);

        // 入睡规律：有历史则按与中位数偏差计分，否则给中等分
        $bedtimeScore = 20;
        if (!empty($record['sleep_time']) && count($recentSleepTimes) >= 3) {
            $current = sleepData_timeToCircularMinutes($record['sleep_time']);
            $history = array_map('sleepData_timeToCircularMinutes', $recentSleepTimes);
            sort($history);
            $median = $history[(int) floor((count($history) - 1) / 2)];
            $delta = abs($current - $median);
            if ($delta > 12 * 60) {
                $delta = 24 * 60 - $delta;
            }
            if ($delta <= 20) {
                $bedtimeScore = 30;
            } elseif ($delta <= 45) {
                $bedtimeScore = 24;
            } elseif ($delta <= 90) {
                $bedtimeScore = 16;
            } else {
                $bedtimeScore = 8;
            }
        }

        return max(1, min(100, $durationScore + $bedtimeScore + $interruptScore));
    }
}

if (!function_exists('sleepData_timeToCircularMinutes')) {
    function sleepData_timeToCircularMinutes($time)
    {
        $parts = explode(':', (string) $time);
        $h = isset($parts[0]) ? (int) $parts[0] : 0;
        $m = isset($parts[1]) ? (int) $parts[1] : 0;
        $minutes = $h * 60 + $m;
        // 午睡后入睡通常在下午后，映射到以正午为界的环形
        if ($minutes > 12 * 60) {
            $minutes -= 24 * 60;
        }
        return $minutes;
    }
}

if (!function_exists('sleepData_getRecentSleepTimes')) {
    /**
     * 从已存 JSON 读取最近入睡时间，供评分估算
     */
    function sleepData_getRecentSleepTimes($excludeDate = null, $limit = 13)
    {
        $times = [];
        try {
            $dataFile = sleepData_resolveDataFile();
            if (!file_exists($dataFile)) {
                return $times;
            }
            $rows = json_decode(file_get_contents($dataFile), true) ?: [];
            usort($rows, function ($a, $b) {
                return strcmp($b['date'] ?? '', $a['date'] ?? '');
            });
            foreach ($rows as $row) {
                if ($excludeDate && ($row['date'] ?? '') === $excludeDate) {
                    continue;
                }
                if (!empty($row['sleep_time'])) {
                    $times[] = substr($row['sleep_time'], 0, 5);
                }
                if (count($times) >= $limit) {
                    break;
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return $times;
    }
}
