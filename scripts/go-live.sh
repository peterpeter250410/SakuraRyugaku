#!/bin/bash
# ============================================================
# SakuraRyugaku — 一键上线编排（Go-Live Orchestrator）
# ------------------------------------------------------------
# 串联既有脚本，按顺序完成上线前的自动化步骤：
#   [0] 代码更新（git pull 或从 CODE_SRC rsync；非 git 且无 CODE_SRC 则跳过）
#   [1] 环境自检（wp-config / WP-CLI / WordPress 安装状态）
#   [2] 内容与站点初始化（wp-cli-setup.sh：主题/插件/页面/菜单）
#   [3] 安全审计（security-check.sh）
#   [4] 上线前 SEO / 页面就绪检查（preflight-check.sh，需传站点 URL）
#
# 注意：
#   - 本脚本不下载 WP 核心、不改数据库连接、不生成密钥。
#     首次部署请先按 docs/study-abroad/GO-LIVE.md 完成：
#       deploy.sh → 配置 wp-config.php → 建库 → 安装向导。
#   - 本脚本是「幂等编排层」，可在每次发布/回归时重复运行。
#
# 用法：
#   bash scripts/go-live.sh https://studyinjp.com
#   不传 URL 时跳过在线 preflight（仅做本地初始化与安全审计）。
#
# 选项（环境变量）：
#   SKIP_UPDATE=1     跳过 [0] 代码更新
#   CODE_SRC=/path    非 git 部署时，从该源目录 rsync 同步代码
#   SKIP_SETUP=1      跳过 wp-cli-setup.sh（仅检查，不改内容）
#   SKIP_SECURITY=1   跳过 security-check.sh
#   SKIP_PREFLIGHT=1  跳过 preflight-check.sh
#
# 退出码：0 全部通过；1 存在阻断项（安全或 preflight 失败）。
# ============================================================

set -u

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCRIPTS="${SITE_ROOT}/scripts"
SITE_URL="${1:-}"
FAILED=0

echo "############################################################"
echo "#  SakuraRyugaku 一键上线编排 (go-live)"
echo "#  Site root : ${SITE_ROOT}"
echo "#  Site URL  : ${SITE_URL:-<未提供，跳过在线检查>}"
echo "#  Date      : $(date)"
echo "############################################################"

# WP-CLI 包装（与 wp-cli-setup.sh 保持一致）
WP="wp --path=${SITE_ROOT}"
if [ "$(id -u 2>/dev/null || echo 1000)" = "0" ]; then
    WP="${WP} --allow-root"
fi

# ------------------------------------------------------------
# [0] 代码更新（自动适配）
#   - git 仓库           → git pull 拉取最新代码
#   - 指定了 CODE_SRC    → 从该目录 rsync 同步代码（非 git 部署用）
#   - 两者都无           → 跳过（假定代码已是最新）
#   开关：SKIP_UPDATE=1 强制跳过本阶段
# ------------------------------------------------------------
echo ""
echo "==== [0] 代码更新 ===="
if [ "${SKIP_UPDATE:-0}" = "1" ]; then
    echo "  [SKIP] SKIP_UPDATE=1，跳过代码更新"
elif git -C "${SITE_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "  [git] 检测到 git 仓库，执行 git pull ..."
    git config --global --add safe.directory "${SITE_ROOT}" 2>/dev/null || true
    if git -C "${SITE_ROOT}" pull --ff-only; then
        echo "  [OK] 代码已更新到最新"
    else
        echo "  [WARN] git pull 未成功（可能有本地改动或需鉴权），沿用现有代码继续"
    fi
elif [ -n "${CODE_SRC:-}" ]; then
    # 从源目录同步代码（排除运行期/敏感文件），非 git 部署使用
    if command -v rsync >/dev/null 2>&1; then
        echo "  [rsync] 从 ${CODE_SRC} 同步代码到 ${SITE_ROOT} ..."
        rsync -a --delete \
            --exclude='.git/' \
            --exclude='wp-config.php' \
            --exclude='.htaccess' \
            --exclude='wp-content/uploads/' \
            --exclude='wp-content/cache/' \
            "${CODE_SRC%/}/" "${SITE_ROOT}/" \
            && echo "  [OK] 代码已同步" \
            || { echo "  [WARN] rsync 同步失败，沿用现有代码继续"; }
    else
        echo "  [WARN] 未安装 rsync，无法从 CODE_SRC 同步，沿用现有代码"
    fi
else
    echo "  [SKIP] 非 git 仓库且未指定 CODE_SRC，跳过代码更新（假定代码已最新）"
    echo "         如需自动拉取：将站点转为 git 部署，或用 CODE_SRC=/path/to/repo 指定源目录"
