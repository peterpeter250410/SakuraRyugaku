# 阶段 2：撰写正文

按给定大纲撰写文章正文。

## 硬性约束（与阶段 1 相同，重申因为这一步最容易越界）

**不得编造事实。** 每一个具体数字都要带来源标记。写不出出处就不写这个数字。

**来源标记格式**，紧跟在该论断所在句子的句末：

```
Students may work up to 28 hours per week once the permit is granted. [source:1]
```

数字对应你在 `sources` 里列出的条目，从 1 起数。

**注意：机器会去抓取每一个来源 URL，并检查你引用的数字是否真的出现在那个页面上。** 对不上就整篇打回。所以：
- 不要引用一个"大概会有这个信息"的页面，要引用你确实看到过该数字的页面
- 不要把 A 页面的数字标成 B 页面的出处
- 日本官方站常用全角数字（２８時間），这没问题，机器会处理

**不得虚构亲身经历。** 不写"我"、"我们的学生"、"有位同学"。

**不得暗示合作关系。** 本站只做材料准备与递交，录取由学校决定，学费由学生直接付给学校 —— 任何让读者以为本站能左右录取结果、或代收学费的措辞都不行。

## 写法

**具体压倒周全。** 与其把八个方面各写两句，不如把三个方面写透。读者要的是能据以行动的信息。

**句子长短要有起伏。** 一个短句。然后一个把前因后果交代清楚、带上限定条件的长句。再一个短的。通篇同样长度的句子读起来像念稿。

**不要用这些词** —— 它们是填充物，删掉句子照样成立：
Additionally / Moreover / Furthermore / It is important to note / It's worth noting /
In conclusion / To sum up / When it comes to / delve into / navigating the /
the landscape of / a myriad of / plays a crucial role / unlock / embark on

**不要每段都三句话。** 段落长短应当跟着内容走。

**不要凡事三项并列。** "the tuition, the location, and the schedule" 这种句式偶尔用是修辞，通篇用是套路。

**开头不要铺垫。** 第一句就进入读者关心的事。不要"日本是一个拥有悠久历史的国家"。

## 摘要

另外写一条 `summary`，它同时用作搜索结果里的 meta description 和页面顶部的导读：

- 120–155 字符（英文）/ 60–80 字（中日文）
- 说明这篇文章能解决什么具体问题，不是复述标题
- 搜索结果里承诺了什么，读者点进来第一眼就要看到什么 —— 不要写文章里没有的内容

## 输出

只输出 JSON，不要任何其它文字，不要用 ``` 包裹：

```
{
  "summary": "用作 meta description 的摘要",
  "body_html": "<p>正文</p><h2>小标题</h2><p>……</p>",
  "sources": [
    {
      "url": "https://www.moj.go.jp/isa/...",
      "title": "页面标题",
      "publisher": "出入国在留管理庁",
      "accessed": "YYYY-MM-DD"
    }
  ],
  "internal_links_used": ["schools/isi-japanese-language-school"],
  "claims_without_sources": ["你主动放弃的、因查不到出处而没写进去的点"]
}
```

`body_html` 只用这些标签：`<p> <h2> <h3> <ul> <ol> <li> <strong> <a>`。
不要写 `<h1>`（页面标题已经是 H1）。站内链接写成相对路径，由程序补全语种前缀。
