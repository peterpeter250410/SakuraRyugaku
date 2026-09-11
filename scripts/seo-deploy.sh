#!/bin/bash
# ============================================================
# SakuraRyugaku — SEO 版本生产发布
# ------------------------------------------------------------
# 在生产服务器上执行，一条命令完成：
#   [1] 拉取最新代码
#   [2] 编译多语言翻译（.po → .mo / .l10n.php）
#   [3] 刷新伪静态规则与各级缓存
#   [4] 校验三语种页面可访问
#   [5] 跑完整 SEO 排查
#
# 用法：
#   bash scripts/seo-deploy.sh                          # 用默认站点 URL
#   bash scripts/seo-deploy.sh https://studyinjp.com
#
# 选项（环境变量）：
#   BRANCH=xxx        指定拉取分支（默认 main）
#   SKIP_PULL=1       跳过 git pull（代码已手工同步时）
#   SKIP_AUDIT=1      跳过发布后排查
#
# 退出码：0 发布成功；1 存在阻断项。
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE_URL="${1:-https://studyinjp.com}"
SITE_URL="${SITE_URL%/}"
BRANCH="${BRANCH:-main}"
SKIP_PULL="${SKIP_PULL:-0}"
SKIP_AUDIT="${SKIP_AUDIT:-0}"
FAILED=0

c_grn() { printf '\033[32m%s\033[0m\n' "$*"; }
c_red() { printf '\033[31m%s\033[0m\n' "$*"; }
c_ylw() { printf '\033[33m%s\033[0m\n' "$*"; }
step()  { echo; printf '\033[36m>>> %s\033[0m\n' "$*"; }

# WP-CLI 包装
if command -v wp >/dev/null 2>&1; then
    WP="wp --path=${SITE_ROOT}"
elif [ -f "${SITE_ROOT}/wp-cli.phar" ]; then
    WP="php ${SITE_ROOT}/wp-cli.phar --path=${SITE_ROOT}"
else
    WP=""
fi
if [ -n "$WP" ] && [ "$(id -u 2>/dev/null || echo 1000)" = "0" ]; then
    WP="${WP} --allow-root"
fi

echo "############################################################"
echo "#  SakuraRyugaku SEO 版本发布"
echo "#  站点根目录 : ${SITE_ROOT}"
echo "#  站点 URL   : ${SITE_URL}"
echo "#  分支       : ${BRANCH}"
echo "#  时间       : $(date '+%Y-%m-%d %H:%M:%S')"
echo "############################################################"

# ------------------------------------------------------------
step "[1/5] 拉取最新代码"
# ------------------------------------------------------------
if [ "$SKIP_PULL" = "1" ]; then
    c_ylw "  已按 SKIP_PULL=1 跳过"
