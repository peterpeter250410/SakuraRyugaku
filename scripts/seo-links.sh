#!/bin/bash
# ============================================================
# SakuraRyugaku — SEO 人工检测链接清单
# ------------------------------------------------------------
# 生成一份可直接点击的检测链接清单。脚本自身不访问网络，
# 只负责把各家检测工具的 URL 拼好，你复制到浏览器逐项点开验证。
#
# 用法：
#   bash scripts/seo-links.sh                          # 默认 studyinjp.com
#   bash scripts/seo-links.sh https://studyinjp.com
#   bash scripts/seo-links.sh https://studyinjp.com > seo-links.txt
# ============================================================

set -u

SITE_URL="${1:-https://studyinjp.com}"
SITE_URL="${SITE_URL%/}"
HOST=$(echo "$SITE_URL" | sed 's#https\?://##; s#/.*##')

# URL 编码（只处理 : / ? & = # 这些常见字符，足够拼查询串）
enc() {
    printf '%s' "$1" | sed \
        -e 's|%|%25|g' -e 's|:|%3A|g' -e 's|/|%2F|g' \
        -e 's|?|%3F|g' -e 's|&|%26|g' -e 's|=|%3D|g' -e 's|#|%23|g'
}

sec() { echo; echo "============================================================"; echo "  $*"; echo "============================================================"; }
sub() { echo; echo "--- $* ---"; }

cat <<HEADER
############################################################
#  SakuraRyugaku SEO 检测链接清单
#  站点: ${SITE_URL}
#  生成: $(date '+%Y-%m-%d %H:%M:%S')
#
#  用法：逐项复制到浏览器打开，按「预期结果」核对。
############################################################
HEADER

# ------------------------------------------------------------
sec "一、收录与索引（Google Search Console）"
cat <<EOF

【GSC 主控制台】
  https://search.google.com/search-console?resource_id=$(enc "sc-domain:${HOST}")
  备用（URL 前缀属性）:
  https://search.google.com/search-console?resource_id=$(enc "${SITE_URL}/")

【提交 sitemap】（三语种页面都在这一份里）
  控制台 → 索引 → 站点地图 → 输入： wp-sitemap.xml
  直接查看: ${SITE_URL}/wp-sitemap.xml
  预期: 能打开 XML，且包含 locales 分组（中英文页面）

【URL 检查 —— 逐个语种验证收录状态】
  网址检查没有可用的直达链接：深链会 404，而且此前这里还用了
  sc-domain: 格式的属性 ID —— 本站在 GSC 里是 URL 前缀属性
  (${SITE_URL}/)，属性类型都不对。走控制台顶部搜索框是唯一可靠方式。

  打开控制台: https://search.google.com/search-console?resource_id=$(enc "${SITE_URL}/")
  把下面的地址逐个粘进顶部搜索框：
    ${SITE_URL}/
    ${SITE_URL}/zh/
    ${SITE_URL}/en/
  预期: 三个都显示「网址在 Google 上」或可「请求编入索引」

【hreflang 错误怎么看】
  注意: GSC 的「国际定位」报告已被 Google 下线，控制台里已经没有这一项。
  现在 hreflang 问题通过以下途径确认：
    1. 网址检查 → 输入 /zh/ 或 /en/ → 看「网页抓取」是否正常、是否被编入索引
    2. 编制索引 → 网页 → 看是否出现「重复网页，Google 选择的规范网址不同」
       （这是 hreflang 失效最典型的症状：各语种被判定为同一页面的副本）
    3. 第三方 hreflang 校验器（见下方第四节），比 GSC 更直观

【site: 查询 —— 快速看收录量】
  全站  : https://www.google.com/search?q=$(enc "site:${HOST}")
  中文站: https://www.google.com/search?q=$(enc "site:${HOST}/zh/")
  英文站: https://www.google.com/search?q=$(enc "site:${HOST}/en/")
  说明: 刚上线通常为 0，提交 sitemap 后数天到数周才会出现
EOF

# ------------------------------------------------------------
sec "二、性能与 Core Web Vitals（直接影响排名）"
cat <<EOF

【PageSpeed Insights】—— 同时给出实验室数据与真实用户数据
  日文首页: https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/")
  中文首页: https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/zh/")
  英文首页: https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/en/")
  服务介绍: https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/services/")
  常见问题: https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/faq/")

  预期指标（移动端，这是 Google 排名实际采用的口径）:
    LCP  最大内容绘制  < 2.5s
    CLS  累计布局偏移  < 0.1
    INP  交互到下次绘制 < 200ms
    性能总分            >= 90（移动端 >= 75 可接受）

【GTmetrix】—— 瀑布流看具体哪个资源慢
  https://gtmetrix.com/?url=$(enc "${SITE_URL}/")

【WebPageTest】—— 可选测试节点，看不同地区速度
  https://www.webpagetest.org/?url=$(enc "${SITE_URL}/")
  建议测试节点: 中国大陆学生看 Hong Kong / Singapore；日本市场看 Tokyo

【Chrome UX Report】—— 真实用户 28 天数据
  https://cruxvis.withgoogle.com/#/?dataset=0&origin=$(enc "${SITE_URL}")
EOF

# ------------------------------------------------------------
sec "三、结构化数据与富媒体结果"
cat <<EOF

【Google 富媒体结果测试】—— 权威，以 Google 实际解析为准
  日文首页: https://search.google.com/test/rich-results?url=$(enc "${SITE_URL}/")
  中文首页: https://search.google.com/test/rich-results?url=$(enc "${SITE_URL}/zh/")
  FAQ 页  : https://search.google.com/test/rich-results?url=$(enc "${SITE_URL}/faq/")
  预期: 检测到 FAQPage / BreadcrumbList，且无错误（警告可接受）

