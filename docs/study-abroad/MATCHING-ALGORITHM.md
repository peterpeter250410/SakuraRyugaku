# 日本留学中介平台 — 自动匹配算法设计

> 文档版本：v1.0
> 更新日期：2026-07-16
> 阅读对象：开发团队内部

---

## 1. 设计目标

- MVP 阶段以**预算（budget）**和**意向专业（major）**为核心匹配维度。
- 算法采用**加权评分模型**，各维度独立评分后加权求和，输出排序后的推荐院校/专业列表。
- 架构**可插拔、配置驱动**：后续新增维度（语言、学历、地区、时间等）不改主流程，只加规则类 + 配置行。

> 需求约束：匹配条件目前无法完全细化，因此本设计**先锁定预算 + 专业**，其余维度作为「已预留、默认关闭/低权重」的扩展项。

---

## 2. 匹配对象与粒度

- 匹配的**最小单位是「专业（program）」**，而非院校整体。
- 一个学生 → 对每个 `active` 专业计算得分 → 按院校聚合/或直接按专业排序输出。
- 学费与专业标签绑定在 `sa_programs`（见数据库设计），保证匹配精度。

---

## 3. 评分模型

### 3.1 总分公式

```
TotalScore(student, program) = Σ ( weight_i × ruleScore_i(student, program) )

其中：
  weight_i      = 维度 i 的权重（来自 sa_match_rules，可后台配置）
  ruleScore_i   = 维度 i 的归一化得分，范围 [0, 1]
  i ∈ 已启用的维度集合
```

- 每个维度得分归一化到 `[0,1]`，便于跨维度加权。
- 总分再乘 100 或保留原值用于排序展示（匹配度百分比）。

### 3.2 硬性过滤（Hard Filter）与软性评分（Soft Score）

匹配分两层：

1. **硬过滤**：不满足则直接排除（不进入评分）。
   - 院校/专业 `status = active`
   - （可配置）预算严重不符时是否直接排除，或降分保留（默认降分保留，避免无结果）
2. **软评分**：满足硬过滤的候选，按各维度打分排序。

---

## 4. 核心维度规则（MVP）

### 4.1 预算维度（Budget Rule）— P0

**输入**：学生 `budget_min` / `budget_max`（年学费预算区间），专业 `tuition_min` / `tuition_max`。

**评分逻辑**（归一化到 [0,1]）：

```
设学生预算区间 [Bmin, Bmax]，专业学费区间 [Tmin, Tmax]

情况 A：完全落入预算（Tmax ≤ Bmax 且 Tmin ≥ Bmin） → score = 1.0
情况 B：区间有重叠但部分超预算            → score = 重叠比例 (0,1)
情况 C：学费高于预算上限                  → 按超出幅度衰减
        overflow = (Tmin - Bmax) / Bmax
        score = max(0, 1 - overflow × penaltyFactor)
情况 D：学费远低于预算（可选加分/中性）    → score = 1.0（默认满分，不惩罚便宜）
```

**参数（threshold，可配置）**：
- `penaltyFactor`：超预算惩罚系数（默认 1.5）
- `hardExclude`：是否对超预算过多者硬排除（默认 false）
- `hardExcludeRatio`：硬排除阈值（如超出 50% 排除，默认 0.5，仅当 hardExclude=true 生效）

### 4.2 专业维度（Major Rule）— P0

**输入**：学生 `intended_major_tags`（意向专业标签数组），专业 `major_tags`（专业标签数组）。

**评分逻辑**（标签集合相似度，归一化到 [0,1]）：

```
学生标签集合 S，专业标签集合 P

方式1（Jaccard 相似度）：
  score = |S ∩ P| / |S ∪ P|

方式2（覆盖率，偏向学生意向被满足）：
  score = |S ∩ P| / |S|      （学生想要的有多少被这个专业覆盖）

默认采用方式2（更贴合「满足学生意向」的业务目标），可配置切换。
```

**兜底**：
- 若学生只填了自由文本 `intended_major` 而无标签，做**关键词/同义词映射**到标签后再匹配（维护一张「专业关键词→标签」映射表）。
- 无任何专业信息时，该维度得分记为中性值（如 0.5）或按配置跳过。

