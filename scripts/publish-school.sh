#!/bin/bash
# ============================================================
# SakuraRyugaku — 院校页发布开关
# ------------------------------------------------------------
# 为什么需要这个脚本：
#
# 发布/下线是「每核实一所院校就要做一次」的操作。此前的做法是手工
# 修改 scripts/schools.json 里的 published 再重新导入 —— 那个文件
# 被 git 跟踪，在服务器上改它会让工作区变脏，进而挡住下一次
# `git merge --ff-only`（本项目实际踩过：
#   error: Your local changes to the following files would be
#          overwritten by merge: scripts/schools.json）
#
# 发布状态本质上是数据库里的一个开关，不是代码。所以直接改库，
# 并把「改完必须做的事」一次做完：清缓存、验证三语种 HTTP 状态、
# 给出收录检查链接。
#
# 用法：
#   bash scripts/publish-school.sh                      # 列出所有院校与当前状态
#   bash scripts/publish-school.sh human-academy-japanese-school on    # 发布
#   bash scripts/publish-school.sh human-academy-japanese-school off   # 下线
#
# 用法示例里一律写真实的 slug，不写 <slug> 这类尖括号占位符 ——
# 直接粘贴时 bash 会把 < 当成输入重定向，报 "No such file or directory"。
#
# 发布前请确认该校的名称、所在地、学费均已对照官网核实 ——
# 院校页冠以真实院校名称展示学费，未核实即公开等同于发布未经证实的数据。
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_URL="${SITE_URL:-https://studyinjp.com}"
SITE_URL="${SITE_URL%/}"

c_grn() { printf '\033[32m%s\033[0m\n' "$*"; }
c_red() { printf '\033[31m%s\033[0m\n' "$*"; }
c_ylw() { printf '\033[33m%s\033[0m\n' "$*"; }
c_cyn() { printf '\033[36m%s\033[0m\n' "$*"; }

SLUG="${1:-}"
ACTION="${2:-}"

# 参数校验放在最前面：拼错 on/off 时应当立刻看到这一条，
# 而不是先撞上「未找到 wp-cli」之类与真实错误无关的信息。
if [ -n "$SLUG" ]; then
    case "$ACTION" in
        on)  NEWVAL=1 ;;
        off) NEWVAL=0 ;;
        "")
            c_red "缺少第二个参数。例： bash scripts/publish-school.sh human-academy-japanese-school on"
            exit 1
            ;;
        *)
            c_red "第二个参数必须是 on 或 off（当前: '${ACTION}'）"
            echo "例： bash scripts/publish-school.sh human-academy-japanese-school on"
            exit 1
            ;;
    esac
fi

# ---------- wp-cli ----------
if command -v wp >/dev/null 2>&1; then
    WP="wp --path=${SITE_ROOT}"
