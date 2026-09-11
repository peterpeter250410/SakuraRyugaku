#!/bin/bash
# ============================================================
# SakuraRyugaku — SEO 排查脚本
# ------------------------------------------------------------
# 两段式检查：
#   [A] 本地代码检查 —— 不需要联网，随时可跑
#   [B] 线上站点检查 —— 传入站点 URL 后执行，逐项验证真实 HTTP 响应
#
# 用法：
#   bash scripts/seo-audit.sh                        # 只做本地代码检查
#   bash scripts/seo-audit.sh https://studyinjp.com  # 本地 + 线上全量检查
#
# 退出码：0 = 无阻断项；1 = 存在必须修复的问题。
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="${SITE_ROOT}/wp-content/themes/study-abroad-theme"
SITE_URL="${1:-}"
SITE_URL="${SITE_URL%/}"

FAIL=0
WARN=0

# ---------- 输出helper ----------
c_red()   { printf '\033[31m%s\033[0m\n' "$*"; }
c_grn()   { printf '\033[32m%s\033[0m\n' "$*"; }
c_ylw()   { printf '\033[33m%s\033[0m\n' "$*"; }
c_cyn()   { printf '\033[36m%s\033[0m\n' "$*"; }

ok()   { c_grn  "  [OK]   $*"; }
bad()  { c_red  "  [FAIL] $*"; FAIL=$((FAIL+1)); }
warn() { c_ylw  "  [WARN] $*"; WARN=$((WARN+1)); }
info() { echo   "  [..]   $*"; }
head1(){ echo; c_cyn "============================================================"; c_cyn "  $*"; c_cyn "============================================================"; }
head2(){ echo; echo "--- $* ---"; }

head1 "SakuraRyugaku SEO 排查  ($(date '+%Y-%m-%d %H:%M:%S'))"
echo "站点根目录 : ${SITE_ROOT}"
echo "站点 URL   : ${SITE_URL:-<未提供，跳过线上检查>}"

# ============================================================
# [A] 本地代码检查
# ============================================================
head1 "[A] 本地代码检查"

# ---------- A1. PHP 语法 ----------
head2 "A1. PHP 语法"
if command -v php >/dev/null 2>&1; then
    SYNTAX_ERR=0
    while IFS= read -r f; do
        if ! php -l "$f" >/dev/null 2>&1; then
            bad "语法错误: ${f#$SITE_ROOT/}"
            php -l "$f" 2>&1 | head -3 | sed 's/^/         /'
            SYNTAX_ERR=1
        fi
    done < <(find "${THEME}" "${SITE_ROOT}/wp-content/plugins/study-abroad-core" "${SITE_ROOT}/wp-content/mu-plugins" -name '*.php' 2>/dev/null)
    [ "$SYNTAX_ERR" = "0" ] && ok "全部 PHP 文件语法正确"
    info "本机 PHP 版本: $(php -r 'echo PHP_VERSION;')"
else
    warn "未找到 php 命令，跳过语法检查"
fi

# ---------- A2. PHP 7.4 兼容性 ----------
head2 "A2. PHP 7.4 兼容性（生产环境为 PHP 7.4）"
INCOMPAT=$(grep -rnE '\bstr_contains\(|\bstr_starts_with\(|\bstr_ends_with\(|\?->|\bmatch *\{|^\s*#\[|\bfdiv\(|\bget_debug_type\(' \
    --include='*.php' "${THEME}" "${SITE_ROOT}/wp-content/plugins/study-abroad-core" "${SITE_ROOT}/wp-content/mu-plugins" 2>/dev/null)
if [ -n "$INCOMPAT" ]; then
    bad "发现 PHP 8.0+ 专有语法，在 PHP 7.4 上会致命错误："
    echo "$INCOMPAT" | sed 's/^/         /'
else
    ok "未发现 PHP 8.0+ 专有语法"
fi

# ---------- A3. 多语言文件完整性 ----------
head2 "A3. 多语言翻译文件"
LANG_DIR="${THEME}/languages"
if [ ! -d "$LANG_DIR" ]; then
    bad "languages 目录不存在 —— 中文站/英文站不会生效"
