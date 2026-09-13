#!/bin/bash
# ============================================================
# SakuraRyugaku — 生产代码与 GitHub 的同步状态检查
# ------------------------------------------------------------
# 解决的问题：
#
# 这个项目有三个地方存着同一份代码 —— 开发环境、GitHub、生产服务器。
# 代码单向流动（开发 → GitHub → 生产），但生产上会凭空产生一些文件：
#   · 用 fetch-images.sh 下载的主题图片
#   · GSC / 百度等平台的站点验证文件
#   · 构建产物、备份、日志
# 这些文件永远不会回到 GitHub。于是出现「生产能跑但仓库缺文件」的状态：
# 换台机器克隆下来就缺图，而且谁也说不清生产上到底比仓库多了什么。
#
# 本脚本把漂移分成四类分别处理，每类都给出可直接执行的命令：
#   1. 落后远端        —— 拉取即可
#   2. 领先远端        —— 生产上有未推送的提交，需要推上去
#   3. 已跟踪文件被改  —— 生产上直接改了代码，必须决定推送还是丢弃
#   4. 未跟踪文件      —— 按路径判断该入库、该忽略、还是需要人工判断
#
# 用法：
#   bash scripts/sync-check.sh          # 检查并报告
#   bash scripts/sync-check.sh --fix    # 额外打印一键处理命令
#
# 退出码：0 完全同步；1 存在需要处理的漂移。
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BRANCH="${BRANCH:-main}"
SHOW_FIX=0
[ "${1:-}" = "--fix" ] && SHOW_FIX=1

cd "$SITE_ROOT" || exit 1

c_grn() { printf '\033[32m%s\033[0m\n' "$*"; }
c_red() { printf '\033[31m%s\033[0m\n' "$*"; }
c_ylw() { printf '\033[33m%s\033[0m\n' "$*"; }
c_cyn() { printf '\033[36m%s\033[0m\n' "$*"; }

DRIFT=0

echo "============================================================"
echo "  代码同步状态检查"
echo "  目录: ${SITE_ROOT}"
echo "  分支: ${BRANCH}"
echo "============================================================"

if [ ! -d .git ]; then
    c_red "这不是一个 git 仓库，无法检查同步状态。"
    exit 1
fi

# ---------- 0. 权限位 ----------
# 面板类主机会批量重设文件权限。core.fileMode=true 时 git 会把成千上万个
# 文件报成「已修改」，淹没真正的漂移。
if [ "$(git config core.fileMode 2>/dev/null)" = "true" ]; then
    MODE_N=$(git diff --summary 2>/dev/null | grep -c 'mode change')
    if [ "${MODE_N:-0}" -gt 20 ]; then
        c_ylw "检测到 ${MODE_N} 个文件仅权限位变更（面板重设权限所致）"
        echo "  修复： git config core.fileMode false"
        echo
    fi
fi

# ---------- 1. 与远端的提交差异 ----------
echo
c_cyn "--- 1. 与远端提交的差异 ---"

# 必须用显式 refspec：旧版 git 的 `git fetch origin main` 只更新 FETCH_HEAD，
# 不更新 refs/remotes/origin/main，据此比较会得到过期结论。
if git fetch origin "${BRANCH}:refs/remotes/origin/${BRANCH}" >/dev/null 2>&1; then
    LOCAL=$(git rev-parse --short HEAD 2>/dev/null)
    REMOTE=$(git rev-parse --short "refs/remotes/origin/${BRANCH}" 2>/dev/null)
    BEHIND=$(git rev-list --count "HEAD..refs/remotes/origin/${BRANCH}" 2>/dev/null || echo 0)
    AHEAD=$(git rev-list --count "refs/remotes/origin/${BRANCH}..HEAD" 2>/dev/null || echo 0)

    echo "  本地 ${LOCAL} ／ 远端 ${REMOTE}"

    if [ "${BEHIND:-0}" = "0" ] && [ "${AHEAD:-0}" = "0" ]; then
        c_grn "  提交完全一致"
    else
        if [ "${BEHIND:-0}" != "0" ]; then
            c_ylw "  落后远端 ${BEHIND} 个提交："
            git log --oneline "HEAD..refs/remotes/origin/${BRANCH}" 2>/dev/null | head -10 | sed 's/^/       /'
            echo "       拉取： git merge --ff-only refs/remotes/origin/${BRANCH}"
            DRIFT=1
        fi
        if [ "${AHEAD:-0}" != "0" ]; then
            c_red "  领先远端 ${AHEAD} 个提交（这些改动只存在于本机，GitHub 上没有）："
            git log --oneline "refs/remotes/origin/${BRANCH}..HEAD" 2>/dev/null | head -10 | sed 's/^/       /'
            echo "       推送： git push origin ${BRANCH}"
            DRIFT=1
        fi
    fi
else
    c_ylw "  无法获取远端信息（网络或凭据问题），跳过提交比较"
fi

# ---------- 2. 已跟踪文件的本地修改 ----------
echo
c_cyn "--- 2. 已跟踪文件是否被就地修改 ---"
MODIFIED=$(git status --porcelain --untracked-files=no 2>/dev/null)
if [ -z "$MODIFIED" ]; then
    c_grn "  无本地修改"
else
    c_red "  以下已跟踪文件在本机被改动，GitHub 上没有这些改动："
    echo "$MODIFIED" | sed 's/^/       /'
    echo
    echo "       查看具体改了什么： git diff"
    echo "       要保留并同步到 GitHub："
    echo "         git add -A && git commit -m '说明改了什么' && git push origin ${BRANCH}"
    echo "       确认是误改、可丢弃："
    echo "         git checkout -- <文件路径>"
    DRIFT=1
fi

