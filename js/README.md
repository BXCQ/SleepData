# 可选本地 ECharts

独立页 `health-stats.php` 默认从 jsDelivr 加载 ECharts 5.4.3。

若 CDN 不可用，可将官方 `echarts.min.js` 放到本目录：

```text
usr/plugins/HealthData/js/echarts.min.js
```

页面会在 CDN 超时后自动回退到该文件。