else
    for LC in zh_CN en_US; do
        if [ -f "${LANG_DIR}/sa-theme-${LC}.mo" ]; then
            SIZE=$(wc -c < "${LANG_DIR}/sa-theme-${LC}.mo")
            if [ "$SIZE" -gt 1000 ]; then
                ok "sa-theme-${LC}.mo 存在 (${SIZE} 字节)"
            else
                bad "sa-theme-${LC}.mo 过小 (${SIZE} 字节)，可能编译失败"
            fi
        else
            bad "缺少 sa-theme-${LC}.mo —— ${LC} 语种不会生效"
        fi
    done

    # .po 未翻译条目统计
    for LC in zh_CN en_US; do
        PO="${LANG_DIR}/sa-theme-${LC}.po"
        if [ -f "$PO" ]; then
            EMPTY=$(grep -c '^msgstr ""$' "$PO" 2>/dev/null || echo 0)
            # 减去 header 的那一条
            EMPTY=$((EMPTY - 1))
            [ "$EMPTY" -lt 0 ] && EMPTY=0
            if [ "$EMPTY" -eq 0 ]; then
                ok "${LC}: 无未翻译条目"
            else
                warn "${LC}: 有 ${EMPTY} 条未翻译（会回退显示日文）"
            fi
        fi
    done
fi

# ---------- A4. 变量翻译 bug ----------
head2 "A4. 翻译函数误用（传变量导致永不翻译）"
# 排除注释行（// 、# 、* 开头），避免把说明文字误判为代码。
VARTRANS=$(grep -rnE "(esc_html__|esc_attr__|__|_e|esc_html_e|esc_attr_e)\( *\\\$" --include='*.php' "${THEME}" 2>/dev/null \
    | grep -vE ':[0-9]+:[[:space:]]*(//|#|\*|/\*)')
if [ -n "$VARTRANS" ]; then
    bad "翻译函数收到变量，gettext 无法提取，这些文案永远不会被翻译："
    echo "$VARTRANS" | sed 's/^/         /'
else
    ok "翻译函数均使用字面量字符串"
fi

# ---------- A5. SEO 关键函数就位 ----------
head2 "A5. SEO 模块完整性"
for pair in \
    "inc/i18n.php:sa_bootstrap_locale" \
    "inc/i18n.php:sa_current_url_in" \
    "inc/seo.php:sa_canonical_url" \
    "inc/seo.php:sa_robots_meta" \
    "inc/sitemap.php:SA_Sitemap_Locale_Provider" \
    "inc/performance.php:sa_webfont_url" ; do
    F="${pair%%:*}"; FN="${pair##*:}"
    if [ -f "${THEME}/${F}" ] && grep -q "$FN" "${THEME}/${F}"; then
        ok "${F} → ${FN}"
    else
        bad "${F} 中缺少 ${FN}"
    fi
done

# ---------- A6. robots/noindex 冲突 ----------
head2 "A6. robots.txt 与 noindex 冲突检查"
# 经典错误：页面既被 robots.txt Disallow，又输出 noindex。
# Disallow 会阻止爬虫抓取，导致它读不到 noindex，反而可能被索引。
CONFLICT=""
for slug in privacy thanks upload consent; do
    if grep -q "Disallow: /${slug}/" "${THEME}/inc/seo.php" 2>/dev/null \
       && grep -q "'${slug}'" "${THEME}/inc/seo.php" 2>/dev/null; then
        CONFLICT="${CONFLICT} ${slug}"
    fi
done
if [ -n "$CONFLICT" ]; then
    bad "以下页面同时被 Disallow 和 noindex（冲突）:${CONFLICT}"
    echo "         修复：noindex 的页面必须允许抓取，否则爬虫读不到 noindex"
else
    ok "无 Disallow / noindex 冲突"
fi

# ---------- A7. 语种路由单元测试 ----------
head2 "A7. 语种路由逻辑测试（含 hreflang 双向对称性）"
if [ -f "${SITE_ROOT}/scripts/lib/test-i18n-routing.php" ] && command -v php >/dev/null 2>&1; then
    TEST_OUT=$(php "${SITE_ROOT}/scripts/lib/test-i18n-routing.php" 2>&1)
    if [ $? -eq 0 ]; then
        ok "$(echo "$TEST_OUT" | grep -o '通过 [0-9]* 项，失败 [0-9]* 项')"
    else
        bad "语种路由测试未通过："
        echo "$TEST_OUT" | grep -A3 'FAIL' | head -20 | sed 's/^/         /'
    fi
