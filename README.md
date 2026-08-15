# 睡眠数据记录插件

这是一个用于记录和查看睡眠数据的 Typecho 插件。支持手动 OCR 上传，也支持通过 **iOS 快捷指令**从苹果健康自动同步（适合 OPPO 手表 → OPPO 健康 → 苹果健康 的数据流）。

## 特性

- 支持通过 API 上传睡眠数据
- 数据同时保存到文件和数据库（如果可用）
- 提供简单的前端界面用于数据上传和查看
- 支持数据可视化（图表展示）
- 自动适应服务器权限，寻找可写目录
- 支持 API 访问令牌验证，增强安全性
- **iOS 快捷指令自动上传**（`shortcut-api.php`，免费，无需付费健康导出 App）

## 安装方法

1. 下载插件并解压到 Typecho 的 `usr/plugins` 目录
2. 登录 Typecho 后台，进入「控制台」→「插件」
3. 找到「睡眠数据记录插件」并点击「启用」

## 使用方法

### 方式一：手动 OCR 上传

1. 在浏览器中打开 `https://博客地址/usr/plugins/SleepData/index.html`
2. 填写 **API 地址** 和 **访问令牌**（浏览器会记住）
3. 拍照或选择健康 App 截图，OCR 识别后核对并发送

### 方式二：iOS 快捷指令自动上传（推荐）

适用于：OPPO 手表数据已同步到苹果「健康」。

1. **iPhone Safari** 打开：`https://博客地址/usr/plugins/SleepData/install-shortcut.html`
2. 点「打开快捷指令并导入」，添加后填写 API 与令牌
3. 也可直接调用：`https://博客地址/usr/plugins/SleepData/shortcut-api.php`
4. 详细说明见 [SHORTCUTS.md](./SHORTCUTS.md)

> 说明：苹果 Sleep Score / OPPO 健康分数通常无法被快捷指令读取；未传分数时接口会估算，也可在 JSON 里手动带 `sleep_score`。

## 最后

具体使用方法也可参考：https://blog.ybyq.wang/archives/818.html

## 许可证

MIT License
