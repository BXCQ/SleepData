SleepData 插件是一个用于在 Typecho 博客系统中记录、存储和分析个人睡眠数据的工具。它解决了部分健康应用（如 OPPO 健康）数据封闭、无公开 API 的问题，允许用户将数据掌握在自己手中。

## 核心问题与解决方案

许多健康应用（例如 OPPO 健康）不提供公开的 API 接口，也无法将数据同步到 Google Fit 等开放平台，导致用户的健康数据完全封闭，难以进行统一管理和长期分析。

本插件通过提供一个独立的数据录入和存储系统来解决此问题。用户可以通过截图 OCR 识别、手动录入或 API 调用等方式，将睡眠数据保存在自己的服务器上，从而实现数据的自主掌控和长期追踪。

## 工作流程

整个数据处理流程从用户手机端的健康应用开始，经过前端的 OCR 识别与数据提交，再到服务器端的处理与存储，最后通过不同的页面进行展示。
![][1]


## 插件特性

- **数据自主可控**: 所有数据存储在用户自己的服务器上。
- **双重存储**: 同时保存到数据库和 JSON 文件，保证数据安全。
- **OCR 自动录入**: 通过识别截图，简化数据输入过程。
- **API 支持**: 开放 API 接口，方便进行二次开发或批量导入。
- **自适应存储**: 自动检测服务器环境，寻找可写目录，无需手动配置。
- **双重配置**: 支持后台配置和文件配置两种方式，提高灵活性。
- **Handsome 主题集成**: 与 Handsome 主题无缝集成，支持顶部导航栏和侧边栏展示。


## 使用指南

### 1. 安装插件

1.  从源码地址下载插件压缩包。
2.  解压后，将文件夹重命名为 `SleepData`。
3.  上传 `SleepData` 文件夹到 Typecho 的 `usr/plugins` 目录下。
4.  登录 Typecho 后台，进入"控制台" -> "插件"，找到"SleepData"并启用。

### 2. 配置插件

启用插件后，点击"设置"进入配置页面。

- **API 访问令牌**: 设置一个足够复杂的字符串作为 API 访问的密钥。这是保证数据安全的关键。
  ![][2]

此令牌有两种配置方式，系统会优先使用方法一：

1.  **方法一（推荐）**: 直接在后台设置页面填写并保存。
2.  **方法二（备用）**: 编辑插件目录下的 `data_config.php` 文件，修改 `API_ACCESS_TOKEN` 的值。

### 3. 数据录入

#### 方式一：OCR 识别上传（推荐）

这是最便捷的方式，尤其适用于从手机健康 App 录入数据。

1.  在浏览器中打开插件目录下的 `https://博客地址/sleep-data-uploader/index.html` 文件。
2.  填写您的 **API 地址** 和 **访问令牌** （一次填写后浏览器会记住）。
3.  点击"拍照识别"或"选择截图"按钮，上传您在健康 App 中截取的睡眠数据图片。
4.  系统会自动识别图片中的数据并填充到表单中。
5.  核对无误后，点击"发送数据"。

![][3]

#### 方式二：手动输入

如果 OCR 识别有误或没有截图，可以选择手动填写所有睡眠数据，然后点击"发送数据"。

![][4]

#### 方式三：通过 API 上传

对于有开发能力的用户，可以通过 POST 请求直接向 API `https://博客地址/usr/plugins/SleepData/simple-api.php` 提交数据。请求体为 JSON 格式，需包含`access_token`及所有睡眠数据字段。

### 4. 查看数据

1.  **后台查看**: 在 Typecho 后台的插件设置页面，会直接展示最近 50 条睡眠记录。
2.  **独立页面查看**: 访问 `https://博客地址/usr/plugins/SleepData/view-data.php` 可以查看 JSON 文件中的所有数据记录。
3.  **数据库查看**: 在数据库中的`typecho_sleep_data`表中查看所有数据记录。
4.  **根目录查看**: 在根目录下的`temp/sleep_data.json`文件中查看所有数据记录。

### 5. 在 Handsome 主题中展示睡眠数据

#### 展示一：添加到顶部导航栏

