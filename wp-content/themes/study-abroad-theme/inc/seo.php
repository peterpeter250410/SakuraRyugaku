<?php
/**
 * SEO：meta description、canonical、robots、hreflang、Open Graph、结构化数据。
 *
 * 修复要点（相对 v0.1.0）：
 *   - canonical 不再混入 query 参数（此前 utm/gclid 等会生成海量重复 canonical，稀释权重）。
 *   - robots.txt 不再 Disallow 需要 noindex 的页面（Disallow 会让爬虫读不到 noindex，
 *     反而可能以「无摘要」形式被收录）。二者择一：需要 noindex 就必须允许抓取。
 *   - hreflang 逐页输出真实存在的对应语种 URL，而非一律指向各语种首页。
 *   - 补全 og:image / og:url / og:locale / og:locale:alternate / twitter:card。
 *   - 为可索引页面补 max-image-preview:large 等正面 robots 指令。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * lang / dir 属性
 * ---------------------------------------------------------------------- */

add_filter(
	'language_attributes',
	function () {
		$cur  = sa_current_locale();
		$dir  = sa_locale_field( $cur, 'dir', 'ltr' );
		$lang = sa_locale_field( $cur, 'hreflang', str_replace( '_', '-', $cur ) );
		return 'lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"';
	}
);

/* -------------------------------------------------------------------------
 * meta description
 * ---------------------------------------------------------------------- */

/**
 * 允许模板层覆盖 meta description。
 *
 * @param string $desc 描述文案。
 * @return void
 */
function sa_set_meta_description( $desc ) {
	$GLOBALS['sa_meta_desc'] = wp_strip_all_tags( (string) $desc );
}

/**
 * 计算当前页面的 meta description。
 *
 * @return string
 */
function sa_meta_description() {
	if ( ! empty( $GLOBALS['sa_meta_desc'] ) ) {
		return $GLOBALS['sa_meta_desc'];
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && ! empty( $post->post_excerpt ) ) {
			return wp_trim_words( wp_strip_all_tags( $post->post_excerpt ), 40 );
		}
		if ( $post instanceof WP_Post && ! empty( $post->post_content ) ) {
			return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40 );
		}
	}

	$tagline = get_bloginfo( 'description' );
	if ( $tagline ) {
		return $tagline;
	}

	return __( '日本留学のことなら、無料マッチングで最適な学校をご提案。', 'sa-theme' );
}

/* -------------------------------------------------------------------------
 * canonical
 * ---------------------------------------------------------------------- */

/**
 * 当前页面的规范 URL。
 *
 * 关键：绝不携带 query 参数。广告与分享流量带来的 utm_source / gclid / fbclid
 * 若进入 canonical，会让同一页面产生无数「自指」变体，严重稀释权重。
 *
 * @return string 规范 URL；返回空字符串表示本页不应输出 canonical。
 */
function sa_canonical_url() {
	// 搜索结果与 404 不参与索引，也不输出 canonical。
	if ( is_search() || is_404() ) {
		return '';
	}

	$url = '';

	if ( is_front_page() ) {
		$url = home_url( '/' );
	} elseif ( is_home() ) {
		$blog_page = (int) get_option( 'page_for_posts' );
		$url       = $blog_page ? get_permalink( $blog_page ) : home_url( '/' );
	} elseif ( is_singular() ) {
		$url = get_permalink();
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$term_link = get_term_link( $term );
			$url       = is_wp_error( $term_link ) ? '' : $term_link;
		}
	} elseif ( is_post_type_archive() ) {
		$pt_link = get_post_type_archive_link( get_query_var( 'post_type' ) );
		$url     = $pt_link ? $pt_link : '';
	}

	// 兜底：用剥离了语种前缀与 query 的当前路径重建。
	if ( '' === $url ) {
		$base     = sa_home_path();
		$path     = sa_request_path();
		$relative = 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
		$url      = home_url( '/' . ltrim( (string) $relative, '/' ) );
	}

	// 去除任何残留 query。
	$qpos = strpos( $url, '?' );
	if ( false !== $qpos ) {
		$url = substr( $url, 0, $qpos );
	}

	// 分页归位：第 N 页的 canonical 指向自身，而非第一页。
	$paged = (int) get_query_var( 'paged' );
	if ( ! $paged ) {
		$paged = (int) get_query_var( 'page' );
	}
	if ( $paged > 1 && ! is_singular() ) {
		$url = trailingslashit( $url ) . 'page/' . $paged . '/';
	}

	/**
	 * 过滤器 sa_canonical_url：供自定义端点覆盖。
	 *
	 * 院校详情页这类自定义 rewrite 端点走的是 index.php?sa_school=xxx，
	 * WordPress 会把它当作默认查询（is_home），上面的分支无法推导出正确的
	 * 规范 URL，必须由端点自己指定。
	 */
	return apply_filters( 'sa_canonical_url', sa_url( $url ) );
}

/* -------------------------------------------------------------------------
 * robots
 * ---------------------------------------------------------------------- */

