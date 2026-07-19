# 上线操作手册（Go-Live SOP）

> 面向：SakuraRyugaku（桜留学）市场验证版首次上线 / 每次发布回归。
> 目标：把「服务器 + 域名 + WordPress + 主题/插件 + SEO + 埋点」按可复现的顺序落地。
> 配套脚本：`scripts/deploy.sh`、`scripts/wp-cli-setup.sh`、`scripts/security-check.sh`、`scripts/preflight-check.sh`、`scripts/go-live.sh`。

---

## 0. 一图看懂流程

```
首次部署（人工，一次性）                     每次发布（自动，可重复）
────────────────────────────           ────────────────────────────
① 准备服务器 + 域名 + HTTPS       ┐
② deploy.sh 下载 WP 核心          │      go-live.sh <站点URL>
③ 配置 wp-config.php（库/密钥）    ├──►    ├─ [1] 环境自检
④ 建库 + 安装向导                 │      ├─ [2] wp-cli-setup.sh（主题/插件/页面/菜单）
⑤ 复制 .htaccess                  ┘      ├─ [3] security-check.sh（安全审计）
                                          └─ [4] preflight-check.sh（SEO/页面就绪）
                                         ↓
                                  ⑥ Search Console 提交 sitemap
                                  ⑦ 确认 GA4 / 埋点回流
```

- **①～⑤ 一次性人工步骤**：涉及机密（数据库密码、密钥），脚本不代做。
- **go-live.sh 是编排层**：幂等，每次改完主题/插件都可重跑，安全。

---

## 1. 前置条件（Checklist）

| 项 | 要求 | 备注 |
|----|------|------|
| 服务器 | Linux（PHP 8.1+ / MySQL 5.7+ 或 MariaDB 10.4+）| 内存 ≥ 1GB |
| 域名 | 已解析到服务器 IP | 例：`studyinjp.com` |
| HTTPS | 已签发有效证书（Let's Encrypt 等）| 生产必须 HTTPS |
| Web 服务器 | Nginx 或 Apache（支持伪静态） | 固定链接依赖 rewrite |
| WP-CLI | 已安装 `wp` 命令 | https://wp-cli.org/ |
| curl | 已安装 | preflight 依赖 |

> 检查：`wp --info`、`curl --version`、`php -v` 均能正常输出。

---

## 2. 首次部署（人工，一次性）

### 2.1 下载 WordPress 核心

```bash
cd /path/to/SakuraRyugaku
bash scripts/deploy.sh            # 下载最新版
# 或指定版本：bash scripts/deploy.sh 6.5.5
```

> `deploy.sh` 会保留 `wp-content/`（我们的主题/插件）与 `wp-config.php`，只同步核心文件。

### 2.2 配置 wp-config.php

```bash
cp config/wp-config-sample.php wp-config.php
```

编辑 `wp-config.php`，填写以下项（模板中的占位符必须替换）：

| 占位符 | 填入 | 说明 |
|--------|------|------|
| `{{DB_NAME}}` | 数据库名 | 见 2.3 |
| `{{DB_USER}}` | 数据库用户 | |
| `{{DB_PASSWORD}}` | 数据库密码 | 强密码 |
| `{{GENERATE_NEW}}`（8 处）| 认证密钥/盐值 | 从 https://api.wordpress.org/secret-key/1.1/salt/ 复制整段替换 |

已在模板中默认开启的安全项（保持不变）：

- `$table_prefix = 'wpbase_';`（非默认 `wp_`）
- `DISALLOW_FILE_EDIT = true`（禁后台改文件）
- `FORCE_SSL_ADMIN = true`（后台强制 HTTPS）
- `WP_DEBUG = false`（生产关闭调试）

> 生产环境建议：装完插件后把 `DISALLOW_FILE_MODS` 改为 `true`。

### 2.3 建库

```sql
CREATE DATABASE sakura_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sakura_user'@'localhost' IDENTIFIED BY '你的强密码';
GRANT ALL PRIVILEGES ON sakura_db.* TO 'sakura_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2.4 运行安装向导

浏览器访问站点域名，按 WordPress 安装向导设置站点标题、管理员账号/密码、邮箱。
或用 WP-CLI 无头安装：

```bash
wp core install \
  --url="https://studyinjp.com" \
  --title="桜留学 SakuraRyugaku" \
  --admin_user="你的管理员名" \
  --admin_password="强密码" \
  --admin_email="admin@studyinjp.com"
