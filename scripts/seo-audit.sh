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
            # .mo 是构建产物，已不入库（由 .po 编译而来），全新克隆时本就没有。
            # 生产环境的把关在 seo-deploy.sh 第 [2/7] 步：编译后仍缺失会直接失败。
            warn "尚未编译 sa-theme-${LC}.mo —— 该语种会回退显示日文"
            echo "         编译命令: bash scripts/i18n-build.sh compile"
        fi
    done

    # .po 未翻译条目统计。
    # 必须按语法解析：msgmerge 折行后译文首行是 `msgstr ""`，grep 会误判。
    for LC in zh_CN en_US; do
        PO="${LANG_DIR}/sa-theme-${LC}.po"
        if [ -f "$PO" ]; then
            if [ -f "${SITE_ROOT}/scripts/lib/po-stat.php" ] && command -v php >/dev/null 2>&1; then
                STAT=$(php "${SITE_ROOT}/scripts/lib/po-stat.php" "$PO" 2>/dev/null | head -1)
                EMPTY=$(echo "$STAT" | awk '{print $1}')
                TOTALN=$(echo "$STAT" | awk '{print $2}')
                if [ "${EMPTY:-0}" -eq 0 ]; then
                    ok "${LC}: 无未翻译条目（共 ${TOTALN} 条）"
                else
                    warn "${LC}: 有 ${EMPTY}/${TOTALN} 条未翻译（会回退显示日文）"
                fi
            else
                warn "${LC}: 缺少 po-stat.php 或 php，跳过翻译完成度统计"
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
    # 检查的是派生文件而不是源图。
    # 页面引用的是 optimize-images.php 按显示尺寸生成的 -960w / -1920w，
    # 只查源图会在「源图在、没跑过优化脚本」时误报齐全，而前台全是 404。
    for img in slide-1-960w.jpg slide-2-960w.jpg slide-3-960w.jpg \
               slide-1-960w.webp slide-2-960w.webp slide-3-960w.webp \
               hero-bg-1280w.jpg hero-bg-1280w.webp \
               hero-bg-1920w.jpg hero-bg-1920w.webp; do
        [ -f "${THEME}/assets/images/${img}" ] || { warn "缺少 assets/images/${img}"; MISSING_IMG=1; }
    done
    if [ "$MISSING_IMG" = "0" ]; then
        ok "轮播图与 hero 的各尺寸／WebP 派生文件齐全"
    else
        echo "         生成命令: php scripts/optimize-images.php"
    fi
else
    warn "assets/images/ 目录不存在 —— 首页轮播将不渲染（代码已做降级处理，不会出现破图）"
    echo "         补图命令: bash scripts/fetch-images.sh"
fi
# 分享图按语种各一张（sa_share_image() 会优先选当前语种的图）
OG_MISSING=""
for og in "og-default.jpg:ja" "og-default-zh_CN.jpg:zh_CN" "og-default-en_US.jpg:en_US"; do
    F="${og%%:*}"; LC="${og##*:}"
    [ -f "${THEME}/assets/images/${F}" ] || OG_MISSING="${OG_MISSING} ${LC}"
done
if [ -z "$OG_MISSING" ]; then
    ok "三语分享图齐全（1200x630）"
else
    warn "缺少分享图语种:${OG_MISSING} —— 该语种分享时无缩略图"
    echo "         生成命令: php scripts/make-og-image.php"
fi