elif [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    WP="php ${SITE_ROOT}/wp-cli.phar --path=${SITE_ROOT}"
else
    c_red "未找到 wp-cli"
    exit 1
fi
[ "$(id -u 2>/dev/null || echo 1000)" = "0" ] && WP="${WP} --allow-root"

PREFIX=$(${WP} db prefix 2>/dev/null | tr -d '\r\n')
if [ -z "$PREFIX" ]; then
    c_red "无法读取数据库表前缀，请确认 wp-config.php 与数据库连接正常。"
    exit 1
fi
T_SCHOOLS="${PREFIX}sa_schools"

# ---------- 列出当前状态 ----------
list_schools() {
    echo
    c_cyn "============================================================"
    c_cyn "  院校发布状态"
    c_cyn "============================================================"
    ${WP} db query \
        "SELECT id, slug, published, name FROM ${T_SCHOOLS} ORDER BY sort_order, id" \
        2>/dev/null | sed 's/^/  /'
    echo
    echo "  published: 1 = 对外可索引 ／ 0 = 返回 404，不被收录"
}

if [ -z "$SLUG" ]; then
    list_schools
    echo
    echo "用法： bash scripts/publish-school.sh 上面某一行的 slug on|off"
    echo "例：   bash scripts/publish-school.sh human-academy-japanese-school on"
    exit 0
fi

# ---------- 校验 slug 存在 ----------
# 不校验就会出现「命令成功但什么都没发生」—— 手工执行 UPDATE 时
# slug 拼错正是这样静默失败的。
EXISTS=$(${WP} db query "SELECT COUNT(*) FROM ${T_SCHOOLS} WHERE slug = '${SLUG}'" --skip-column-names 2>/dev/null | tr -d '[:space:]')
if [ "${EXISTS:-0}" = "0" ]; then
    c_red "找不到 slug 为 '${SLUG}' 的院校。"
    list_schools
    exit 1
fi

CUR=$(${WP} db query "SELECT published FROM ${T_SCHOOLS} WHERE slug = '${SLUG}'" --skip-column-names 2>/dev/null | tr -d '[:space:]')
NAME=$(${WP} db query "SELECT name FROM ${T_SCHOOLS} WHERE slug = '${SLUG}'" --skip-column-names 2>/dev/null | tr -d '\r\n')

echo
c_cyn "============================================================"
c_cyn "  ${NAME}"
c_cyn "  slug: ${SLUG}"
c_cyn "============================================================"
echo "  当前 published : ${CUR}"
echo "  目标 published : ${NEWVAL}"

if [ "$CUR" = "$NEWVAL" ]; then
    c_ylw "  状态未变（已经是 ${NEWVAL}），仍将重新校验页面。"
else
    if [ "$NEWVAL" = "1" ]; then
        echo
        c_ylw "  发布前请确认已对照官网核实："
        c_ylw "    · 院校名称、所在地与官方一致"
        c_ylw "    · 各课程学费金额与计价口径（年额 / 课程总额）正确"
        c_ylw "    · 学费包含哪些费用、哪些另计"
        c_ylw "    · 未把「预定开设」的校区写成现有校区"
    fi

    ${WP} db query "UPDATE ${T_SCHOOLS} SET published = ${NEWVAL} WHERE slug = '${SLUG}'" >/dev/null 2>&1

    # 不看 wp-cli 的返回信息，直接回读确认 ——
    # `wp db query` 报告的 "Rows affected" 与实际结果并不总是一致。
    AFTER=$(${WP} db query "SELECT published FROM ${T_SCHOOLS} WHERE slug = '${SLUG}'" --skip-column-names 2>/dev/null | tr -d '[:space:]')
    if [ "$AFTER" = "$NEWVAL" ]; then
        c_grn "  数据库已更新：${CUR} → ${AFTER}"
    else
        c_red "  更新失败：期望 ${NEWVAL}，实际仍为 ${AFTER}"
        exit 1
    fi
fi

# ---------- 清缓存 ----------
echo
echo ">>> 清理缓存"
${WP} cache flush >/dev/null 2>&1 && c_grn "  对象缓存已清空" || c_ylw "  对象缓存清理跳过"

# ---------- 验证三语种 ----------
echo
echo ">>> 校验页面状态"
if [ "$NEWVAL" = "1" ]; then
    WANT=200
    WANT_DESC="可访问"
else
    WANT=404
    WANT_DESC="返回 404（不被收录）"
fi

FAILED=0
for P in "" "zh/" "en/"; do
    URL="${SITE_URL}/${P}schools/${SLUG}/"
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL" 2>/dev/null)
    if [ "$CODE" = "$WANT" ]; then
        c_grn "  ${URL} → ${CODE}"
    else
        c_red "  ${URL} → ${CODE}（期望 ${WANT}）"
        FAILED=1
    fi
done

if [ "$FAILED" = "1" ]; then
    echo
    c_ylw "  部分页面状态不符预期。若刚改过路由，先执行："
    c_ylw "    ${WP} rewrite flush --hard"
fi

# ---------- SEO 检查链接 ----------
ENC_LIST=$(printf '%s' "${SITE_URL}/schools/" | sed 's|:|%3A|g; s|/|%2F|g')
ENC_PAGE=$(printf '%s' "${SITE_URL}/schools/${SLUG}/" | sed 's|:|%3A|g; s|/|%2F|g')
ENC_ROOT=$(printf '%s' "${SITE_URL}/" | sed 's|:|%3A|g; s|/|%2F|g')

echo
echo "------------------------------------------------------------"
echo "SEO 检查链接（院校页 ${WANT_DESC}）"
echo "------------------------------------------------------------"

if [ "$NEWVAL" = "1" ]; then
    echo
    echo "【立刻做：请求编入索引】新页面不会自己被发现"
    echo "  注意：没有可直达某个网址的链接 —— /search-console/inspect?...&id=..."
    echo "        这种深链会返回 Google 的 404 页。唯一入口是控制台顶部的搜索框。"
    echo
    echo "  1) 打开 https://search.google.com/search-console?resource_id=${ENC_ROOT}"
    echo "  2) 把下面的地址逐个粘进页面顶部的搜索框，回车后点「请求编入索引」："
    for P in "" "zh/" "en/"; do
        echo "       ${SITE_URL}/${P}schools/${SLUG}/"
    done
    echo "       ${SITE_URL}/schools/"
    echo
    echo "【结构化数据】重点确认 about.url 指向校方官网而非本站"
    echo "  https://search.google.com/test/rich-results?url=${ENC_PAGE}"
    echo "  https://validator.schema.org/#url=${ENC_PAGE}"
    echo
    echo "【性能】"
    echo "  https://pagespeed.web.dev/analysis?url=${ENC_PAGE}"
fi

echo
echo "【收录情况】"
echo "  site: 院校页  https://www.google.com/search?q=site%3A${SITE_URL#https://}%2Fschools"
echo "  GSC 索引报告  https://search.google.com/search-console/index?resource_id=${ENC_ROOT}"
echo "  GSC 站点地图  https://search.google.com/search-console/sitemaps?resource_id=${ENC_ROOT}"
echo "  站点地图只需提交 wp-sitemap.xml 一个（它是索引文件，指向所有子地图）"
echo
echo "【页面】"
echo "  院校列表      ${SITE_URL}/schools/"
echo "  sitemap       ${SITE_URL}/wp-sitemap.xml"
echo
echo "【本地命令】"
echo "  全量排查      bash scripts/seo-audit.sh ${SITE_URL}"
echo "  完整链接清单  bash scripts/seo-links.sh ${SITE_URL}"
echo

list_schools