elif [ -d "${SITE_ROOT}/.git" ]; then
    cd "${SITE_ROOT}" || exit 1

    # 面板类主机（宝塔 / cPanel）会批量重设站点文件权限，
    # 若 core.fileMode=true，git 会把成千上万个文件报成「已修改」，
    # 导致 merge 因「本地改动会被覆盖」而中止 —— 但其实一行代码都没变。
    if [ "$(git config core.fileMode 2>/dev/null)" = "true" ]; then
        MODE_CHANGES=$(git diff --summary 2>/dev/null | grep -c 'mode change')
        if [ "${MODE_CHANGES:-0}" -gt 20 ]; then
            c_ylw "  检测到 ${MODE_CHANGES} 个文件仅权限位变更（面板重设权限所致，非代码改动）"
            c_ylw "  正在关闭 core.fileMode，使 git 不再跟踪权限位…"
            git config core.fileMode false
            c_grn "  已设置 core.fileMode=false"
        fi
    fi

    # 语言包产物（.mo / .l10n.php / .po / .pot）每次发布都会由本脚本重新生成，
    # 服务器上的 gettext 与本地生成器在换行与排版上略有差异，于是这些文件
    # 会永远处于「已修改」状态，并在下一次语言文件变更时挡住 --ff-only 合并。
    # 它们是可重新生成的产物，仓库中的版本才是基准，因此发布前直接丢弃本地差异。
    LANG_REL="wp-content/themes/study-abroad-theme/languages"
    if [ -d "${SITE_ROOT}/${LANG_REL}" ]; then
        LANG_DIRTY=$(git status --porcelain -- "${LANG_REL}" 2>/dev/null | grep '^ M' | wc -l | tr -d ' ')
        if [ "${LANG_DIRTY:-0}" -gt 0 ]; then
            git checkout -- "${LANG_REL}" 2>/dev/null \
                && c_grn "  已重置 ${LANG_DIRTY} 个语言包产物文件（稍后会重新编译）" \
                || c_ylw "  语言包产物重置失败，可能影响合并"
        fi
    fi

    # 发布前看看是否还有真实的未提交改动，避免 merge 中止后一头雾水。
    DIRTY=$(git status --porcelain 2>/dev/null | head -10)
    if [ -n "$DIRTY" ]; then
        c_ylw "  检测到未提交的本地改动："
        echo "$DIRTY" | sed 's/^/         /'
        c_ylw "  若其中有需要保留的内容，先备份；确认可丢弃再执行："
        c_ylw "    git stash save \"backup-\$(date +%F-%H%M)\"    # 旧版 git 用 save，不是 push"
    fi

    OLD_REV=$(git rev-parse --short HEAD 2>/dev/null)

    # 网络抖动时重试，指数退避
    PULL_OK=0
    for delay in 0 2 4 8 16; do
        [ "$delay" != "0" ] && { c_ylw "  第 $((delay))s 后重试…"; sleep "$delay"; }
        if git fetch origin "${BRANCH}" 2>&1 | sed 's/^/         /'; then
            PULL_OK=1
            break
        fi
    done

    if [ "$PULL_OK" = "1" ]; then
        git merge --ff-only "origin/${BRANCH}" 2>&1 | sed 's/^/         /' \
            || { c_red "  快进合并失败（本地有分叉提交），请手工处理"; FAILED=1; }
        NEW_REV=$(git rev-parse --short HEAD 2>/dev/null)
        if [ "$OLD_REV" = "$NEW_REV" ]; then
            c_grn "  代码已是最新 (${NEW_REV})"
        else
            c_grn "  已更新 ${OLD_REV} → ${NEW_REV}"
            echo "  本次变更："
            git log --oneline "${OLD_REV}..${NEW_REV}" 2>/dev/null | head -15 | sed 's/^/         /'
        fi
    else
        c_red "  git fetch 多次失败，请检查网络或 git 凭据"
        FAILED=1
    fi
else
    c_ylw "  非 git 部署，跳过代码拉取"
fi

# ------------------------------------------------------------
step "[2/5] 编译多语言翻译"
# ------------------------------------------------------------
# 必须编译：WordPress 读的是 .mo / .l10n.php，不是 .po。
# 漏掉这一步，中文站和英文站会整页回退成日文。
if [ -f "${SITE_ROOT}/scripts/i18n-build.sh" ]; then
    bash "${SITE_ROOT}/scripts/i18n-build.sh" compile 2>&1 | sed 's/^/  /'
else
    c_red "  缺少 scripts/i18n-build.sh"
    FAILED=1
fi

for LC in zh_CN en_US; do
    MO="${SITE_ROOT}/wp-content/themes/study-abroad-theme/languages/sa-theme-${LC}.mo"
    if [ -f "$MO" ] && [ "$(wc -c < "$MO")" -gt 1000 ]; then
        c_grn "  ${LC} 语言包就位"
    else
        c_red "  ${LC} 语言包缺失或异常 —— 该语种会回退显示日文"
        FAILED=1
    fi
done

# ------------------------------------------------------------
step "[3/5] 刷新伪静态与缓存"
# ------------------------------------------------------------
if [ -n "$WP" ]; then
    ${WP} rewrite flush --hard >/dev/null 2>&1 \
        && c_grn "  伪静态规则已刷新" \
        || c_ylw "  伪静态刷新跳过（可能未安装 WP 或权限不足）"

    ${WP} cache flush >/dev/null 2>&1 \
        && c_grn "  对象缓存已清空" \
        || c_ylw "  对象缓存清空跳过"

    # 常见缓存插件（存在才执行）
    for P in "w3-total-cache:w3tc flush all" "wp-super-cache:super-cache flush" "litespeed-cache:litespeed-purge all" "wp-rocket:rocket clean"; do
        PLUG="${P%%:*}"; CMD="${P##*:}"
        if ${WP} plugin is-active "$PLUG" >/dev/null 2>&1; then
            ${WP} $CMD >/dev/null 2>&1 \
                && c_grn "  ${PLUG} 缓存已清空" \
                || c_ylw "  ${PLUG} 缓存清空失败，请到后台手工清理"
        fi
    done

    # OPcache：PHP 7.4 下代码更新后不清会继续跑旧字节码
    ${WP} eval 'if (function_exists("opcache_reset")) { opcache_reset(); echo "ok"; }' 2>/dev/null | grep -q ok \
        && c_grn "  OPcache 已重置" \
        || c_ylw "  OPcache 未重置（若代码改动未生效，请重启 php-fpm）"
