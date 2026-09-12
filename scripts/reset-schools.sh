#!/bin/bash
# ============================================================
# SakuraRyugaku — 清空院校与专业数据
# ------------------------------------------------------------
# 只清 sa_schools 与 sa_programs 两张表。
# 匹配历史（sa_match_results）、选校记录（sa_selections）、
# 学生档案与已上传的加密文档一律不动。
#
# 这是不可逆操作，因此：
#   1. 默认只做「预演」：显示将被删除的内容，不实际删除
#   2. 必须显式加 --yes 才真正执行
#   3. 执行前自动导出备份 SQL，可随时还原
#
# 用法：
#   bash scripts/reset-schools.sh            # 预演，查看将删除什么
#   bash scripts/reset-schools.sh --yes      # 确认执行（先自动备份）
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIRM=0
[ "${1:-}" = "--yes" ] && CONFIRM=1

c_red() { printf '\033[31m%s\033[0m\n' "$*"; }
c_grn() { printf '\033[32m%s\033[0m\n' "$*"; }
c_ylw() { printf '\033[33m%s\033[0m\n' "$*"; }
c_cyn() { printf '\033[36m%s\033[0m\n' "$*"; }

# WP-CLI
if command -v wp >/dev/null 2>&1; then
    WP="wp --path=${SITE_ROOT}"
