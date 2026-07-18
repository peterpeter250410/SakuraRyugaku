<?php
/**
 * Study Abroad Theme — 主题函数。
 *
 * 职责：主题支持、资源加载、SEO 基础（title/meta/canonical/JSON-LD/sitemap 提示）、
 * 多语言语种注册表底座、落地页转化辅助。业务逻辑在 study-abroad-core 插件中。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SA_THEME_VERSION', '0.1.0' );

/* -------------------------------------------------------------------------
 * 主题支持
 * ---------------------------------------------------------------------- */
add_action( 'after_setup_theme', function () {
	load_theme_textdomain( 'sa-theme', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'automatic-feed-links' );

	register_nav_menus( array(
		'primary' => __( '主导航', 'sa-theme' ),
		'footer'  => __( '页脚导航', 'sa-theme' ),
	) );
} );

/* -------------------------------------------------------------------------
 * 资源加载
 * ---------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'sa-theme', get_stylesheet_uri(), array(), SA_THEME_VERSION );

	// Google Fonts（日文 + 简体中文字形）
	wp_enqueue_style(
		'sa-fonts',
		'https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;800&family=Noto+Sans+SC:wght@400;500;700&display=swap',
		array(),
		null
	);

	wp_enqueue_script( 'sa-theme', get_template_directory_uri() . '/assets/js/main.js', array(), SA_THEME_VERSION, true );

	// 向前端暴露落地页表单端点（若核心插件启用）。
	wp_localize_script( 'sa-theme', 'SA_LP', array(
		'leadEndpoint' => esc_url_raw( rest_url( 'sa/v1/lead' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'i18n'         => array(
			'submitting' => __( '提交中…', 'sa-theme' ),
			'success'    => __( '提交成功！我们会尽快与您联系。', 'sa-theme' ),
			'error'      => __( '提交失败，请稍后重试。', 'sa-theme' ),
			'required'   => __( '请填写姓名与联系方式并同意隐私政策。', 'sa-theme' ),
		),
	) );
}, 20 );

/* -------------------------------------------------------------------------
 * 多语言语种注册表（配置驱动，可扩展远超日/中/英）
 * 详见 docs/study-abroad/I18N-DESIGN.md
 * ---------------------------------------------------------------------- */
function sa_locales() {
	/**
	 * 过滤器 sa_locales：新增语种只需在此追加一行，无需改逻辑。
	 */
	return apply_filters( 'sa_locales', array(
		'ja'    => array( 'label' => '日本語',  'prefix' => '',   'wp_locale' => 'ja',    'dir' => 'ltr', 'default' => true ),
		'zh_CN' => array( 'label' => '中文',     'prefix' => 'zh', 'wp_locale' => 'zh_CN', 'dir' => 'ltr' ),
		'en_US' => array( 'label' => 'English',  'prefix' => 'en', 'wp_locale' => 'en_US', 'dir' => 'ltr' ),
		// 后续补充语种示例（默认注释，需要时启用）：
		// 'ko_KR' => array( 'label' => '한국어',    'prefix' => 'ko', 'wp_locale' => 'ko_KR', 'dir' => 'ltr' ),
		// 'vi'    => array( 'label' => 'Tiếng Việt','prefix' => 'vi', 'wp_locale' => 'vi',    'dir' => 'ltr' ),
	) );
}

/**
 * 当前语种 key（依据 URL 前缀），默认返回默认语种。
 */
function sa_current_locale() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	$path    = trim( wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ), '/' );
	$first   = explode( '/', $path )[0];
	$default = 'ja';

	foreach ( sa_locales() as $key => $loc ) {
		if ( ! empty( $loc['default'] ) ) {
			$default = $key;
		}
		if ( '' !== $loc['prefix'] && $first === $loc['prefix'] ) {
			$cached = $key;
			return $cached;
		}
	}

	$cached = $default;
	return $cached;
}

/* -------------------------------------------------------------------------
 * SEO 基础
 * ---------------------------------------------------------------------- */

/** 输出 lang / dir 属性 */
add_filter( 'language_attributes', function ( $output ) {
	$locales = sa_locales();
	$cur     = sa_current_locale();
	$dir     = isset( $locales[ $cur ]['dir'] ) ? $locales[ $cur ]['dir'] : 'ltr';
	$lang    = str_replace( '_', '-', $cur );
	return 'lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"';
} );

/** meta description + canonical + hreflang + JSON-LD */
add_action( 'wp_head', function () {
	// meta description
	$desc = sa_meta_description();
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}

	// canonical
	$canonical = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
	echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";

	// robots：隐私/同意类页面 noindex（按 slug 约定）
	if ( is_page( array( 'privacy', 'privacy-policy', 'consent' ) ) ) {
		echo '<meta name="robots" content="noindex,follow">' . "\n";
	}

	// hreflang（多语种）
	$home = home_url( '/' );
	foreach ( sa_locales() as $key => $loc ) {
		$url      = '' === $loc['prefix'] ? $home : trailingslashit( $home . $loc['prefix'] );
		$hreflang = str_replace( '_', '-', $key );
		echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( $url ) . '">' . "\n";
	}
	echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $home ) . '">' . "\n";

	// Open Graph 基础
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}

	// JSON-LD Organization
	$org = array(
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'name'     => get_bloginfo( 'name' ),
		'url'      => home_url( '/' ),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $org ) . '</script>' . "\n";
}, 1 );

/** 计算 meta description */
function sa_meta_description() {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_excerpt ) ) {
			return wp_trim_words( wp_strip_all_tags( $post->post_excerpt ), 40 );
		}
		if ( $post && ! empty( $post->post_content ) ) {
			return wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
		}
	}
	$tagline = get_bloginfo( 'description' );
	return $tagline ? $tagline : __( '日本留学のことなら、無料マッチングで最適な学校をご提案。', 'sa-theme' );
}

/* -------------------------------------------------------------------------
 * FAQ 结构化数据（供落地页模板调用）
 * ---------------------------------------------------------------------- */
function sa_output_faq_schema( array $faqs ) {
	if ( empty( $faqs ) ) {
		return;
	}
	$items = array();
	foreach ( $faqs as $faq ) {
		$items[] = array(
			'@type'          => 'Question',
			'name'           => $faq['q'],
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $faq['a'],
			),
		);
	}
	$schema = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => $items,
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
}

/* -------------------------------------------------------------------------
 * robots.txt：放行可索引内容，屏蔽后台与隐私页，声明 sitemap（WP 核心生成 /wp-sitemap.xml）
 * ---------------------------------------------------------------------- */
add_filter( 'robots_txt', function ( $output, $public ) {
	if ( ! $public ) {
		return $output; // 站点未公开时保持默认。
	}
	$lines   = array();
	$lines[] = 'User-agent: *';
	$lines[] = 'Disallow: /wp-admin/';
	$lines[] = 'Allow: /wp-admin/admin-ajax.php';
	$lines[] = 'Disallow: /wp-login.php';
	$lines[] = 'Disallow: /privacy/';
	$lines[] = '';
	$lines[] = 'Sitemap: ' . esc_url_raw( home_url( '/wp-sitemap.xml' ) );
	return implode( "\n", $lines ) . "\n";
}, 10, 2 );

/* -------------------------------------------------------------------------
 * 首页使用落地页模板
 * ---------------------------------------------------------------------- */
add_filter( 'template_include', function ( $template ) {
	if ( is_front_page() ) {
		$landing = locate_template( 'front-page.php' );
		if ( $landing ) {
			return $landing;
		}
	}
	return $template;
} );
