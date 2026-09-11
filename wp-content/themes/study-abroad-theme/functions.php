<?php
/**
 * Study Abroad Theme — 主题入口。
 *
 * 职责：主题支持声明 + 模块装载。
 * 具体实现拆分在 inc/ 下：
 *   inc/i18n.php        多语言：语种注册表、URL 语种路由、locale 切换
 *   inc/seo.php         SEO：meta / canonical / robots / hreflang / OG / 结构化数据
 *   inc/sitemap.php     多语种 sitemap
 *   inc/performance.php 性能：按语种加载字体、异步资源、CWV 优化
 *
 * 业务逻辑在 study-abroad-core 插件中。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SA_THEME_VERSION', '0.2.0' );

/* -------------------------------------------------------------------------
 * 模块装载
 *
 * i18n 必须最先加载：它需要在 WordPress 解析请求之前
 * 从 REQUEST_URI 中剥离语种前缀。
 * ---------------------------------------------------------------------- */

require_once get_template_directory() . '/inc/i18n.php';

// 立即引导语种（早于 WordPress 解析请求）。
sa_bootstrap_locale();

require_once get_template_directory() . '/inc/seo.php';
require_once get_template_directory() . '/inc/sitemap.php';
require_once get_template_directory() . '/inc/performance.php';

/* -------------------------------------------------------------------------
 * 主题支持
 * ---------------------------------------------------------------------- */

add_action(
	'after_setup_theme',
	function () {
		// 语言包的实际加载由 inc/i18n.php 的 sa_load_theme_translations() 负责
		// （显式加载，不依赖「按需加载」机制，原因见该函数上方注释）。
		// 此处仍调用一次，用于向 WordPress 登记主题语言包目录，
		// 使子主题覆盖与其它依赖该注册表的机制正常工作。
		load_theme_textdomain( 'sa-theme', get_template_directory() . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'responsive-embeds' );

		register_nav_menus(
			array(
				'primary' => __( '主导航', 'sa-theme' ),
				'footer'  => __( '页脚导航', 'sa-theme' ),
			)
		);
	}
);

/**
 * 非默认语种下，确保插件等已加载的语言包也切换过去。
 *
 * 插件的 textdomain 在 plugins_loaded 阶段加载，早于主题的语种判定，
 * 此处显式切换，使全站（含插件文案）语种一致。
 */
add_action(
	'after_setup_theme',
	function () {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$wp_locale = sa_locale_field( sa_current_locale(), 'wp_locale', '' );
		if ( $wp_locale && function_exists( 'switch_to_locale' ) && determine_locale() !== $wp_locale ) {
			switch_to_locale( $wp_locale );
		}
	},
	1
);

/* -------------------------------------------------------------------------
 * 首页使用落地页模板
 * ---------------------------------------------------------------------- */

add_filter(
	'template_include',
	function ( $template ) {
		if ( is_front_page() ) {
			$landing = locate_template( 'front-page.php' );
			if ( $landing ) {
				return $landing;
			}
		}
		return $template;
	}
);

/* -------------------------------------------------------------------------
 * 站点标题（供各语种独立优化）
 * ---------------------------------------------------------------------- */

/**
 * 首页 title 使用「品牌 | 卖点」结构，而非仅品牌名。
 *
 * 首页通常是权重最高的页面，title 只放品牌名会浪费核心关键词位置。
 */
add_filter(
	'document_title_parts',
	function ( $parts ) {
		if ( is_front_page() ) {
			$tagline = get_bloginfo( 'description' );
			if ( empty( $parts['tagline'] ) && $tagline ) {
				$parts['tagline'] = $tagline;
			}
		}
		return $parts;
	}
);