# ---------- A9. 虚假合作关系措辞 ----------
#
# 本站与刊载院校之间不存在代理或合作关系：实际业务只到「协助准备出願材料 +
# 转交给院校」为止，入学审核与学费收取都由院校与学生直接完成。
#
# 因此「提携校」「合作院校」「指定校」「代办申请」这类措辞属于虚假陈述 ——
# 在日本可能构成景品表示法上的优良误认表示，也给院校方要求下架的理由。
#
# 这种措辞最容易在写新文案时被无意带回来（读起来更"有实力"），
# 所以固化成一条会失败的检查，而不是只写在文档里。
head2 "A9. 是否出现暗示合作/代理关系的措辞"
BAD_WORDS='提携校|合作院校|指定校|代办|代辦|申請代行|出願代行|partner school|Partner School'
#
# 三类排除：
#   1. 纯注释行 —— 注释里必须写出这些词才能说明为什么禁止
#   2. /languages/ —— .po 中的历史译文不影响前台输出，由 i18n-build 负责收敛
#   3. 显式豁免 —— 后台「发布前核对清单」要把禁用词列给编辑看。
#      这类位置在同一行末尾写 sa-audit-allow-partner-words。
#      刻意用同行标记而不是上一行：跨行判断需要 -B1 之类的上下文匹配，
#      管道一复杂就容易出现"看着过了其实没过"的假绿灯。
HITS=$(grep -rnE "$BAD_WORDS" \
        --include='*.php' \
        "${THEME}" "${SITE_ROOT}/wp-content/plugins/study-abroad-core" 2>/dev/null \
      | grep -v 'sa-audit-allow-partner-words' \
      | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(\*|//|#)' \
      | grep -vE '/languages/' || true)
if [ -z "$HITS" ]; then
    ok "未发现暗示合作或代理关系的措辞"
else
    bad "发现暗示合作/代理关系的措辞（本站无代理关系，属虚假陈述）"
    echo "$HITS" | head -10 | sed 's/^/         /'
    echo "         替代写法：掲載校 / 学校情報 / 出願書類の準備・取次ぎ"
fi

# 关系开示组件是否仍被各页面引用（被误删会让所有开示同时消失）
DISCLOSURE="${THEME}/template-parts/relationship-disclosure.php"
if [ ! -f "$DISCLOSURE" ]; then
    bad "缺少 template-parts/relationship-disclosure.php —— 全站关系开示已失效"
else
    USES=$(grep -rl "relationship-disclosure" --include='*.php' "${THEME}" 2>/dev/null \
           | grep -v 'template-parts/relationship-disclosure.php' | wc -l | tr -d ' ')
    # 期望：footer / template-schools / template-school-single / page-services
    if [ "${USES:-0}" -ge 4 ]; then
        ok "关系开示组件被 ${USES} 个模板引用"
    else
        bad "关系开示组件仅被 ${USES} 个模板引用（应至少 4：页脚 + 院校列表 + 院校详情 + 服务介绍）"
    fi
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

# ---------- B1b. 伪静态是否生效（根因诊断） ----------
# 多语种页面 404 有两种完全不同的原因，必须区分，否则会误以为是主题代码问题：
#   (a) 主题语种路由没生效  —— 代码问题
#   (b) 服务器伪静态没配好  —— 服务器配置问题，与主题无关
# wp-sitemap.xml 是最好的探针：它是 WordPress 核心功能，与本主题完全无关。
# 它一旦 404，说明固定链接/伪静态整体失效，此时所有子路径都会 404。
head2 "B1b. 伪静态（pretty permalinks）是否生效"
SITEMAP_CODE=$(status_of "${SITE_URL}/wp-sitemap.xml")
ROOT_CODE=$(status_of "${SITE_URL}/")
if [ "$SITEMAP_CODE" = "200" ]; then
    ok "wp-sitemap.xml → 200，伪静态正常"
elif [ "$ROOT_CODE" = "200" ]; then
    bad "wp-sitemap.xml → ${SITEMAP_CODE}，但首页正常 —— 伪静态未生效"
    echo "         这是【服务器配置问题】，不是主题代码问题。"
    echo "         所有子路径（/zh/ /en/ /faq/ 等）都会因此 404。"
    echo ""
    echo "         排查与修复："
    echo "         1) 查看固定链接结构（返回空即为「朴素」，必须改）:"
    echo "              wp option get permalink_structure --path=${SITE_ROOT} --allow-root"
    echo "         2) 设为 postname 并刷新:"
    echo "              wp rewrite structure '/%postname%/' --path=${SITE_ROOT} --allow-root"
    echo "              wp rewrite flush --hard --path=${SITE_ROOT} --allow-root"
    echo "         3) nginx 需要把未命中的路径转交 index.php:"
    echo "              location / { try_files \$uri \$uri/ /index.php?\$args; }"
    echo "            宝塔面板: 网站 → 设置 → 伪静态 → 选择 wordpress → 保存"
    echo "            （请在面板操作，手改 nginx conf 会被面板覆盖）"
else
    bad "首页也无法访问（${ROOT_CODE}），请先确认站点与域名解析正常"
fi

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
# H1 在模板中常跨多行（含 <em> 等内联标签），grep 是按行匹配的，
# 因此先用 tr 把换行压掉再提取，否则会取到空值。
extract_h1() {
    tr '\n' ' ' < "$1" 2>/dev/null \
        | grep -o '<h1[^>]*>.*\?</h1>' \
        | head -1 | sed 's/<[^>]*>//g' | tr -s ' ' | sed 's/^ *//; s/ *$//'
}
H1_JA=$(extract_h1 "${TMP}/home.html")
H1_ZH=$(extract_h1 "${TMP}/home_zh.html")
H1_EN=$(extract_h1 "${TMP}/home_en.html")
echo "    日文 H1: ${H1_JA:0:60}"
echo "    中文 H1: ${H1_ZH:0:60}"
echo "    英文 H1: ${H1_EN:0:60}"

# 关键：必须先确认页面真的是 200。
# 否则 404 页面的 H1 与首页 H1 天然不同，会被误判为「翻译已生效」。
ZH_CODE=$(status_of "${SITE_URL}/zh/")
EN_CODE=$(status_of "${SITE_URL}/en/")

if [ "$ZH_CODE" != "200" ]; then
    bad "中文页返回 ${ZH_CODE}，无法校验翻译（需先修复页面可访问性）"
elif [ -z "$H1_ZH" ]; then
    warn "中文页未取到 H1，无法自动判定"
elif [ "$H1_ZH" = "$H1_JA" ]; then
    bad "中文页 H1 与日文页完全相同 —— 翻译未生效，会被判定为重复内容"
else
    ok "中文页内容与日文页不同（翻译已生效）"
fi

if [ "$EN_CODE" != "200" ]; then
    bad "英文页返回 ${EN_CODE}，无法校验翻译（需先修复页面可访问性）"
elif [ -z "$H1_EN" ]; then
    warn "英文页未取到 H1，无法自动判定"
elif [ "$H1_EN" = "$H1_JA" ]; then
    bad "英文页 H1 与日文页完全相同 —— 翻译未生效"
else
    ok "英文页内容与日文页不同（翻译已生效）"
fi

# ---------- B4b. 数据库内容是否也已语种化 ----------
# 站点名称、导航菜单项、页面标题都存在数据库里，不经过 gettext。
# 只检查 H1 会漏掉这一整类问题：正文已翻译，但 Logo 和导航栏仍是建站时的日文。
head2 "B4b. 数据库内容语种化（站点名称 / 导航菜单）"
if [ "$ZH_CODE" = "200" ]; then
    LEAK=""
    for jp in "サービス紹介" "会社概要" "お問い合わせ" "よくある質問" "私たちについて"; do
        if grep -q "$jp" "${TMP}/home_zh.html" 2>/dev/null; then
            LEAK="${LEAK} ${jp}"
        fi
    done
    if [ -n "$LEAK" ]; then
        bad "中文页仍出现日文导航/标题：${LEAK}"
        echo "         这些文案来自数据库（菜单项或页面标题），不经过语言包。"
        echo "         需在 inc/i18n.php 的 sa_content_label_map() 中补映射。"
    else
        ok "中文页导航与标题已语种化"
    fi

    # 站点名称（出现在 <title> 中）
    TITLE_ZH=$(tr '\n' ' ' < "${TMP}/home_zh.html" 2>/dev/null | grep -o '<title>[^<]*</title>' | head -1 | sed 's/<[^>]*>//g')
    TITLE_JA=$(tr '\n' ' ' < "${TMP}/home.html" 2>/dev/null | grep -o '<title>[^<]*</title>' | head -1 | sed 's/<[^>]*>//g')
    echo "    日文 title: ${TITLE_JA:0:70}"
    echo "    中文 title: ${TITLE_ZH:0:70}"
    if [ -n "$TITLE_ZH" ] && [ "$TITLE_ZH" = "$TITLE_JA" ]; then
        bad "中文页 <title> 与日文页完全相同 —— 站点名称或标题未按语种区分"
    elif [ -n "$TITLE_ZH" ]; then
        ok "中文页 <title> 已与日文页区分"
    fi

    # 逐条比对导航链接。
    #
    # 上面那个 H1 对比只能说明「页面整体翻译生效了」，看不出单个菜单项漏没漏。
    # 菜单项存在数据库里、不走 gettext，要靠 sa_content_label_map() 逐条映射，
    # 而那是张硬编码的表 —— 在后台新增一个菜单项就会漏翻，且不会有任何报错。
    # 实际发生过：手工加的「無料AI診断」「ご利用の流れ」在中文站一直显示日文。
    #
    # 判据是「同一链接在日文页与本页文字完全相同」，与字符种类无关 ——
    # 只查假名会漏掉「無料AI診断」这种全由汉字与字母组成的标签。
    NAV_CHECK="${SITE_ROOT}/scripts/lib/check-nav-i18n.php"
    if [ -f "$NAV_CHECK" ] && command -v php >/dev/null 2>&1; then
        for LC in zh en; do
            NAV_OUT=$(php "$NAV_CHECK" "${SITE_URL}/" "${SITE_URL}/${LC}/" 2>&1)
            NAV_RC=$?
            case "$NAV_RC" in
                0) ok "/${LC}/ 导航逐条已语种化（$(echo "$NAV_OUT" | head -1 | grep -o '[0-9]* 项')）" ;;
                1) bad "/${LC}/ 导航有菜单项仍显示日文原文"
                   echo "$NAV_OUT" | sed 's/^/         /' ;;
                *) warn "/${LC}/ 导航检查未能完成"
                   echo "$NAV_OUT" | head -3 | sed 's/^/         /' ;;
            esac
        done
    else
        warn "跳过导航逐条检查（缺少 scripts/lib/check-nav-i18n.php 或 php）"
    fi