else
    warn "跳过语种路由测试（缺少 scripts/lib/test-i18n-routing.php 或 php）"
fi

# ---------- A8. 缺失图片资源 ----------
head2 "A8. 模板引用的图片是否存在"
MISSING_IMG=0
if [ -d "${THEME}/assets/images" ]; then
    for img in slide-1.jpg slide-2.jpg slide-3.jpg; do
        [ -f "${THEME}/assets/images/${img}" ] || { warn "缺少 assets/images/${img}"; MISSING_IMG=1; }
    done
    [ "$MISSING_IMG" = "0" ] && ok "轮播图资源齐全"
else
    warn "assets/images/ 目录不存在 —— 首页轮播将不渲染（代码已做降级处理，不会出现破图）"
    echo "         补图命令: bash scripts/fetch-images.sh"
fi
if [ ! -f "${THEME}/assets/images/og-default.jpg" ]; then
    warn "缺少 og-default.jpg —— 社交分享无缩略图，建议放一张 1200x630 的品牌图"
fi

# ============================================================
# [B] 线上站点检查
# ============================================================
if [ -z "$SITE_URL" ]; then
    head1 "[B] 线上检查已跳过"
    echo "  传入站点 URL 即可执行线上检查："
    echo "    bash scripts/seo-audit.sh https://studyinjp.com"
else

head1 "[B] 线上站点检查 — ${SITE_URL}"

CURL="curl -sS -L --max-time 25 -A Mozilla/5.0 (compatible; SEOAudit/1.0)"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fetch() {  # fetch <url> <outfile>
    curl -sS -L --max-time 25 -A "Mozilla/5.0 (compatible; SakuraSEOAudit/1.0)" "$1" -o "$2" 2>/dev/null
}
status_of() {
    curl -sS -o /dev/null -w '%{http_code}' -L --max-time 25 \
        -A "Mozilla/5.0 (compatible; SakuraSEOAudit/1.0)" "$1" 2>/dev/null
}
status_nofollow() {
    curl -sS -o /dev/null -w '%{http_code}' --max-time 25 \
        -A "Mozilla/5.0 (compatible; SakuraSEOAudit/1.0)" "$1" 2>/dev/null
}

# ---------- B1. 三语种首页可访问性 ----------
head2 "B1. 三语种页面可访问性（此前 /zh/ /en/ 为 404）"
for path in "/" "/zh/" "/en/"; do
    CODE=$(status_of "${SITE_URL}${path}")
    if [ "$CODE" = "200" ]; then
        ok "${path} → HTTP ${CODE}"
    else
        bad "${path} → HTTP ${CODE}（应为 200）"
    fi
done

# ---------- B2. 抓取首页用于后续分析 ----------
fetch "${SITE_URL}/" "${TMP}/home.html"
fetch "${SITE_URL}/zh/" "${TMP}/home_zh.html"
fetch "${SITE_URL}/en/" "${TMP}/home_en.html"

# ---------- B3. canonical ----------
head2 "B2. canonical"
CANON=$(grep -o '<link rel="canonical" href="[^"]*"' "${TMP}/home.html" 2>/dev/null | head -1 | sed 's/.*href="//; s/"$//')
if [ -z "$CANON" ]; then
    bad "首页未输出 canonical"
else
    ok "首页 canonical: ${CANON}"
    case "$CANON" in
        *\?*) bad "canonical 含 query 参数 —— 会因 utm/gclid 产生海量重复 canonical" ;;
        *)    ok "canonical 不含 query 参数" ;;
    esac
