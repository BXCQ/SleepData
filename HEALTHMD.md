# Health.md 自动同步睡眠数据

推荐用 [Health.md](https://healthmd.isolated.tech/) 的 **API Export**，把苹果健康（含 OPPO 同步进去的睡眠）定时 POST 到本插件。

## 服务器存储位置（插件目录内）

默认都在 `usr/plugins/SleepData/data/`：

| 内容 | 路径 |
|------|------|
| 睡眠摘要（列表） | `data/sleep_data.json` |
| Health.md 完整请求包 | `data/raw/exports/healthmd-*.json` |
| 单日原始文档（按日覆盖） | `data/raw/daily/YYYY-MM-DD.json` |

绝对路径示例：

```text
/path/to/blog/usr/plugins/SleepData/data/sleep_data.json
/path/to/blog/usr/plugins/SleepData/data/raw/exports/
/path/to/blog/usr/plugins/SleepData/data/raw/daily/
```

请确保 Web 对 `data/` 目录可写（一般 `755`/`775`，属主为 PHP 运行用户）。

## 在 Health.md 里怎么填

打开 **API Export** 弹窗：

| 项 | 填写 |
|----|------|
| **Endpoint** | `https://blog.ybyq.wang/usr/plugins/SleepData/healthmd-api.php` |
| **bearer token** | Typecho 插件「睡眠数据」里的 **API访问令牌** |

说明：

- Health.md 会把 token 存进 Keychain，并以 `Authorization: Bearer <token>` 发送
- 每天一条 JSON 记录；重复导出同一天会 **upsert 覆盖**
- 请勾选 **Sleep** 相关指标；Lossless 可不开发（摘要即可）

然后在 Schedule 里设每天早上导出到 **API Endpoint**。

## 数据映射

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
| `date`（noon-to-noon 日记日） | 换算为**醒来当天**日历日后写入 `date` |

### 当日 / 起床日说明（重要）

Health.md 睡眠摘要按 **正午 → 次日正午** 归属一夜，所以「昨晚 23:30 睡、今早 07:15 醒」通常落在**昨天的** `records[].date` 上。

本插件入库时会规范为**醒来当天**的日历日（优先 `wakeTimeISO`，否则按午前起床则日记日 +1），以便主题「今日睡眠」能查到。

响应里的 `saved[].source_date` 是 Health.md 原始日记日，`saved[].date` 是入库用的起床日。原始 JSON 仍按 Health.md 原 `date` 写到 `data/raw/daily/`。

定时导出一般到「昨天」；若要当天上午立刻看到今早这夜，在 Health.md 里对今天再跑一次 **Today Refresh / 手动导出**。

苹果 Sleep Score / OPPO 分数通常进不了 HealthKit；未带分数时接口会估算并标记 `score_estimated`。

## 自测

```bash
curl -X POST 'https://blog.ybyq.wang/usr/plugins/SleepData/healthmd-api.php' \
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

成功时 `saved[].date` 应为 **`2026-08-16`**（起床日），`source_date` 为 `2026-08-15`。

## 上传到服务器的文件

至少需要：

- `healthmd-api.php`
- `lib/SleepDataHelper.php`
- `Plugin.php`（后台会显示 Health.md Endpoint）