else
    info "中文页不可访问（${ZH_CODE}），跳过本项"
fi

# ---------- B4c. 院校公开页 ----------
head2 "B4c. 院校公开页（/schools/）"
SCHOOLS_CODE=$(status_of "${SITE_URL}/schools/")
if [ "$SCHOOLS_CODE" = "200" ]; then
    ok "/schools/ → 200"
    fetch "${SITE_URL}/schools/" "${TMP}/schools.html"

    # 取第一个院校详情链接，验证详情页确实可访问
    FIRST_SCHOOL=$(grep -o 'href="[^"]*/schools/[^"/]\+/"' "${TMP}/schools.html" 2>/dev/null \
        | head -1 | sed 's/.*href="//; s/"$//')
    if [ -n "$FIRST_SCHOOL" ]; then
        DETAIL_CODE=$(status_of "$FIRST_SCHOOL")
        if [ "$DETAIL_CODE" = "200" ]; then
            ok "院校详情页可访问: ${FIRST_SCHOOL}"
            fetch "$FIRST_SCHOOL" "${TMP}/school.html"
            grep -q '"@type":"WebPage"' "${TMP}/school.html" 2>/dev/null \
                && ok "详情页含 WebPage/about 结构化数据" \
                || warn "详情页未发现 WebPage 结构化数据"
            DETAIL_CANON=$(grep -o '<link rel="canonical" href="[^"]*"' "${TMP}/school.html" 2>/dev/null | head -1 | sed 's/.*href="//; s/"$//')
            case "$DETAIL_CANON" in
                */schools/*) ok "详情页 canonical 正确: ${DETAIL_CANON}" ;;
                "")          bad "详情页未输出 canonical" ;;
                *)           bad "详情页 canonical 未指向自身（${DETAIL_CANON}）" ;;
            esac

            # --- 事实准确性相关的检查 ---
            # 这些页面冠着真实院校名称展示学费，写错就是发布虚假信息，
            # 因此把「容易错、错了后果重」的几项固化成检查。
            SCHOOL_TXT=$(tr '\n' ' ' < "${TMP}/school.html" 2>/dev/null)

            # 1) 结构化数据里院校实体的 url 绝不能是本站。
            #    标成本站等于声称「本站就是这所学校」。
            ABOUT_URL=$(printf '%s' "$SCHOOL_TXT" \
                | grep -o '"about":{[^}]*"url":"[^"]*"' 2>/dev/null \
                | head -1 | sed 's/.*"url":"//; s/"$//; s/\\\///g')
            if [ -z "$ABOUT_URL" ]; then
                warn "详情页 about 节点未输出 url（该校未填 official_url 时属预期）"
            else
                case "$ABOUT_URL" in
                    *studyinjp.com*)
                        bad "结构化数据把院校实体的 url 指向了本站（${ABOUT_URL}）—— 等于声称本站就是这所学校" ;;
                    http*)
                        ok "院校实体 url 指向校方官网: ${ABOUT_URL}" ;;
                    *)
                        warn "院校实体 url 取值异常: ${ABOUT_URL}" ;;
                esac
            fi

            # 2) 学费口径必须显式写出「年間」或「総額」。
            #    此前代码把单位硬编码成「年間」，会把课程总额显示成年额，
            #    金额差出一倍 —— 这条检查就是为了防止那种回归。
            if printf '%s' "$SCHOOL_TXT" | grep -q '学費'; then
                if printf '%s' "$SCHOOL_TXT" | grep -qE '(年間|総額|年间|总额|Total|per year)'; then
                    BASIS_SHOWN=$(printf '%s' "$SCHOOL_TXT" | grep -oE '(年間 [^<]{0,24}万円|総額 [^<]{0,24}万円)' | head -2 | tr '\n' ' ')
                    ok "学费已标明口径: ${BASIS_SHOWN:-（非日文语种）}"
                else
                    warn "详情页有学费表但未见「年間 / 総額」字样，请确认口径是否显示"
                fi
            fi

            # 3) 关系开示必须在页面上真实出现（不能只在模板里）。
            if printf '%s' "$SCHOOL_TXT" | grep -qE '(代理店ではありません|不是所刊载院校的代理|not an agent)'; then
                ok "关系开示已在详情页输出"
            else
                bad "详情页未见关系开示 —— 页面以真实校名展示学费却未说明本站与该校无代理关系"
            fi

            # 4) 官网链接：页面写着「以官方最新信息为准」，就必须给得出链接。
            if printf '%s' "$SCHOOL_TXT" | grep -qE 'rel="noopener"[^>]*>|学校公式サイト|学校官方网站|Official website'; then
                ok "详情页含学校官网入口"
            else
                warn "详情页未见学校官网链接（该校未填 official_url？）"
            fi
        else
            bad "院校详情页返回 ${DETAIL_CODE}: ${FIRST_SCHOOL}"
        fi
    else
        info "列表页暂无已发布院校（published 默认为 0，需在后台逐校核实后开启）"
    fi

    # 未发布 / 不存在的院校必须 404，否则会产生可被收录的软 404 页面
    NX_CODE=$(status_of "${SITE_URL}/schools/__nonexistent-school-check__/")
    if [ "$NX_CODE" = "404" ]; then
        ok "不存在的院校正确返回 404"
    else
        bad "不存在的院校返回 ${NX_CODE}（应为 404，否则会被收录为空页面）"
    fi
else
    warn "/schools/ → ${SCHOOLS_CODE}（若刚部署，需执行 wp rewrite flush --hard）"
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

# ---------- B10b. 404 页面 ----------
#
# 只查状态码是不够的：空白的 404 与有内容的 404 都返回 404，
# 从状态码上完全看不出区别。此前主题没有 404.php，WordPress 会回落到
# index.php —— 那是文章列表模板，而本站没有任何文章，结果是一个
# 状态码正确但内容全空的页面，访客只能关掉浏览器。
#
# 因此除状态码外还要确认：页面确实渲染了导航与可用的出口链接。
head2 "B10b. 404 页面是否可用"
NF_URL="${SITE_URL}/__nonexistent-page-check__/"
NF_CODE=$(status_of "$NF_URL")
if [ "$NF_CODE" != "404" ]; then
    bad "不存在的地址返回 ${NF_CODE}（应为 404；返回 200 会产生可被收录的软 404）"
else
    ok "不存在的地址正确返回 404"
    fetch "$NF_URL" "${TMP}/404.html"
    NF_TXT=$(tr '\n' ' ' < "${TMP}/404.html" 2>/dev/null)
    NF_BYTES=$(wc -c < "${TMP}/404.html" 2>/dev/null || echo 0)

    # 正文里应当有指向主要页面的链接，否则就是个死胡同
    NF_LINKS=$(printf '%s' "$NF_TXT" | grep -o 'href="[^"]*/\(schools\|faq\|services\|contact\)/"' | wc -l | tr -d ' ')
    if [ "${NF_BYTES:-0}" -lt 2000 ]; then
        bad "404 页面仅 ${NF_BYTES} 字节 —— 可能回落到了空模板（缺少 404.php？）"
    elif [ "${NF_LINKS:-0}" -lt 2 ]; then
        warn "404 页面缺少通往主要页面的出口链接（仅 ${NF_LINKS} 条）"
    else
        ok "404 页面有内容（${NF_BYTES} 字节）且含 ${NF_LINKS} 条出口链接"
    fi

    # 各语种的 404 也要能正常渲染，不能只有日文版有
    for LC in zh en; do
        LC_CODE=$(status_of "${SITE_URL}/${LC}/__nonexistent-page-check__/")
        [ "$LC_CODE" = "404" ] \
            && ok "/${LC}/ 的 404 正常" \
            || bad "/${LC}/ 不存在的地址返回 ${LC_CODE}（应为 404）"
    done
fi

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
# 只匹配真正的字体样式表 URL（含 css2?family=），
# 不能匹配 <link rel="preconnect"> 的裸域名，否则 display=swap 检测必然误报。
JA_FONTS=$(grep -o "fonts\.googleapis\.com/css2?[^\"']*" "${TMP}/home.html" 2>/dev/null)
if [ -n "$JA_FONTS" ]; then
    echo "$JA_FONTS" | sed 's/^/         /'
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
    # 异步加载检测：media="print" onload 手法可避免字体样式表阻塞首屏渲染。
    if grep -q "onload=\"this.media='all'\"\|onload='this.media=\"all\"'" "${TMP}/home.html" 2>/dev/null; then
        ok "字体样式表异步加载（不阻塞首屏渲染）"
    else
        warn "字体样式表可能阻塞渲染"
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