fi
CANON_ZH=$(grep -o '<link rel="canonical" href="[^"]*"' "${TMP}/home_zh.html" 2>/dev/null | head -1 | sed 's/.*href="//; s/"$//')
case "$CANON_ZH" in
    */zh/*) ok "中文页 canonical 含 /zh/ 前缀: ${CANON_ZH}" ;;
    "")     bad "中文页未输出 canonical" ;;
    *)      bad "中文页 canonical 未指向 /zh/（${CANON_ZH}）—— 会被判定为日文页副本" ;;
esac

# ---------- B4. hreflang ----------
head2 "B3. hreflang（最关键：必须全部返回 200）"
HREFS=$(grep -o 'rel="alternate" hreflang="[^"]*" href="[^"]*"' "${TMP}/home.html" 2>/dev/null)
if [ -z "$HREFS" ]; then
    bad "首页未输出 hreflang"
else
    echo "$HREFS" | while IFS= read -r line; do
        LANG=$(echo "$line" | sed 's/.*hreflang="//; s/".*//')
        URL=$(echo "$line" | sed 's/.*href="//; s/"$//')
        CODE=$(status_of "$URL")
        if [ "$CODE" = "200" ]; then
            printf '\033[32m  [OK]   hreflang=%-10s %s → %s\033[0m\n' "$LANG" "$URL" "$CODE"
        else
            printf '\033[31m  [FAIL] hreflang=%-10s %s → %s  (指向非 200 页面，GSC 会报错)\033[0m\n' "$LANG" "$URL" "$CODE"
        fi
    done
    # x-default
    if echo "$HREFS" | grep -q 'hreflang="x-default"'; then
        ok "存在 x-default"
    else
        warn "缺少 x-default"
    fi
fi

# ---------- B5. 语种内容真的不同吗 ----------
head2 "B4. 三语种内容是否真的不同（防止「假多语言」）"
H1_JA=$(grep -o '<h1[^>]*>.*</h1>' "${TMP}/home.html" 2>/dev/null | head -1 | sed 's/<[^>]*>//g' | tr -d ' \t')
H1_ZH=$(grep -o '<h1[^>]*>.*</h1>' "${TMP}/home_zh.html" 2>/dev/null | head -1 | sed 's/<[^>]*>//g' | tr -d ' \t')
H1_EN=$(grep -o '<h1[^>]*>.*</h1>' "${TMP}/home_en.html" 2>/dev/null | head -1 | sed 's/<[^>]*>//g' | tr -d ' \t')
echo "    日文 H1: ${H1_JA:0:60}"
echo "    中文 H1: ${H1_ZH:0:60}"
echo "    英文 H1: ${H1_EN:0:60}"
if [ -n "$H1_ZH" ] && [ "$H1_ZH" = "$H1_JA" ]; then
    bad "中文页 H1 与日文页完全相同 —— 翻译未生效，会被判定为重复内容"
elif [ -n "$H1_ZH" ]; then
    ok "中文页内容与日文页不同（翻译已生效）"
fi
if [ -n "$H1_EN" ] && [ "$H1_EN" = "$H1_JA" ]; then
    bad "英文页 H1 与日文页完全相同 —— 翻译未生效"
elif [ -n "$H1_EN" ]; then
    ok "英文页内容与日文页不同（翻译已生效）"
fi

# ---------- B6. lang 属性 ----------
head2 "B5. html lang 属性"
for pair in "home.html:/" "home_zh.html:/zh/" "home_en.html:/en/"; do
    F="${pair%%:*}"; P="${pair##*:}"
    LANGATTR=$(grep -o '<html[^>]*lang="[^"]*"' "${TMP}/${F}" 2>/dev/null | head -1 | sed 's/.*lang="//; s/".*//')
    if [ -n "$LANGATTR" ]; then
        ok "${P} → lang=\"${LANGATTR}\""
    else
        bad "${P} 缺少 html lang 属性"
    fi
done

