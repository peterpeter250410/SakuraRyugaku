#!/bin/bash
# ============================================================
# SakuraRyugaku — WP-CLI 站点初始化脚本
# ------------------------------------------------------------
# 作用（幂等，可重复运行）：
#   1. 激活 study-abroad-theme 主题
#   2. 激活 study-abroad-core 插件（激活时自动建表/建角色/初始化）
#   3. 设置固定链接结构（伪静态，供 hreflang/canonical/干净 URL）
#   4. 创建落地页所需页面并绑定模板：
#        - thanks   (slug 命中 page-thanks.php，自动 noindex)
#        - services (模板 page-services.php)
#        - about    (模板 page-about.php)
#        - contact  (模板 page-contact.php)
#        - faq      (模板 page-faq.php)
#        - privacy  (通用 page.php，自动 noindex)
#   5. 将首页设为「静态首页」→ 使用 front-page.php 落地页
#   6. 创建并挂载主导航 / 页脚导航菜单
#   7. 刷新固定链接
#
# 用法：bash scripts/wp-cli-setup.sh
# 依赖：WP-CLI（wp）、已完成 WordPress 安装（wp core is-installed 通过）
# ============================================================

set -e

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${SITE_ROOT}"

# WP-CLI 包装：以站点 root 为路径；若以 root 运行需 --allow-root。
WP="wp --path=${SITE_ROOT}"
if [ "$(id -u)" = "0" ]; then
    WP="${WP} --allow-root"
fi

echo "=== SakuraRyugaku WP-CLI Setup ==="
echo "Site root: ${SITE_ROOT}"
echo ""

# ---- 前置检查 ----
if ! command -v wp >/dev/null 2>&1; then
    echo "[FATAL] 未找到 wp 命令（WP-CLI）。请先安装：https://wp-cli.org/"
    exit 1
fi

if ! ${WP} core is-installed >/dev/null 2>&1; then
    echo "[FATAL] WordPress 尚未完成安装。请先跑 install wizard 或 wp core install。"
    exit 1
fi

# ------------------------------------------------------------
# 1. 主题
# ------------------------------------------------------------
echo "[1/7] 激活主题 study-abroad-theme..."
${WP} theme activate study-abroad-theme
echo "  [OK] 主题已激活"

# ------------------------------------------------------------
# 2. 插件（激活触发 activator：建表、角色、匹配规则、加密目录）
# ------------------------------------------------------------
echo "[2/7] 激活插件 study-abroad-core..."
if ${WP} plugin is-active study-abroad-core >/dev/null 2>&1; then
    echo "  [SKIP] 插件已处于激活状态"
else
    ${WP} plugin activate study-abroad-core
    echo "  [OK] 插件已激活（已自动建表/角色/初始化）"
fi

# ------------------------------------------------------------
# 3. 固定链接结构（伪静态）
# ------------------------------------------------------------
echo "[3/7] 设置固定链接结构..."
${WP} rewrite structure '/%postname%/' --hard >/dev/null
echo "  [OK] 固定链接 = /%postname%/"

# ------------------------------------------------------------
# 4. 创建页面并绑定模板（幂等：已存在则跳过）
# ------------------------------------------------------------
echo "[4/7] 创建站点页面并绑定模板..."

# ensure_page <slug> <title> <template-file|""> <noindex-note>
ensure_page() {
    local slug="$1"
    local title="$2"
    local template="$3"

    # 已存在则复用（按 slug 查页面 ID）
    local pid
    pid=$(${WP} post list --post_type=page --name="${slug}" --field=ID --format=ids 2>/dev/null | tr -d '[:space:]')

    if [ -z "${pid}" ]; then
        pid=$(${WP} post create --post_type=page --post_status=publish \
            --post_title="${title}" --post_name="${slug}" --porcelain)
        echo "  [NEW] 创建页面 ${slug} (ID=${pid})"
    else
        echo "  [SKIP] 页面 ${slug} 已存在 (ID=${pid})"
    fi

    # 绑定模板（thanks/privacy 走 slug 命中或通用模板，template 传空则跳过）
    if [ -n "${template}" ]; then
        ${WP} post meta update "${pid}" _wp_page_template "${template}" >/dev/null
        echo "        └ 模板绑定 = ${template}"
    fi

    # 回传 ID 给调用方（通过全局变量）
    PAGE_ID="${pid}"
}

