# 文章生成流水线

长尾词 → 文章 → 自动发布。四道客观闸门挡在发布之前。

## 部署后第一次：刷新固定链接

新注册了 `sa_article` post type，不刷 `/guides/` 会 404：

```bash
cd /www/wwwroot/studyinjp.com
wp rewrite flush --hard --allow-root     # 非 root 身份跑时去掉 --allow-root
```

## 快速开始

```bash
cd /www/wwwroot/studyinjp.com
source /root/.sa-content-env             # 里面是 export ANTHROPIC_API_KEY=...

php scripts/content/pipeline.php list                      # 看有哪些选题
php scripts/content/pipeline.php run isi-tuition --dry-run # 跑一篇，不发布
php scripts/content/pipeline.php run isi-tuition           # 跑完并发布
php scripts/content/pipeline.php run-all --limit=2         # 按优先级跑 2 篇
php scripts/content/pipeline.php status                    # 各阶段数量 + 今日已发
```

pipeline 自己调 WP-CLI 时会检测 uid，root 下自动补 `--allow-root`，
不需要你额外设置（约定同 `scripts/publish-school.sh`）。

**第一次务必用 `--dry-run`。** 它会完整跑完生成与全部闸门，把成稿留在
`state/<id>.json` 里，只是不写进 WordPress。先看几篇再决定要不要放开自动发布。

## 目录

```
scripts/content/
  pipeline.php          编排主控（CLI）
  keywords/en.json      长尾词库 —— 选题与其证据依据
  prompts/              四个阶段的提示词，改写作风格改这里
    outline.md          调研与大纲
    draft.md            撰写正文
    humanize.md         文风修订
    review.md           质量评审
  lib/
    class-llm.php       Claude Messages API 客户端
    class-source-gate.php  来源闸门
    class-ai-tell.php   文风评分（纯本地，不调 API）
    class-dedup.php     重复度闸门
  wp/
    publish-article.php 写入 WordPress（经 WP-CLI）
    dump-articles.php   导出站内文章供查重
  state/                运行状态（gitignore，可随时删）
```

## 流程

```
outline → draft → [闸门3 文风] → [闸门1 来源] → [闸门2 重复度] → review → [闸门4 节奏] → publish
                        ↑__________|
                     超标则改写，最多 2 轮
```

每阶段产物都落盘。中断后重跑从上次停下的地方继续，不重复烧 token。
改了 prompt 想整篇重来：`pipeline.php reset <id>`。

## 四道闸门

| 闸门 | 检查 | 不通过 |
|---|---|---|
| **1 来源** | 每个 URL 实际抓取（须 200）；每个数字在来源页上核对；域名白名单；无孤儿引用；无裸数字 | 拒绝发布 |
| **2 重复度** | 与站内同语种已发文章的 Jaccard 相似度 > 0.28 | 拒绝发布 |
| **3 文风** | AI 味评分 > 35 | 触发改写，2 轮仍超标转人工 |
| **4 节奏** | 每日发布数（默认 2） | 留在 ready，次日可发 |

闸门 1、2、4 是客观的。模型评审（review）**只能否决，不能单独放行** ——
模型评自己写的东西有系统性偏高倾向，不能把它当作唯一关卡。

### 来源闸门的写法约定

正文中的事实性论断要带标记，紧跟句末：

```
Students may work up to 28 hours per week once the permit is granted. [source:1]
```

数字对应 `sources` 数组，从 1 起。闸门会抓取该 URL，确认「28」确实出现在
那个页面上且紧邻「hours / 時間」。标记在发布时会被剥掉，读者看到的是页面底部的出处列表。

**日本官方站大量使用全角数字**：ISA 的资格外活动页写的是「１週について２８時間以内」，
半角 `28` 在整页里出现 0 次。闸门会做全角展开与「万」单位换算。

### 这道闸门做不到什么

它验证的是「这个数字在被引用的页面上出现过，且挨着对应的量词」，
不是「这个数字在那篇文章里的含义与本文用法一致」。

能挡住**凭空编造的数字**——自动生成内容最主要的失真来源。
挡不住张冠李戴。后者需要理解语义，正则做不到，别指望它。

## 环境变量

| 变量 | 默认 | 说明 |
|---|---|---|
| `ANTHROPIC_API_KEY` | — | 必需 |
| `SA_LLM_MODEL` | `claude-opus-5` | |
| `SA_WP_PATH` | `/www/wwwroot/studyinjp.com` | WordPress 根目录 |
| `SA_MAX_PER_DAY` | `2` | 每日发布上限 |

## 关于发布节奏

默认每天 2 篇不是保守，是有依据的：批量灌 AI 文章是 Google 明确打击的模式
（scaled content abuse，2024 年 3 月写进垃圾内容政策），命中是**站点级**处罚，
不是单页降权。

而且本站目前三个月累计 3 次点击，正处在被观察期。前几篇的收录与排名表现
出来之前，加量没有依据。

## 内容红线

这些写死在 prompts 里，改 prompt 时不要动：

- **不编造事实。** 查不到出处的数字就不写，不用「约」「一般来说」糊弄。
- **不虚构亲身经历。** 「我在东京留学时」是假的。具体化的正确做法是把泛泛陈述
  换成确切情形，不是编一个人出来讲故事。
- **不暗示合作关系。** 本站只做材料准备与递交，录取由学校决定，学费由学生
  直接付给学校。任何让读者以为本站能左右录取、或代收学费的措辞都不行。

## 词库

`keywords/en.json`。每个选题的 `evidence` 字段分四档，**严格区分**：

- `gsc` —— 本站 Search Console 里真实出现过的查询，附真实排名
- `competitor` —— 竞品站上确实存在的文章（URL 已抓取核对）
- `serp` —— 实际跑过搜索、看过结果页得出的竞争度判断
- `inferred` —— 我的推断，没有外部数据支撑

**文件里没有「月搜索量」字段。** 接入付费关键词工具之前，任何搜索量数字
都只能是编的。宁可没有，不要假的。

`run-all` 的排序是：先按 priority，同优先级下 `gsc` > `competitor` > `serp` > `inferred`。
有真实数据支撑的先做。