# ---------- B7. 基础 meta ----------
head2 "B6. 页面级 SEO 元素（首页）"
grep -q '<meta name="description"' "${TMP}/home.html" && ok "meta description 存在" || bad "缺少 meta description"
H1_COUNT=$(grep -o '<h1' "${TMP}/home.html" | wc -l | tr -d ' ')
if [ "$H1_COUNT" = "1" ]; then ok "H1 唯一（1 个）"
elif [ "$H1_COUNT" = "0" ]; then bad "没有 H1"
else bad "H1 有 ${H1_COUNT} 个，应当唯一"; fi
grep -q 'property="og:image"' "${TMP}/home.html" && ok "og:image 存在" || warn "缺少 og:image（社交分享无缩略图）"
grep -q 'property="og:url"' "${TMP}/home.html" && ok "og:url 存在" || warn "缺少 og:url"
grep -q 'name="twitter:card"' "${TMP}/home.html" && ok "twitter:card 存在" || warn "缺少 twitter:card"
grep -q 'max-image-preview:large' "${TMP}/home.html" && ok "robots 含 max-image-preview:large" || warn "缺少 max-image-preview:large（影响 Google 图片展示）"

# ---------- B8. 结构化数据 ----------
head2 "B7. 结构化数据 JSON-LD"
for t in Organization WebSite FAQPage; do
    if grep -q "\"@type\":\"${t}\"" "${TMP}/home.html" 2>/dev/null; then
        ok "${t} 已输出"
    else
        warn "未发现 ${t}"
    fi
done
LD_COUNT=$(grep -o 'application/ld+json' "${TMP}/home.html" | wc -l | tr -d ' ')
info "JSON-LD 块数量: ${LD_COUNT}"

# ---------- B9. robots.txt / sitemap ----------
head2 "B8. robots.txt 与 sitemap"
fetch "${SITE_URL}/robots.txt" "${TMP}/robots.txt"
if [ -s "${TMP}/robots.txt" ]; then
    ok "robots.txt 可访问"
    if grep -qi "^Sitemap:" "${TMP}/robots.txt"; then
        ok "robots.txt 声明了 sitemap"
    else
        bad "robots.txt 未声明 Sitemap"
    fi
    # 检查是否误屏蔽了需要 noindex 的页面
    for slug in privacy thanks upload; do
        if grep -qi "Disallow: /${slug}/" "${TMP}/robots.txt"; then
            bad "robots.txt 屏蔽了 /${slug}/，爬虫将读不到该页的 noindex（冲突）"
        fi
    done
    # 静态资源必须放行
    if grep -qiE "Disallow: */wp-content/? *$" "${TMP}/robots.txt"; then
        bad "robots.txt 屏蔽了 /wp-content/ —— Google 无法渲染页面，CWV 评分会受损"
    fi
else
    bad "robots.txt 无法访问"
fi

SM_CODE=$(status_of "${SITE_URL}/wp-sitemap.xml")
if [ "$SM_CODE" = "200" ]; then
    ok "wp-sitemap.xml → 200"
    fetch "${SITE_URL}/wp-sitemap.xml" "${TMP}/sitemap.xml"
    if grep -q "locales" "${TMP}/sitemap.xml" 2>/dev/null; then
        ok "sitemap 含多语种分组（locales）"
    else
        warn "sitemap 未发现多语种分组 —— 中英文页面可能未被提交"
    fi
else
    bad "wp-sitemap.xml → ${SM_CODE}"
fi

# ---------- B10. HTTPS / 重定向 / 规范化 ----------
head2 "B9. HTTPS 与域名规范化"
HOST=$(echo "$SITE_URL" | sed 's#https\?://##; s#/.*##')
HTTP_CODE=$(status_nofollow "http://${HOST}/")
case "$HTTP_CODE" in
    301) ok "http:// → 301 重定向（正确）" ;;
    302|307) warn "http:// → ${HTTP_CODE} 临时重定向，SEO 建议改为 301 永久重定向" ;;
    200) bad "http:// 直接返回 200，未强制跳转 HTTPS" ;;
    *)   info "http:// → ${HTTP_CODE}" ;;
esac
WWW_CODE=$(status_nofollow "https://www.${HOST}/")
case "$WWW_CODE" in
    301) ok "www → 301 重定向到规范域名" ;;
    200) bad "www 与非 www 均返回 200 —— 重复内容，须 301 统一" ;;
    *)   info "www 版本 → ${WWW_CODE}（未解析或未配置，通常可接受）" ;;
esac

