# 睡眠数据记录插件

Typecho 睡眠数据插件。支持 **Health.md API 自动同步**、iOS 快捷指令，以及手动 OCR 上传。  
适合：OPPO 手表 → OPPO 健康 → 苹果健康 → 自动入库。

## 特性

- 支持通过 API 上传睡眠数据（文件 + 数据库）
- **Health.md API Export**（推荐）：定时 POST，Bearer Token，按天 upsert
- iOS 快捷指令自动上传（`shortcut-api.php`）
- 前端 OCR 上传与简单可视化
- API 访问令牌校验

## 安装方法

1. 下载插件并解压到 Typecho 的 `usr/plugins` 目录
2. 登录 Typecho 后台，进入「控制台」→「插件」
3. 找到「睡眠数据记录插件」并点击「启用」

## 使用方法

### 方式一：Health.md 自动同步（推荐）

1. Endpoint：`https://blog.ybyq.wang/usr/plugins/SleepData/healthmd-api.php`
2. Token：插件后台的 API 访问令牌（填到 Health.md 的 bearer token）
3. 勾选 Sleep 指标，Schedule 定时导出到 API Endpoint  
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
