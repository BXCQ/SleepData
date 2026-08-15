# iOS 快捷指令自动上传睡眠数据

适用数据流：

```text
OPPO 手表 → OPPO 健康 → 授权同步到苹果「健康」→ 快捷指令定时 POST → shortcut-api.php
```

全程免费，不需要 Health Auto Export / Health Webhook 等付费 App。

## 一键安装（推荐）

1. **用 iPhone Safari** 打开：

   `https://你的博客/usr/plugins/SleepData/install-shortcut.html`

2. 点 **「打开快捷指令并导入」**，在系统弹窗里添加「睡眠数据自动上传」。
3. 首次运行时填写：
   - API：`https://你的博客/usr/plugins/SleepData/shortcut-api.php`
   - 访问令牌：与插件后台一致
4. 允许读取健康数据后，可在「自动化」里设每天早上运行。

若提示未签名 / 无法导入：

- 设置 → 快捷指令 → 高级 → 允许未信任的快捷指令  
- 或在导入页点「下载 .shortcut 文件」，用「文件」App 打开

快捷指令源文件：`shortcuts/SleepData-AutoUpload.shortcut`（可用同目录 `generate_shortcut.py` 重新生成）。

---

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

一键安装的快捷指令会把样本编码为 `samples_text`（每行 `阶段|开始|结束`），服务端会解析并汇总。你也可以直接传 `samples` 数组。

### 示例 JSON（samples）

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

### 示例 JSON（samples_text，快捷指令使用）

```json
{
  "access_token": "你的令牌",
  "date": "2026-08-15",
  "samples_text": "Deep|2026-08-14T23:10:00+08:00|2026-08-15T00:40:00+08:00\nCore|2026-08-15T00:40:00+08:00|2026-08-15T05:00:00+08:00"
}
```

`date` 建议填**醒来当天**。不填时会按样本结束时间推断。

阶段名支持：`Deep` / `Core` / `Light` / `REM` / `Awake` / `In Bed` / `Asleep`（大小写不敏感）。  
OPPO 若只同步「Asleep」而无细分阶段，会记入浅睡，保证总时长仍可用。

### 手动搭建步骤（备用）

> 请在 **iPhone** 上编辑（健康数据在手机上；Mac 上编容易踩坑）。

1. 打开「快捷指令」→ 点右上角 `+` → 命名为 `上传昨晚睡眠`。
2. 添加 **「日期」**，设为「今天」，存为变量 `醒来日`。
3. 添加 **「调整日期」**：`醒来日` 减去 1 天 → 变量 `昨晚`。
4. 添加 **「查找健康样本」**：
   - 类型：**睡眠**（Sleep）
   - 开始日期：最近 2 天（或昨晚 18:00 → 今天 15:00）
   - 排序：开始日期，升序
5. 添加 **「重复」**，对健康样本逐条处理，拼成文本行：`值|开始|结束`
6. 用 **「获取 URL 内容」** POST JSON：`access_token` / `date` / `samples_text`
7. （可选）显示结果通知

### 定时自动化

1. 「快捷指令」→ **自动化** → **个人自动化** → **一天中的时间**
2. 例如每天 **08:00**（等 OPPO 数据同步完再跑更稳）
3. 操作：运行快捷指令 `睡眠数据自动上传`
4. 关闭「运行前询问」

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

多半是把午睡或前一晚片段也算进去了。服务端会按「间隔 > 90 分钟」拆会话并取最长一夜。

### 2. 只有总睡着时间，没有深睡/REM

部分 OPPO 同步只写 `Asleep`。接口会把未分类睡着记入浅睡，总时长仍正确。

### 3. 分数和苹果/OPPO App 不一致

正常现象。未传 `sleep_score` 时接口会估算并标记 `"score_estimated": true`。

### 4. 401 令牌错误

检查插件后台「API访问令牌」与快捷指令中填写的是否完全一致。

### 5. 自动化偶尔没跑

锁屏、低电量、专注模式可能延迟个人自动化。可把触发时间设晚一点，或当天手动跑一次补传。
