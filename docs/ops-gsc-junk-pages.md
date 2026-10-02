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

## 二、`/cache/` 与 `/en/cache/`（robots.txt 已加，nginx 仍需处理）

**成因**：服务器上存在 `/www/wwwroot/studyinjp.com/cache/`。该目录不在
仓库里，推测是某个缓存插件或手工操作留下的。`/en/cache/` 也能解析，
说明语种前缀的 rewrite 把它一起接了下来。

**已做**：`robots.txt` 增加

```
Disallow: /cache/
Disallow: /*/cache/
```

**仍需在服务器上做**：robots.txt 只是请求，不是拦截。真正要堵住得在
nginx 侧。先看清里面是什么：

```bash
ls -la /www/wwwroot/studyinjp.com/cache/ | head -30
du -sh /www/wwwroot/studyinjp.com/cache/
curl -sI https://studyinjp.com/cache/ | head -5
```

如果确认里面没有线上需要的文件，在 nginx 的 server 块中加：

```nginx
location ~* ^/(?:[a-z]{2}/)?cache/ {
    deny all;
    return 404;
}
```

然后 `nginx -t && nginx -s reload`。

用 `return 404` 而不是 `403`：404 会让 Search Console 把这些 URL 从
索引候选里清掉，403 则会被当作「暂时不可访问」而反复重试。

**不要只在 robots.txt 里 Disallow 就算完**：目录列表会暴露服务器上的
文件结构，而这与索引无关 —— 那是个访问控制问题。

---

## 三、`/en/hello-world/` 与 `/en/sample-page/`（需在服务器上删除）

**成因**：WordPress 安装时自带的示例文章与示例页面，从未被删除。
内容是英文默认文案，与本站无关。

**处理**（在 `/www/wwwroot/studyinjp.com` 下执行）：

```bash
# 先确认 ID 与标题，不要凭 slug 猜
wp post list --post_type=post,page --fields=ID,post_title,post_name,post_status --allow-root

# 确认是 hello-world / sample-page 之后再删，--force 跳过回收站
wp post delete <ID_hello_world> <ID_sample_page> --force --allow-root
```

删除后这两个 URL 会返回 404，Search Console 在下一轮抓取后把它们移出
报告。**不要用 301 重定向到首页** —— 这两个 URL 没有任何外部链接或
权重可以继承，重定向只会制造一条需要长期维护的规则。

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

**不能做的事**：没有办法让 Google 加快收录。反复在 GSC 里请求编制索引
对批量 URL 无效，而且是对该功能的误用。

---

## 核查

代码侧改动在服务器上生效后：

```bash
curl -s https://studyinjp.com/robots.txt
curl -s https://studyinjp.com/category/uncategorized/ | grep -o '<meta name="robots"[^>]*>'
curl -sI https://studyinjp.com/en/hello-world/ | head -3
curl -sI https://studyinjp.com/cache/ | head -3
```

期望：robots.txt 含两条 cache 的 Disallow；分类归档输出
`noindex,follow`；两个示例 URL 返回 404；`/cache/` 返回 404。