```

### 2.5 复制 .htaccess（Apache）

```bash
cp config/.htaccess-sample .htaccess   # 若存在该模板
```

> Nginx 无 .htaccess，需在站点配置里加伪静态 `try_files $uri $uri/ /index.php?$args;`，并禁止目录列表。

---

## 3. 一键上线（自动，可重复）

首次部署完成后（②～⑤ 就绪），执行编排脚本：

```bash
bash scripts/go-live.sh https://studyinjp.com
```

脚本按序执行四个阶段：

| 阶段 | 脚本 | 做什么 | 失败即阻断 |
|------|------|--------|:---:|
| [1] 环境自检 | 内置 | 校验 wp-config / WP-CLI / WP 已安装 | ✅ |
| [2] 站点初始化 | `wp-cli-setup.sh` | 启用主题/插件、建页绑模板、设首页、建菜单、刷固定链接 | ✅ |
| [3] 安全审计 | `security-check.sh` | 权限/前缀/调试/密钥/敏感文件等 10 项 | ✅ |
| [4] Preflight | `preflight-check.sh` | 抓真实站点验证 SEO/页面就绪（10 组） | ✅ |

### 3.1 可选开关（环境变量）

```bash
SKIP_SETUP=1     bash scripts/go-live.sh https://studyinjp.com  # 只检查不改内容
SKIP_SECURITY=1  bash scripts/go-live.sh https://studyinjp.com  # 跳过安全审计
SKIP_PREFLIGHT=1 bash scripts/go-live.sh                            # 只做本地初始化
```

> 不传站点 URL 时，自动跳过在线 preflight（仅本地初始化 + 安全审计）。

### 3.2 单独运行子脚本（排障用）

```bash
bash scripts/wp-cli-setup.sh                                  # 仅内容初始化
bash scripts/security-check.sh                                # 仅安全审计
bash scripts/preflight-check.sh https://studyinjp.com     # 仅 SEO/页面检查
```

---

## 4. 自动创建的站点结构

`wp-cli-setup.sh` 幂等创建以下页面并绑定主题模板（已存在则跳过）：

| slug | 标题 | 模板 | SEO |
|------|------|------|-----|
| （front）home | ホーム | `front-page.php` 落地页 | 核心转化页 |
| services | サービス紹介 | `page-services.php` | index |
| about | 私たちについて | `page-about.php` | index |
| faq | よくある質問 | `page-faq.php` | index + FAQPage |
| contact | お問い合わせ | `page-contact.php` | index |
| thanks | お申し込みありがとうございます | `page-thanks.php` | **noindex** |
| privacy | プライバシーポリシー | `page.php` | noindex |

同时：

- 固定链接结构设为 `/%postname%/`（干净 URL，供 canonical/hreflang）。
- 主导航（primary）：サービス紹介 / よくある質問 / お問い合わせ。
- 页脚导航（footer）：私たちについて / サービス紹介 / よくある質問 / お問い合わせ。
- 首页设为静态页，由 `front-page.php` 落地页渲染。

---

## 5. 上线后收尾（人工）

### 5.1 Search Console（必做）

1. 到 https://search.google.com/search-console 添加资源（域名或 URL 前缀）。
2. 验证所有权（DNS TXT 或 HTML 文件）。
3. 提交站点地图：`https://studyinjp.com/wp-sitemap.xml`。
4. 用「网址检查」对首页与各站点页请求编入索引。

### 5.2 GA4 / 埋点回流（必做）

- 确认页面加载了自建埋点 `tracker.js`（preflight [5] 会检查）。
- 落地页视图 `data-sa-lp`、表单曝光 `data-sa-form`、感谢页 `thanks_view` 均能上报。
- 若接入 GA4，确认 `gtag` 已配置，感谢页触发 `conversion` 事件。
- 提交一次测试表单，验证：入库 → 跳转 `/thanks/` → 转化事件上报 三链路通畅。

### 5.3 备份基线

```bash
bash scripts/backup.sh    # DB + wp-content + config 备份
```

---

## 6. 常见问题（Troubleshooting）

| 现象 | 原因 | 处理 |
|------|------|------|
| `[FATAL] 未找到 wp-config.php` | 未完成首次部署 | 先做第 2 章 ②～④ |
| `[FATAL] WordPress 尚未完成安装` | 没跑安装向导 | 见 2.4 |
| preflight `/thanks/ 返回 404` | 页面未创建 | 重跑 `wp-cli-setup.sh` |
| preflight `缺少 canonical/hreflang` | 主题头未加载 | 确认主题已激活、清缓存 |
| preflight `未见 tracker.js` | 核心插件未激活 | 确认 `study-abroad-core` 激活 |
| 页面 404（services 等） | 固定链接未刷新 | `wp rewrite flush --hard` 或重跑 setup |
| 安全审计 `表前缀 wp_` | 用了默认前缀 | 改 `wp-config.php` 的 `$table_prefix` |
| 安全审计 `密钥未替换` | 有 `{{GENERATE_NEW}}` | 见 2.2 替换盐值 |

---

## 7. 回归发布（后续每次改动）

改完主题/插件代码后，无需重复首次部署，只需：

```bash
bash scripts/go-live.sh https://studyinjp.com
```

`wp-cli-setup.sh` 幂等（已存在页面跳过、菜单先清后建），可安全重跑；随后自动过一遍安全与 SEO 检查。全部 `✅` 即可对外。

---

> 相关文档：`SEO-DEPLOYMENT.md`（SEO 细则与 Go-Live Checklist 来源）、`SECURITY-DESIGN.md`（安全设计）、`ANALYTICS.md`（埋点口径）、`FEATURES.md`（功能与实现进度）。
