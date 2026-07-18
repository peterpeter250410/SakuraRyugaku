# Study Abroad Core

日本留学中介平台核心业务插件（市场验证阶段版本 v0.1.0）。

## 职责

- **落地页留资**：REST 端点接收意向表单，落库为线索（`sa_leads`）。
- **自建埋点**：访问/转化事件落库（`sa_analytics_events`），配合 GA4 / Search Console。
- **院校库 + 自动匹配**：预算 + 专业加权评分（可插拔规则）。
- **加密资料**：AES-256-GCM 加密存储 + 鉴权下载。
- **通知**：站内 + 邮件（新线索通知运营）。
- **后台看板**：UV/PV/线索/转化率 + 线索列表 + GA4 设置。
- **安全**：权限分级、越权守卫、审计日志。

## 安装

1. 将 `study-abroad-core` 目录放入 `wp-content/plugins/`。
2. 后台「插件」中启用「Study Abroad Core」。
   - 激活时自动建表、创建角色（`sa_student`/`sa_advisor`）、初始化匹配规则、准备加密目录。
3. 启用「Study Abroad Theme」主题。
4. 后台「留学中介 → 设置」填入 GA4 测量 ID（可选）。

## 关键配置（wp-config.php）

```php
// 文档加密密钥（强烈建议在生产环境显式设置，独立于数据库）
define( 'SA_DOC_ENC_KEY', '一段高强度随机字符串' );
```

未设置时回退用 `wp_salt('secure_auth')` 派生密钥。

## REST 端点

| 方法 | 路由 | 用途 | 鉴权 |
|------|------|------|------|
| POST | `/wp-json/sa/v1/lead` | 落地页意向表单提交 | REST Nonce + 蜜罐 + 频率限制 |
| POST | `/wp-json/sa/v1/track` | 埋点事件上报 | REST Nonce + 事件白名单 |

## 数据表

见 `docs/study-abroad/DATABASE-DESIGN.md` 与 `includes/class-activator.php`。

## 目录结构

```
includes/
├── class-activator.php / class-deactivator.php / class-plugin.php / class-db.php
├── security/    权限、越权守卫、审计日志
├── leads/       线索数据访问
├── analytics/   埋点数据访问 + 前端 tracker 注入
├── schools/     院校库数据访问
├── matching/    匹配引擎（rules 可插拔）
├── documents/   加密存储 + 受控下载
├── notify/      通知
├── rest/        REST 端点
└── admin/       后台看板
assets/js/tracker.js   前端自建埋点
```

## 阶段说明

本版本聚焦**市场验证**：落地页留资 + 埋点 + 数据看板为第一优先级；账号中心、完整选校/资料上传前端页面将随后续阶段接入（后端能力已具备）。
