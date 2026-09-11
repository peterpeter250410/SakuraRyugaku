#!/bin/bash
# ============================================================
# SakuraRyugaku — 无用组件 / 冗余文件排查
# ------------------------------------------------------------
# 本脚本只做「检测 + 生成命令」，绝不自动删除任何东西。
# 每一项都会打印出可直接复制执行的清理命令，由你决定是否执行。
#
# 用法：
#   bash scripts/cleanup-check.sh              # 检测并打印清理命令
#   bash scripts/cleanup-check.sh --commands   # 只输出命令，便于直接复制
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME_DIR="${SITE_ROOT}/wp-content/themes"
PLUGIN_DIR="${SITE_ROOT}/wp-content/plugins"
ONLY_CMD=0
[ "${1:-}" = "--commands" ] && ONLY_CMD=1

CMDS=""
FOUND=0

c_grn() { [ "$ONLY_CMD" = "1" ] || printf '\033[32m%s\033[0m\n' "$*"; }
c_red() { [ "$ONLY_CMD" = "1" ] || printf '\033[31m%s\033[0m\n' "$*"; }
c_ylw() { [ "$ONLY_CMD" = "1" ] || printf '\033[33m%s\033[0m\n' "$*"; }
say()   { [ "$ONLY_CMD" = "1" ] || echo "$*"; }
sec()   { [ "$ONLY_CMD" = "1" ] || { echo; printf '\033[36m============================================================\033[0m\n'; printf '\033[36m  %s\033[0m\n' "$*"; printf '\033[36m============================================================\033[0m\n'; }; }

