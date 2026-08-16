<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 睡眠数据记录插件
 * 
 * @package SleepData
 * @author 璇
 * @version 1.9.0
 * @link https://blog.ybyq.wang
 */
class SleepData_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $tableName = $prefix . 'sleep_data';

        // 创建数据表，时长字段使用INT类型存储分钟数
        $db->query("CREATE TABLE IF NOT EXISTS `{$tableName}` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `date` date DEFAULT NULL,
          `sleep_time` time DEFAULT NULL,
          `wake_up_time` time DEFAULT NULL,
          `sleep_score` int(3) DEFAULT NULL,
          `deep_sleep_minutes` int(11) DEFAULT NULL,
          `light_sleep_minutes` int(11) DEFAULT NULL,
          `rem_sleep_minutes` int(11) DEFAULT NULL,
          `awake_minutes` int(11) DEFAULT NULL,
          `total_sleep_minutes` int(11) DEFAULT NULL,
          `created_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

        // 安全地添加新列，避免重复添加导致错误
        // 使用 SHOW COLUMNS 检查列是否存在，更具兼容性
        $columns = $db->fetchAll($db->query("SHOW COLUMNS FROM `{$tableName}`"));
        $hasSleepTime = false;
        $hasWakeUpTime = false;
        $hasDateAsDateType = false;

        foreach ($columns as $column) {
            if ($column['Field'] == 'sleep_time') {
                $hasSleepTime = true;
            }
            if ($column['Field'] == 'wake_up_time') {
                $hasWakeUpTime = true;
            }
            if ($column['Field'] == 'date' && strtolower($column['Type']) == 'date') {
                $hasDateAsDateType = true;
            }
        }

        if (!$hasSleepTime) {
            $db->query('ALTER TABLE `' . $tableName . '` ADD COLUMN `sleep_time` TIME DEFAULT NULL AFTER `date`;');
        }
        if (!$hasWakeUpTime) {
            $db->query('ALTER TABLE `' . $tableName . '` ADD COLUMN `wake_up_time` TIME DEFAULT NULL AFTER `sleep_time`;');
        }
        // 更新date字段类型
        if (!$hasDateAsDateType) {
            $db->query('ALTER TABLE `' . $tableName . '` MODIFY COLUMN `date` DATE DEFAULT NULL;');
        }

        return _t('插件已经激活，数据表已更新！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        return _t('插件已被禁用');
    }

    /**
     * 格式化分钟为 “X小时Y分钟” 的字符串
     * @param int $minutes
     * @return string
     */
    public static function formatMinutesToDuration($minutes)
    {
        if ($minutes <= 0) {
            return '0分钟';
        }
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}小时";
        }
        if ($remainingMinutes > 0) {
            $parts[] = "{$remainingMinutes}分钟";
        }
        return implode('', $parts);
    }

    /**
     * 获取今天的睡眠数据（按醒来当天的日历日）
     * 兼容旧数据：Health.md noon-to-noon 曾把今早醒来的一夜记在昨天。
     * @return array|null
     */
    public static function getTodaySleepData()
    {
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $tableName = $prefix . 'sleep_data';

            $tzName = 'Asia/Shanghai';
            try {
                $tz = new DateTimeZone($tzName);
            } catch (Exception $e) {
                $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
            }
            $now = new DateTimeImmutable('now', $tz);
            $today = $now->format('Y-m-d');
            $yesterday = $now->modify('-1 day')->format('Y-m-d');

            $todayRow = $db->fetchRow($db->select()->from($tableName)->where('date = ?', $today));
            if (!empty($todayRow)) {
                return $todayRow;
            }

            // 旧记录可能仍用 Health.md 日记日（昨夜）
            $yesterdayRow = $db->fetchRow($db->select()->from($tableName)->where('date = ?', $yesterday));
            if (!empty($yesterdayRow)) {
                $wake = isset($yesterdayRow['wake_up_time']) ? substr((string) $yesterdayRow['wake_up_time'], 0, 5) : '';
                if ($wake !== '' && $wake < '12:00') {
                    return $yesterdayRow;
                }
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 获取最近一条睡眠数据（主题侧边栏等会调用此方法）
     * 优先返回今天的记录；今天没有则返回按日期最新的一条。
     * @return array|null
     */
    public static function getLatestSleepData()
    {
        try {
            $todayData = self::getTodaySleepData();
            if (!empty($todayData)) {
                return $todayData;
            }

            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $tableName = $prefix . 'sleep_data';
            return $db->fetchRow(
                $db->select()->from($tableName)->order('date', Typecho_Db::SORT_DESC)->limit(1)
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 获取指定日（或今天）的全日健康亮点（步数、心率等）
     * 数据来自 Health.md 导出后的 data/health/
     * @param string|null $date Y-m-d，null 表示今天
     * @return array|null highlights
     */
    public static function getTodayHealthData($date = null)
    {
        try {
            $helper = dirname(__FILE__) . '/lib/SleepDataHelper.php';
            if (file_exists($helper)) {
                require_once $helper;
            }
            if ($date === null) {
                try {
                    $tz = new DateTimeZone('Asia/Shanghai');
                } catch (Exception $e) {
                    $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
                }
                $date = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            }
            $day = sleepData_loadHealthDay($date);
            return $day['highlights'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 最近一日全日健康亮点
     * @return array|null
     */
    public static function getLatestHealthData()
    {
        try {
            $helper = dirname(__FILE__) . '/lib/SleepDataHelper.php';
            if (file_exists($helper)) {
                require_once $helper;
            }
            $day = sleepData_getLatestHealthDay();
            return $day['highlights'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 最近 N 天健康亮点列表
     * @param int $days
     * @return array
     */
    public static function getHealthHighlights($days = 14)
    {
        try {
            $helper = dirname(__FILE__) . '/lib/SleepDataHelper.php';
            if (file_exists($helper)) {
                require_once $helper;
            }
            return sleepData_loadHealthIndex($days);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 获取图表和统计所需的数据
     * @param int $days
     * @return array|null
     */
    public static function getSleepDataForChart($days = 30)
    {
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $tableName = $prefix . 'sleep_data';

            $limit = (int)$days;
            $history = $db->fetchAll(
                $db->select()->from($tableName)->order('date', Typecho_Db::SORT_DESC)->limit($limit)
            );

            $stats = [
                'today_sleep' => 0,
                'week_avg' => 0,
                'month_avg' => 0,
                'best_record' => null,
                'worst_record' => null,
                'total_sleep_all_time' => 0,
                'avg_sleep_time' => null,
                'latest_sleep_time_week' => null,
            ];

            // 获取累计总睡眠
            $totalSleepRow = $db->fetchRow($db->select('SUM(total_sleep_minutes) AS total')->from($tableName));
            $stats['total_sleep_all_time'] = $totalSleepRow ? (int)$totalSleepRow['total'] : 0;

            if (empty($history)) {
                return ['stats' => $stats, 'chart_data' => []];
            }

            $week_sleep_minutes = 0;
            $week_count = 0;
            $month_sleep_minutes = 0;
            $month_count = 0;

            $best_score = 0;
            $best_date = '';
            $worst_score = 101;
            $worst_date = '';

            $sleep_times_minutes = [];
            $latest_sleep_time_val = -24 * 60; // Initial value for comparison
            $latest_sleep_time_str = '';
            $latest_sleep_day = '';
            $week_day_map = ['日', '一', '二', '三', '四', '五', '六'];

            $seven_days_ago = strtotime('-7 days');
            $today_date = date('Y-m-d');

            if ($history[0]['date'] == $today_date) {
                $totalSleepToday = ($history[0]['deep_sleep_minutes'] ?? 0) + ($history[0]['light_sleep_minutes'] ?? 0) + ($history[0]['rem_sleep_minutes'] ?? 0);
                $stats['today_sleep'] = $totalSleepToday;
            }

            foreach ($history as $item) {
                $current_score = (int)$item['sleep_score'];
                $current_sleep_minutes = ($item['deep_sleep_minutes'] ?? 0) + ($item['light_sleep_minutes'] ?? 0) + ($item['rem_sleep_minutes'] ?? 0);
                $item_timestamp = strtotime($item['date']);

                // For average sleep time calculation
                if (!empty($item['sleep_time'])) {
                    list($h, $m) = explode(':', $item['sleep_time']);
                    $minutes_from_midnight = $h * 60 + $m;
                    // Handle times past noon (e.g., 23:00 is before 01:00)
                    if ($minutes_from_midnight > 12 * 60) {
                        $minutes_from_midnight -= 24 * 60;
                    }
                    $sleep_times_minutes[] = $minutes_from_midnight;
                }

                // For latest sleep time this week
                if ($item_timestamp > $seven_days_ago && !empty($item['sleep_time'])) {
                    list($h, $m) = explode(':', $item['sleep_time']);
                    $val = ($h * 60 + $m);
                    if ($val > 12 * 60) $val -= (24 * 60);

                    if ($val > $latest_sleep_time_val) {
                        $latest_sleep_time_val = $val;
                        $latest_sleep_time_str = substr($item['sleep_time'], 0, 5);
                        $day_of_week_index = date('w', $item_timestamp);
                        $latest_sleep_day = '周' . $week_day_map[$day_of_week_index];
                    }
                }

                $month_sleep_minutes += $current_sleep_minutes;
                $month_count++;

                if ($item_timestamp > $seven_days_ago) {
                    $week_sleep_minutes += $current_sleep_minutes;
                    $week_count++;
                }

                if ($current_score >= $best_score) {
                    $best_score = $current_score;
                    $best_date = $item['date'];
                }
                if ($current_score > 0 && $current_score < $worst_score) {
                    $worst_score = $current_score;
                    $worst_date = $item['date'];
                }
            }

            $stats['week_avg'] = $week_count > 0 ? (int)round($week_sleep_minutes / $week_count) : 0;
            $stats['month_avg'] = $month_count > 0 ? (int)round($month_sleep_minutes / $month_count) : 0;
            if ($best_score > 0) $stats['best_record'] = ['date' => $best_date, 'score' => $best_score];
            if ($worst_score < 101) $stats['worst_record'] = ['date' => $worst_date, 'score' => $worst_score];

            // Calculate average sleep time
            if (!empty($sleep_times_minutes)) {
                $avg_minutes = array_sum($sleep_times_minutes) / count($sleep_times_minutes);
                if ($avg_minutes < 0) {
                    $avg_minutes += 24 * 60;
                }
                $avg_h = floor($avg_minutes / 60);
                $avg_m = $avg_minutes % 60;
                $stats['avg_sleep_time'] = sprintf('%02d:%02d', $avg_h, $avg_m);
            }

            if (!empty($latest_sleep_time_str)) {
                $stats['latest_sleep_time_week'] = $latest_sleep_time_str . ' (' . $latest_sleep_day . ')';
            }

            $chartData = array_map(function ($item) {
                $total_sleep_for_chart = ($item['deep_sleep_minutes'] ?? 0) + ($item['light_sleep_minutes'] ?? 0) + ($item['rem_sleep_minutes'] ?? 0);
                return [
                    'date' => $item['date'],
                    'score' => (int)$item['sleep_score'],
                    'total_sleep' => $total_sleep_for_chart,
                    'deep_sleep' => (int)$item['deep_sleep_minutes'],
                    'light_sleep' => (int)$item['light_sleep_minutes'],
                    'rem_sleep' => (int)$item['rem_sleep_minutes'],
                ];
            }, array_reverse($history));

            return ['stats' => $stats, 'chart_data' => $chartData];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 获取睡眠数据
     */
    private static function getSleepData()
    {
        $db = Typecho_Db::get();
        $tableName = $db->getPrefix() . 'sleep_data';
        try {
            // 使用 'DESC' 字符串代替常量，增加兼容性，并限制50条
            $sleepData = $db->fetchAll($db->select()->from($tableName)->order('created_at', 'DESC')->limit(50));
            return $sleepData;
        } catch (Exception $e) {
            // 将异常抛出，由config方法捕获并显示
            throw $e;
        }
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 获取API地址
        $api_url = Helper::options()->siteUrl . 'usr/plugins/SleepData/simple-api.php';
        $shortcut_api_url = Helper::options()->siteUrl . 'usr/plugins/SleepData/shortcut-api.php';
        $healthmd_api_url = Helper::options()->siteUrl . 'usr/plugins/SleepData/healthmd-api.php';
        $health_query_url = Helper::options()->siteUrl . 'usr/plugins/SleepData/health-api.php';

        // 添加访问令牌设置
        $accessToken = new Typecho_Widget_Helper_Form_Element_Text(
            'accessToken',
            null,
            md5(uniqid(rand(), true)), // 默认生成一个随机令牌
            _t('API访问令牌'),
            _t('设置一个自定义的访问令牌，用于API认证。请使用复杂字符串，留空表示不启用认证。')
        );
        $form->addInput($accessToken);

        // 直接输出HTML以获得最大兼容性
        echo '<style>
            .typecho-page-main { max-width: 100% !important; } /* 加宽主内容区域 */
            .typecho-list-table { width: 100%; border-collapse: collapse; }
            .typecho-list-table th, .typecho-list-table td { padding: 8px; border: 1px solid #E9E9E9; text-align: left; }
            .typecho-list-table thead { background-color: #F5F5F5; }
            .api-info { background-color: #f0f8ff; padding: 10px 15px; border-radius: 5px; margin-bottom: 20px; }
            .api-info code { background: #e1e4e8; padding: 2px 6px; border-radius: 3px; font-size: 14px; }
        </style>';

        echo '<div class="api-info">';
        echo '<h3>API 信息</h3>';
        echo '<p><strong>推荐 · Health.md API Export（写入）：</strong><br><code>' . htmlspecialchars($healthmd_api_url) . '</code></p>';
        echo '<p>在 Health.md 填写上述 Endpoint，Token 填本页访问令牌。可勾选 Sleep / Activity / Heart / Vitals 等<strong>全部</strong>指标。</p>';
        echo '<p><strong>全日健康查询：</strong><br><code>' . htmlspecialchars($health_query_url) . '?latest=1&amp;access_token=令牌</code></p>';
        echo '<p>手动上传页 API：<br><code>' . htmlspecialchars($api_url) . '</code></p>';
        echo '<p>iOS 快捷指令 API：<br><code>' . htmlspecialchars($shortcut_api_url) . '</code></p>';
        $shortcut_install_url = Helper::options()->siteUrl . 'usr/plugins/SleepData/install-shortcut.html';
        echo '<p>手机一键导入快捷指令：<br><code>' . htmlspecialchars($shortcut_install_url) . '</code></p>';
        echo '<p>说明见 <code>HEALTHMD.md</code> / <code>SHORTCUTS.md</code>。</p>';
        echo '<p>重要：使用API时需要在请求中包含访问令牌，否则请求会被拒绝。</p>';
        echo '<p>您可以通过两种方式设置访问令牌：</p>';
        echo '<ol>';
        echo '<li><strong>方法一（推荐）：</strong>直接在上方表单中填写并保存设置。</li>';
        echo '<li><strong>方法二（备用）：</strong>编辑 <code>usr/plugins/SleepData/data_config.php</code> 文件，修改 API_ACCESS_TOKEN 常量的值。</li>';
        echo '</ol>';
        echo '<p>系统会优先使用方法一设置的令牌，如果未设置则使用方法二中的令牌。</p>';
        echo '</div>';

        // 健康亮点预览
        try {
            $helper = dirname(__FILE__) . '/lib/SleepDataHelper.php';
            if (file_exists($helper)) {
                require_once $helper;
                $healthRows = sleepData_loadHealthIndex(7);
                if (!empty($healthRows)) {
                    echo '<h3>' . _t('全日健康亮点 (最近7天)') . '</h3>';
                    echo '<table class="typecho-list-table"><thead><tr>';
                    echo '<th>日期</th><th>步数</th><th>活动热量</th><th>静息心率</th><th>HRV</th><th>睡眠(分)</th><th>分类</th>';
                    echo '</tr></thead><tbody>';
                    foreach ($healthRows as $row) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['date'] ?? '') . '</td>';
                        echo '<td>' . htmlspecialchars((string) ($row['steps'] ?? '-')) . '</td>';
                        echo '<td>' . htmlspecialchars((string) ($row['active_calories'] ?? '-')) . '</td>';
                        echo '<td>' . htmlspecialchars((string) ($row['resting_heart_rate'] ?? '-')) . '</td>';
                        echo '<td>' . htmlspecialchars((string) ($row['hrv'] ?? '-')) . '</td>';
                        echo '<td>' . htmlspecialchars((string) ($row['sleep_total_minutes'] ?? '-')) . '</td>';
                        echo '<td>' . htmlspecialchars(implode(',', $row['categories'] ?? [])) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        echo '<h3>' . _t('数据库中的睡眠数据 (最近50条)') . '</h3>';

        // 添加一个隐藏的元素，防止Typecho在没有表单元素时报错
        $info = new Typecho_Widget_Helper_Form_Element_Hidden('info', null, '1');
        $form->addInput($info);

        // 从数据库获取数据
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $tableName = $prefix . 'sleep_data';
            $sleepData = $db->fetchAll($db->select()->from($tableName)->order('created_at', 'DESC')->limit(50));

            if (!empty($sleepData)) {
                echo '<table class="typecho-list-table" style="width:160%;">';
                echo '<thead><tr><th>日期</th><th>入睡时间</th><th>醒来时间</th><th>分数</th><th>总睡眠</th><th>深睡</th><th>浅睡</th><th>快速眼动</th><th>清醒</th><th>记录时间</th></tr></thead>';
                echo '<tbody>';

                foreach ($sleepData as $item) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['date'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($item['sleep_time'], 0, 5) ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($item['wake_up_time'], 0, 5) ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($item['sleep_score'] ?? '') . '</td>';
                    echo '<td>' . self::formatMinutesToDuration($item['total_sleep_minutes'] ?? 0) . '</td>';
                    echo '<td>' . self::formatMinutesToDuration($item['deep_sleep_minutes'] ?? 0) . '</td>';
                    echo '<td>' . self::formatMinutesToDuration($item['light_sleep_minutes'] ?? 0) . '</td>';
                    echo '<td>' . self::formatMinutesToDuration($item['rem_sleep_minutes'] ?? 0) . '</td>';
                    echo '<td>' . self::formatMinutesToDuration($item['awake_minutes'] ?? 0) . '</td>';
                    echo '<td>' . htmlspecialchars($item['created_at'] ?? '') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<div style="text-align: center; padding: 20px; color: #666;">数据库中暂无睡眠数据</div>';
            }
        } catch (Exception $e) {
            echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-top: 20px;">';
            echo '<h3>加载数据时出错</h3>';
            echo '<p><b>错误信息:</b> ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}
}
