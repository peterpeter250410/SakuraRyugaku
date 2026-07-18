# 日本留学中介平台 — 数据库设计

> 文档版本：v1.0
> 更新日期：2026-07-16
> 阅读对象：开发团队内部

---

## 1. 设计约定

- 所有自定义表使用 WordPress 表前缀（`$wpdb->prefix`），文档中以 `{prefix}` 表示。
- 业务表前缀统一为 `{prefix}sa_`（sa = study abroad）。
- 主键统一 `BIGINT UNSIGNED AUTO_INCREMENT`。
- 字符集 `utf8mb4`，排序规则 `utf8mb4_unicode_ci`（支持多语种）。
- 时间字段统一 `DATETIME`，存 UTC。
- 学生用户复用 WordPress `wp_users`，角色通过能力区分，业务扩展信息放自定义表。

---

## 2. 表关系总览（ER 概览）

```
wp_users (学生/顾问/管理员)
   │
   ├──1:1── sa_student_profiles        学生扩展档案（含匹配条件）
   │
   ├──1:N── sa_intake_submissions      基础信息提交记录（含草稿/版本）
   │
   ├──1:N── sa_match_results           匹配结果（快照）
   │            │
   │            └──N:1── sa_schools / sa_programs
   │
   ├──1:N── sa_selections              选校记录
   │            │
   │            └──N:1── sa_schools / sa_programs
   │
   ├──1:N── sa_documents               申请资料（加密）
   │
   ├──1:N── sa_notifications           通知
   │
   ├──1:N── sa_messages                学生-顾问消息
   │
   └──N:1── sa_assignments             学生-顾问分配关系

sa_schools ──1:N── sa_programs         院校 → 专业
sa_match_rules                         匹配规则/权重配置
sa_audit_logs                          审计日志
```

---

## 3. 表结构定义

### 3.1 `{prefix}sa_student_profiles` — 学生扩展档案

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED | 关联 wp_users.ID（唯一） |
| full_name | VARCHAR(191) | 姓名 |
| phone | VARCHAR(32) | 手机号（可选） |
| nationality | VARCHAR(64) | 国籍 |
| preferred_locale | VARCHAR(16) | 首选语种（如 zh_CN/ja/en/...） |
| budget_min | INT UNSIGNED | 预算下限（年学费，单位日元） |
| budget_max | INT UNSIGNED | 预算上限 |
| intended_major | VARCHAR(191) | 意向专业（文本，配合标签匹配） |
| intended_major_tags | JSON | 意向专业标签数组（用于匹配） |
| education_level | VARCHAR(64) | 最高学历（扩展维度） |
| gpa | DECIMAL(4,2) | 平均成绩（扩展维度，可空） |
| jp_level | VARCHAR(16) | 日语等级 N1-N5（扩展维度，可空） |
| en_score | VARCHAR(32) | 英语成绩（扩展维度，可空） |
| target_region | VARCHAR(64) | 目标地区（扩展维度，可空） |
| intake_term | VARCHAR(32) | 计划入学时间（扩展维度，可空） |
| consent_privacy | TINYINT(1) | 是否同意隐私政策 |
| consent_at | DATETIME | 同意时间 |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间 |

索引：`UNIQUE(user_id)`，`INDEX(intended_major)`，`INDEX(budget_min, budget_max)`。

> 说明：`budget_*` 与 `intended_major_tags` 为 MVP 核心匹配字段；其余为扩展维度，预留但非必填。

---

### 3.2 `{prefix}sa_intake_submissions` — 基础信息提交记录

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED | 学生 |
| status | ENUM('draft','submitted') | 草稿/已提交 |
| payload | JSON | 本次提交的全部字段快照 |
| submitted_at | DATETIME | 提交时间（草稿为空） |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间 |

索引：`INDEX(user_id, status)`。

> 保留提交历史，支持草稿保存与「修改后重新匹配」的追溯。

---