addcmd() { CMDS="${CMDS}$1
"; FOUND=$((FOUND+1)); }

# WP-CLI 包装
if [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    WP="php ${SITE_ROOT}/wp-cli.phar --path=${SITE_ROOT}"
else
    WP="wp --path=${SITE_ROOT}"
fi
[ "$(id -u 2>/dev/null || echo 1000)" = "0" ] && WP="${WP} --allow-root"

say "############################################################"
say "#  无用组件排查 —— 只检测，不删除"
say "#  站点根目录: ${SITE_ROOT}"
say "############################################################"

# ============================================================
sec "一、暴露在公网的敏感文件（安全 + SEO 双重问题）"
# ============================================================

# --- wp-cli.phar ---
if [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    SIZE=$(du -h "${SITE_ROOT}/wp-cli.phar" | cut -f1)
    c_red "  [高危] wp-cli.phar (${SIZE}) 位于网站根目录，可被任何人直接下载"
    say   "         风险: 该文件是可执行的 PHP 归档，暴露在公网是明确的安全隐患。"
    say   "         建议: 移出网站根目录，放到上级目录后照常使用。"
    addcmd "# 把 wp-cli 移出网站根目录（移动而非删除，仍可正常使用）"
    addcmd "mv ${SITE_ROOT}/wp-cli.phar /usr/local/bin/wp && chmod +x /usr/local/bin/wp"
    addcmd ""
fi

# --- 主题目录里的 zip ---
for z in "${THEME_DIR}"/*.zip; do
    [ -e "$z" ] || continue
    SIZE=$(du -h "$z" | cut -f1)
    c_red "  [高危] $(basename "$z") (${SIZE}) 位于主题目录，可被公网直接下载"
    say   "         风险: 主题/插件压缩包泄露完整源码。"
    addcmd "# 删除主题目录中的压缩包（源码已在 git 中，删除不会丢失）"
    addcmd "rm -f ${z}"
    addcmd ""
done

# --- WordPress 自带说明文件（泄露版本号）---
for f in readme.html license.txt wp-config-sample.php; do
    if [ -f "${SITE_ROOT}/${f}" ]; then
        c_ylw "  [中] ${f} 可公开访问"
        say   "       风险: readme.html 会直接暴露 WordPress 版本号，便于针对性攻击。"
        addcmd "# 删除会泄露版本信息的默认文件（WordPress 升级后会重新出现，需再次清理）"
        addcmd "rm -f ${SITE_ROOT}/${f}"
        addcmd ""
    fi
done

[ "$FOUND" = "0" ] && c_grn "  未发现暴露的敏感文件"

# ============================================================
sec "二、未使用的主题"
# ============================================================
ACTIVE_THEME="study-abroad-theme"
UNUSED_THEMES=""
if [ -d "$THEME_DIR" ]; then
    for d in "${THEME_DIR}"/*/; do
        [ -d "$d" ] || continue
        NAME=$(basename "$d")
        [ "$NAME" = "$ACTIVE_THEME" ] && continue
        UNUSED_THEMES="${UNUSED_THEMES} ${NAME}"
        SIZE=$(du -sh "$d" 2>/dev/null | cut -f1)
        c_ylw "  [中] 未使用主题: ${NAME} (${SIZE})"
    done
fi
if [ -n "$UNUSED_THEMES" ]; then
    say "       风险: 停用的主题仍会被扫描器探测，且不会随主题更新修补漏洞。"
    say "       注意: WordPress 建议至少保留一个官方默认主题作为故障回退。"
    addcmd "# 删除未使用的主题（保留当前启用的 study-abroad-theme）"
    for t in $UNUSED_THEMES; do
        addcmd "${WP} theme delete ${t}"
    done
    addcmd ""
else
    c_grn "  除当前主题外无其它主题"
fi

# ============================================================
sec "三、已停用的插件"
# ============================================================
if command -v php >/dev/null 2>&1 && [ -f "${SITE_ROOT}/wp-config.php" ]; then
    INACTIVE=$(${WP} plugin list --status=inactive --field=name 2>/dev/null)
    if [ -n "$INACTIVE" ]; then
        for p in $INACTIVE; do
            c_ylw "  [中] 已停用插件: ${p}"
        done
        say "       风险: 停用的插件代码仍在服务器上，漏洞依然可被利用。"
        addcmd "# 删除已停用的插件"
        for p in $INACTIVE; do
            addcmd "${WP} plugin delete ${p}"
        done
        addcmd ""
    else
        c_grn "  无已停用插件"
    fi
else
    c_ylw "  [跳过] 未检测到 wp-config.php，无法查询插件状态"
    say   "         请在生产服务器上运行本脚本以获得准确结果。"
    say   "         手动查询: ${WP} plugin list"
fi

# ============================================================
sec "四、前端加载的无用资源（影响 Core Web Vitals）"
# ============================================================

# jQuery
# 排除 inc/performance.php：卸载 jQuery 的代码本身含该关键词，会造成误报。
JQ_USE=$(grep -rl "jquery\|jQuery" "${SITE_ROOT}/wp-content/themes/${ACTIVE_THEME}" "${PLUGIN_DIR}" 2>/dev/null \
    | grep -v 'inc/performance.php' | head -3)
if [ -z "$JQ_USE" ]; then
    c_grn "  jQuery: 主题与插件均无依赖 —— 已在 inc/performance.php 中自动卸载"
    say   "          若日后装了依赖 jQuery 的插件，用下面这行关闭该优化："
    say   "          add_filter( 'sa_dequeue_jquery', '__return_false' );"
else
    c_ylw "  jQuery: 检测到依赖，保持加载"
    echo "$JQ_USE" | sed 's/^/          /'
fi

# 区块编辑器样式
BLOCK_USE=$(grep -rl "wp-block\|has_blocks\|register_block" "${SITE_ROOT}/wp-content/themes/${ACTIVE_THEME}" 2>/dev/null \
    | grep -v 'inc/performance.php' | head -3)
if [ -z "$BLOCK_USE" ]; then
    c_grn "  区块样式: 主题未使用 Gutenberg 区块 —— 页面无区块内容时自动卸载（约省 90KB CSS）"
    say   "            若某页确实用了区块编辑器排版，会自动保留，无需手工干预。"
else
    c_ylw "  区块样式: 检测到区块相关代码，已保留"
fi

# emoji / oEmbed / XML-RPC（mu-plugin 已处理）
if [ -f "${SITE_ROOT}/wp-content/mu-plugins/security-hardening.php" ]; then
    for feat in "print_emoji_detection_script:Emoji 脚本" "wp_oembed_add_discovery_links:oEmbed 发现链接" "xmlrpc_enabled:XML-RPC" "feed_links:RSS Feed 链接"; do
        HOOK="${feat%%:*}"; LABEL="${feat##*:}"
        if grep -q "$HOOK" "${SITE_ROOT}/wp-content/mu-plugins/security-hardening.php"; then
            c_grn "  ${LABEL}: 已由 security-hardening.php 停用"
        else
            c_ylw "  ${LABEL}: 未停用，建议关闭"
        fi
    done
fi

# ============================================================
sec "五、大文件与冗余目录"
# ============================================================
say "  站点体积 Top 10（排除 .git）:"
du -sh "${SITE_ROOT}"/* 2>/dev/null | grep -v '\.git$' | sort -rh | head -10 | sed 's/^/    /'

UPLOAD_SIZE=$(du -sh "${SITE_ROOT}/wp-content/uploads" 2>/dev/null | cut -f1)
say ""
say "  uploads 目录: ${UPLOAD_SIZE:-未知}"

# 备份文件
BAKS=$(find "${SITE_ROOT}" -maxdepth 2 -name '*.bak' -o -maxdepth 2 -name '*.sql' -o -maxdepth 2 -name '*.tar.gz' 2>/dev/null | grep -v '/\.git/' | head -10)
if [ -n "$BAKS" ]; then
    c_red "  [高危] 网站目录中发现备份文件，可被公网直接下载："
    echo "$BAKS" | sed 's/^/         /'
    addcmd "# 把备份文件移出网站根目录"
    echo "$BAKS" | while IFS= read -r b; do
        echo "mv ${b} /root/backups/"
    done
    addcmd ""
else
    c_grn "  网站目录中未发现备份文件"
fi

# ============================================================
# 汇总：可执行命令
# ============================================================
if [ "$ONLY_CMD" = "1" ]; then
    printf '%s' "$CMDS"
    exit 0
fi

echo
printf '\033[36m============================================================\033[0m\n'
printf '\033[36m  清理命令集合（复制粘贴执行）\033[0m\n'
printf '\033[36m============================================================\033[0m\n'
echo
if [ -z "$CMDS" ]; then
    c_grn "  没有需要清理的内容。"
else
    c_ylw "  以下命令不会自动执行，请你确认后复制执行："
    echo
    echo "----------------------------- 复制开始 -----------------------------"
    printf '%s' "$CMDS"
    echo "----------------------------- 复制结束 -----------------------------"
    echo
    say "  提示: 执行前建议先备份  →  bash scripts/backup.sh"
    say "  只取命令（便于管道使用）:  bash scripts/cleanup-check.sh --commands"
fi
echo