ensure_page "services" "サービス紹介"       "page-services.php"
SERVICES_ID="${PAGE_ID}"
ensure_page "about"    "私たちについて"     "page-about.php"
ABOUT_ID="${PAGE_ID}"
ensure_page "faq"      "よくある質問"       "page-faq.php"
FAQ_ID="${PAGE_ID}"
ensure_page "contact"  "お問い合わせ"       "page-contact.php"
CONTACT_ID="${PAGE_ID}"
ensure_page "thanks"   "お申し込みありがとうございます" "page-thanks.php"
ensure_page "privacy"  "プライバシーポリシー" ""

# ------------------------------------------------------------
# 5. 静态首页 → front-page.php 落地页
#    创建一个占位「ホーム」页作为 front page（front-page.php 会覆盖其展示）。
# ------------------------------------------------------------
echo "[5/7] 设置静态首页..."
HOME_ID=$(${WP} post list --post_type=page --name="home" --field=ID --format=ids 2>/dev/null | tr -d '[:space:]')
if [ -z "${HOME_ID}" ]; then
    HOME_ID=$(${WP} post create --post_type=page --post_status=publish \
        --post_title="ホーム" --post_name="home" --porcelain)
    echo "  [NEW] 创建首页占位 (ID=${HOME_ID})"
else
    echo "  [SKIP] 首页占位已存在 (ID=${HOME_ID})"
fi
${WP} option update show_on_front page >/dev/null
${WP} option update page_on_front "${HOME_ID}" >/dev/null
echo "  [OK] 首页 = 静态页 (ID=${HOME_ID})，实际渲染由 front-page.php 落地页负责"

# ------------------------------------------------------------
# 6. 导航菜单（主导航 primary + 页脚 footer）
# ------------------------------------------------------------
echo "[6/7] 创建并挂载导航菜单..."

# 主导航
if ! ${WP} menu list --fields=name --format=csv 2>/dev/null | grep -q '^"\?Primary"\?$'; then
    ${WP} menu create "Primary" >/dev/null 2>&1 || true
fi
# 幂等：先清空该菜单已有项再重建，避免重复
PRIMARY_ITEMS=$(${WP} menu item list Primary --field=db_id --format=ids 2>/dev/null || true)
for it in ${PRIMARY_ITEMS}; do ${WP} menu item delete "${it}" >/dev/null 2>&1 || true; done

${WP} menu item add-post Primary "${SERVICES_ID}" --title="サービス紹介" >/dev/null 2>&1 || true
${WP} menu item add-post Primary "${FAQ_ID}"      --title="よくある質問" >/dev/null 2>&1 || true
${WP} menu item add-post Primary "${CONTACT_ID}"  --title="お問い合わせ" >/dev/null 2>&1 || true
${WP} menu location assign Primary primary >/dev/null 2>&1 || true
echo "  [OK] 主导航已挂载到 primary"

# 页脚导航
if ! ${WP} menu list --fields=name --format=csv 2>/dev/null | grep -q '^"\?Footer"\?$'; then
    ${WP} menu create "Footer" >/dev/null 2>&1 || true
fi
FOOTER_ITEMS=$(${WP} menu item list Footer --field=db_id --format=ids 2>/dev/null || true)
for it in ${FOOTER_ITEMS}; do ${WP} menu item delete "${it}" >/dev/null 2>&1 || true; done

${WP} menu item add-post Footer "${ABOUT_ID}"    --title="私たちについて" >/dev/null 2>&1 || true
${WP} menu item add-post Footer "${SERVICES_ID}" --title="サービス紹介" >/dev/null 2>&1 || true
${WP} menu item add-post Footer "${FAQ_ID}"      --title="よくある質問" >/dev/null 2>&1 || true
${WP} menu item add-post Footer "${CONTACT_ID}"  --title="お問い合わせ" >/dev/null 2>&1 || true
${WP} menu location assign Footer footer >/dev/null 2>&1 || true
echo "  [OK] 页脚导航已挂载到 footer"

# ------------------------------------------------------------
# 7. 刷新固定链接
# ------------------------------------------------------------
echo "[7/7] 刷新固定链接..."
${WP} rewrite flush --hard >/dev/null
echo "  [OK] 已刷新"

echo ""
echo "=== WP-CLI Setup 完成 ==="
echo ""
echo "已创建/确认页面："
echo "  首页(front)   → ID ${HOME_ID}  (front-page.php 落地页)"
echo "  services      → ${SERVICES_ID}  (page-services.php)"
echo "  about         → ${ABOUT_ID}"
echo "  faq           → ${FAQ_ID}"
echo "  contact       → ${CONTACT_ID}"
echo "  thanks/privacy → 已创建（noindex）"
echo ""
echo "下一步：bash scripts/preflight-check.sh <站点URL> 做上线前检查。"
