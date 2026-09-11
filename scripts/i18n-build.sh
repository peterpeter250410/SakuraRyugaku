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
if [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    WPCLI="php ${SITE_ROOT}/wp-cli.phar"
elif command -v wp >/dev/null 2>&1; then
    WPCLI="wp"
else
    c_red "未找到 wp-cli。请确认仓库根目录存在 wp-cli.phar，或系统已安装 wp 命令。"
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
echo
echo "[3/4] 编译 .po → .mo"
for LC in ${LOCALES}; do
    PO="${LANG_DIR}/${DOMAIN}-${LC}.po"
    MO="${LANG_DIR}/${DOMAIN}-${LC}.mo"
    if [ ! -f "$PO" ]; then
        c_red "  ${LC}: 缺少 ${DOMAIN}-${LC}.po"
        continue
    fi
    if ${WPCLI} i18n make-mo "$PO" "$MO" >/dev/null 2>&1; then
        SIZE=$(wc -c < "$MO" 2>/dev/null || echo 0)
        c_grn "  ${LC}: ${DOMAIN}-${LC}.mo (${SIZE} 字节)"
    else
        # wp-cli 的 make-mo 对单文件参数形式较敏感，回退为目录形式。
        if ${WPCLI} i18n make-mo "${LANG_DIR}" >/dev/null 2>&1 && [ -f "$MO" ]; then
            SIZE=$(wc -c < "$MO")
            c_grn "  ${LC}: ${DOMAIN}-${LC}.mo (${SIZE} 字节)"
        else
            c_red "  ${LC}: .mo 编译失败"
        fi
    fi
done

# ---------- 4. 编译 .l10n.php ----------
echo
echo "[4/4] 编译 .po → .l10n.php（WordPress 6.5+ 高速格式）"
if ${WPCLI} i18n make-php "${LANG_DIR}" 2>&1 | tail -1; then
    c_grn "  完成"
else
    c_ylw "  .l10n.php 生成失败（不影响功能，WordPress 会回退用 .mo）"
fi

# ---------- 未翻译统计 ----------
echo
echo "------------------------------------------------------------"
echo "未翻译条目统计（这些会回退显示日文）"
echo "------------------------------------------------------------"
TOTAL_EMPTY=0
for LC in ${LOCALES}; do
    PO="${LANG_DIR}/${DOMAIN}-${LC}.po"
    [ -f "$PO" ] || continue
    EMPTY=$(grep -c '^msgstr ""$' "$PO" 2>/dev/null || echo 0)
    EMPTY=$((EMPTY - 1))   # 减掉 header 那条
    [ "$EMPTY" -lt 0 ] && EMPTY=0
    TOTAL_EMPTY=$((TOTAL_EMPTY + EMPTY))
    if [ "$EMPTY" -eq 0 ]; then
        c_grn "  ${LC}: 全部已翻译"
    else
        c_ylw "  ${LC}: ${EMPTY} 条待翻译"
        echo "       待翻译内容："
        grep -B1 '^msgstr ""$' "$PO" | grep '^msgid ' | grep -v '^msgid ""$' | head -20 | sed 's/^/         /'
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
