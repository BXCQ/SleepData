# 健康数据记录插件（HealthData）

Typecho 插件。通过 **Health.md API** 同步苹果健康全日数据（睡眠、步数、心率、血氧等），并支持手动 OCR 上传睡眠。

适合：OPPO 手表 → OPPO 健康 → 苹果健康 → Health.md → 博客。

> **v2.0.0+ 重大变更**：插件目录与包名由 `SleepData` 更名为 `HealthData`；已移除 iOS 快捷指令。请使用 `usr/plugins/HealthData`，主题改用 `HealthData_Plugin::…`。  
> **v2.1.0**：Handsome 独立页 + 公开只读 API + raw 回填 `data/health/`；启用插件时自动安装主题模板。

## 特性

- **Health.md API Export**（推荐）：定时 POST，Bearer Token，按天 upsert
- 全日健康摘要：`data/health/`（Activity / Heart / Vitals / …）
- 睡眠摘要：文件 + 数据库（起床日对齐）
- **Handsome 独立页** `health-stats.php`：嵌在主题布局内（顶栏 / 侧栏 / 主内容），日期筛选、摘要卡、趋势图、日明细
- 公开接口 `public-health-api.php`（按后台勾选分类过滤，无需令牌）
- 从 `data/raw/daily/` **回填**健康索引（升级后不必等下次导出）
- 查询接口 `health-api.php`（需令牌）；主题方法 `getTodayHealthData` / `getLatestHealthData` / `getTodaySleepData` 等
- 前端 OCR 上传与简单可视化
- API 访问令牌校验

## 安装方法

1. 下载插件并解压到 Typecho 的 `usr/plugins/HealthData` 目录（文件夹名须为 `HealthData`）
2. 若从旧版 `SleepData` 升级：备份 `data/`，停用并删除旧插件目录，放入本目录后启用；令牌需在后台重新配置
3. 登录 Typecho 后台启用「健康数据记录插件」（会自动安装独立页模板并尝试 raw 回填）
4. （可选）手动回填：`.../backfill-from-raw.php?access_token=令牌`

## Handsome 独立页

与访客统计插件的 `visitor-stats.php` 相同用法：主题自定义模板，嵌在 Handsome 完整布局中。

1. 启用插件或打开插件设置后，会把 **`health-stats.php`** 安装到当前主题根目录（也可手动复制）
2. 后台 → 创建新页面 → 自定义模板选 **「健康数据」** → 发布
3. 插件设置中勾选 **独立页公开展示的分类**（默认：睡眠 / 活动 / 心率 / 锻炼）

建议保持关闭：心态、听力、行动能力、身体指标。

可选：把 ECharts 放到 `usr/plugins/HealthData/js/echarts.min.js`，CDN 失败时会回退本地。

## 目录结构（精简）

```
HealthData/
├── Plugin.php              # 插件入口 / 主题方法 / 设置面板
├── health-stats.php        # Handsome 独立页模板（自动安装到主题）
├── healthmd-api.php        # Health.md 写入
├── health-api.php          # 需令牌查询
├── public-health-api.php   # 独立页公开只读
├── backfill-from-raw.php   # raw → health 索引回填
├── simple-api.php          # OCR / 手动上传写入
├── index.html              # OCR 上传页
├── data_config.php         # 令牌与路径备用配置
├── lib/HealthDataHelper.php
├── data/                   # 运行时数据（勿提交真实数据）
├── js/                     # 可选本地 echarts.min.js
├── tessdata/               # 可选 OCR 本地语言包
├── HEALTHMD.md
└── README.md
```

## 使用方法

### 方式一：Health.md 自动同步（推荐）

1. Endpoint：`https://blog.ybyq.wang/usr/plugins/HealthData/healthmd-api.php`
2. Token：插件后台的 API 访问令牌
3. 勾选 Sleep、Activity、Heart、Vitals 等需要的指标
4. 详见 [HEALTHMD.md](./HEALTHMD.md)

### 方式二：手动 OCR 上传

1. 打开 `https://blog.ybyq.wang/usr/plugins/HealthData/index.html`
2. 填写 API 与令牌，识别截图后发送

> 苹果 Sleep Score / OPPO 分数通常无法经 HealthKit 读取；未传分数时接口会估算。

## 最后

说明文章：https://blog.ybyq.wang/archives/818.html

## 许可证

MIT License
