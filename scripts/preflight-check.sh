#!/bin/bash
# ============================================================
# SakuraRyugaku — 上线前检查（Preflight / Go-Live Check）
# ------------------------------------------------------------
# 对照 docs/study-abroad/SEO-DEPLOYMENT.md 的 Go-Live Checklist，
# 抓取真实站点验证 SEO 与页面就绪状态。
#
# 用法：bash scripts/preflight-check.sh https://sakuraryugaku.com
#   不传 URL 时默认 http://localhost
#
# 依赖：curl。检查项失败计入 ISSUES，非致命项计入 WARN。
# ============================================================

BASE_URL="${1:-http://localhost}"
BASE_URL="${BASE_URL%/}"   # 去尾斜杠
ISSUES=0
WARN=0

echo "=== SakuraRyugaku Preflight Check ==="
echo "Target: ${BASE_URL}"
echo "Date:   $(date)"
echo "===================================="

if ! command -v curl >/dev/null 2>&1; then
    echo "[FATAL] 需要 curl。"
    exit 1
fi

# fetch <path> -> 输出到 $BODY，HTTP 码到 $CODE
fetch() {
    local url="${BASE_URL}$1"
    BODY=$(curl -sSL -A "SakuraPreflight/1.0" -m 20 -w $'\n__HTTP__%{http_code}' "${url}" 2>/dev/null)
    CODE=$(printf '%s' "${BODY}" | sed -n 's/.*__HTTP__\([0-9]*\)$/\1/p' | tail -1)
    BODY=$(printf '%s' "${BODY}" | sed 's/__HTTP__[0-9]*$//')
}

pass() { echo "  [PASS] $1"; }
fail() { echo "  [FAIL] $1"; ISSUES=$((ISSUES+1)); }
warn() { echo "  [WARN] $1"; WARN=$((WARN+1)); }

# ---- 1. 首页可访问 + HTTPS ----
echo ""
echo "[1] 首页可访问性"
fetch "/"
if [ "${CODE}" = "200" ]; then pass "首页返回 200"; else fail "首页返回 ${CODE}"; fi
case "${BASE_URL}" in
    https://*) pass "使用 HTTPS" ;;
    *) warn "非 HTTPS（生产必须 HTTPS，本地忽略）" ;;
esac

# ---- 2. SEO 头部（title / description / canonical） ----
echo ""
echo "[2] SEO 头部标签"
echo "${BODY}" | grep -qi "<title>[^<]\+</title>" && pass "存在 <title>" || fail "缺少 <title>"
echo "${BODY}" | grep -qi '<meta name="description"' && pass "存在 meta description" || fail "缺少 meta description"
echo "${BODY}" | grep -qi 'rel="canonical"' && pass "存在 canonical" || fail "缺少 canonical"
CNT_H1=$(echo "${BODY}" | grep -oi "<h1" | wc -l | tr -d '[:space:]')
if [ "${CNT_H1}" = "1" ]; then pass "唯一 H1"; else warn "H1 数量=${CNT_H1}（建议每页唯一）"; fi

# ---- 3. hreflang / OG ----
echo ""
echo "[3] 多语言与社交标签"
echo "${BODY}" | grep -qi 'hreflang=' && pass "存在 hreflang" || fail "缺少 hreflang"
echo "${BODY}" | grep -qi 'property="og:title"' && pass "存在 Open Graph" || warn "缺少 Open Graph"

# ---- 4. 结构化数据 JSON-LD ----
echo ""
echo "[4] 结构化数据 JSON-LD"
echo "${BODY}" | grep -qi '"@type":"Organization"' && pass "Organization" || fail "缺少 Organization"
echo "${BODY}" | grep -qi '"@type":"WebSite"' && pass "WebSite" || warn "缺少 WebSite"
echo "${BODY}" | grep -qi '"@type":"FAQPage"' && pass "FAQPage（首页 FAQ）" || warn "首页未见 FAQPage"

# ---- 5. 埋点就位 ----
echo ""
echo "[5] 埋点脚本"
echo "${BODY}" | grep -qi 'tracker.js' && pass "自建埋点 tracker.js 已加载" || warn "未见 tracker.js（确认核心插件启用）"
echo "${BODY}" | grep -qi 'data-sa-lp' && pass "落地页视图埋点标记 data-sa-lp" || warn "未见落地页埋点标记"
echo "${BODY}" | grep -qi 'data-sa-form' && pass "表单曝光埋点标记 data-sa-form" || warn "未见表单埋点标记"

# ---- 6. robots.txt ----
echo ""
echo "[6] robots.txt"
fetch "/robots.txt"
if [ "${CODE}" = "200" ]; then
    pass "robots.txt 可访问"
    echo "${BODY}" | grep -qi 'Sitemap:' && pass "robots 声明 Sitemap" || warn "robots 未声明 Sitemap"
    echo "${BODY}" | grep -qi 'Disallow: /wp-admin/' && pass "屏蔽 /wp-admin/" || warn "未屏蔽 /wp-admin/"
    echo "${BODY}" | grep -qi 'Disallow: /thanks/' && pass "屏蔽 /thanks/" || warn "未屏蔽 /thanks/"
else
    fail "robots.txt 返回 ${CODE}"
fi

# ---- 7. sitemap ----
echo ""
echo "[7] sitemap.xml"
fetch "/wp-sitemap.xml"
if [ "${CODE}" = "200" ]; then pass "wp-sitemap.xml 可访问"; else fail "wp-sitemap.xml 返回 ${CODE}"; fi

# ---- 8. 必要页面就绪 ----
echo ""
echo "[8] 站点页面就绪"
for p in services about faq contact; do
    fetch "/${p}/"
    if [ "${CODE}" = "200" ]; then pass "/${p}/ 200"; else fail "/${p}/ 返回 ${CODE}"; fi
done

# ---- 9. 感谢页 noindex ----
echo ""
echo "[9] 感谢页 noindex"
fetch "/thanks/"
if [ "${CODE}" = "200" ]; then
    echo "${BODY}" | grep -qi 'name="robots" content="noindex' && pass "/thanks/ 已 noindex" || fail "/thanks/ 未 noindex"
else
    warn "/thanks/ 返回 ${CODE}（若尚未建页，先跑 wp-cli-setup.sh）"
fi

# ---- 10. 404 状态 ----
echo ""
echo "[10] 404 处理"
fetch "/this-should-not-exist-$(date +%s)/"
if [ "${CODE}" = "404" ]; then pass "未知路径返回 404"; else warn "未知路径返回 ${CODE}（应为 404）"; fi

# ---- 汇总 ----
echo ""
echo "===================================="
echo "Preflight 完成：ISSUES=${ISSUES}  WARN=${WARN}"
if [ "${ISSUES}" -gt 0 ]; then
    echo "Status: 存在阻断项，请修复后再上线。"
    exit 1
else
    echo "Status: 通过（WARN 请人工确认）。"
    exit 0
fi