elif [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    WP="php ${SITE_ROOT}/wp-cli.phar --path=${SITE_ROOT}"
else
    c_red "未找到 wp-cli。安装：curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
    exit 1
fi
[ "$(id -u 2>/dev/null || echo 1000)" = "0" ] && WP="${WP} --allow-root"

PREFIX=$(${WP} db prefix 2>/dev/null | tr -d '\r\n')
if [ -z "$PREFIX" ]; then
    c_red "无法读取数据库表前缀，请确认 wp-config.php 与数据库连接正常。"
    exit 1
fi

T_SCHOOLS="${PREFIX}sa_schools"
T_PROGRAMS="${PREFIX}sa_programs"

echo "############################################################"
echo "#  清空院校与专业数据"
echo "#  站点: ${SITE_ROOT}"
echo "#  表  : ${T_SCHOOLS}, ${T_PROGRAMS}"
echo "############################################################"
echo

# ---------- 现状 ----------
N_SCHOOLS=$(${WP} db query "SELECT COUNT(*) FROM ${T_SCHOOLS}" --skip-column-names 2>/dev/null | tr -d '\r\n ')
N_PROGRAMS=$(${WP} db query "SELECT COUNT(*) FROM ${T_PROGRAMS}" --skip-column-names 2>/dev/null | tr -d '\r\n ')
N_SCHOOLS=${N_SCHOOLS:-0}
N_PROGRAMS=${N_PROGRAMS:-0}

c_cyn "=== 当前数据 ==="
echo "  院校: ${N_SCHOOLS} 条"
echo "  专业: ${N_PROGRAMS} 条"
echo

if [ "$N_SCHOOLS" = "0" ] && [ "$N_PROGRAMS" = "0" ]; then
    c_grn "两张表都已是空的，无需清理。"
    exit 0
fi

c_cyn "=== 将被删除的院校 ==="
${WP} db query "SELECT id, name, slug, school_type, status, published FROM ${T_SCHOOLS} ORDER BY id" 2>/dev/null \
    | sed 's/^/  /'
echo

# ---------- 关联数据提示 ----------
# 匹配结果与选校记录会引用 school_id。院校删除后这些记录会指向不存在的院校，
# 属于孤儿数据 —— 不自动删除（可能是真实用户行为），但必须让执行者知道。
N_MATCH=$(${WP} db query "SELECT COUNT(*) FROM ${PREFIX}sa_match_results" --skip-column-names 2>/dev/null | tr -d '\r\n ')
N_SEL=$(${WP} db query "SELECT COUNT(*) FROM ${PREFIX}sa_selections" --skip-column-names 2>/dev/null | tr -d '\r\n ')
N_MATCH=${N_MATCH:-0}
N_SEL=${N_SEL:-0}

if [ "$N_MATCH" != "0" ] || [ "$N_SEL" != "0" ]; then
    c_ylw "=== 注意：存在引用院校的关联数据（本脚本不会删除）==="
    echo "  匹配结果 sa_match_results : ${N_MATCH} 条"
    echo "  选校记录 sa_selections    : ${N_SEL} 条"
    echo "  清空院校后，这些记录会引用到已不存在的院校 ID（孤儿数据）。"
    echo "  它们可能是真实用户行为，因此不自动删除。"
    echo "  确认全是测试数据、想一并清掉的话，执行："
    echo "    ${WP} db query \"TRUNCATE TABLE ${PREFIX}sa_match_results\""
    echo "    ${WP} db query \"TRUNCATE TABLE ${PREFIX}sa_selections\""
    echo
fi

# ---------- 预演模式 ----------
if [ "$CONFIRM" != "1" ]; then
    c_ylw "这是预演，未做任何修改。"
    echo
    echo "确认要删除上述 ${N_SCHOOLS} 所院校与 ${N_PROGRAMS} 条专业，执行："
    echo "  bash scripts/reset-schools.sh --yes"
    echo
    exit 0
fi

# ---------- 备份 ----------
BACKUP_DIR="${SITE_ROOT}/../sakura-backups"
mkdir -p "$BACKUP_DIR" 2>/dev/null || BACKUP_DIR="/tmp"
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/schools-${STAMP}.sql"

echo ">>> 导出备份"
if ${WP} db export "$BACKUP_FILE" --tables="${T_SCHOOLS},${T_PROGRAMS}" >/dev/null 2>&1; then
    c_grn "  已备份: ${BACKUP_FILE} ($(du -h "$BACKUP_FILE" 2>/dev/null | cut -f1))"
else
    c_red "  备份失败，已中止删除。"
    c_red "  请检查 ${BACKUP_DIR} 的写入权限，或手工备份后重试。"
    exit 1
fi
echo

# ---------- 删除 ----------
echo ">>> 清空数据表"
# 先删专业（引用院校），再删院校。
${WP} db query "TRUNCATE TABLE ${T_PROGRAMS}" >/dev/null 2>&1 \
    && c_grn "  ${T_PROGRAMS} 已清空" \
    || c_red "  ${T_PROGRAMS} 清空失败"

${WP} db query "TRUNCATE TABLE ${T_SCHOOLS}" >/dev/null 2>&1 \
    && c_grn "  ${T_SCHOOLS} 已清空" \
    || c_red "  ${T_SCHOOLS} 清空失败"
echo

# ---------- 清缓存并刷新 sitemap ----------
echo ">>> 刷新缓存与固定链接"
${WP} cache flush >/dev/null 2>&1 && c_grn "  对象缓存已清空" || c_ylw "  对象缓存清空跳过"
${WP} rewrite flush --hard >/dev/null 2>&1 && c_grn "  固定链接已刷新" || c_ylw "  固定链接刷新跳过"
echo

# ---------- 结果 ----------
A_SCHOOLS=$(${WP} db query "SELECT COUNT(*) FROM ${T_SCHOOLS}" --skip-column-names 2>/dev/null | tr -d '\r\n ')
A_PROGRAMS=$(${WP} db query "SELECT COUNT(*) FROM ${T_PROGRAMS}" --skip-column-names 2>/dev/null | tr -d '\r\n ')

c_cyn "=== 清理后 ==="
echo "  院校: ${A_SCHOOLS:-?} 条"
echo "  专业: ${A_PROGRAMS:-?} 条"
echo

c_grn "完成。"
echo
echo "还原备份（如需）："
echo "  ${WP} db import ${BACKUP_FILE}"
echo
echo "下一步：采集官网信息 → 整理 → 导入"
echo "  1. php scripts/fetch-school-data.php"
echo "  2. 把 scripts/school-data/SUMMARY.txt 发给我，我整理成导入文件"
echo "  3. php scripts/import-schools.php scripts/schools.json"
echo
echo "------------------------------------------------------------"
echo "SEO 检查链接（清空院校后，这些页面应变为空列表）"
echo "------------------------------------------------------------"
echo "  院校列表   https://studyinjp.com/schools/"
echo "  sitemap    https://studyinjp.com/wp-sitemap.xml"
echo "  GSC 索引   https://search.google.com/search-console/index?resource_id=https%3A%2F%2Fstudyinjp.com%2F"
echo "  完整清单   bash scripts/seo-links.sh https://studyinjp.com"
echo