1. 效果展示
![][5]
2. 添加代码到主题的`headnav.php`文件中。
![添加位置][6]
[collapse status="false" title="php代码，放在`nav navbar-nav hidden-sm`的`ul`中"]
[hide]
```php
            <!-- 睡眠统计 -->
            <li class="dropdown pos-stc" id="SleepDataPos">
                <a id="SleepData" href="#" data-toggle="dropdown" class="dropdown-toggle feathericons dropdown-toggle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-moon">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                    </svg>
                    <span class="caret"></span>
                </a>
                <div class="dropdown-menu wrapper w-full bg-white">
                    <div class="row">
                        <div class="col-sm-4 b-l b-light">
                            <div class="m-t-xs m-b-xs font-bold">睡眠统计</div>
                            <div class="">
                                <span class="pull-right text-info" id="today_sleep">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-bed fa-fw" aria-hidden="true"></i> 今日睡眠</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-info" id="week_avg_sleep">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-calendar-alt fa-fw" aria-hidden="true"></i> 本周平均</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-info" id="month_avg_sleep">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-calendar-alt fa-fw" aria-hidden="true"></i> 本月平均</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-info" id="total_sleep_all_time">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-history fa-fw" aria-hidden="true"></i> 累计睡眠</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-info" id="avg_sleep_time">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="far fa-clock fa-fw" aria-hidden="true"></i> 平均入睡</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-danger" id="latest_sleep_time_week">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-exclamation-circle fa-fw" aria-hidden="true"></i> 本周最晚</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-success" id="best_day_sleep">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-trophy fa-fw" aria-hidden="true"></i> 质量最佳</span>
                            </div>
                            <br />
                            <div class="">
                                <span class="pull-right text-danger" id="worst_day_sleep">
                                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                </span>
                                <span><i class="fas fa-thumbs-down fa-fw" aria-hidden="true"></i> 质量最差</span>
                            </div>
                        </div>
                        <div class="col-sm-8 b-l b-light">
                            <div class="m-t-xs m-b-xs font-bold">睡眠趋势</div>
                            <div class="text-center">
                                <nav class="loading-echart text-center m-t-lg m-b-lg">
                                    <p class="infinite-scroll-request"><i class="animate-spin fontello fontello-refresh"></i>加载中...</p>
                                </nav>
                                <div id="sleep-chart" class="top-echart hide" style="height: 300px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </li>    
```
[/hide]
[/collapse]
[collapse status="false" title="JS代码，添加到`</header>`前面"]
[hide]
```js
    <!-- 睡眠统计图表的JavaScript代码 -->
    <script>
        try {
            (function($) {
                $(document).ready(function() {
                    var sleepChartLoaded = false;

                    function getSleepData() {
                        <?php
                        if (class_exists('SleepData_Plugin')) {
                            try {
                                $sleepPluginData = SleepData_Plugin::getSleepDataForChart();
                                echo 'var sleepDataForChart = ' . json_encode($sleepPluginData) . ';';
                            } catch (Exception $e) {
                                echo "console.error('PHP Error getting sleep data: " . addslashes($e->getMessage()) . "');";
                                echo 'var sleepDataForChart = null;';
                            }
                        } else {
                            echo 'var sleepDataForChart = null;';
                        }
                        ?>
                        return sleepDataForChart;
                    }

                    $('#SleepDataPos').on('show.bs.dropdown', function() {
                        if (sleepChartLoaded) {
                            return;
                        }

                        var sleepData = getSleepData();
                        if (!sleepData) {
                            // alert('无法获取睡眠数据，请检查插件是否正常。');
                            return;
                        }

                        $('#SleepDataPos .loading-echart').hide();
                        $('#sleep-chart').removeClass('hide').css('height', '300px');
                        updateSleepStats(sleepData.stats);

                        setTimeout(function() {
                            // 使用延迟加载器加载 ECharts
                            if (window.loadECharts) {
                                window.loadECharts(function(echarts) {
                                    renderSleepChart(sleepData.chart_data);
                                });
                            } else {
                                // 如果延迟加载器不可用，显示错误信息
                                $('#sleep-chart').html('<div style="padding:20px;text-align:center;color:#999;">图表库加载失败</div>');
                            }
                        }, 150);

                        sleepChartLoaded = true;
                    });

                    function formatMinutesToDuration(minutes) {
                        if (!minutes || minutes <= 0) return '0分钟';
                        var hours = Math.floor(minutes / 60);
                        var mins = minutes % 60;
                        var parts = [];
                        if (hours > 0) parts.push(hours + 'h');
                        if (mins > 0) parts.push(mins + 'm');
                        return parts.join(' ');
                    }

                    function updateSleepStats(stats) {
                        if (!stats) {
                            stats = {
                                today_sleep: 0,
                                week_avg: 0,
                                month_avg: 0,
                                best_record: null,
                                worst_record: null,
                                total_sleep_all_time: 0,
                                avg_sleep_time: 'N/A',
                                latest_sleep_time_week: 'N/A'
                            };
                        }
                        $('#today_sleep').text(formatMinutesToDuration(stats.today_sleep));
                        $('#week_avg_sleep').text(formatMinutesToDuration(stats.week_avg));
                        $('#month_avg_sleep').text(formatMinutesToDuration(stats.month_avg));
                        $('#total_sleep_all_time').text(formatMinutesToDuration(stats.total_sleep_all_time, true));
                        $('#avg_sleep_time').text(stats.avg_sleep_time || 'N/A');
                        $('#latest_sleep_time_week').text(stats.latest_sleep_time_week || 'N/A');
                        $('#best_day_sleep').html(stats.best_record ? stats.best_record.score + '分 <small>(' + stats.best_record.date.substring(5) + ')</small>' : 'N/A');
                        $('#worst_day_sleep').html(stats.worst_record ? stats.worst_record.score + '分 <small>(' + stats.worst_record.date.substring(5) + ')</small>' : 'N/A');
                    }

                    function renderSleepChart(chartData) {
                        if (typeof echarts === 'undefined') {
                            return;
                        }
                        var chartElement = document.getElementById('sleep-chart');
                        if (!chartElement) return;
                        var myChart = echarts.init(chartElement);

                        var dates = chartData.map(item => item.date.substring(5));

                        var option = {
                            tooltip: {
                                trigger: 'axis',
                                confine: true,
                                formatter: function(params) {
                                    var res = params[0].axisValueLabel + '<br/>';
                                    var data = chartData[params[0].dataIndex];
                                    res += params[0].marker + '得分: <strong>' + data.score + '</strong><br/>';
                                    res += params[1].marker + '总睡眠: <strong>' + formatMinutesToDuration(data.total_sleep) + '</strong><br/>';
                                    res += '<div style="margin-left: 18px;">';
                                    res += '深睡: ' + formatMinutesToDuration(data.deep_sleep) + '<br/>';
                                    res += '浅睡: ' + formatMinutesToDuration(data.light_sleep) + '<br/>';
                                    res += '快速眼动: ' + formatMinutesToDuration(data.rem_sleep);
                                    res += '</div>';
                                    return res;
                                }
                            },
                            legend: {
                                data: ['睡眠得分', '总睡眠时长'],
                                top: 10,
                            },
                            grid: {
                                left: '3%',
                                right: '4%',
                                bottom: '15%',
                                containLabel: true
                            },
                            xAxis: {
                                type: 'category',
                                data: dates
                            },
                            yAxis: [{
                                    type: 'value',
                                    name: '分钟',
                                    position: 'right',
                                    axisLabel: {
                                        formatter: '{value} m'
                                    }
                                },
                                {
                                    type: 'value',
                                    name: '分数',
                                    position: 'left',
                                    min: 50,
                                    max: 100
                                }
                            ],
                            dataZoom: [{
                                type: 'slider',
                                start: Math.max(0, 100 - (15 / dates.length * 100)),
                                end: 100,
                                height: 20,
                                bottom: 10
                            }],
                            series: [{
                                    name: '睡眠得分',
                                    type: 'line',
                                    smooth: true,
                                    yAxisIndex: 1,
                                    data: chartData.map(item => item.score),
                                    itemStyle: {
                                        color: '#91cc75'
                                    }
                                },
                                {
                                    name: '总睡眠时长',
                                    type: 'bar',
                                    yAxisIndex: 0,
                                    data: chartData.map(item => item.total_sleep),
                                    itemStyle: {
                                        color: '#5470c6'
                                    }
                                }
                            ]
                        };
                        myChart.setOption(option);
                        $(window).on('resize', function() {
                            myChart.resize();
                        });
                    }
                });
            })(jQuery);
        } catch (e) {
            // alert('A critical error occurred in sleep data script: ' + e.message);
        }
    </script>
```
[/hide]
[/collapse]

