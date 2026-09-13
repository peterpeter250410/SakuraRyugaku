#!/bin/bash
# ============================================================
# SakuraRyugaku — SEO 版本生产发布
# ------------------------------------------------------------
# 在生产服务器上执行，一条命令完成：
#   [1] 拉取最新代码
#   [2] 编译多语言翻译（.po → .mo / .l10n.php）
#   [3] 同步数据库结构（插件版本号变化时补列 / 建表）
#   [4] 刷新伪静态规则与各级缓存
#   [5] 校验三语种页面可访问
#   [6] 跑完整 SEO 排查
#   [7] 报告生产与 GitHub 的代码同步状态
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
step "[1/7] 拉取最新代码"
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

    # 语言包漂移检查。
    #
    # 此前 .mo 与 .l10n.php 是被跟踪的，而本脚本每次发布都会重新编译它们，
    # 于是它们永远处于「已修改」状态并挡住 --ff-only 合并。当时的办法是
    # 合并前 git checkout 强制覆盖 —— 那是治标：真正的问题是把构建产物
    # 放进了版本控制。现在它们已从仓库移除并加入 .gitignore。
    #
    # 仍然保留这个检查，但语义变了：现在只有 .po / .pot 会出现在这里，
    # 而它们是翻译源文件、编译模式不会改动。一旦真的漂移，说明有人在
    # 生产上直接改了翻译 —— 那是需要人来决定去留的内容，不能默默丢弃。
    LANG_REL="wp-content/themes/study-abroad-theme/languages"
    if [ -d "${SITE_ROOT}/${LANG_REL}" ]; then
        LANG_DIRTY=$(git status --porcelain -- "${LANG_REL}" 2>/dev/null | grep -c '^ M')
        if [ "${LANG_DIRTY:-0}" -gt 0 ]; then
            c_ylw "  翻译源文件（.po/.pot）在生产上被修改了 ${LANG_DIRTY} 处："
            git status --porcelain -- "${LANG_REL}" 2>/dev/null | grep '^ M' | sed 's/^/         /'
            c_ylw "  这些是翻译成果，不会自动丢弃。请先决定："
            c_ylw "    保留并同步： git add -A && git commit -m '更新翻译' && git push origin ${BRANCH}"
            c_ylw "    确认可丢弃： git checkout -- ${LANG_REL}"
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
        # 必须用显式 refspec。
        # `git fetch origin main` 在较旧的 git 上只更新 FETCH_HEAD，
        # 不更新 refs/remotes/origin/main；随后 merge origin/main 合的是陈旧引用，
        # 结果明明有新提交却报 "Already up-to-date"（本项目实际踩过：
        # 服务器停在旧提交，新脚本文件根本没拉下来）。
        if git fetch origin "${BRANCH}:refs/remotes/origin/${BRANCH}" 2>&1 | sed 's/^/         /'; then
            PULL_OK=1
            break
        fi
    done

    if [ "$PULL_OK" = "1" ]; then
        git merge --ff-only "refs/remotes/origin/${BRANCH}" 2>&1 | sed 's/^/         /' \
            || { c_red "  快进合并失败（本地有分叉提交），请手工处理"; FAILED=1; }

        NEW_REV=$(git rev-parse --short HEAD 2>/dev/null)
        REMOTE_REV=$(git rev-parse --short "refs/remotes/origin/${BRANCH}" 2>/dev/null)

        if [ "$OLD_REV" = "$NEW_REV" ]; then
            c_grn "  代码未变动 (${NEW_REV})"
        else
            c_grn "  已更新 ${OLD_REV} → ${NEW_REV}"
            echo "  本次变更："
            git log --oneline "${OLD_REV}..${NEW_REV}" 2>/dev/null | head -15 | sed 's/^/         /'
        fi

        # 关键校验：合并后必须与远端一致。
        # 只看「HEAD 有没有变」是不够的 —— 合并了陈旧的跟踪引用时，
        # HEAD 不变会被误报为「已是最新」，而实际落后好几个提交
        # （本项目真实发生过：新脚本文件没拉下来，却显示发布成功）。
        if [ -n "$REMOTE_REV" ] && [ "$NEW_REV" != "$REMOTE_REV" ]; then
            BEHIND=$(git rev-list --count "HEAD..refs/remotes/origin/${BRANCH}" 2>/dev/null)
            c_red "  本地 (${NEW_REV}) 仍落后远端 (${REMOTE_REV}) ${BEHIND:-?} 个提交"
            echo "         未拉取的提交："
            git log --oneline "HEAD..refs/remotes/origin/${BRANCH}" 2>/dev/null | head -10 | sed 's/^/           /'
            FAILED=1
        elif [ -n "$REMOTE_REV" ]; then
            c_grn "  与远端一致 (${REMOTE_REV})"
        fi
    else
        c_red "  git fetch 多次失败，请检查网络或 git 凭据"
        FAILED=1
    fi
else
    c_ylw "  非 git 部署，跳过代码拉取"