else
    c_ylw "  未找到 wp-cli，跳过缓存刷新"
    echo "         手工操作: 后台清缓存 + systemctl reload php-fpm"
fi

# nginx 环境提示：本方案不依赖 rewrite 规则，nginx 无需改配置
echo "  说明: 语种前缀在 PHP 层处理，nginx 保持标准 WordPress 配置即可，无需改 nginx.conf"

# ------------------------------------------------------------
step "[4/5] 校验三语种页面"
# ------------------------------------------------------------
if command -v curl >/dev/null 2>&1; then
    for path in "/" "/zh/" "/en/"; do
        CODE=$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 25 "${SITE_URL}${path}" 2>/dev/null)
        if [ "$CODE" = "200" ]; then
            c_grn "  ${path} → ${CODE}"
        else
            c_red "  ${path} → ${CODE}（应为 200）"
            FAILED=1
        fi
    done

    # 确认翻译真的生效。
    # 探测串必须是「只可能来自语言包」的文案：模板里硬编码的英文装饰性标签
    # （如 <span class="sa-section__tag">FAQ</span>）会让 grep "FAQ" 必然命中，
    # 从而在翻译完全没生效时给出假绿灯。
    TRANS_BAD=0

    ZH_HTML=$(curl -sS -L --max-time 25 "${SITE_URL}/zh/" 2>/dev/null)
    if echo "$ZH_HTML" | grep -q "为什么选择我们\|免费院校匹配\|意向专业"; then
        c_grn "  中文站文案已生效"
    else
        c_red "  中文站未出现中文文案 —— 语言包未加载"
        TRANS_BAD=1
        FAILED=1
    fi

    EN_HTML=$(curl -sS -L --max-time 25 "${SITE_URL}/en/" 2>/dev/null)
    if echo "$EN_HTML" | grep -q "Why students choose us\|Free school matching\|Intended major"; then
        c_grn "  英文站文案已生效"
    else
        c_red "  英文站未出现英文文案 —— 语言包未加载"
        TRANS_BAD=1
        FAILED=1
    fi

    if [ "$TRANS_BAD" = "1" ]; then
        echo
        c_ylw "  语种路由已通（页面可访问、lang 属性正确），但语言包未加载。"
        c_ylw "  运行诊断探针查看 WordPress 侧的真实状态："
        echo "    curl -s '${SITE_URL}/zh/?sa_locale_debug=1' | grep -A24 'SA-LOCALE-DEBUG'"
        echo
    fi
else
    c_ylw "  未找到 curl，跳过在线校验"
fi

# ------------------------------------------------------------
step "[5/5] SEO 全量排查"
# ------------------------------------------------------------
if [ "$SKIP_AUDIT" = "1" ]; then
    c_ylw "  已按 SKIP_AUDIT=1 跳过"
elif [ -f "${SITE_ROOT}/scripts/seo-audit.sh" ]; then
    bash "${SITE_ROOT}/scripts/seo-audit.sh" "${SITE_URL}" || FAILED=1
else
    c_ylw "  缺少 scripts/seo-audit.sh"
fi

# ------------------------------------------------------------
echo
echo "############################################################"
if [ "$FAILED" -eq 0 ]; then
    c_grn "#  发布完成，全部校验通过"
    echo "#"
    echo "#  接下来手工验证（链接清单）："
    echo "#    bash scripts/seo-links.sh ${SITE_URL}"
    echo "#"
    echo "#  然后去 Google Search Console："
    echo "#    1. 重新提交 sitemap: wp-sitemap.xml"
    echo "#    2. 对 / 、/zh/ 、/en/ 分别执行「请求编入索引」"
    echo "#    3. 查看「国际定位」报告，确认 hreflang 无错误"
else
    c_red "#  发布过程中存在问题，请查看上方标红项"
fi
echo "############################################################"
echo

exit "$FAILED"