### 3.3 `{prefix}sa_schools` — 院校库

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| post_id | BIGINT UNSIGNED | 关联院校 CPT（展示内容），可空 |
| name | VARCHAR(191) | 院校名称（默认语种） |
| name_i18n | JSON | 多语种名称 {locale: name} |
| school_type | VARCHAR(32) | 类型：大学/语言学校/专门学校 |
| region | VARCHAR(64) | 所在地区（东京/大阪等） |
| city | VARCHAR(64) | 城市 |
| language_req | VARCHAR(32) | 语言要求（如 N2） |
| min_education | VARCHAR(64) | 最低学历要求 |
| description_i18n | JSON | 多语种简介 |
| status | ENUM('active','inactive') | 是否参与匹配/展示 |
| sort_order | INT | 排序权重 |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间 |

索引：`INDEX(status)`，`INDEX(region)`，`INDEX(school_type)`。

---

### 3.4 `{prefix}sa_programs` — 院校专业子项

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| school_id | BIGINT UNSIGNED | 关联 sa_schools.id |
| name | VARCHAR(191) | 专业名称 |
| name_i18n | JSON | 多语种专业名 |
| major_tags | JSON | 专业标签数组（匹配用，如 ["经营","IT"]） |
| tuition_min | INT UNSIGNED | 学费下限（年，日元） |
| tuition_max | INT UNSIGNED | 学费上限 |
| language_req | VARCHAR(32) | 该专业语言要求 |
| duration | VARCHAR(32) | 学制 |
| status | ENUM('active','inactive') | 状态 |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间 |

索引：`INDEX(school_id, status)`，`INDEX(tuition_min, tuition_max)`。

> 匹配以**专业**为最小粒度：预算匹配 `tuition_*`，专业匹配 `major_tags`。

---

### 3.5 `{prefix}sa_match_results` — 匹配结果快照

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED | 学生 |
| batch_id | CHAR(36) | 一次匹配的批次 UUID |
| school_id | BIGINT UNSIGNED | 院校 |
| program_id | BIGINT UNSIGNED | 专业 |
| total_score | DECIMAL(6,2) | 综合匹配得分 |
| score_detail | JSON | 各维度得分明细（budget/major/...） |
| rank | INT | 本批次排名 |
| rule_snapshot | JSON | 当次使用的权重/阈值快照 |
| created_at | DATETIME | 匹配时间 |

索引：`INDEX(user_id, batch_id)`，`INDEX(user_id, rank)`。

> 每次匹配生成新 `batch_id`，保存得分依据，便于追溯与「重新匹配」对比。

---

### 3.6 `{prefix}sa_selections` — 选校记录

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED | 学生 |
| school_id | BIGINT UNSIGNED | 院校 |
| program_id | BIGINT UNSIGNED | 专业 |
| source_batch_id | CHAR(36) | 来源匹配批次 |
| status | ENUM('selected','submitting','submitted','withdrawn') | 选校状态 |
| created_at | DATETIME | 选定时间 |
| updated_at | DATETIME | 更新时间 |

索引：`INDEX(user_id, status)`，`UNIQUE(user_id, school_id, program_id)`。

---

### 3.7 `{prefix}sa_documents` — 申请资料（加密）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED | 学生 |
| selection_id | BIGINT UNSIGNED | 关联选校（可空，通用材料时为空） |
| doc_type | VARCHAR(64) | 资料类型（passport/transcript/photo/...） |
| original_name | VARCHAR(255) | 原始文件名 |
| stored_path | VARCHAR(255) | 加密文件相对路径（受保护目录） |
| mime_type | VARCHAR(128) | MIME 类型 |
| file_size | BIGINT UNSIGNED | 文件大小 |
| checksum | CHAR(64) | 明文 SHA-256 校验（完整性） |
| enc_algo | VARCHAR(32) | 加密算法标识 |
| enc_iv | VARBINARY(32) | 加密 IV（每文件唯一） |
| key_ref | VARCHAR(64) | 密钥引用标识（非密钥本身） |
| status | ENUM('uploaded','reviewing','need_fix','approved','rejected') | 审核状态 |
| review_note | TEXT | 审核备注 |
| reviewed_by | BIGINT UNSIGNED | 审核人（顾问 user_id） |
| version | INT | 版本号 |
| created_at | DATETIME | 上传时间 |
| updated_at | DATETIME | 更新时间 |

索引：`INDEX(user_id, doc_type)`，`INDEX(selection_id)`，`INDEX(status)`。

> 安全要点：`stored_path` 指向 Web 不可直连的受保护目录；`enc_iv`/`key_ref` 用于解密但**不存密钥本体**。详见 `SECURITY-DESIGN.md`。

