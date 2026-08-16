# 睡眠 / 健康数据记录插件

Typecho 插件。通过 **Health.md API** 同步苹果健康日汇总（睡眠、步数、心率、血氧等），并支持快捷指令与手动 OCR 上传睡眠。

适合：OPPO 手表 → OPPO 健康 → 苹果健康 → Health.md → 博客。

## 特性

- **Health.md API Export**（推荐）：定时 POST，Bearer Token，按天 upsert
- 全日健康摘要：`data/health/`（Activity / Heart / Vitals / Body / …）
- 睡眠摘要：文件 + 数据库（起床日对齐，供主题「今日睡眠」）
- 查询接口 `health-api.php`；主题方法 `getTodayHealthData` / `getLatestHealthData`
- iOS 快捷指令、前端 OCR 上传

## 安装方法

1. 下载插件并解压到 Typecho 的 `usr/plugins` 目录
2. 登录 Typecho 后台，进入「控制台」→「插件」
3. 找到「睡眠数据记录插件」并点击「启用」

## 使用方法

### 方式一：Health.md 自动同步（推荐）

1. Endpoint：`https://blog.ybyq.wang/usr/plugins/SleepData/healthmd-api.php`
2. Token：插件后台的 API 访问令牌
3. 勾选 Sleep、Activity、Heart、Vitals 等需要的指标，Schedule 导出到 API Endpoint
4. 详见 [HEALTHMD.md](./HEALTHMD.md)

### 方式二：手动 OCR 上传

1. 打开 `https://blog.ybyq.wang/usr/plugins/SleepData/index.html`
2. 填写 API 与令牌，识别截图后发送

### 方式三：iOS 快捷指令

见 [SHORTCUTS.md](./SHORTCUTS.md)。

> 苹果 Sleep Score / OPPO 分数通常无法经 HealthKit 读取；未传分数时接口会估算。

## 最后

说明文章：https://blog.ybyq.wang/archives/818.html

## 许可证

MIT License
