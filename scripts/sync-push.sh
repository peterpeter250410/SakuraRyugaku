#!/bin/bash
# ============================================================
# SakuraRyugaku — 把生产环境独有的文件推送到 GitHub
# ------------------------------------------------------------
# 为什么要有这个脚本：
#
# 生产上会产生仓库里没有的文件（下载的主题图片、站点验证文件等），
# 它们需要回流到 GitHub，否则换台机器克隆就缺文件。但手工做这件事
# 有三个反复踩到的坑：
#
#   1. 占位符。把 https://<TOKEN>@github.com/... 这样的命令直接粘贴，
#      bash 会把 < 当成输入重定向，报 "No such file or directory"。
#   2. 身份未配置。服务器上通常没设 user.name / user.email，
#      git commit 会直接失败。
#   3. Token 进历史。写在命令行里的 token 会留在 ~/.bash_history。
#
# 本脚本用交互式输入规避全部三点：token 不回显、不进历史，
# 身份缺失时当场询问，提交前先列出将要加入的文件让人确认。
#
# 用法：
#   bash scripts/sync-push.sh                 # 自动挑选「应当入库」的未跟踪文件
#   bash scripts/sync-push.sh <路径> [...]    # 只提交指定路径
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BRANCH="${BRANCH:-main}"
REPO_PATH="peterpeter250410/SakuraRyugaku"

cd "$SITE_ROOT" || exit 1

c_grn() { printf '\033[32m%s\033[0m\n' "$*"; }
c_red() { printf '\033[31m%s\033[0m\n' "$*"; }
c_ylw() { printf '\033[33m%s\033[0m\n' "$*"; }
c_cyn() { printf '\033[36m%s\033[0m\n' "$*"; }

echo "============================================================"
echo "  推送生产环境独有的文件到 GitHub"
echo "  目录: ${SITE_ROOT}"
echo "  分支: ${BRANCH}"
echo "============================================================"

# ---------- 1. 确定要提交哪些文件 ----------
echo
c_cyn "--- 1. 待提交的文件 ---"

#
# git 的状态不止「未跟踪」和「已修改」两种，漏掉任何一种都会误判。
# 这里按 porcelain 的实际取值分清楚：
#
#   ??   未跟踪          —— 新文件，符合资源特征就加入
#   A    已暂存的新增    —— 有人先跑过 git add，等着提交，直接用
#   M    已暂存的修改    —— 同上
#    M   已修改未暂存    —— 对已跟踪文件的改动，属于代码变更，要人明确指定
#
# 此前只处理了 ?? 和 " M"，结果把已暂存的资源文件（A）错判成「代码改动」
# 而拒绝提交 —— 那恰恰是要提交的东西。
STAGED=$(git diff --cached --name-only 2>/dev/null)

if [ "$#" -gt 0 ]; then
    FILES="$*"
    echo "  使用命令行指定的路径"
