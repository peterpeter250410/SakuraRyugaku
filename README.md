# SakuraRyugaku — 日本留学中介平台

> 品牌：**桜留学 / SakuraRyugaku**　主域：`sakuraryugaku.com`（日本主域 `sakuraryugaku.jp` 后补）
> 技术栈：WordPress（自定义主题 + 自定义插件）　支持终端：PC + H5

面向赴日留学生的中介平台。学生填写意向信息 → 系统自动匹配院校 → 选校 → 加密上传申请资料 → 顾问后台跟进。
当前处于**市场验证阶段**：以落地页 + 白帽 SEO 投入市场，用数据判断项目是否值得继续投入。

> 本仓库为留学项目独立代码库，已与旧的荣光/EIKOU 会展项目物理隔离（仅复用 WordPress 安全加固与通用运维脚本作为基座）。

## 组成

| 组件 | 路径 | 说明 |
|------|------|------|
| 核心插件 | `wp-content/plugins/study-abroad-core/` | 落地页留资、埋点、院校匹配、加密资料、后台看板、权限/审计 |
| 前端主题 | `wp-content/themes/study-abroad-theme/` | 落地页（PC+H5）、SEO、多语言底座 |
| 安全加固 | `wp-content/mu-plugins/security-hardening.php` | 通用安全基座（版本隐藏、XML-RPC 禁用、登录限制等） |
| 配置模板 | `config/` | wp-config / .htaccess 安全模板 |
| 运维脚本 | `scripts/` | 通用部署 / 备份 / 安全检查 |
| 项目文档 | `docs/study-abroad/` | 规划、功能、架构、数据库、匹配算法、SEO、数据分析、安全 |

## 文档导航

设计文档见 [`docs/study-abroad/`](docs/study-abroad/README.md)：

- `PROJECT-PLAN.md` — 项目计划与市场验证阶段
- `FEATURES.md` — 产品功能清单（含落地页 L、SEO/数据分析 M 模块）
- `TECH-ARCHITECTURE.md` — 技术架构
- `DATABASE-DESIGN.md` — 数据库设计
- `MATCHING-ALGORITHM.md` — 自动匹配算法
- `I18N-DESIGN.md` — 多语言架构
- `SEO-DEPLOYMENT.md` — 白帽 SEO 部署与测试
- `ANALYTICS.md` — 数据分析与指标体系
- `SECURITY-DESIGN.md` — 安全与合规

## 快速开始

```bash
# 1. 克隆到站点目录
git clone <本仓库地址> /www/wwwroot/sakuraryugaku.com
cd /www/wwwroot/sakuraryugaku.com

# 2. 下载 WordPress 核心
bash scripts/deploy.sh

# 3. 配置
cp config/wp-config-sample.php wp-config.php
cp config/.htaccess-sample .htaccess
# 编辑 wp-config.php：数据库、密钥，并建议设置文档加密密钥：
#   define( 'SA_DOC_ENC_KEY', '高强度随机字符串' );

# 4. 后台启用「Study Abroad Core」插件与「Study Abroad Theme」主题
#    激活插件时自动建表、创建角色、初始化匹配规则、准备加密目录

# 5. 后台「留学中介 → 设置」填入 GA4 测量 ID（可选）

# 6. 上线前安全检查
bash scripts/security-check.sh
```

> WordPress 核心文件不纳入版本控制，通过 `scripts/deploy.sh` 下载。

## 上线要点（市场验证阶段）

- 域名 `sakuraryugaku.com` 免备案，配境外/港台服务器可快速上线。
- SEO：提交 sitemap 至 Google Search Console；面向中国可提交百度搜索资源平台。
- 埋点：GA4 + Search Console + 自建埋点（后台数据看板查看 UV/PV/线索/转化率）。
- 详见 `docs/study-abroad/SEO-DEPLOYMENT.md` 与 `ANALYTICS.md`。

## 许可证

WordPress 遵循 GPLv2。本项目自定义代码同样遵循 GPLv2。
