#!/bin/bash
# ============================================================
# SakuraRyugaku — 首页视觉素材下载脚本
# ------------------------------------------------------------
# 从免费图库（Unsplash）拉取「日本留学 / 校园 / 樱花」主题图片，
# 保存到主题的 assets/images/ 目录，供首页 hero 背景与轮播使用。
#
# 产出文件：
#   assets/images/hero-bg.jpg   —— 首页 hero 背景（style.css .sa-hero 引用）
#   assets/images/slide-1.jpg   —— 轮播图 1（front-page.php 信任背书区）
#   assets/images/slide-2.jpg   —— 轮播图 2
#   assets/images/slide-3.jpg   —— 轮播图 3
#
# 用法：
#   bash scripts/fetch-images.sh
#
# 说明：
#   - Unsplash 图片遵循 Unsplash License（可免费商用，无需署名）。
#   - 如所在网络无法访问 Unsplash，可自行替换下方 URL 为任意直链，
#     或手动放入同名文件即可（脚本可重复运行，已存在会覆盖）。
#   - 需要 curl（多数系统自带）。
# ============================================================
set -euo pipefail

# 解析脚本所在目录 → 项目根 → 主题图片目录（不依赖调用时的工作目录）。
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
IMG_DIR="${PROJECT_ROOT}/wp-content/themes/study-abroad-theme/assets/images"

mkdir -p "${IMG_DIR}"

# 图片来源（Unsplash 定向直链，尺寸经 w/h/fit 参数裁剪）。
# hero 宽幅横图；slide 为 4:3 展示图。
HERO_URL="https://images.unsplash.com/photo-1490806843957-31f4c9a91c65?auto=format&fit=crop&w=1920&h=1080&q=80"
SLIDE1_URL="https://images.unsplash.com/photo-1528360983277-13d401cdc186?auto=format&fit=crop&w=1200&h=900&q=80"
SLIDE2_URL="https://images.unsplash.com/photo-1523050854058-8df90110c9f1?auto=format&fit=crop&w=1200&h=900&q=80"
SLIDE3_URL="https://images.unsplash.com/photo-1542051841857-5f90071e7989?auto=format&fit=crop&w=1200&h=900&q=80"

download() {
	local url="$1"
	local out="$2"
	echo "→ 下载 ${out##*/}"
	if curl -fsSL --retry 3 --retry-delay 2 -o "${out}" "${url}"; then
		echo "  ✓ 已保存：${out}"
	else
		echo "  ✗ 下载失败：${url}"
		echo "    可手动放置同名文件到 ${IMG_DIR}/"
		return 1
	fi
}

echo "目标目录：${IMG_DIR}"
echo "------------------------------------------------------------"

RC=0
download "${HERO_URL}"   "${IMG_DIR}/hero-bg.jpg" || RC=1
download "${SLIDE1_URL}" "${IMG_DIR}/slide-1.jpg" || RC=1
download "${SLIDE2_URL}" "${IMG_DIR}/slide-2.jpg" || RC=1
download "${SLIDE3_URL}" "${IMG_DIR}/slide-3.jpg" || RC=1

echo "------------------------------------------------------------"
if [ "${RC}" -eq 0 ]; then
	echo "全部图片下载完成。刷新首页即可看到 hero 背景与轮播。"
else
	echo "部分图片下载失败，请检查网络或手动放置对应文件。"
fi
exit "${RC}"