else
    # 自动挑选：只取「缺了站点会出问题」的那类，不碰备份、日志、缓存。
    # 站点验证文件的名字各平台格式不一（Google 是 google+token.html，
    # 百度是 baidu_verify_xxx.html），故按前缀匹配而不是限定字符集 ——
    # 曾经写成 google[0-9a-f]+ 只能匹配十六进制 token，换个站点就漏掉。
    FILES=$(git ls-files --others --exclude-standard 2>/dev/null \
        | grep -Ei '^(google[a-z0-9_-]+\.html|baidu[_-]?verify.*|sogousiteverification.*|_?bytedance.*\.txt|wp-content/themes/[^/]+/assets/)' || true)
    if [ -z "$FILES" ] && [ -z "$STAGED" ]; then
        # 只看「未暂存的」改动 —— 已暂存的由 $STAGED 处理，不该在这里报警。
        MODIFIED=$(git diff --name-only 2>/dev/null)
        OTHER_UNTRACKED=$(git ls-files --others --exclude-standard 2>/dev/null)

        if [ -n "$MODIFIED" ]; then
            c_ylw "  没有未跟踪的资源文件，但有已跟踪文件被就地修改："
            echo "$MODIFIED" | sed 's/^/       /'
            echo
            echo "  这些是代码改动，不自动提交。先看清楚改了什么： git diff"
            echo "  确认要提交： bash scripts/sync-push.sh <具体文件路径>"
            exit 1
        fi

        if [ -n "$OTHER_UNTRACKED" ]; then
            # 有未跟踪文件，只是不属于自动挑选的类别 —— 不能报成「已一致」，
            # 那会让人以为没事，实际仓库里仍然缺东西。
            c_ylw "  没有属于「资源文件」类别的未跟踪文件，但存在其它未跟踪文件："
            echo "$OTHER_UNTRACKED" | head -15 | sed 's/^/       /'
            echo
            echo "  这些需要人工判断该不该入库： bash scripts/sync-check.sh"
            echo "  确认要提交某些： bash scripts/sync-push.sh <路径> [...]"
            exit 1
        fi

        c_grn "  没有需要提交的内容，生产与 GitHub 已一致。"
        exit 0
    fi
fi

if [ -n "$STAGED" ]; then
    echo "  已暂存（之前跑过 git add，等待提交）："
    echo "$STAGED" | sed 's/^/       /'
fi
if [ -n "$FILES" ]; then
    [ -n "$STAGED" ] && echo "  本次新加入："
    echo "$FILES" | tr ' ' '\n' | grep -v '^$' | sed 's/^/       /'
fi

TOTAL=$(printf '%s\n%s\n' "$STAGED" "$FILES" | tr ' ' '\n' | grep -vc '^$')
echo "  共 ${TOTAL} 项"

# ---------- 2. git 身份 ----------
echo
c_cyn "--- 2. 提交身份 ---"
GIT_NAME=$(git config user.name 2>/dev/null || true)
GIT_MAIL=$(git config user.email 2>/dev/null || true)

if [ -z "$GIT_NAME" ] || [ -z "$GIT_MAIL" ]; then
    c_ylw "  本仓库未配置提交身份，git commit 会失败。现在设置："
    [ -z "$GIT_NAME" ] && { printf "    姓名（例 peter）: "; read -r GIT_NAME; }
    [ -z "$GIT_MAIL" ] && { printf "    邮箱: "; read -r GIT_MAIL; }
    if [ -z "$GIT_NAME" ] || [ -z "$GIT_MAIL" ]; then
        c_red "  姓名与邮箱都不能为空。"
        exit 1
    fi
    git config user.name "$GIT_NAME"
    git config user.email "$GIT_MAIL"
    c_grn "  已设置（仅对本仓库生效）"
else
    echo "  ${GIT_NAME} <${GIT_MAIL}>"
fi

# ---------- 3. 推送凭据 ----------
echo
c_cyn "--- 3. 推送凭据 ---"
if GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/true \
    git push --dry-run origin "${BRANCH}" >/dev/null 2>&1; then
    c_grn "  当前凭据可用，无需配置 Token"
    PUSH_URL=""
else
    c_ylw "  当前无法推送，需要 GitHub Personal Access Token。"
    echo
    echo "  获取方式："
    echo "    1. 打开 https://github.com/settings/tokens"
    echo "    2. Generate new token (classic)"
    echo "    3. 勾选 repo，设一个到期时间（建议 90 天）"
    echo "    4. 生成后复制那串以 ghp_ 开头的值"
    echo
    # -s 不回显；在提示符下输入，不会进入 ~/.bash_history。
    printf "  粘贴 Token 后按回车（输入不会显示）: "
    read -rs GH_TOKEN
    echo
    if [ -z "${GH_TOKEN:-}" ]; then
        c_red "  未输入 Token，已终止。"
        exit 1
    fi
    PUSH_URL="https://x-access-token:${GH_TOKEN}@github.com/${REPO_PATH}.git"

    echo "  正在验证 Token…"
    if GIT_TERMINAL_PROMPT=0 git push --dry-run "$PUSH_URL" "${BRANCH}" >/dev/null 2>&1; then
        c_grn "  Token 有效"
    else
        c_red "  Token 无效或权限不足（需要 repo 权限）。"
        exit 1
    fi
