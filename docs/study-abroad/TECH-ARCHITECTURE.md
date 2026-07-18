# 日本留学中介平台 — 技术架构设计

> 文档版本：v1.0
> 更新日期：2026-07-16
> 阅读对象：开发团队内部

---

## 1. 架构原则

1. **业务与内容分离**：官网内容用 WordPress 原生能力；留学核心业务（匹配、资料、权限）集中在**自定义插件**中，避免污染主题。
2. **数据隔离**：院校库、匹配记录、申请资料等业务数据使用**自定义数据表**，不滥用 `wp_postmeta`，保证查询性能与结构清晰。
3. **可扩展优先**：匹配维度、语种、资料清单均设计为**配置驱动**，新增不改核心代码。
4. **安全内建**：敏感数据加密、访问鉴权、权限分级在架构层面固化。
5. **复用现有基础**：沿用仓库的安全加固 mu-plugins、部署与 i18n 脚本。

---

## 2. 系统总体架构

```
┌───────────────────────────────────────────────────────────────┐
│                          浏览器 (多语言 Web)                     │
│      游客 / 学生 / 顾问 / 管理员    ── HTTPS 强制 ──              │
└───────────────────────────────┬───────────────────────────────┘
                                 │
┌───────────────────────────────▼───────────────────────────────┐
│                        WordPress (PHP 7.4+)                     │
│                                                                 │
│  ┌─────────────────────┐    ┌────────────────────────────────┐ │
│  │   自定义主题          │    │   自定义插件 (核心业务)         │ │
│  │  study-abroad-theme  │    │   study-abroad-core            │ │
│  │  - 官网页面模板       │    │   ├─ 学生账号/个人中心          │ │
│  │  - 学生个人中心 UI    │◀──▶│   ├─ 基础信息采集              │ │
│  │  - 语言切换器         │    │   ├─ 院校库 (CPT + 自定义表)   │ │
│  │  - 响应式布局         │    │   ├─ 匹配引擎 (Matching)       │ │
│  └─────────────────────┘    │   ├─ 推荐与选校                │ │
│                              │   ├─ 申请资料 (加密存储)        │ │
│  ┌─────────────────────┐    │   ├─ 顾问/管理后台 (分级权限)   │ │
│  │  mu-plugins          │    │   ├─ 通知 (站内/邮件)          │ │
│  │  - 安全加固(沿用)     │    │   ├─ 多语言适配层              │ │
│  └─────────────────────┘    │   └─ REST API (Ajax 交互)      │ │
│                              └────────────────────────────────┘ │
└───────────────────────────────┬───────────────────────────────┘
                                 │
        ┌────────────────────────┼────────────────────────┐
        ▼                        ▼                        ▼
┌───────────────┐      ┌──────────────────┐     ┌──────────────────┐
│   MySQL        │      │  受保护文件存储    │     │   邮件/通知服务    │
│  - WP 核心表   │      │  (加密附件目录)   │     │  (SMTP/队列)      │
│  - 业务自定义表 │      │  Web 不可直接访问 │     │                  │
└───────────────┘      └──────────────────┘     └──────────────────┘
```

---

## 3. 代码组织结构

```
wp-content/
├── mu-plugins/
│   └── security-hardening.php            # 沿用仓库现有安全加固
│
├── plugins/
│   └── study-abroad-core/                # 留学业务核心插件（主要开发区）
│       ├── study-abroad-core.php         # 插件主文件 / 引导
│       ├── includes/
│       │   ├── class-plugin.php          # 插件容器/初始化
│       │   ├── class-activator.php       # 激活：建表、角色、能力
│       │   ├── class-deactivator.php     # 停用清理
│       │   ├── class-loader.php          # hook 注册器
│       │   │
│       │   ├── account/                  # B. 学生账号
│       │   │   ├── class-registration.php
│       │   │   ├── class-auth.php
│       │   │   └── class-profile.php
│       │   │
│       │   ├── intake/                   # C. 基础信息采集
│       │   │   ├── class-intake-form.php
│       │   │   └── class-intake-fields.php   # 字段配置驱动
│       │   │
│       │   ├── schools/                  # D. 院校库
│       │   │   ├── class-school-cpt.php      # 院校 CPT
│       │   │   ├── class-school-repo.php     # 自定义表数据访问
│       │   │   └── class-program.php         # 专业子项
│       │   │
│       │   ├── matching/                 # E. 匹配引擎
│       │   │   ├── class-matcher.php         # 匹配主流程
│       │   │   ├── class-scoring.php         # 评分策略
│       │   │   ├── rules/                    # 可插拔维度
│       │   │   │   ├── interface-rule.php
│       │   │   │   ├── class-budget-rule.php # 预算维度 (P0)
│       │   │   │   ├── class-major-rule.php  # 专业维度 (P0)
│       │   │   │   └── class-...-rule.php    # 扩展维度 (P1)
│       │   │   └── class-match-repo.php      # 匹配结果快照
│       │   │
│       │   ├── recommend/                # F. 推荐与选校
│       │   │   ├── class-recommend.php
│       │   │   └── class-selection.php
│       │   │
│       │   ├── documents/                # G. 申请资料
│       │   │   ├── class-doc-uploader.php
│       │   │   ├── class-doc-crypto.php      # 加密/解密
│       │   │   ├── class-doc-repo.php
│       │   │   └── class-doc-access.php      # 受控下载/鉴权
│       │   │
│       │   ├── admin/                    # H. 顾问/管理后台
│       │   │   ├── class-admin-menu.php
│       │   │   ├── class-student-manager.php
│       │   │   ├── class-review.php          # 资料审核
│       │   │   ├── class-assignment.php      # 学生-顾问分配
│       │   │   └── class-rule-settings.php   # 匹配规则配置
│       │   │
│       │   ├── notify/                   # I. 通知
│       │   │   ├── class-notifier.php
│       │   │   ├── class-mailer.php
│       │   │   └── class-inbox.php
│       │   │
│       │   ├── i18n/                     # J. 多语言适配
│       │   │   ├── class-locale.php
│       │   │   └── class-content-translation.php
│       │   │
│       │   ├── security/                # K. 安全
│       │   │   ├── class-capabilities.php    # 角色能力定义
│       │   │   ├── class-access-guard.php    # 越权防护
│       │   │   └── class-audit-log.php       # 审计日志
│       │   │
│       │   └── rest/                     # REST API 端点
│       │       ├── class-rest-intake.php
│       │       ├── class-rest-matching.php
│       │       ├── class-rest-documents.php
│       │       └── class-rest-selection.php
│       │
│       ├── templates/                    # 前端模板片段
│       │   ├── dashboard/                 # 个人中心
│       │   ├── intake-form/
│       │   ├── recommend/
│       │   └── documents/
│       │
│       ├── assets/
│       │   ├── js/
│       │   └── css/
│       │
│       └── languages/                    # 插件翻译文件 (.pot/.po/.mo)
│
└── themes/
    └── study-abroad-theme/               # 自定义主题（展示层）
        ├── style.css
        ├── functions.php
        ├── header.php / footer.php
        ├── front-page.php                # 首页
        ├── page-templates/               # 服务/关于/联系等
        ├── single-school.php             # 院校详情
        ├── archive-school.php            # 院校列表
        ├── parts/                        # 组件（语言切换器等）
        ├── assets/
        └── languages/
```