# ---------- 3. 未跟踪文件分类 ----------
echo
c_cyn "--- 3. 未跟踪文件（仓库里没有，只存在于本机） ---"
UNTRACKED=$(git ls-files --others --exclude-standard 2>/dev/null)

if [ -z "$UNTRACKED" ]; then
    c_grn "  无未跟踪文件"
else
    # 按路径分类。判断依据是「换台机器克隆后，缺了它站点还能不能正常跑」：
    #   缺了会坏  → 应当入库（主题资源、站点验证文件）
    #   缺了无碍  → 应当忽略（运行期产物）
    SHOULD_TRACK=""
    SHOULD_IGNORE=""
    UNKNOWN=""

    while IFS= read -r f; do
        [ -z "$f" ] && continue
        case "$f" in
            wp-content/themes/*/assets/*|wp-content/plugins/study-abroad-core/*)
                SHOULD_TRACK="${SHOULD_TRACK}${f}"$'\n' ;;
            google*.html|BaiduVerify*|baidu_verify*|*.txt)
                # 各搜索平台的站点验证文件：丢了验证就失效，必须入库
                SHOULD_TRACK="${SHOULD_TRACK}${f}"$'\n' ;;
            *.log|*.sql|*.sql.gz|*.tar.gz|*.zip|*/cache/*|*/backups/*|*/uploads/*)
                SHOULD_IGNORE="${SHOULD_IGNORE}${f}"$'\n' ;;
            *)
                UNKNOWN="${UNKNOWN}${f}"$'\n' ;;
        esac
    done <<< "$UNTRACKED"

    if [ -n "$SHOULD_TRACK" ]; then
        c_red "  [应当入库] 换台机器克隆后缺了这些文件，站点会出问题："
        printf '%s' "$SHOULD_TRACK" | sed 's/^/       /'
        SIZE=$(printf '%s' "$SHOULD_TRACK" | tr '\n' '\0' 2>/dev/null | xargs -0 du -ch 2>/dev/null | tail -1 | awk '{print $1}')
        echo "       合计大小: ${SIZE:-?}"
        DRIFT=1
    fi

    if [ -n "$SHOULD_IGNORE" ]; then
        c_ylw "  [建议忽略] 运行期产物，不该入库："
        printf '%s' "$SHOULD_IGNORE" | head -10 | sed 's/^/       /'
    fi

    if [ -n "$UNKNOWN" ]; then
        c_ylw "  [需人工判断] 无法自动归类："
        printf '%s' "$UNKNOWN" | head -15 | sed 's/^/       /'
        DRIFT=1
    fi
fi

# ---------- 4. 推送权限 ----------
echo
c_cyn "--- 4. 本机能否推送到 GitHub ---"
#
# GIT_TERMINAL_PROMPT=0 是必须的：没有凭据时 git 会交互式地要
# 用户名和密码，把这个「检查」变成一个挂起等待输入的命令。
# 诊断脚本必须立刻失败并说明原因，绝不能阻塞。
if GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/true \
    git push --dry-run origin "${BRANCH}" >/dev/null 2>"${SITE_ROOT}/.sync-push-test" ; then
    c_grn "  可以推送（--dry-run 验证，未实际推送任何内容）"
    PUSH_OK=1
else
    PUSH_OK=0
    c_ylw "  无法推送："
    head -4 "${SITE_ROOT}/.sync-push-test" 2>/dev/null | sed 's/^/       /'
    echo
    echo "       这台机器没有推送权限。两种处理方式："
    echo
    echo "       [A] 配置 Personal Access Token（一次配置，长期有效）"
    echo "           1. 打开 https://github.com/settings/tokens"
    echo "           2. Generate new token (classic) → 勾选 repo → 生成并复制"
    echo "           3. 在本机执行（把 <TOKEN> 换成刚复制的值）："
    echo "              git remote set-url origin https://<TOKEN>@github.com/peterpeter250410/SakuraRyugaku.git"
    echo "           4. 重新运行本脚本确认"
    echo
    echo "       [B] 不配置凭据，把文件交给开发侧代为提交"
    echo "           打包上面「应当入库」的文件："
    echo "              cd ${SITE_ROOT} && tar -czf /tmp/prod-assets.tar.gz \\"
    echo "                \$(git ls-files --others --exclude-standard | grep -E '^(google|wp-content/themes/.*/assets/)')"
    echo "           然后把 /tmp/prod-assets.tar.gz 下载下来发出去。"
fi
rm -f "${SITE_ROOT}/.sync-push-test"

# ---------- 处理建议 ----------
if [ "$SHOW_FIX" = "1" ] && [ "$DRIFT" = "1" ]; then
    echo
    c_cyn "============================================================"
    c_cyn "  一键处理（执行前请先看清上面列出的文件）"
    c_cyn "============================================================"
    echo
    echo "把本机独有的文件与改动全部同步到 GitHub："
    echo
    echo "  cd ${SITE_ROOT} && \\"
    echo "  git add -A && \\"
    echo "  git commit -m '同步生产环境独有的资源文件' && \\"
    echo "  git push origin ${BRANCH}"
    echo
    echo "注意：git add -A 会把上面所有未跟踪文件都加进来，"
    echo "      包括「建议忽略」那一类。若不想要，先把它们写进 .gitignore，"
    echo "      或改用 git add <具体路径> 逐个添加。"
fi

# ---------- 汇总 ----------
echo
echo "============================================================"
if [ "$DRIFT" = "0" ]; then
    c_grn "  生产代码与 GitHub 完全同步。"
    echo "============================================================"
    exit 0
else
    c_ylw "  存在需要处理的漂移，见上方各节。"
    [ "$SHOW_FIX" = "0" ] && echo "  查看处理命令： bash scripts/sync-check.sh --fix"
    echo "============================================================"
    exit 1
fi
