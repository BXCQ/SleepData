# Health.md 自动同步健康数据

推荐用 [Health.md](https://healthmd.isolated.tech/) 的 **API Export**，把苹果健康（含 OPPO 同步进去的数据）定时 POST 到本插件。

不止睡眠：Activity / Heart / Vitals / Body / Nutrition / Workouts 等日汇总都会落盘；睡眠仍会额外写入主题用的睡眠表。

## 服务器存储位置（插件目录内）

默认都在 `usr/plugins/HealthData/data/`：

| 内容 | 路径 | 说明 |
|------|------|------|
| 睡眠摘要（列表） | `data/sleep_data.json` | 起床日；供主题「今日睡眠」 |
| 全日健康亮点索引 | `data/health/index.json` | 按日历日，步数/心率等 |
| 全日健康日摘要 | `data/health/daily/YYYY-MM-DD.json` | 去样本/归档后的各分类汇总 |
| Health.md 完整请求包 | `data/raw/exports/healthmd-*.json` | 原始 envelope |
| 单日原始文档 | `data/raw/daily/YYYY-MM-DD.json` | Health.md 原样（可含大字段） |

请确保 Web 对 `data/` 目录可写（一般 `755`/`775`，属主为 PHP 运行用户）。

## 在 Health.md 里怎么填

打开 **API Export** 弹窗：

| 项 | 填写 |
|----|------|
| **Endpoint** | `https://blog.ybyq.wang/usr/plugins/HealthData/healthmd-api.php` |
| **bearer token** | Typecho 插件「健康数据」里的 **API访问令牌** |

说明：

- Health.md 会把 token 存进 Keychain，并以 `Authorization: Bearer <token>` 发送
- 每天一条 JSON 记录；重复导出同一天会 **upsert 覆盖**
- 建议勾选 **Sleep + Activity + Heart + Vitals**（以及你关心的 Body / Nutrition / Workouts）
- Lossless / 心率样本可不开发给博客 API（日汇总足够；完整包仍在 `data/raw/`）

然后在 Schedule 里设每天早上导出到 **API Endpoint**。

## 查询全日健康

写入用 `healthmd-api.php`；需令牌读取用 `health-api.php`；**独立页公开读取**用 `public-health-api.php`（按后台勾选分类过滤，无需令牌）：

```bash
# 最近一天亮点（需令牌）
curl 'https://blog.ybyq.wang/usr/plugins/HealthData/health-api.php?latest=1&access_token=你的令牌'

# 指定日完整摘要（需令牌）
curl 'https://blog.ybyq.wang/usr/plugins/HealthData/health-api.php?date=2026-08-16&full=1&access_token=你的令牌'

# 最近 30 天亮点列表（需令牌）
curl 'https://blog.ybyq.wang/usr/plugins/HealthData/health-api.php?days=30&access_token=你的令牌'

# 独立页公开列表（无需令牌）
curl 'https://blog.ybyq.wang/usr/plugins/HealthData/public-health-api.php?days=30'
curl 'https://blog.ybyq.wang/usr/plugins/HealthData/public-health-api.php?start=2026-08-01&end=2026-08-15'
```

### 从 raw 回填

若升级前只有 `data/raw/daily/`、还没有 `data/health/`：

```bash
# CLI
php /path/to/usr/plugins/HealthData/backfill-from-raw.php

# 或 HTTP（需令牌）
curl 'https://blog.ybyq.wang/usr/plugins/HealthData/backfill-from-raw.php?access_token=你的令牌'
```

启用插件时若检测到 raw 也会自动回填一次。

主题侧也可调用：

- `HealthData_Plugin::getTodayHealthData()` / `getLatestHealthData()` / `getHealthHighlights(14)`
- `HealthData_Plugin::getTodaySleepData()` / `getLatestSleepData()`（睡眠表）

### Handsome 独立页

1. 复制 `health-stats.php` 到 Handsome 主题根目录  
2. 后台新建页面，模板选「健康数据」  
3. 插件设置勾选公开展示分类（默认睡眠/活动/心率/锻炼）

## 睡眠映射

| Health.md `sleep` 字段 | 插件字段 |
|------------------------|----------|
| `deepSleep`（秒） | `deep_sleep_minutes` |
| `coreSleep`（秒） | `light_sleep_minutes`（浅睡/核心） |
| `remSleep`（秒） | `rem_sleep_minutes` |
| `awakeTime`（秒） | `awake_minutes` |
| `inBedTime`（秒） | 无阶段时长时回退为 `total_sleep_minutes` |
| `totalDuration`（秒） | `total_sleep_minutes` |
| `bedtime` / `bedtimeISO` | `sleep_time` |
| `wakeTime` / `wakeTimeISO` | `wake_up_time` |
| `date`（noon-to-noon 日记日） | 换算为**醒来当天**日历日后写入睡眠 `date` |

### 当日 / 起床日说明（重要）

Health.md 睡眠摘要按 **正午 → 次日正午** 归属一夜，所以「昨晚 23:30 睡、今早 07:15 醒」通常落在**昨天的** `records[].date` 上。

- **全日健康**（步数等）按 Health.md **日历日**写入 `data/health/`
- **睡眠**入库时规范为**醒来当天**（优先 `wakeTimeISO`，否则午前起床则日记日 +1）

响应里的 `saved_sleep[].source_date` 是 Health.md 原始日记日，`saved_sleep[].date` 是睡眠入库用的起床日。

定时导出一般到「昨天」；若要当天上午立刻看到今早这夜，在 Health.md 里对今天再跑一次 **Today Refresh / 手动导出**。

苹果 Sleep Score / OPPO 分数通常进不了 HealthKit；未带分数时接口会估算并标记 `score_estimated`。

## 全日健康亮点字段

`highlights` / `index.json` 常用键：

| 键 | 来源 |
|----|------|
| `steps` | `activity.steps` |
| `active_calories` | `activity.activeCalories` |
| `exercise_minutes` | `activity.exerciseMinutes` |
| `stand_hours` | `activity.standHours` |
| `distance_km` | `activity.walkingRunningDistanceKm` |
| `resting_heart_rate` | `heart.restingHeartRate` |
| `average_heart_rate` | `heart.averageHeartRate` |
| `hrv` | `heart.hrv` |
| `blood_oxygen` | `vitals.bloodOxygenAvg` 等 |
| `weight` | `body.weight` |
| `mindful_minutes` | `mindfulness.mindfulMinutes` |
| `workout_count` | `workouts` 条数 |
| `sleep_total_minutes` | `sleep.totalDuration`（按日历日，未换算起床日） |
| `categories` | 当日实际有数据的分类名 |

日文件 `health` 对象里仍保留各分类完整汇总（已去掉心率样本、sleepStages、HealthKit 归档等大字段）。需要无损样本请看 `data/raw/`。

## 自测

```bash
curl -X POST 'https://blog.ybyq.wang/usr/plugins/HealthData/healthmd-api.php' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的令牌' \
  -d '{
    "schema": "healthmd.api_export",
    "schema_version": 1,
    "exported_at": "2026-08-16T08:00:00.000Z",
    "source": "ios",
    "record_count": 1,
    "records": [{
      "schema": "healthmd.health_data",
      "schema_version": 7,
      "date": "2026-08-15",
      "activity": { "steps": 8421, "activeCalories": 320, "exerciseMinutes": 35 },
      "heart": { "restingHeartRate": 58, "hrv": 42 },
      "sleep": {
        "deepSleep": 5400,
        "coreSleep": 14400,
        "remSleep": 8100,
        "awakeTime": 900,
        "totalDuration": 27900,
        "bedtime": "23:30",
        "wakeTime": "07:15",
        "bedtimeISO": "2026-08-15T23:30:00+08:00",
        "wakeTimeISO": "2026-08-16T07:15:00+08:00"
      }
    }]
  }'
```

成功时：

- `saved_health[].date` = `2026-08-15`（日历日）
- `saved_sleep[].date` = `2026-08-16`（起床日）
- `saved_sleep[].source_date` = `2026-08-15`

## 上传到服务器的文件

至少需要：

- `healthmd-api.php`
- `health-api.php`
- `public-health-api.php`
- `backfill-from-raw.php`
- `health-stats.php`（复制到 Handsome 主题根目录）
- `lib/HealthDataHelper.php`
- `Plugin.php`（后台会显示 Health.md Endpoint、公开分类与健康亮点）