#### 展示二：添加到侧边栏

1. 效果展示
![][7]
2. 添加代码到主题的`sidebar.php`文件中。
![展示位置][8]
[collapse status="false" title="php代码，放在`博客信息`的上下即可"]
[hide]
```php
<!-- 今日睡眠数据 -->
            <?php if (class_exists('SleepData_Plugin')): ?>
                <section id="sleep_data_widget" class="widget widget_categories wrapper-md padder-v-none clear">
                    <h5 class="widget-title m-t-none"><?php _me("今日睡眠") ?></h5>
                    <div class="panel wrapper-sm padder-v-ssm" style="padding: 15px !important; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <?php
                        $sleepData = SleepData_Plugin::getTodaySleepData();
                        if ($sleepData):
                        ?>
                            <div class="sleep-data-card">
                                <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <span style="font-size: 18px; font-weight: bold; color: #6a8aee;">
                                        <?php echo htmlspecialchars($sleepData['sleep_score']); ?>
                                        <small style="font-size: 12px; font-weight: normal;">分</small>
                                    </span>
                                    <span style="font-size: 13px; color: #888;"><?php echo date('m月d日', strtotime($sleepData['date'])); ?></span>
                                </div>
                                <div style="font-size: 13px; color: #555;">
                                    <div style="display:flex; justify-content: space-between; margin-bottom: 8px;">
                                        <span>总睡眠</span>
                                        <strong><?php
                                                $totalSleep = ($sleepData['deep_sleep_minutes'] ?? 0) + ($sleepData['light_sleep_minutes'] ?? 0) + ($sleepData['rem_sleep_minutes'] ?? 0);
                                                echo SleepData_Plugin::formatMinutesToDuration($totalSleep);
                                                ?></strong>
                                    </div>
                                    <div style="display:flex; justify-content: space-between; margin-bottom: 8px;">
                                        <span>睡眠时段</span>
                                        <span><?php echo substr($sleepData['sleep_time'], 0, 5); ?> - <?php echo substr($sleepData['wake_up_time'], 0, 5); ?></span>
                                    </div>
                                    <div style="display:flex; justify-content: space-between; margin-bottom: 8px;">
                                        <span>深睡</span>
                                        <span><?php echo SleepData_Plugin::formatMinutesToDuration($sleepData['deep_sleep_minutes']); ?></span>
                                    </div>
                                    <div style="display:flex; justify-content: space-between; margin-bottom: 8px;">
                                        <span>浅睡</span>
                                        <span><?php echo SleepData_Plugin::formatMinutesToDuration($sleepData['light_sleep_minutes']); ?></span>
                                    </div>
                                    <div style="display:flex; justify-content: space-between;">
                                        <span>快速眼动</span>
                                        <span><?php echo SleepData_Plugin::formatMinutesToDuration($sleepData['rem_sleep_minutes']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center" style="padding:15px; color: #888;">手表没戴或者没电了，暂无今日睡眠数据</div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
```
[/hide]
[/collapse]


---

## 最后

插件使用过程中有任何问题，欢迎在评论区留言。

插件Github地址：
[hide]
https://github.com/BXCQ/SleepData
[/hide]


网盘下载：
[hide]
https://pan.xunlei.com/s/VOWWC8KD7Vl3QiqIDGk4xnk-A1?pwd=z8h6#
[/hide]




  [1]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T13:43:49.png?x-oss-process=style/shuiyin
  [2]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T09:55:06.png?x-oss-process=style/shuiyin
  [3]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T14:27:00.png?x-oss-process=style/shuiyin
  [4]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T14:27:33.png?x-oss-process=style/shuiyin
  [5]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T15:15:40.png?x-oss-process=style/shuiyin
  [6]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T15:23:30.png?x-oss-process=style/shuiyin
  [7]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T15:20:29.png?x-oss-process=style/shuiyin
  [8]: https://static.blog.ybyq.wang/usr/uploads/2025/07/31/2025-07-31T15:26:43.png?x-oss-process=style/shuiyin