fi

# ------------------------------------------------------------
# [1] 环境自检
# ------------------------------------------------------------
echo ""
echo "==== [1/4] 环境自检 ===="

if [ ! -f "${SITE_ROOT}/wp-config.php" ]; then
    echo "  [FATAL] 未找到 wp-config.php。"
    echo "          首次部署请先执行：bash scripts/deploy.sh，"
    echo "          再复制并编辑 config/wp-config-sample.php → wp-config.php，"
    echo "          建库并跑安装向导后再运行本脚本。"
    exit 1
fi
echo "  [OK] wp-config.php 存在"

if ! command -v wp >/dev/null 2>&1; then
    echo "  [FATAL] 未找到 WP-CLI（wp）。安装参考：https://wp-cli.org/"
    exit 1
fi
echo "  [OK] WP-CLI 可用"

if ! ${WP} core is-installed >/dev/null 2>&1; then
    echo "  [FATAL] WordPress 尚未完成安装。请先跑安装向导 / wp core install。"
    exit 1
fi
echo "  [OK] WordPress 已安装"

# ------------------------------------------------------------
# [2] 内容与站点初始化
# ------------------------------------------------------------
echo ""
echo "==== [2/4] 站点初始化（主题/插件/页面/菜单） ===="
if [ "${SKIP_SETUP:-0}" = "1" ]; then
    echo "  [SKIP] SKIP_SETUP=1，跳过 wp-cli-setup.sh"
elif [ -f "${SCRIPTS}/wp-cli-setup.sh" ]; then
    if bash "${SCRIPTS}/wp-cli-setup.sh"; then
        echo "  [OK] 站点初始化完成"
    else
        echo "  [FAIL] wp-cli-setup.sh 执行失败"
        FAILED=$((FAILED + 1))
    fi
else
    echo "  [WARN] 未找到 wp-cli-setup.sh，跳过"
fi

# ------------------------------------------------------------
# [3] 安全审计
# ------------------------------------------------------------
echo ""
echo "==== [3/4] 安全审计 ===="
if [ "${SKIP_SECURITY:-0}" = "1" ]; then
    echo "  [SKIP] SKIP_SECURITY=1，跳过 security-check.sh"
elif [ -f "${SCRIPTS}/security-check.sh" ]; then
    if bash "${SCRIPTS}/security-check.sh"; then
        echo "  [OK] 安全审计通过"
    else
        echo "  [FAIL] 安全审计发现阻断项，请修复后重试"
        FAILED=$((FAILED + 1))
    fi
else
    echo "  [WARN] 未找到 security-check.sh，跳过"
fi

# ------------------------------------------------------------
# [4] 上线前 SEO / 页面就绪检查
# ------------------------------------------------------------
echo ""
echo "==== [4/4] 上线前 Preflight 检查 ===="
if [ "${SKIP_PREFLIGHT:-0}" = "1" ]; then
    echo "  [SKIP] SKIP_PREFLIGHT=1，跳过 preflight-check.sh"
elif [ -z "${SITE_URL}" ]; then
    echo "  [SKIP] 未提供站点 URL，跳过在线 preflight。"
    echo "         如需检查：bash scripts/go-live.sh https://你的域名"
elif [ -f "${SCRIPTS}/preflight-check.sh" ]; then
    if bash "${SCRIPTS}/preflight-check.sh" "${SITE_URL}"; then
        echo "  [OK] Preflight 通过"
    else
        echo "  [FAIL] Preflight 存在阻断项，请修复后再上线"
        FAILED=$((FAILED + 1))
    fi
else
    echo "  [WARN] 未找到 preflight-check.sh，跳过"
fi

# ------------------------------------------------------------
# 汇总
# ------------------------------------------------------------
echo ""
echo "############################################################"
if [ "${FAILED}" -gt 0 ]; then
    echo "#  Go-Live 结果：存在 ${FAILED} 个阻断阶段，未通过。"
    echo "#  请根据上方 [FAIL] 提示修复后重跑：bash scripts/go-live.sh ${SITE_URL}"
    echo "############################################################"
    exit 1
else
    echo "#  Go-Live 结果：全部通过 ✅"
    echo "#  下一步（人工）："
    echo "#    1. Search Console 提交 sitemap：${SITE_URL:-<域名>}/wp-sitemap.xml"
    echo "#    2. 确认 GA4 / 埋点数据回流"
    echo "#    3. 参照 docs/study-abroad/GO-LIVE.md 完成收尾清单"
    echo "############################################################"
    exit 0
fi