**参数（threshold，可配置）**：
- `mode`：`jaccard` | `coverage`（默认 coverage）
- `synonymMap`：关键词同义词映射配置

---

## 5. 扩展维度（预留，P1，默认低权重/关闭）

| 维度 | rule_key | 输入 | 评分思路 |
|------|----------|------|----------|
| 语言能力 | language | 学生 jp_level/en_score vs 专业 language_req | 达标=1，接近=部分分，不达标=低分或硬过滤 |
| 学历 | education | 学生 education_level vs 院校 min_education | 满足=1，不满足按差距降分/过滤 |
| 地区 | region | 学生 target_region vs 院校 region | 命中目标地区=1，否则中性/降分 |
| 入学时间 | intake_term | 学生 intake_term vs 招生季 | 匹配招生季=1，否则降分 |
| 成绩/GPA | gpa | 学生 gpa vs 院校门槛 | 达标=1，接近按比例 |

> 每个扩展维度 = 实现 `interface-rule.php` 的一个类 + `sa_match_rules` 插入配置行。开启后自动纳入总分计算。

---

## 6. 算法执行流程

```
1. 读取学生 profile（预算、专业标签、扩展字段）
2. 读取已启用维度配置（sa_match_rules：weight/threshold）
3. 候选集预筛（性能优化）：
     - 仅取 status=active 的院校与专业
     - （可选）按地区/学费粗筛，缩小候选规模
4. 遍历候选专业：
     for each program:
         if 硬过滤不通过 → skip
         score = 0
         for each enabled rule:
             s = rule.score(student, program)   # [0,1]
             score += rule.weight × s
         记录 score 与各维度明细
5. 排序（score 降序），取 Top N（可配置，如 20）
6. 生成 batch_id，写入 sa_match_results（含 score_detail、rule_snapshot）
7. 触发推送通知（match_done）
8. 无结果兜底 → 提示放宽条件 / 引导人工咨询
```

---

## 7. 匹配度展示

- 前台将 `total_score` 转为**匹配度百分比**（如 `min(100, round(total_score / maxPossible × 100))`）。
- 可展示各维度贡献（预算匹配、专业匹配），增强可信度。
- `maxPossible = Σ weight_i`（所有启用维度权重之和），用于归一化展示。

---

## 8. 重新匹配（Re-matching）

- 学生修改基础信息（预算/专业等）后，可手动或自动触发重新匹配。
- 每次生成新的 `batch_id`，旧结果保留（快照），支持前后对比。
- 已选校记录 `sa_selections` 不因重新匹配自动失效，由学生/顾问确认。

---

## 9. 配置示例（sa_match_rules 初始数据）

| rule_key | weight | enabled | threshold(JSON) |
|----------|--------|---------|-----------------|
| budget | 0.50 | 1 | `{"penaltyFactor":1.5,"hardExclude":false}` |
| major | 0.50 | 1 | `{"mode":"coverage"}` |
| language | 0.00 | 0 | `{"hardFilter":false}` |
| education | 0.00 | 0 | `{}` |
| region | 0.00 | 0 | `{}` |
| intake_term | 0.00 | 0 | `{}` |

> MVP：预算 50% + 专业 50%。上线后可根据数据调整权重、逐步开启扩展维度。

---

## 10. 性能与边界考虑

| 关注点 | 处理 |
|--------|------|
| 院校/专业规模增大 | 候选预筛（地区/学费粗筛）+ 分批计算 |
| 匹配计算耗时 | 提交后异步计算（如后台任务/队列），前台显示「匹配中」 |
| 权重全为 0 | 校验：至少一个启用维度且权重 > 0 |
| 学生信息不全 | 缺失维度按中性分/跳过，不阻断匹配 |
| 无匹配结果 | 兜底提示 + 人工咨询引导 |
| 结果重复计算 | 相同输入可缓存 batch，信息变更才重算 |

---

## 11. 可测试性

- 每个 Rule 类为纯函数式评分（输入 student+program → [0,1]），**易于单元测试**。
- 提供典型用例：预算完全匹配、部分超支、专业完全命中、部分命中、无标签兜底等。
- 匹配主流程可对 mock 院校集做集成测试，验证排序正确性。

---

_算法为可演进模型；权重/维度调整应通过配置而非改代码完成。_
