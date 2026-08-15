# iOS 快捷指令自动上传睡眠数据

适用数据流：

```text
OPPO 手表 → OPPO 健康 → 授权同步到苹果「健康」→ 快捷指令定时 POST → shortcut-api.php
```

全程免费，不需要 Health Auto Export / Health Webhook 等付费 App。

## 重要说明：睡眠评分

| 分数来源 | 快捷指令能否读取 | 本接口如何处理 |
|----------|------------------|----------------|
| 苹果「睡眠评分」(Sleep Score) | **不能**（Apple 未开放 HealthKit API） | 未传 `sleep_score` 时自动估算 |
| OPPO 健康里的睡眠分数 | **通常不能**（一般不同步进 HealthKit） | 同上；若你能手工填入可放进 JSON |
| 你在快捷指令里手动/计算的分数 | 可以 | 传 `sleep_score` 字段即可优先使用 |

苹果健康 App 里能「看到」评分，不代表快捷指令能读到。OPPO → 苹果健康同步的通常是**睡眠分析阶段**（深睡 / 核心 / REM / 清醒等），这正是自动上传所需的核心数据。

## 接口地址

```text
https://你的博客域名/usr/plugins/SleepData/shortcut-api.php
```

- 方法：`POST`
- Header：`Content-Type: application/json`
- 鉴权（二选一）：
  - JSON 里的 `access_token`
  - Header：`Authorization: Bearer <令牌>`

令牌与插件后台 / `data_config.php` 中现有配置相同。

---

## 推荐：上传原始样本（服务端自动汇总）

快捷指令只负责「查出睡眠样本并打包」，跨午夜切割、选主睡眠段、阶段汇总都由服务器完成。

### 示例 JSON

```json
{
  "access_token": "你的令牌",
  "date": "2026-08-15",
  "samples": [
    {
      "stage": "In Bed",
      "start": "2026-08-14T23:05:00+08:00",
      "end": "2026-08-15T07:12:00+08:00"
    },
    {
      "stage": "Core",
      "start": "2026-08-14T23:20:00+08:00",
      "end": "2026-08-15T01:10:00+08:00"
    },
    {
      "stage": "Deep",
      "start": "2026-08-15T01:10:00+08:00",
      "end": "2026-08-15T02:00:00+08:00"
    },
    {
      "stage": "REM",
      "start": "2026-08-15T02:00:00+08:00",
      "end": "2026-08-15T02:40:00+08:00"
    },
    {
      "stage": "Awake",
      "start": "2026-08-15T02:40:00+08:00",
      "end": "2026-08-15T02:48:00+08:00"
    }
  ]
}
```

`date` 建议填**醒来当天**（例如 8 月 15 日早上醒来就填 `2026-08-15`）。不填时会按样本结束时间推断。

阶段名支持：`Deep` / `Core` / `Light` / `REM` / `Awake` / `In Bed` / `Asleep`（大小写不敏感）。  
OPPO 若只同步「Asleep」而无细分阶段，会记入浅睡，保证总时长仍可用。

### 快捷指令搭建步骤（在 iPhone 上操作）

> 请在 **iPhone** 上编辑（健康数据在手机上；Mac 上编容易踩坑）。

1. 打开「快捷指令」→ 点右上角 `+` → 命名为 `上传昨晚睡眠`。
2. 添加 **「日期」**，设为「今天」，存为变量 `醒来日`。
3. 添加 **「调整日期」**：`醒来日` 减去 1 天 → 变量 `昨晚`。
4. 添加 **「查找健康样本」**：
   - 类型：**睡眠**（Sleep）
   - 开始日期：`昨晚` 的 18:00（可用「调整日期」把时间设到傍晚）
   - 结束日期：`醒来日` 的 15:00
   - 排序：开始日期，升序  
   > 过滤不必过于精确；服务端还会再按窗口筛选。
