<?php
/**
 * 品牌标识。
 *
 * 页眉与页脚原本用一个「●」字符当标识占位符。那既不是标识，
 * 渲染结果还随字体变化 —— 不同系统下的圆点大小、基线位置都不一样。
 *
 * 现在改为内联 assets/images/logo-mark.svg（樱花形，由 scripts/make-logo.php
 * 生成）。内联而不是 <img>：省一次请求，并且能用 currentColor 让 CSS 控制颜色，
 * 页眉朱红、深色页脚白色，共用同一个文件。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 标识图形的 SVG 源码。
 *
 * 文件内容在单次请求内缓存。SVG 由构建脚本生成、随主题一起发布，
 * 不接受用户输入，因此直接原样输出；但仍做存在性检查 ——
 * 万一文件缺失（部署漏传、被安全插件清理），要退回占位符而不是输出空白。
 *
 * @return string SVG 源码；不可用时为空字符串。
 */
function sa_logo_mark_svg() {
	static $svg = null;

	if ( null !== $svg ) {
		return $svg;
	}

	$path = get_template_directory() . '/assets/images/logo-mark.svg';
	$svg  = '';

	if ( is_readable( $path ) ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 本地主题资源，非远程请求。
		if ( is_string( $raw ) && 0 === strpos( ltrim( $raw ), '<svg' ) ) {
			$svg = trim( $raw );
		}
	}

	return $svg;
}

/**
 * 输出标识图形。
 *
 * @return void
 */
function sa_logo_mark() {
	$svg = sa_logo_mark_svg();

	// 花形是纯装饰：相邻的 <span> 已经有站点名，读屏软件再念一遍标识没有意义。
	// SVG 内部已带 aria-hidden，外层再包一层是为了文件缺失时占位符也一并隐藏。
	echo '<span class="sa-logo__mark" aria-hidden="true">';
	if ( '' !== $svg ) {
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 构建脚本生成的本地资源。
	} else {
		echo '&#9679;'; // 兜底：原来的占位符。
	}
	echo '</span>';
}