【Schema.org 官方校验器】—— 看全部 JSON-LD 是否合法
  https://validator.schema.org/#url=$(enc "${SITE_URL}/")
  预期: Organization、WebSite、BreadcrumbList、FAQPage 均无 Error
EOF

# ------------------------------------------------------------
sec "四、多语言 hreflang 专项（本次修复重点）"
cat <<EOF

【hreflang 校验工具】
  https://technicalseo.com/tools/hreflang/?url=$(enc "${SITE_URL}/")
  https://www.aleydasolis.com/english/international-seo-tools/hreflang-tags-generator/

【必须满足的三条规则】（任一不满足，Google 就会忽略整组 hreflang）
  1. 双向引用：日文页指向中文页，中文页也必须指回日文页
  2. 自引用  ：每个页面的 hreflang 列表里必须包含它自己
  3. 全部 200：每个 hreflang 指向的 URL 都必须返回 200，不能是 404 或跳转

【人工快速核对】—— 浏览器打开后 Ctrl+U 看源码，搜 hreflang
  ${SITE_URL}/
  ${SITE_URL}/zh/
  ${SITE_URL}/en/
  预期: 三个页面各输出 4 条 alternate（ja / zh-Hans / en / x-default），且三页内容一致

【命令行一键核对】（在你本机或服务器执行）
  for u in "" "zh/" "en/"; do
    echo "=== ${SITE_URL}/\$u ==="
    curl -s "${SITE_URL}/\$u" | grep -o 'hreflang="[^"]*" href="[^"]*"'
  done
EOF

# ------------------------------------------------------------
sec "五、抓取与索引指令"
cat <<EOF

【robots.txt】
  ${SITE_URL}/robots.txt
  预期: 放行 /wp-content/（否则 Google 无法渲染页面）
        不得出现 Disallow: /privacy/ 或 /thanks/（这些页面靠 noindex 排除，
        一旦 Disallow，爬虫读不到 noindex，反而可能被收录）

【robots.txt 测试工具】
  https://search.google.com/search-console/robots-testing-tool?resource_id=$(enc "sc-domain:${HOST}")

【canonical 核对】
  curl -s ${SITE_URL}/ | grep canonical
  预期: 输出唯一 canonical，且不含 ? 参数

【带追踪参数时 canonical 是否仍然干净】（关键回归测试）
  curl -s "${SITE_URL}/?utm_source=test&gclid=abc123" | grep canonical
  预期: canonical 仍为 ${SITE_URL}/ ，不带任何参数

【noindex 页面核对】
  curl -s ${SITE_URL}/privacy/ | grep 'name="robots"'
  curl -s ${SITE_URL}/thanks/  | grep 'name="robots"'
  预期: 都含 noindex，且页面本身能正常访问（200）
EOF

# ------------------------------------------------------------
sec "六、移动端与可访问性"
cat <<EOF

【移动设备适用性】（GSC 内）
  https://search.google.com/search-console/mobile-usability?resource_id=$(enc "sc-domain:${HOST}")

【Lighthouse 可访问性 / SEO 评分】
  Chrome 打开页面 → F12 → Lighthouse → 勾选 SEO + Accessibility + Performance → 移动端
  预期: SEO >= 95，可访问性 >= 90

【W3C HTML 校验】
  https://validator.w3.org/nu/?doc=$(enc "${SITE_URL}/")
  说明: 少量警告可接受，但不应有未闭合标签、重复 id 这类结构性错误
EOF

# ------------------------------------------------------------
sec "七、安全与基础设施（间接影响 SEO 信任度）"
cat <<EOF

【SSL 证书等级】
  https://www.ssllabs.com/ssltest/analyze.html?d=$(enc "${HOST}")
  预期: A 或 A+

【安全响应头】
  https://securityheaders.com/?q=$(enc "${SITE_URL}")&followRedirects=on

【HTTP → HTTPS 跳转核对】
  curl -sI http://${HOST}/ | head -1
  预期: HTTP/1.1 301（必须是 301 永久跳转，不是 302）

【www 与非 www 是否统一】
  curl -sI https://www.${HOST}/ | head -1
  预期: 301 跳转到 ${SITE_URL}，否则构成重复内容
EOF

# ------------------------------------------------------------
sec "八、中国市场专项（你的主攻方向：简中优先）"
cat <<EOF

【百度搜索资源平台】—— 中国市场收录入口
  https://ziyuan.baidu.com/
  注意: 站点在境外服务器时百度抓取较慢；若中国流量成为主力，
        再评估「大陆服务器 + ICP 备案」，那会显著改善百度收录。

【百度收录查询】
  https://www.baidu.com/s?wd=$(enc "site:${HOST}")

【必应站长工具】—— 可直接从 GSC 导入，成本低，别漏
  https://www.bing.com/webmasters/

【中国大陆访问速度实测】
  https://www.itdog.cn/http/${HOST}
  预期: 各省份首字节时间 < 1.5s；若普遍超过 3s，需考虑 CDN
EOF

# ------------------------------------------------------------
sec "九、本地自动化检查（无需浏览器）"
cat <<EOF

【一键全量排查】
  bash scripts/seo-audit.sh ${SITE_URL}

【只查本地代码，不联网】
  bash scripts/seo-audit.sh

【排查无用组件】
  bash scripts/cleanup-check.sh

【重新编译多语言】
  bash scripts/i18n-build.sh
EOF

echo
echo "############################################################"
echo "#  清单结束。建议上线后按「一→二→三→四」的顺序验证。"
echo "#  其中第四项（hreflang）是本次修复的重点，务必逐条核对。"
echo "############################################################"
echo