5. 添加 **「重复」**，对健康样本逐条处理，构建字典：
   - `stage` ← 重复项的「值」或「名称」
   - `start` ← 重复项的「开始日期」（格式化为 ISO 8601 文本更稳妥）
   - `end` ← 重复项的「结束日期」
   - 用 **「添加到变量」** 把字典放进列表 `samples`
6. 添加 **「字典」**，包含：
   - `access_token`：你的令牌
   - `date`：把 `醒来日` 格式化为 `yyyy-MM-dd`
   - `samples`：上面的列表
7. 添加 **「获取 URL 内容」**：
   - URL：`https://你的域名/usr/plugins/SleepData/shortcut-api.php`
   - 方法：`POST`
   - 请求体：JSON
   - 正文：上一步字典  
   - 也可在标头加 `Authorization: Bearer 你的令牌`
8. （可选）添加「显示通知」，把响应里的 `message` 弹出来便于调试。

### 定时自动化

1. 「快捷指令」→ **自动化** → **个人自动化** → **一天中的时间**
2. 例如每天 **08:00**（等 OPPO 数据同步完再跑更稳）
3. 操作：运行快捷指令 `上传昨晚睡眠`
4. 关闭「运行前询问」（不同 iOS 版本文案略有差异）

首次运行会请求「健康」权限，请允许读取睡眠数据。

---

## 备选：直接上传已汇总分钟数

若你更愿意在快捷指令里自己加总：

```json
{
  "access_token": "你的令牌",
  "date": "2026-08-15",
  "sleep_time": "23:20",
  "wake_up_time": "07:12",
  "sleep_score": 85,
  "deep_minutes": 90,
  "core_minutes": 220,
  "rem_minutes": 75,
  "awake_minutes": 18
}
```

字段别名也可用：`deep_sleep_minutes`、`light_sleep_minutes`、`asleep_minutes` 等。

计算每段分钟数时，请用「开始 / 结束」做 **时间之间的分钟差**，不要直接累加健康样本的「持续时间」字段（快捷指令里该字段有时不可靠）。

---

## 用 curl 自测

```bash
curl -X POST 'https://你的域名/usr/plugins/SleepData/shortcut-api.php' \
  -H 'Content-Type: application/json' \
  -d '{
    "access_token": "你的令牌",
    "date": "2026-08-15",
    "deep_minutes": 80,
    "core_minutes": 200,
    "rem_minutes": 70,
    "awake_minutes": 15,
    "sleep_time": "23:30",
    "wake_up_time": "07:00",
    "sleep_score": 82
  }'
```

成功时返回 `"status":"success"`，并带上 `saved_data`。同一天重复上传会**覆盖更新**当天记录。

---

## 常见问题

### 1. 总时长明显偏长（例如 10 小时以上）

多半是快捷指令把午睡或前一晚片段也算进去了。请：

- 查询窗口用「昨晚 18:00 → 今天 15:00」
- 或依赖服务端按「间隔 > 90 分钟」拆会话并取最长一夜（`samples` 模式已支持）

### 2. 只有总睡着时间，没有深睡/REM

部分 OPPO 同步只写 `Asleep`。接口会把未分类睡着记入浅睡，总时长仍正确；细分阶段以 OPPO 是否写入苹果健康为准。

### 3. 分数和苹果/OPPO App 不一致

正常现象。苹果 Sleep Score 与 OPPO 分数都进不了快捷指令时，接口会估算并在响应里标记 `"score_estimated": true`。若你日后能拿到真实分数，只要带上 `sleep_score` 即可覆盖。

### 4. 401 令牌错误

检查插件后台「API访问令牌」，或 `data_config.php` 里的 `API_ACCESS_TOKEN`，与快捷指令中填写的是否完全一致。

### 5. 自动化偶尔没跑

锁屏、低电量、Focus 等可能延迟个人自动化。可把触发时间设晚一点，或当天手动跑一次快捷指令补传。