---

### 3.8 `{prefix}sa_assignments` — 学生-顾问分配

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| student_id | BIGINT UNSIGNED | 学生 user_id |
| advisor_id | BIGINT UNSIGNED | 顾问 user_id |
| status | ENUM('active','transferred') | 状态 |
| assigned_by | BIGINT UNSIGNED | 分配操作人 |
| created_at | DATETIME | 分配时间 |
| updated_at | DATETIME | 更新时间 |

索引：`INDEX(advisor_id, status)`，`INDEX(student_id, status)`。

> 权限分级依据：顾问查询学生数据时以此表为准，只能访问 `advisor_id = 当前顾问` 的学生。

---

### 3.9 `{prefix}sa_match_rules` — 匹配规则/权重配置

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| rule_key | VARCHAR(64) | 维度标识（budget/major/language/...） |
| weight | DECIMAL(5,2) | 权重 |
| threshold | JSON | 阈值/参数配置 |
| enabled | TINYINT(1) | 是否启用 |
| updated_by | BIGINT UNSIGNED | 更新人 |
| updated_at | DATETIME | 更新时间 |

索引：`UNIQUE(rule_key)`。

> 支持后台调整权重、启停维度，无需改代码。新增维度插入一行即可。

---

### 3.10 `{prefix}sa_notifications` — 通知

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| user_id | BIGINT UNSIGNED | 接收人 |
| type | VARCHAR(64) | 通知类型（match_done/doc_rejected/...） |
| title | VARCHAR(191) | 标题 |
| body | TEXT | 内容 |
| link | VARCHAR(255) | 跳转链接 |
| is_read | TINYINT(1) | 已读标记 |
| channel | VARCHAR(32) | 渠道（inbox/email） |
| created_at | DATETIME | 创建时间 |

索引：`INDEX(user_id, is_read)`。

---

### 3.11 `{prefix}sa_messages` — 学生-顾问消息（P1）

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| thread_id | BIGINT UNSIGNED | 会话 ID |
| sender_id | BIGINT UNSIGNED | 发送人 |
| receiver_id | BIGINT UNSIGNED | 接收人 |
| body | TEXT | 内容 |
| is_read | TINYINT(1) | 已读 |
| created_at | DATETIME | 时间 |

索引：`INDEX(thread_id)`，`INDEX(receiver_id, is_read)`。

---

### 3.12 `{prefix}sa_audit_logs` — 审计日志

| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT UNSIGNED PK | 主键 |
| actor_id | BIGINT UNSIGNED | 操作人 |
| action | VARCHAR(64) | 动作（view_doc/download_doc/review/...） |
| object_type | VARCHAR(64) | 对象类型 |
| object_id | BIGINT UNSIGNED | 对象 ID |
| ip | VARCHAR(45) | 操作 IP |
| meta | JSON | 附加信息 |
| created_at | DATETIME | 时间 |

索引：`INDEX(actor_id)`，`INDEX(object_type, object_id)`，`INDEX(created_at)`。

> 敏感数据（尤其资料查看/下载）访问必须留痕。

---

## 4. 院校内容展示的 CPT 方案

- 用自定义文章类型 `sa_school`（CPT）承载院校**展示型内容**（富文本介绍、图片、视频）。
- 结构化匹配数据存于 `sa_schools`/`sa_programs` 自定义表（性能 + 精确查询）。
- `sa_schools.post_id` 关联 CPT，实现「展示内容」与「匹配数据」的桥接。

---

## 5. 字段扩展策略

| 场景 | 策略 |
|------|------|
| 新增匹配维度 | `sa_student_profiles` 加字段 + `sa_match_rules` 加规则行 + 新增 Rule 类 |
| 新增语种 | `*_i18n` JSON 字段加键值，无需改表结构 |
| 新增资料类型 | `sa_documents.doc_type` 取值扩展 + 资料清单配置 |
| 新增专业标签 | `major_tags` JSON 追加，标签体系可维护 |

> JSON 字段用于「多语种」「标签」「配置快照」等半结构化数据，避免频繁改表；但**核心匹配字段（预算数值、状态枚举）用独立列**以保证索引与查询性能。

---

_数据库设计随业务细化演进；表结构变更需在插件 activator 中提供迁移脚本。_