fi

# ------------------------------------------------------------
step "[2/7] 编译多语言翻译"
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
step "[3/7] 同步数据库结构"
# ------------------------------------------------------------
#
# SA_Activator::maybe_upgrade() 挂在 admin_init 上：它比对插件版本号与
# 已存库版本，不一致才执行 dbDelta 与补列。问题是这个钩子只有在有人
# 登录后台打开页面时才会触发 —— 发布完新增了列的代码后如果没人进后台，
# 前台就会去读一个还不存在的列，直接 WPDB 报错。
# 因此发布流程必须主动触发一次，不能依赖「记得去开一下后台」。
#
# 该函数自身幂等：版本号一致时立刻返回，dbDelta 与补列也都带存在性判断，
# 所以每次发布都跑一遍是安全的。
if [ -n "$WP" ]; then
    DB_BEFORE=$(${WP} option get sa_core_db_version 2>/dev/null || echo "?")
    if ${WP} eval 'if ( class_exists( "SA_Activator" ) ) { SA_Activator::maybe_upgrade(); echo "ok"; } else { echo "missing"; }' 2>/dev/null | grep -q ok; then
        DB_AFTER=$(${WP} option get sa_core_db_version 2>/dev/null || echo "?")
        if [ "$DB_BEFORE" = "$DB_AFTER" ]; then
            c_grn "  数据库结构已是最新（版本 ${DB_AFTER}）"
        else
            c_grn "  数据库结构已升级：${DB_BEFORE} → ${DB_AFTER}"
        fi
    else
        c_ylw "  未能调用 SA_Activator::maybe_upgrade（插件未启用？）"
        c_ylw "  请登录 wp-admin 任意页面一次，迁移会在 admin_init 时自动执行"
    fi
else
    c_ylw "  缺少 wp-cli，跳过数据库结构同步"
    c_ylw "  请登录 wp-admin 任意页面一次，迁移会在 admin_init 时自动执行"
fi

# ------------------------------------------------------------
step "[4/7] 刷新伪静态与缓存"
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

    # --- OPcache ---
    # 注意：不能用 wp eval 调 opcache_reset()。
    # CLI PHP 与 php-fpm 是两个独立进程，各有自己的 OPcache 实例，
    # 在 CLI 里 reset 清掉的是 CLI 的缓存，对网站请求毫无作用。
    # 真正要让 fpm 重新读取改动后的 PHP 文件，只能 reload fpm 进程
    # （reload 是优雅重启，不中断正在处理的请求）。
    FPM_RELOADED=0

    # 宝塔面板用自己的 init 脚本管理 PHP，systemd 单元状态常年显示 failed，
    # 因此优先走 init.d，再退回 systemctl。
    for INIT in /etc/init.d/php-fpm-* /etc/init.d/php-fpm; do
        [ -x "$INIT" ] || continue
        if "$INIT" reload >/dev/null 2>&1; then
            c_grn "  已 reload php-fpm（$(basename "$INIT")），OPcache 随之失效"
            FPM_RELOADED=1
            break
        fi
    done

    if [ "$FPM_RELOADED" = "0" ] && command -v systemctl >/dev/null 2>&1; then
        for UNIT in php-fpm-74 php7.4-fpm php-fpm; do
            if systemctl reload "$UNIT" >/dev/null 2>&1; then
                c_grn "  已 reload ${UNIT}，OPcache 随之失效"
                FPM_RELOADED=1
                break
            fi
        done
    fi

    if [ "$FPM_RELOADED" = "0" ]; then
        c_ylw "  未能自动 reload php-fpm"
        echo "         若代码改动未生效，请手动执行其中一条："
        echo "           /etc/init.d/php-fpm-74 reload"
        echo "           宝塔面板 → 软件商店 → PHP 7.4 → 重启"
        echo "         （OPcache 若开启了 validate_timestamps，文件改动通常会自动生效）"
    fi
else
    c_ylw "  未找到 wp-cli，跳过缓存刷新"
    echo "         手工操作: 后台清缓存 + systemctl reload php-fpm"
fi

# nginx 环境提示：本方案不依赖 rewrite 规则，nginx 无需改配置
echo "  说明: 语种前缀在 PHP 层处理，nginx 保持标准 WordPress 配置即可，无需改 nginx.conf"

# ------------------------------------------------------------
step "[5/7] 校验三语种页面"
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
step "[6/7] SEO 全量排查"
# ------------------------------------------------------------
if [ "$SKIP_AUDIT" = "1" ]; then
    c_ylw "  已按 SKIP_AUDIT=1 跳过"
elif [ -f "${SITE_ROOT}/scripts/seo-audit.sh" ]; then
    bash "${SITE_ROOT}/scripts/seo-audit.sh" "${SITE_URL}" || FAILED=1
else
    c_ylw "  缺少 scripts/seo-audit.sh"
fi

