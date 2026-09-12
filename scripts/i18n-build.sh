#!/bin/bash
# ============================================================
# SakuraRyugaku — 多语言翻译编译
# ------------------------------------------------------------
# 流程：
#   1. 从主题源码重新提取字符串 → sa-theme.pot
#   2. 把 .pot 中的新增字符串合并进各语种 .po（保留已有翻译）
#   3. 编译 .po → .mo（WordPress 通用格式）
#   4. 编译 .po → .l10n.php（WordPress 6.5+ 高速格式）
#
# 只要改动了模板里的文案，就跑一次本脚本，然后翻译 .po 里新出现的空条目。
#
# 依赖：仓库自带的 wp-cli.phar（无需在服务器安装 gettext / msgfmt）
#
# 用法：
#   bash scripts/i18n-build.sh          # 全量：提取 + 合并 + 编译
#   bash scripts/i18n-build.sh compile  # 只编译（改完 .po 翻译后用这个）
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="${SITE_ROOT}/wp-content/themes/study-abroad-theme"
LANG_DIR="${THEME}/languages"
DOMAIN="sa-theme"
LOCALES="zh_CN en_US"
MODE="${1:-all}"

c_grn() { printf '\033[32m%s\033[0m\n' "$*"; }
c_red() { printf '\033[31m%s\033[0m\n' "$*"; }
c_ylw() { printf '\033[33m%s\033[0m\n' "$*"; }

echo "============================================================"
echo "  多语言翻译编译"
echo "  主题目录: ${THEME}"
echo "  模式    : ${MODE}"
echo "============================================================"

# ---------- 定位 wp-cli ----------
# 优先用系统安装的 wp 命令。wp-cli.phar 不应留在网站根目录（可被公网下载），
# 建议安装到 /usr/local/bin/wp；此处保留 phar 回退仅为兼容本地开发环境。
if command -v wp >/dev/null 2>&1; then
    WPCLI="wp"