> 设计要点：**主题只管展示，插件承载业务逻辑**。这样即使更换主题外观，业务不受影响。

---

## 4. 关键模块设计

### 4.1 数据访问层（Repository）

- 院校、匹配记录、资料等使用自定义表，统一通过 `*-repo.php` 封装 `$wpdb` 操作。
- 禁止在业务代码中散落原生 SQL，全部经过 Repository，便于安全（预处理）与维护。

### 4.2 匹配引擎（策略 + 可插拔规则）

```
IntakeData (学生信息)
      │
      ▼
┌──────────────┐
│   Matcher    │  遍历已注册的 Rule
└──────┬───────┘
       │  对每所候选院校
       ▼
┌──────────────────────────────────────┐
│  Rule 集合（实现 interface-rule）      │
│  - BudgetRule  → 分数 + 权重           │
│  - MajorRule   → 分数 + 权重           │
│  - (可扩展) LanguageRule / RegionRule  │
└──────┬───────────────────────────────┘
       ▼
┌──────────────┐
│   Scoring    │  加权汇总 → 总分
└──────┬───────┘
       ▼
   排序输出推荐列表 + 保存快照
```

- 新增匹配维度 = 新增一个实现 `interface-rule.php` 的类并注册，**不改主流程**。
- 权重与阈值从配置读取（后台可调）。详见 `MATCHING-ALGORITHM.md`。

### 4.3 申请资料加密存储

```
上传 → 校验(类型/大小) → 服务端加密 → 存入受保护目录（Web 不可直连）
下载 → 鉴权(本人/负责顾问/管理员) → 解密 → 流式输出（不暴露真实路径）
```

- 加密密钥独立于文件存储，密钥管理见 `SECURITY-DESIGN.md`。
- 下载走 PHP 端点鉴权，物理路径通过 `.htaccess`/目录规则禁止直接访问。

### 4.4 权限分级（Capabilities）

- 自定义角色：`sa_student`、`sa_advisor`、`sa_admin`（映射到 WP 能力）。
- `access-guard.php` 在数据访问前统一校验：顾问只能访问被分配学生的数据。

### 4.5 前后端交互

- 学生端交互（提交信息、触发匹配、上传资料）通过 **REST API + Nonce 校验**。
- 避免直接暴露 admin-ajax 无鉴权接口，所有端点做权限与来源校验。

---

## 5. 与仓库现有资产的关系

| 现有资产 | 复用方式 |
|---------|---------|
| `mu-plugins/security-hardening.php` | 直接沿用作为基础安全底座 |
| `scripts/deploy*.sh`、`server-deploy.sh` | 部署本项目主题/插件 |
| `scripts/i18n-*.php` / `i18n-*.sh` | 作为多语言处理工具链参考/复用 |
| `scripts/security-check.sh` | 上线前安全审计 |
| `config/wp-config-sample.php` / `.htaccess-sample` | 生产配置模板 |
| `mockup/`（EIKOU 会展） | **不复用**，仅作前端技术参考，业务无关 |

---

## 6. 环境与依赖

| 项 | 要求 |
|----|------|
| PHP | 7.4+（建议 8.1+） |
| WordPress | 最新稳定版 |
| MySQL | 5.7+ / 8.0 |
| Web 服务器 | Nginx/Apache（宝塔面板） |
| 传输 | 全站 HTTPS |
| 邮件 | SMTP（通知/找回密码），建议接入队列避免阻塞 |
| 前端构建 | 原生 JS/CSS 优先；如需构建工具，独立于 WP 核心 |

---

## 7. 非功能性要求

| 维度 | 要求 |
|-----|------|
| 性能 | 匹配计算避免全表扫描；候选院校可预筛后计算；结果缓存 |
| 安全 | 见 `SECURITY-DESIGN.md` |
| 可维护性 | 业务集中于插件、Repository 封装、配置驱动 |
| 可扩展性 | 匹配维度、语种、资料清单均可配置扩展 |
| 兼容性 | 响应式，主流浏览器；移动端可用 |

---

_架构随详细设计推进持续细化，重大变更需同步更新本文档。_