/**
 * 需要 noindex 的页面 slug 约定。
 *
 * 这些页面允许抓取（robots.txt 不 Disallow），否则爬虫读不到 noindex。
 *
 * @return string[]
 */
function sa_noindex_slugs() {
	return apply_filters(
		'sa_noindex_slugs',
		array( 'privacy', 'privacy-policy', 'consent', 'thanks', 'thank-you', 'upload' )
	);
}

/**
 * 当前页面是否应 noindex。
 *
 * @return bool
 */
function sa_is_noindex() {
	if ( is_search() || is_404() || is_attachment() ) {
		return true;
	}
	if ( is_page( sa_noindex_slugs() ) ) {
		return true;
	}
	// 带追踪参数的 URL 不应被单独索引（canonical 已指向干净 URL，此处双保险）。
	return (bool) apply_filters( 'sa_is_noindex', false );
}

/**
 * 输出 robots meta。
 *
 * 可索引页面补正面指令：允许大图预览与完整摘要，
 * 显著影响 Google 图片 / Discover 的展示形态。
 */
function sa_robots_meta() {
	if ( sa_is_noindex() ) {
		echo '<meta name="robots" content="noindex,follow">' . "\n";
		return;
	}
	echo '<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">' . "\n";
}

/* -------------------------------------------------------------------------
 * 分享图
 * ---------------------------------------------------------------------- */

/**
 * 当前页面的社交分享图 URL。
 *
 * 优先级：文章特色图 → 自定义 Logo → 站点图标 → 主题内置默认图。
 *
 * @return string
 */
function sa_share_image() {
	if ( is_singular() && has_post_thumbnail() ) {
		$thumb = get_the_post_thumbnail_url( null, 'full' );
		if ( $thumb ) {
			return $thumb;
		}
	}

	$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( $custom_logo_id ) {
		$logo = wp_get_attachment_image_url( $custom_logo_id, 'full' );
		if ( $logo ) {
			return $logo;
		}
	}

	if ( function_exists( 'get_site_icon_url' ) ) {
		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			return $icon;
		}
	}

	// 主题内置默认分享图。
	// 按语种优先：分享图是用户在社交平台看到的第一眼内容，
	// 日文图发给中国学生会明显降低点击意愿，因此各语种用各自的图。
	// 生成方式见 scripts/make-og-image.php。
	// 只返回真实存在的文件，避免输出 404 图链（社交平台会因此不显示缩略图）。
	$candidates = array(
		'/assets/images/og-default-' . sa_current_locale() . '.jpg',
		'/assets/images/og-default.jpg', // 默认语种 / 总兜底
		'/assets/images/slide-1.jpg',
	);

	foreach ( $candidates as $rel ) {
		if ( file_exists( get_template_directory() . $rel ) ) {
			return get_template_directory_uri() . $rel;
		}
	}

	return '';
}

/* -------------------------------------------------------------------------
 * head 输出
 * ---------------------------------------------------------------------- */

add_action(
	'wp_head',
	function () {
		$desc      = sa_meta_description();
		$canonical = sa_canonical_url();
		$cur       = sa_current_locale();
		$title     = wp_get_document_title();

		// --- meta description ---
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}

		// --- canonical ---
		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}

		// --- robots ---
		sa_robots_meta();

		// --- hreflang（逐页对应，不再一律指向各语种首页）---
		// noindex 页面不参与 hreflang 集群。
		if ( ! sa_is_noindex() ) {
			$default_key = sa_default_locale();
			foreach ( sa_locales() as $key => $loc ) {
				$alt_url  = sa_current_url_in( $key );
				$hreflang = sa_locale_field( $key, 'hreflang', str_replace( '_', '-', $key ) );
				echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( $alt_url ) . '">' . "\n";
			}
			// x-default 指向默认语种的对应页面。
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( sa_current_url_in( $default_key ) ) . '">' . "\n";
		}

		// --- Open Graph ---
		$og_type = is_singular() && ! is_front_page() ? 'article' : 'website';
		echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $canonical ) {
			echo '<meta property="og:url" content="' . esc_url( $canonical ) . '">' . "\n";
		}
		echo '<meta property="og:locale" content="' . esc_attr( sa_locale_field( $cur, 'og_locale', 'ja_JP' ) ) . '">' . "\n";
		foreach ( sa_locales() as $key => $loc ) {
			if ( $key === $cur ) {
				continue;
			}
			echo '<meta property="og:locale:alternate" content="' . esc_attr( sa_locale_field( $key, 'og_locale', '' ) ) . '">' . "\n";
		}

		$share_image = sa_share_image();
		if ( $share_image ) {
			echo '<meta property="og:image" content="' . esc_url( $share_image ) . '">' . "\n";
			echo '<meta property="og:image:alt" content="' . esc_attr( $title ) . '">' . "\n";
			// 声明尺寸可让社交平台在未抓取图片前就正确预留位置，
			// 首次分享时更快显示大图卡片而非纯文字。
			// 仅对本主题内置的 1200x630 图声明；文章特色图尺寸不定，不声明。
			if ( false !== strpos( $share_image, '/assets/images/og-default' ) ) {
				echo '<meta property="og:image:width" content="1200">' . "\n";
				echo '<meta property="og:image:height" content="630">' . "\n";
				echo '<meta property="og:image:type" content="image/jpeg">' . "\n";
			}
		}

		// --- Twitter Card ---
		echo '<meta name="twitter:card" content="' . ( $share_image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $share_image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $share_image ) . '">' . "\n";
		}

		// --- JSON-LD: Organization ---
		$org = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);
		if ( $share_image ) {
			$org['logo'] = $share_image;
		}
		$org = apply_filters( 'sa_schema_organization', $org );
		echo '<script type="application/ld+json">' . wp_json_encode( $org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

		// --- JSON-LD: WebSite ---
		$site = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'WebSite',
			'name'       => get_bloginfo( 'name' ),
			'url'        => sa_home_url( '/' ),
			'inLanguage' => sa_locale_field( $cur, 'hreflang', 'ja' ),
		);
		$site = apply_filters( 'sa_schema_website', $site );
		echo '<script type="application/ld+json">' . wp_json_encode( $site, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	},
	1
);