elif [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    WPCLI="php ${SITE_ROOT}/wp-cli.phar"
else
    c_red "未找到 wp-cli。安装方式："
    c_red "  curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
    c_red "  chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
    exit 1
fi
# root 身份需要 --allow-root
if [ "$(id -u 2>/dev/null || echo 1000)" = "0" ]; then
    WPCLI="${WPCLI} --allow-root"
fi

mkdir -p "${LANG_DIR}"

# ---------- 1. 提取 POT ----------
if [ "$MODE" = "all" ]; then
    echo
    echo "[1/4] 从源码提取可翻译字符串 → ${DOMAIN}.pot"
    if ${WPCLI} i18n make-pot "${THEME}" "${LANG_DIR}/${DOMAIN}.pot" --domain="${DOMAIN}" 2>&1 | tail -2; then
        COUNT=$(grep -c '^msgid ' "${LANG_DIR}/${DOMAIN}.pot" 2>/dev/null || echo 0)
        c_grn "  提取完成，共 ${COUNT} 条"
    else
        c_red "  POT 提取失败"
        exit 1
    fi

    # ---------- 2. 合并到各语种 .po ----------
    echo
    echo "[2/4] 把新增字符串合并进各语种 .po（保留已有翻译）"
    for LC in ${LOCALES}; do
        PO="${LANG_DIR}/${DOMAIN}-${LC}.po"
        if [ ! -f "$PO" ]; then
            c_ylw "  ${LC}: .po 不存在，跳过合并（首次请手工创建）"
            continue
        fi
        if command -v msgmerge >/dev/null 2>&1; then
            msgmerge --update --backup=none --no-fuzzy-matching "$PO" "${LANG_DIR}/${DOMAIN}.pot" 2>/dev/null \
                && c_grn "  ${LC}: 已用 msgmerge 合并" \
                || c_ylw "  ${LC}: msgmerge 合并失败"
        else
            # 无 gettext 工具时，用 PHP 做一次「补新增、留旧译」的合并。
            php -d error_reporting=0 "${SITE_ROOT}/scripts/lib/po-merge.php" "$PO" "${LANG_DIR}/${DOMAIN}.pot" \
                && c_grn "  ${LC}: 已合并（PHP 实现）" \
                || c_ylw "  ${LC}: 合并跳过"
        fi
    done
else
    echo
    echo "[跳过提取与合并，仅执行编译]"
fi

# ---------- 3. 编译 .mo ----------
#
# 跳过机制：wp-cli 生成的 .mo/.l10n.php 与仓库中已提交的版本存在字节差异
# （不同实现的编码顺序与元数据不同），因此每次无条件重编译都会让这些
# 已跟踪文件变成「已修改」，进而挡住下一次 git merge --ff-only。
#
# 解决办法：记录上次编译时 .po 的哈希到一个被 gitignore 的 sidecar 文件。
# .po 没变且 .mo 已存在时直接跳过编译，工作区保持干净。
# sidecar 丢失或过期只会导致多编译一次，不会出错。
COMPILED_ANY=0

echo
echo "[3/4] 编译 .po → .mo"
for LC in ${LOCALES}; do
    PO="${LANG_DIR}/${DOMAIN}-${LC}.po"
    MO="${LANG_DIR}/${DOMAIN}-${LC}.mo"
    STAMP="${LANG_DIR}/.${DOMAIN}-${LC}.build"

    if [ ! -f "$PO" ]; then
        c_red "  ${LC}: 缺少 ${DOMAIN}-${LC}.po"
        continue
    fi

    # 计算 .po 当前哈希（sha1sum 缺失时退回 md5sum，都没有则不跳过）
    PO_HASH=""
    if command -v sha1sum >/dev/null 2>&1; then
        PO_HASH=$(sha1sum "$PO" | awk '{print $1}')
    elif command -v md5sum >/dev/null 2>&1; then
        PO_HASH=$(md5sum "$PO" | awk '{print $1}')
    fi

    if [ -n "$PO_HASH" ] && [ -f "$MO" ] && [ -f "$STAMP" ] \
        && [ "$PO_HASH" = "$(cat "$STAMP" 2>/dev/null)" ]; then
        SIZE=$(wc -c < "$MO" 2>/dev/null || echo 0)
        c_grn "  ${LC}: 无变化，跳过编译 (${SIZE} 字节)"
        continue
    fi

    COMPILED_ANY=1

    if ${WPCLI} i18n make-mo "$PO" "$MO" >/dev/null 2>&1; then
        SIZE=$(wc -c < "$MO" 2>/dev/null || echo 0)
        c_grn "  ${LC}: ${DOMAIN}-${LC}.mo (${SIZE} 字节)"
        [ -n "$PO_HASH" ] && printf '%s' "$PO_HASH" > "$STAMP"
    else
        # wp-cli 的 make-mo 对单文件参数形式较敏感，回退为目录形式。
        if ${WPCLI} i18n make-mo "${LANG_DIR}" >/dev/null 2>&1 && [ -f "$MO" ]; then
            SIZE=$(wc -c < "$MO")
            c_grn "  ${LC}: ${DOMAIN}-${LC}.mo (${SIZE} 字节)"
            [ -n "$PO_HASH" ] && printf '%s' "$PO_HASH" > "$STAMP"
        else
            c_red "  ${LC}: .mo 编译失败"
        fi
    fi
done

# ---------- 4. 编译 .l10n.php ----------
echo
echo "[4/4] 编译 .po → .l10n.php（WordPress 6.5+ 高速格式）"
if [ "$COMPILED_ANY" = "0" ]; then
    # .mo 全部跳过说明 .po 未变动，.l10n.php 同样无需重新生成 ——
    # 无条件重跑会再次污染工作区，正是本次要解决的问题。
    c_grn "  无变化，跳过"
elif ${WPCLI} i18n make-php "${LANG_DIR}" 2>&1 | tail -1; then
    c_grn "  完成"
else
    c_ylw "  .l10n.php 生成失败（不影响功能，WordPress 会回退用 .mo）"
fi

# ---------- 未翻译统计 ----------
echo
echo "------------------------------------------------------------"
echo "未翻译条目统计（这些会回退显示日文）"
echo "------------------------------------------------------------"
# 用语法解析而非 grep 统计：msgmerge 会把长字符串折行，
# 折行后译文首行恰好是 `msgstr ""`，grep 会把已翻译条目误判为未翻译。
TOTAL_EMPTY=0
for LC in ${LOCALES}; do
    PO="${LANG_DIR}/${DOMAIN}-${LC}.po"
    [ -f "$PO" ] || continue

    STAT=$(php "${SITE_ROOT}/scripts/lib/po-stat.php" "$PO" --list 2>/dev/null)
    EMPTY=$(echo "$STAT" | head -1 | awk '{print $1}')
    TOTALN=$(echo "$STAT" | head -1 | awk '{print $2}')
    FUZZY=$(echo "$STAT" | head -1 | awk '{print $3}')
    EMPTY=${EMPTY:-0}
    TOTAL_EMPTY=$((TOTAL_EMPTY + EMPTY))

    if [ "$EMPTY" -eq 0 ]; then
        c_grn "  ${LC}: 全部已翻译（共 ${TOTALN} 条）"
    else
        c_ylw "  ${LC}: ${EMPTY}/${TOTALN} 条待翻译"
        echo "       待翻译内容："
        echo "$STAT" | tail -n +2 | head -20 | sed 's/^/         /'
    fi

    if [ "${FUZZY:-0}" -gt 0 ]; then
        c_ylw "  ${LC}: ${FUZZY} 条被标记为 fuzzy（msgmerge 的模糊匹配，建议人工复核）"
    fi
done

echo
if [ "$TOTAL_EMPTY" -eq 0 ]; then
    c_grn "翻译编译完成，无待办。"
else
    c_ylw "翻译编译完成，但有 ${TOTAL_EMPTY} 条待翻译。"
    echo "编辑对应 .po 文件填好 msgstr，然后执行： bash scripts/i18n-build.sh compile"
fi
echo
