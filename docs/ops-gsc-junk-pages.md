# Search Console 上的垃圾 URL：成因与处理

Search Console 报了几类「未编入索引」的 URL。它们不是故障，是装好
WordPress 之后没人清理的默认产物，加上服务器上一个本不该对外的目录。

分四类处理，前两类已在代码里修掉，后两类需要在服务器上执行。

---

## 一、`/category/uncategorized/`（已在代码中修复）

**成因**：WordPress 安装时自带的默认分类，一篇文章都没有。本站的内容
模型是「院校」（自定义表）与「文章」（CPT），两者都有自己的列表页与
sitemap，core 的分类法归档从未被纳入任何入口，也没有对应模板 ——
回落到 `index.php`，产出一个空页面。

**处理**：`inc/seo.php` 的 `sa_is_noindex()` 现在把 core 的
`category / tag / tax / author / date` 归档全部判为 noindex。

**为什么用 noindex 而不是 robots.txt 的 Disallow**：要让爬虫读到
`noindex`，就必须允许它抓到这个页面。Disallow 会让爬虫读不到那条指令，
结果页面可能以「无摘要」的形式继续留在索引里 —— 与预期相反。

**刻意没做的事**：没有对分页页面（第 2 页及以后）加 noindex。
Google 明确不建议对分页序列这么做：被 noindex 的页面会被抓得越来越少，
其上的链接也会被降权，于是只能从第 2 页进入的条目反而更难被发现。
分页需要的是自指 canonical（`sa_canonical_url()` 已经这么做）加 sitemap
覆盖。

---

## 二、`/cache/`、`/cache-2/` 与 5 个 `x` 页面（需在服务器上删除）

**此前的诊断是错的，记在这里以免重犯。**

最初我判断 `/cache/` 是服务器上一个缓存目录，并给出了 robots.txt 的
Disallow 与 nginx deny 方案。实际在服务器上 `ls` 之后：

```
ls: cannot access /www/wwwroot/studyinjp.com/cache/: No such file or directory
```

目录不存在。`/cache/` 是**两个 WordPress 页面**：

| ID  | 标题  | slug      |
|-----|-------|-----------|
| 430 | cache | `cache`   |
| 464 | cache | `cache-2` |

同时还有 5 个标题为 `x` 的页面（ID 322 / 344 / 350 / 376 / 388，
slug `x` 到 `x-5`）—— 调试时留下的。

这些页面都是已发布的 WordPress 页面，因此会进入 core 的 sitemap、
被语种路由接管（所以 `/en/cache/` 也能解析），并以「已发现 —— 尚未编入
索引」的形式堆在报告里。

**那条 robots.txt 规则已经撤掉，因为它有害。**
页面删除后 `/cache/` 会返回 404，而 Disallow 会让爬虫读不到那个 404，
URL 反而更久留在索引里 —— 正是本文件开头讲的那个错误，我自己犯了一次。
对已删除的 URL，要让爬虫**抓得到** 404。

**处理**（在 `/www/wwwroot/studyinjp.com` 下执行）：

```bash
# 先看清内容与状态，不要凭标题就删
wp post list --post__in=430,464,322,344,350,376,388 \
  --fields=ID,post_title,post_name,post_status,post_date --allow-root

# 逐个看正文长度，确认是空页面
for id in 430 464 322 344 350 376 388; do
  printf '%s: %s 字\n' "$id" "$(wp post get $id --field=post_content --allow-root | wc -c)"
done

# 确认之后再删
wp post delete 430 464 322 344 350 376 388 --force --allow-root
```

**为什么用 `--force`**：不加会进回收站，回收站里的页面 slug 仍被占用，
而且 URL 继续返回 404 以外的状态一段时间。直接删干净。

## 三、`/en/hello-world/` 与 `/en/sample-page/`（需在服务器上删除）

**成因**：WordPress 安装时自带的示例文章与示例页面，从未被删除。
内容是英文默认文案，与本站无关。

实际在服务器上确认到的是**三个**默认内容，不是两个：

| ID | 标题         | slug             | 说明 |
|----|--------------|------------------|------|
| 1  | 世界，您好！ | `hello-world`    | 默认文章 |
| 2  | 示例页面     | `sample-page`    | 默认页面 |
| 3  | 隐私政策     | `privacy-policy` | 默认隐私页，**与在用的 ID 10 `privacy` 不是同一个** |

ID 3 要特别看一眼：站点实际使用的隐私政策是 ID 10（slug `privacy`，
日文标题「プライバシーポリシー」）。ID 3 是 WordPress 自带的那一份，
内容是占位文案。删 ID 3 不影响站点 —— `sa_noindex_slugs()` 同时列了
`privacy` 与 `privacy-policy`，所以两个 slug 本来都是 noindex。

**处理**（在 `/www/wwwroot/studyinjp.com` 下执行）：

```bash
# 先确认 ID 3 的内容确实是占位文案，而不是有人写过的正文
wp post get 3 --field=post_content --allow-root | head -20

# 确认之后再删，--force 跳过回收站
wp post delete 1 2 3 --force --allow-root
```

删除后这些 URL 会返回 404，Search Console 在下一轮抓取后把它们移出
报告。**不要用 301 重定向到首页** —— 这些 URL 没有任何外部链接或
权重可以继承，重定向只会制造一条需要长期维护的规则。

也**不要**在 robots.txt 里 Disallow 它们（同第二节的教训）：
要让爬虫抓得到 404，才能把 URL 清出索引。

---

## 四、「已发现 —— 尚未编入索引」持续上升

这一类不是缺陷。Google 发现了 URL 但还没分配抓取资源，常见于新站与
短期内新增大量页面的站点。

能做的事只有三件，而且都做过了：

1. 保证这些 URL 在 sitemap 里（文章与院校都有各自的 sitemap 条目）。
2. 保证它们有站内链接指向（孤岛页面会长期停在这个状态 ——
   `scripts/seo-audit.sh` 的 B4d 段会逐篇检查内链，
   `visa-2026-parttime-rules` 此前就是零内链，已补）。
3. 把上面三类垃圾 URL 清掉，不要浪费抓取预算。

需要注意的是，第二节那 7 个页面本身就在 core 的页面 sitemap 里，
所以这个数字里有一部分就是它们。删掉之后应当回落。

**不能做的事**：没有办法让 Google 加快收录。反复在 GSC 里请求编制索引
对批量 URL 无效，而且是对该功能的误用。

---

## 核查

代码侧改动在服务器上生效后：

```bash
# robots.txt 里**不应**出现 cache 相关的 Disallow
curl -s https://studyinjp.com/robots.txt

# 分类归档应输出 noindex
curl -s https://studyinjp.com/category/uncategorized/ | grep -o '<meta name="robots"[^>]*>'

# 删掉的页面应返回 404
for u in /en/hello-world/ /en/sample-page/ /cache/ /en/cache/ /cache-2/ /x/; do
  printf '%-22s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' "https://studyinjp.com$u")"
done

# 页面 sitemap 里不应再有它们
curl -s https://studyinjp.com/wp-sitemap-posts-page-1.xml | grep -oE '<loc>[^<]+</loc>'
```

期望：robots.txt 无 cache 条目；分类归档 `noindex,follow`；
上面 6 个 URL 全部 404；页面 sitemap 只剩实际在用的页面。
