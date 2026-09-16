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

/**
 * Organization 结构化数据里的 logo。
 *
 * 为什么不复用 sa_share_image()：
 *
 *   此前 Organization.logo 直接取的是 sa_share_image()，而它的最终兜底是
 *   assets/images/og-default.jpg —— 一张 1200×630 的社交分享大图。
 *   那是营销 banner，不是标识。Google 会把这个值用在知识面板等位置，
 *   等于告诉它「本机构的 logo 就是这张横幅」，是一条错误断言；
 *   而且 og 图按语种有三个版本，同一个机构不可能有三个 logo。
 *
 *   所以这里只认真正的标识资源：站点标识（自定义 Logo）与站点图标。
 *   两者都没有配置时返回空，宁可不输出 logo —— 缺字段只是少一个可选信号，
 *   填错字段是给搜索引擎一个错的事实。
 *
 *   尺寸从附件本身读，不写死：get_site_icon_url( 512 ) 在原图小于 512 时
 *   返回的是原图，此时硬写 512×512 同样是假数据。
 *
 * @return array<string,mixed> ImageObject 结构；无可用标识时为空数组。
 */
function sa_organization_logo() {
	$attachment_id = (int) get_theme_mod( 'custom_logo' );

	if ( $attachment_id <= 0 ) {
		// 站点图标（设置 → 常规 → 站点图标）。它本身就是标识用途的方形图，
		// 满足 Google 对 logo 的最小尺寸要求（112×112）。
		$attachment_id = (int) get_option( 'site_icon' );
	}

	if ( $attachment_id <= 0 ) {
		/*
		 * 两项都没配置时，回落到主题自带的标识图。
		 *
		 * 这与「拿 og 横幅充当 logo」是两回事：这张图就是本站的标识
		 * （樱花形，由 scripts/make-logo.php 生成），只是没有经过媒体库上传。
		 * 有了它，机构标识开箱即用，不必等谁去后台点一遍。
		 *
		 * 注意浏览器标签页的图标是另一回事 —— favicon 只能来自
		 * 「设置→常规→站点图标」，主题文件替代不了，那一步仍需人工上传。
		 */
		$rel  = '/assets/images/site-icon-512.png';
		$path = get_template_directory() . $rel;
		if ( file_exists( $path ) ) {
			$logo = array(
				'@type' => 'ImageObject',
				'url'   => get_template_directory_uri() . $rel,
			);
			// 尺寸从文件读，不照着文件名写死 —— 重新生成时换了尺寸，
			// 文件名未必跟着改，写死就变成假数据。
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 文件损坏时返回 false 即可，不需要报错。
			if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
				$logo['width']  = (int) $size[0];
				$logo['height'] = (int) $size[1];
			}
			return $logo;
		}
		return array();
	}

	$src = wp_get_attachment_image_src( $attachment_id, 'full' );
	if ( ! $src || empty( $src[0] ) ) {
		return array();
	}

	$logo = array(
		'@type' => 'ImageObject',
		'url'   => $src[0],
	);

	// 宽高只在确实读到时才写。SVG 等类型可能取不到尺寸。
	if ( ! empty( $src[1] ) && ! empty( $src[2] ) ) {
		$logo['width']  = (int) $src[1];
		$logo['height'] = (int) $src[2];
	}

	return $logo;
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
		/*
		 * @id 用固定的 home_url( '/#organization' )，不带语种前缀：
		 * 机构只有一个，三个语种页面描述的是同一个实体。带上前缀会让
		 * 搜索引擎把它读成三家不同的机构，反而稀释信号。
		 */
		$org_id = home_url( '/#organization' );

		/*
		 * name 固定取默认语种的站点名，不能用 get_bloginfo( 'name' )。
		 *
		 * blogname 被 option_blogname 过滤器按语种覆盖了（见 inc/i18n.php），
		 * 三个语种下会分别返回「日本留学サポート」「日本留学官网」
		 * 「Study in Japan」。配上同一个 @id，等于声称一个实体同时叫三个
		 * 不同的名字 —— 这是自相矛盾的断言。
		 *
		 * 因此主名取默认语种（日本的机构，日文名为准），其余语种的叫法
		 * 放进 alternateName：机构确实以这些名称对外呈现，这正是该字段的用途。
		 */
		$org_default_locale = sa_default_locale();
		$org_name           = sa_locale_field( $org_default_locale, 'site_name', '' );
		if ( '' === $org_name ) {
			// 该语种未配置 site_name 时回退到数据库原值。
			$org_name = get_bloginfo( 'name' );
		}

		$org_alt = array();
		foreach ( array_keys( sa_locales() ) as $org_lk ) {
			if ( $org_lk === $org_default_locale ) {
				continue;
			}
			$alt = sa_locale_field( $org_lk, 'site_name', '' );
			if ( '' !== $alt && $alt !== $org_name && ! in_array( $alt, $org_alt, true ) ) {
				$org_alt[] = $alt;
			}
		}

		$org = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'@id'      => $org_id,
			'name'     => $org_name,
			'url'      => home_url( '/' ),
		);
		if ( ! empty( $org_alt ) ) {
			$org['alternateName'] = $org_alt;
		}

		$org_logo = sa_organization_logo();
		if ( ! empty( $org_logo ) ) {
			$org['logo'] = $org_logo;
		}

		/*
		 * contactPoint。
		 *
		 * 只写此刻确实成立的三件事：可联系的入口、联系目的、可受理的语言。
		 *
		 * 不写 telephone 与 email —— 站内目前没有任何真实的电话或邮箱
		 * （见 footer.php 里的说明，原先那个 info@example.com 是 RFC 2606
		 * 的文档保留域名，永远收不到信）。留学咨询属于 YMYL 领域，
		 * 在结构化数据里挂一个打不通的号码，比不挂更伤信任。
		 *
		 * 副作用要说清楚：Google 的「企业联系信息」富媒体结果要求 telephone，
		 * 少了这个字段就不会触发那一项。这是有意的取舍 —— 拿到真实号码后
		 * 在这里补 'telephone' => '+81-...' 即可，其余不必改动。
		 */
		$org['contactPoint'] = array(
			'@type'             => 'ContactPoint',
			'contactType'       => 'customer support',
			'url'               => sa_home_url( '/contact/' ),
			// 三个语种的页面与咨询表单均已上线，这一项可由站点本身验证。
			'availableLanguage' => array_values(
				array_map(
					function ( $key ) {
						return sa_locale_field( $key, 'hreflang', $key );
					},
					array_keys( sa_locales() )
				)
			),
		);

		$org = apply_filters( 'sa_schema_organization', $org );
		echo '<script type="application/ld+json">' . wp_json_encode( $org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

		// --- JSON-LD: WebSite ---
		// publisher 指回上面的 Organization @id，把两个节点连成一张图；
		// 否则它们是两条互不相干的声明，搜索引擎得自己猜是不是同一家。
		$site = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'WebSite',
			'@id'        => sa_home_url( '/#website' ),
			'name'       => get_bloginfo( 'name' ),
			'url'        => sa_home_url( '/' ),
			'inLanguage' => sa_locale_field( $cur, 'hreflang', 'ja' ),
			'publisher'  => array( '@id' => $org_id ),
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