fi

# ---------- 4. 确认 ----------
echo
c_cyn "--- 4. 确认 ---"
printf "  将提交上述 %s 项并推送到 origin/%s。继续？(y/N) " "$TOTAL" "$BRANCH"
read -r ANSWER
case "$ANSWER" in
    y|Y|yes|YES) ;;
    *) echo "  已取消，未做任何改动。"; exit 0 ;;
esac

# ---------- 5. 提交并推送 ----------
echo
c_cyn "--- 5. 提交并推送 ---"

# 已暂存的无需再 add；只加入本次新挑选出来的。
if [ -n "$FILES" ]; then
    # shellcheck disable=SC2086
    git add -- $FILES || { c_red "  git add 失败"; exit 1; }
fi

if git diff --cached --quiet; then
    c_ylw "  暂存区为空（这些文件可能已在仓库中），无需提交。"
else
    git commit -q -m "补入生产环境独有的资源文件

这些文件由生产服务器上的脚本生成或下载，此前只存在于生产，
仓库中缺失。换台机器克隆会因此缺图或丢失站点验证。" \
        || { c_red "  git commit 失败"; exit 1; }
    c_grn "  已提交: $(git log --oneline -1)"
fi

PUSHED=0
for delay in 0 2 4 8 16; do
    [ "$delay" != "0" ] && { c_ylw "  ${delay}s 后重试…"; sleep "$delay"; }
    if [ -n "$PUSH_URL" ]; then
        GIT_TERMINAL_PROMPT=0 git push "$PUSH_URL" "${BRANCH}" 2>&1 \
            | sed "s|x-access-token:[^@]*@|***@|g" | sed 's/^/       /'
    else
        git push origin "${BRANCH}" 2>&1 | sed 's/^/       /'
    fi
    # 以「本地与远端是否一致」为准判断成功，而不是看 push 的输出文本。
    git fetch origin "${BRANCH}:refs/remotes/origin/${BRANCH}" >/dev/null 2>&1
    if [ "$(git rev-parse HEAD)" = "$(git rev-parse "refs/remotes/origin/${BRANCH}" 2>/dev/null)" ]; then
        PUSHED=1
        break
    fi
done

echo
if [ "$PUSHED" = "1" ]; then
    c_grn "  推送成功，本地与远端一致：$(git rev-parse --short HEAD)"
else
    c_red "  推送后本地与远端仍不一致，请检查上方输出。"
    exit 1
fi

# ---------- 6. 是否记住凭据 ----------
if [ -n "$PUSH_URL" ]; then
    echo
    c_cyn "--- 6. 是否记住这个 Token ---"
    echo "  记住后以后直接 git push 即可，不必每次粘贴。"
    c_ylw "  代价：Token 会以明文存在 .git/config 里。拿到该文件的人"
    c_ylw "        即可向本仓库推送代码。这台机器有公网 SSH 暴露，请自行权衡。"
    printf "  记住？(y/N) "
    read -r REMEMBER
    case "$REMEMBER" in
        y|Y|yes|YES)
            git remote set-url origin "$PUSH_URL"
            chmod 600 .git/config 2>/dev/null
            c_grn "  已保存到 .git/config（权限已收紧为 600）"
            c_ylw "  Token 到期或泄露时，去 GitHub 撤销并重新运行本脚本。"
            ;;
        *)
            c_grn "  未保存。Token 仅用于本次推送，随进程结束消失。"
            ;;
    esac
fi

echo
echo "------------------------------------------------------------"
echo "验证同步状态： bash scripts/sync-check.sh"
echo "------------------------------------------------------------"
