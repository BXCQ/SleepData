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

if (!function_exists('sleepData_resolveDataFile')) {
    /**
     * 解析可写的数据文件路径
     */
    function sleepData_resolveDataFile()
    {
        $configFile = sleepData_pluginDir() . '/data_config.php';
        $dataFile = '';

        if (file_exists($configFile)) {
            include_once $configFile;
            if (defined('SLEEP_DATA_FILE')) {
                $dataFile = SLEEP_DATA_FILE;
            }
        }

        if ($dataFile === '') {
            $pluginDir = sleepData_pluginDir();
            $possibleDirs = [
                sys_get_temp_dir(),
                '/tmp',
                dirname($pluginDir) . '/uploads',
                dirname(dirname($pluginDir)) . '/tmp',
            ];

            foreach ($possibleDirs as $dir) {
                if (is_dir($dir) && is_writable($dir)) {
                    $dataFile = rtrim($dir, '/') . '/sleep_data.json';
                    break;
                }
            }

            if ($dataFile === '') {
                $dataFile = $pluginDir . '/sleep_data.json';
            }

            $tokenLine = '';
            if (defined('API_ACCESS_TOKEN')) {
                $tokenLine = "define('API_ACCESS_TOKEN', '" . addslashes(API_ACCESS_TOKEN) . "');\n";
            }
            $configContent = "<?php\ndefine('SLEEP_DATA_FILE', '" . addslashes($dataFile) . "');\n" . $tokenLine;
            @file_put_contents($configFile, $configContent);
        }

        return $dataFile;
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