# ---------- B11. noindex 页面 ----------
head2 "B10. noindex 页面配置"
for slug in privacy thanks; do
    CODE=$(status_of "${SITE_URL}/${slug}/")
    if [ "$CODE" = "200" ]; then
        fetch "${SITE_URL}/${slug}/" "${TMP}/${slug}.html"
        if grep -q 'name="robots" content="noindex' "${TMP}/${slug}.html"; then
            ok "/${slug}/ 可抓取且含 noindex（配置正确）"
        else
            warn "/${slug}/ 未输出 noindex"
        fi
    else
        info "/${slug}/ → ${CODE}（页面可能尚未创建）"
    fi
done

# ---------- B12. 性能 ----------
head2 "B11. 性能指标（服务端侧）"
read -r TTFB TOTAL SIZE <<EOF
$(curl -sS -o /dev/null -w '%{time_starttransfer} %{time_total} %{size_download}' -L --max-time 30 \
  -A "Mozilla/5.0 (compatible; SakuraSEOAudit/1.0)" "${SITE_URL}/" 2>/dev/null)
EOF
info "TTFB       : ${TTFB}s"
info "总耗时     : ${TOTAL}s"
info "首页大小   : ${SIZE} 字节"
awk -v t="$TTFB" 'BEGIN{ if (t+0 > 0.8) exit 1; exit 0 }' \
    && ok "TTFB 良好（< 0.8s）" \
    || warn "TTFB 偏高（> 0.8s）—— 建议开启页面缓存 / OPcache / Redis"

ENC=$(curl -sS -o /dev/null -D - --max-time 20 -H 'Accept-Encoding: gzip, br' "${SITE_URL}/" 2>/dev/null | grep -i '^content-encoding:' | tr -d '\r')
if [ -n "$ENC" ]; then
    ok "启用了压缩（${ENC}）"
else
    bad "未启用 gzip/br 压缩 —— nginx 需开启 gzip on"
fi

CACHE=$(curl -sS -o /dev/null -D - --max-time 20 "${SITE_URL}/wp-content/themes/study-abroad-theme/style.css" 2>/dev/null | grep -i '^cache-control:' | tr -d '\r')
if [ -n "$CACHE" ]; then
    ok "静态资源缓存头: ${CACHE}"
else
    warn "静态资源无 Cache-Control —— 建议 nginx 对静态资源设长缓存"
fi

# ---------- B13. 字体加载 ----------
head2 "B12. 字体加载（CJK 站点的 LCP 关键项）"
JA_FONTS=$(grep -o 'fonts.googleapis.com[^"]*' "${TMP}/home.html" 2>/dev/null | head -2)
if [ -n "$JA_FONTS" ]; then
    info "日文页字体请求: ${JA_FONTS}"
    if echo "$JA_FONTS" | grep -q "Noto+Sans+SC" && echo "$JA_FONTS" | grep -q "Noto+Sans+JP"; then
        bad "日文页同时加载了 JP 与 SC 两套 CJK 字体 —— 多下载一整套，严重拖累 LCP"
    else
        ok "只加载当前语种所需字体"
    fi
    if echo "$JA_FONTS" | grep -q "display=swap"; then
        ok "字体使用 display=swap"
    else
        warn "字体未使用 display=swap，会出现文字不可见期（FOIT）"
    fi
else
    ok "首页未加载外部网络字体（使用系统字体栈，LCP 最优）"
fi

fi  # end 线上检查

# ============================================================
# 汇总
# ============================================================
head1 "排查结果汇总"
if [ "$FAIL" -eq 0 ] && [ "$WARN" -eq 0 ]; then
    c_grn "  全部通过，没有发现问题。"
elif [ "$FAIL" -eq 0 ]; then
    c_ylw "  没有阻断项，但有 ${WARN} 个建议优化项（WARN）。"
else
    c_red "  发现 ${FAIL} 个必须修复的问题（FAIL），另有 ${WARN} 个建议优化项（WARN）。"
fi
echo
echo "  下一步："
echo "    · 生成人工检测链接清单 : bash scripts/seo-links.sh ${SITE_URL:-https://studyinjp.com}"
echo "    · 排查无用组件         : bash scripts/cleanup-check.sh"
echo "    · 重新编译翻译         : bash scripts/i18n-build.sh"
echo

[ "$FAIL" -eq 0 ] && exit 0 || exit 1