# ------------------------------------------------------------
step "[7/7] 代码同步状态"
# ------------------------------------------------------------
# 发布只保证「GitHub → 生产」这一个方向。生产上凭空产生的文件
# （下载的图片、站点验证文件、就地改的代码）永远不会自己回到 GitHub，
# 时间一长就没人说得清两边差了什么。每次发布都报一次，漂移就不会攒着。
if [ -f "${SITE_ROOT}/scripts/sync-check.sh" ]; then
    bash "${SITE_ROOT}/scripts/sync-check.sh" || true   # 漂移不算发布失败，但必须可见
else
    c_ylw "  缺少 scripts/sync-check.sh"
fi

# ------------------------------------------------------------
echo
echo "############################################################"
if [ "$FAILED" -eq 0 ]; then
    c_grn "#  发布完成，全部校验通过"
else
    c_red "#  发布过程中存在问题，请查看上方标红项"
fi
echo "############################################################"

# ------------------------------------------------------------
# 检查链接（直接可点，按优先级排列）
# ------------------------------------------------------------
HOST=$(echo "$SITE_URL" | sed 's#https\?://##; s#/.*##')

# URL 编码，用于把站点地址塞进各工具的查询串
enc() {
    printf '%s' "$1" | sed \
        -e 's|%|%25|g' -e 's|:|%3A|g' -e 's|/|%2F|g' \
        -e 's|?|%3F|g' -e 's|&|%26|g' -e 's|=|%3D|g' -e 's|#|%23|g'
}

GSC_RES=$(enc "${SITE_URL}/")

cat <<LINKS

============================================================
  一、收录情况（最该先看的）
============================================================

【GSC 索引报告】—— 已收录 / 未收录页数与原因
  https://search.google.com/search-console/index?resource_id=$(enc "${SITE_URL}/")

【GSC 站点地图】—— 确认已发现的网址数是否符合预期
  https://search.google.com/search-console/sitemaps?resource_id=${GSC_RES}
  待提交的 sitemap 路径： wp-sitemap.xml
  直接查看： ${SITE_URL}/wp-sitemap.xml

【GSC 效果报告】—— 展现量 / 点击 / 平均排名
  https://search.google.com/search-console/performance/search-analytics?resource_id=${GSC_RES}

【网址检查】—— 逐个确认收录状态，可「请求编入索引」
  没有可直达某个网址的链接：/search-console/inspect?...&id=... 这种深链
  会返回 Google 的 404 页。唯一可靠的入口是控制台顶部的搜索框。

  先打开控制台： https://search.google.com/search-console?resource_id=${GSC_RES}
  再把下面的地址逐个粘进顶部搜索框（每次一个，回车后点「请求编入索引」）：
    ${SITE_URL}/
    ${SITE_URL}/zh/
    ${SITE_URL}/en/
    ${SITE_URL}/faq/
    ${SITE_URL}/schools/

【site: 快查收录量】—— 不用登录，最快的粗略判断
  全站   https://www.google.com/search?q=$(enc "site:${HOST}")
  中文站 https://www.google.com/search?q=$(enc "site:${HOST}/zh/")
  英文站 https://www.google.com/search?q=$(enc "site:${HOST}/en/")
  百度   https://www.baidu.com/s?wd=$(enc "site:${HOST}")

============================================================
  二、性能（Core Web Vitals，直接影响排名）
============================================================

  日文首页 https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/")
  中文首页 https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/zh/")
  英文首页 https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/en/")
  院校列表 https://pagespeed.web.dev/analysis?url=$(enc "${SITE_URL}/schools/")
  移动端目标： LCP < 2.5s ／ CLS < 0.1 ／ INP < 200ms ／ 性能 >= 75

  中国大陆访问速度 https://www.itdog.cn/http/${HOST}

============================================================
  三、结构化数据与多语言
============================================================

  富媒体结果测试 https://search.google.com/test/rich-results?url=$(enc "${SITE_URL}/")
  Schema 校验器  https://validator.schema.org/#url=$(enc "${SITE_URL}/")
  hreflang 校验  https://technicalseo.com/tools/hreflang/?url=$(enc "${SITE_URL}/")
  说明: GSC 的「国际定位」报告已被 Google 下线；hreflang 问题现在改为
        在「编制索引 → 网页」里看是否出现「重复网页，Google 选择的规范网址不同」。

============================================================
  四、其它站长平台
============================================================

  必应站长工具   https://www.bing.com/webmasters/   （可从 GSC 一键导入）
  百度资源平台   https://ziyuan.baidu.com/          （主攻中国市场必做）
  SSL 证书等级   https://www.ssllabs.com/ssltest/analyze.html?d=$(enc "${HOST}")

============================================================
  五、本地命令
============================================================

  完整检查链接清单   bash scripts/seo-links.sh ${SITE_URL}
  仅本地代码排查     bash scripts/seo-audit.sh
  无用组件排查       bash scripts/cleanup-check.sh
  重新生成分享图     php scripts/make-og-image.php

LINKS

exit "$FAILED"