/* -------------------------------------------------------------------------
 * FAQ 结构化数据
 * ---------------------------------------------------------------------- */

/**
 * 输出 FAQPage 结构化数据。
 *
 * @param array<int,array<string,string>> $faqs FAQ 列表，每项含 q / a。
 * @return void
 */
function sa_output_faq_schema( array $faqs ) {
	if ( empty( $faqs ) ) {
		return;
	}
	$items = array();
	foreach ( $faqs as $faq ) {
		if ( empty( $faq['q'] ) || empty( $faq['a'] ) ) {
			continue;
		}
		$items[] = array(
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags( $faq['q'] ),
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => wp_strip_all_tags( $faq['a'] ),
			),
		);
	}
	if ( empty( $items ) ) {
		return;
	}
	$schema = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => $items,
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}

/* -------------------------------------------------------------------------
 * robots.txt
 * ---------------------------------------------------------------------- */

/**
 * robots.txt。
 *
 * 重要修正：不再 Disallow /privacy/ /thanks/ /upload/。
 * 这些页面通过 <meta name="robots" content="noindex"> 排除索引，
 * 而 Disallow 会阻止爬虫抓取页面，导致它根本读不到那条 noindex，
 * 结果反而可能以「无摘要」形式被收录 —— 与预期完全相反。
 */
add_filter(
	'robots_txt',
	function ( $output, $public ) {
		if ( ! $public ) {
			return $output; // 站点设为「不公开」时保持 WordPress 默认。
		}

		$lines = array();

		$lines[] = 'User-agent: *';
		$lines[] = 'Disallow: /wp-admin/';
		$lines[] = 'Allow: /wp-admin/admin-ajax.php';
		$lines[] = 'Disallow: /wp-login.php';
		$lines[] = 'Disallow: /xmlrpc.php';
		// 参数化 URL 不必抓取（canonical 已指向干净 URL）。
		$lines[] = 'Disallow: /*?s=';
		$lines[] = 'Disallow: /*?replytocom=';
		// 静态资源必须放行，否则 Google 无法渲染页面、Core Web Vitals 评分受损。
		$lines[] = 'Allow: /wp-content/uploads/';
		$lines[] = 'Allow: /wp-content/themes/';
		$lines[] = 'Allow: /wp-includes/js/';
		$lines[] = '';
		$lines[] = 'Sitemap: ' . esc_url_raw( home_url( '/wp-sitemap.xml' ) );

		return implode( "\n", $lines ) . "\n";
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * 面包屑（可访问性 + BreadcrumbList 结构化数据）
 * ---------------------------------------------------------------------- */

/**
 * 输出面包屑与对应结构化数据。
 *
 * @param array<int,array{0:string,1?:string}> $items 面包屑项，末项为当前页（url 传空串）。
 * @return void
 */
function sa_breadcrumb( array $items ) {
	if ( empty( $items ) ) {
		return;
	}

	echo '<nav class="sa-breadcrumb" aria-label="' . esc_attr__( 'パンくずリスト', 'sa-theme' ) . '">';
	echo '<ol>';
	$last = count( $items ) - 1;
	foreach ( $items as $i => $item ) {
		$label = $item[0];
		$url   = isset( $item[1] ) ? $item[1] : '';
		echo '<li>';
		if ( $i === $last || '' === $url ) {
			echo '<span aria-current="page">' . esc_html( $label ) . '</span>';
		} else {
			echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</li>';
	}
	echo '</ol>';
	echo '</nav>';

	$list = array();
	foreach ( $items as $i => $item ) {
		$entry = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'name'     => $item[0],
		);
		if ( ! empty( $item[1] ) ) {
			$entry['item'] = $item[1];
		}
		$list[] = $entry;
	}
	$schema = array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $list,
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
}
