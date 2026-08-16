# Health.md 自动同步睡眠数据

推荐用 [Health.md](https://healthmd.isolated.tech/) 的 **API Export**，把苹果健康（含 OPPO 同步进去的睡眠）定时 POST 到本插件。

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
| `totalDuration`（秒） | `total_sleep_minutes` |
| `bedtime` / `bedtimeISO` | `sleep_time` |
| `wakeTime` / `wakeTimeISO` | `wake_up_time` |
| `date` | `date` |

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
      "date": "2026-08-16",
      "sleep": {
        "deepSleep": 5400,
        "coreSleep": 14400,
        "remSleep": 8100,
        "awakeTime": 900,
        "totalDuration": 27900,
        "bedtime": "23:30",
        "wakeTime": "07:15"
      }
    }]
  }'
```

成功返回 `"status":"success"`，并在插件后台 / 主题侧边栏可见。

## 上传到服务器的文件

至少需要：

- `healthmd-api.php`
- `lib/SleepDataHelper.php`
- `Plugin.php`（后台会显示 Health.md Endpoint）
