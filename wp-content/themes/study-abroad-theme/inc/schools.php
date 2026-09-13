<?php
/**
 * 院校公开页：路由、模板加载、SEO。
 *
 * URL 结构：
 *   /schools/                 院校列表
 *   /schools/{slug}/          院校详情
 *   /zh/schools/{slug}/       中文版（语种前缀由 inc/i18n.php 在解析前剥离，
 *                             因此同一套 rewrite 规则天然支持三语，无需重复注册）
 *
 * 发布策略（重要）：
 *   只有 published = 1 且有 slug 的院校才生成公开页，其余一律 404。
 *   院校页会展示学费、语言要求等事实信息，并冠以真实院校名称 ——
 *   未经业务方核实就公开，等同于以真实院校名义发布未核实数据。
 *   published 默认为 0，必须逐校显式开启。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * URL
 * ---------------------------------------------------------------------- */

/**
 * 院校页 URL 基础段。
 *
 * 三语共用同一基础段，由语种前缀区分（/schools/ 与 /zh/schools/）。
 * 改动此值后必须刷新固定链接：wp rewrite flush --hard
 *
 * @return string
 */
function sa_school_base() {
	return (string) apply_filters( 'sa_school_base', 'schools' );
}

/**
 * 院校列表页 URL。
 *
 * @param string|null $locale 目标语种，默认当前语种。
 * @return string
 */
function sa_schools_url( $locale = null ) {
	return sa_url( home_url( '/' . sa_school_base() . '/' ), $locale );
}

/**
 * 院校详情页 URL。
 *
 * @param string      $slug   院校 slug。
 * @param string|null $locale 目标语种，默认当前语种。
 * @return string
 */
function sa_school_url( $slug, $locale = null ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return sa_schools_url( $locale );
	}
	return sa_url( home_url( '/' . sa_school_base() . '/' . $slug . '/' ), $locale );
}

/* -------------------------------------------------------------------------
 * 路由
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	function () {
		$base = sa_school_base();

		add_rewrite_rule( '^' . $base . '/?$', 'index.php?sa_schools=1', 'top' );
		add_rewrite_rule( '^' . $base . '/page/([0-9]+)/?$', 'index.php?sa_schools=1&sa_paged=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)/?$', 'index.php?sa_school=$matches[1]', 'top' );
	},
	5
);

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'sa_schools';
		$vars[] = 'sa_school';
		$vars[] = 'sa_paged';
		return $vars;
	}
);

/**
 * 当前请求是否为院校列表页。
 *
 * @return bool
 */
function sa_is_schools_archive() {
	return (bool) get_query_var( 'sa_schools' );
}

/**
 * 当前请求是否为院校详情页。
 *
 * @return bool
 */
function sa_is_school_page() {
	return '' !== (string) get_query_var( 'sa_school' );
}

/**
 * 取当前院校详情页对应的数据行（查询一次后缓存在静态变量）。
 *
 * @return array|null
 */
function sa_current_school() {
	static $cached = false;

	if ( false !== $cached ) {
		return $cached;
	}

	$cached = null;

	$slug = (string) get_query_var( 'sa_school' );
	if ( '' === $slug || ! class_exists( 'SA_School_Repo' ) ) {
		return $cached;
	}

	// 已登录且有院校管理权限时允许预览未发布院校，便于核实后再发布。
	$preview = is_user_logged_in() && current_user_can( 'sa_manage_schools' );

	$cached = SA_School_Repo::get_school_by_slug( $slug, ! $preview );

	return $cached;
}

/**
 * 修正自定义端点的查询状态。
 *
 * index.php?sa_school=xxx 会被 WordPress 当作默认查询（is_home 为真），
 * 导致 body_class、标题推导、canonical 兜底逻辑全部按「博客首页」处理。
 * 这里显式纠正，并对找不到的院校返回 404。
 */
add_action(
	'parse_query',
	function ( $query ) {
		if ( ! $query->is_main_query() || is_admin() ) {
			return;
		}

		$is_archive = ! empty( $query->query_vars['sa_schools'] );
		$is_single  = ! empty( $query->query_vars['sa_school'] );

		if ( ! $is_archive && ! $is_single ) {
			return;
		}

		// 不是博客首页，也不是首页。
		$query->is_home     = false;
		$query->is_singular = false;
		$query->is_page     = false;
		$query->is_archive  = false;
		$query->is_404      = false;
	}
);

/**
 * 找不到（或未发布）的院校返回 404，而不是渲染空页面。
 *
 * 返回软 404（200 + 空内容）会让搜索引擎收录无意义页面，
 * 因此必须发出真实的 404 状态码。
 */
add_action(
	'template_redirect',
	function () {
		if ( ! sa_is_school_page() ) {
			return;
		}

		if ( null === sa_current_school() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	1
);

/**
 * 加载院校页模板。
 */
add_filter(
	'template_include',
	function ( $template ) {
		if ( is_404() ) {
			return $template;
		}

		if ( sa_is_school_page() ) {
			$found = locate_template( 'template-school-single.php' );
			if ( $found ) {
				return $found;
			}
		}

		if ( sa_is_schools_archive() ) {
			$found = locate_template( 'template-schools.php' );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	},
	20 // 晚于 functions.php 中首页模板的 template_include（默认 10）
);

/* -------------------------------------------------------------------------
 * 展示辅助
 * ---------------------------------------------------------------------- */

/**
 * 院校类型的可读标签。
 *
 * 数据库存的是英文键（university / vocational / language 等），
 * 直接显示对用户无意义，需按语种映射为可读文案。
 *
 * @param string $type 类型键。
 * @return string
 */
function sa_school_type_label( $type ) {
	$map = array(
		'language'   => __( '語学学校', 'sa-theme' ),
		'vocational' => __( '専門学校', 'sa-theme' ),
		'university' => __( '大学（学部）', 'sa-theme' ),
		'graduate'   => __( '大学院', 'sa-theme' ),
		'junior'     => __( '短期大学', 'sa-theme' ),
	);

	$type = sanitize_key( $type );

	return isset( $map[ $type ] ) ? $map[ $type ] : $type;
}

/**
 * 最低学历要求的可读标签。
 *
 * @param string $key 学历键。
 * @return string
 */
function sa_min_education_label( $key ) {
	$map = array(
		'junior_high' => __( '中学卒業', 'sa-theme' ),
		'high_school' => __( '高校卒業', 'sa-theme' ),
		'bachelor'    => __( '大学卒業', 'sa-theme' ),
	);

	$key = sanitize_key( $key );

	return isset( $map[ $key ] ) ? $map[ $key ] : $key;
}

/**
 * 院校名称（当前语种）。
 *
 * @param array $school 院校行。
 * @return string
 */
function sa_school_name( array $school ) {
	if ( ! class_exists( 'SA_School_Repo' ) ) {
		return isset( $school['name'] ) ? (string) $school['name'] : '';
	}
	return SA_School_Repo::localized_field( $school, 'name_i18n', 'name' );
}

/**
 * 院校简介（当前语种）。
 *
 * @param array $school 院校行。
 * @return string
 */
function sa_school_description( array $school ) {
	if ( ! class_exists( 'SA_School_Repo' ) ) {
		return '';
	}
	return SA_School_Repo::localized_field( $school, 'description_i18n', '' );
}

/**
 * 专业名称（当前语种）。
 *
 * @param array $program 专业行。
 * @return string
 */
function sa_program_name( array $program ) {
	if ( ! class_exists( 'SA_School_Repo' ) ) {
		return isset( $program['name'] ) ? (string) $program['name'] : '';
	}
	return SA_School_Repo::localized_field( $program, 'name_i18n', 'name' );
}

/**
 * 学费区间的可读表述。
 *
 * 数据库存的是日元整数。此处按语种格式化，并明确标注为「目安」——
 * 实际金额随年度与课程变动，不加限定词等于给出承诺。
 *
 * @param int $min 下限（日元/年）。
 * @param int $max 上限（日元/年）。
 * @return string 无有效数据时返回空字符串。
 */
function sa_tuition_range( $min, $max ) {
	$min = (int) $min;
	$max = (int) $max;

	if ( $min <= 0 && $max <= 0 ) {
		return '';
	}

	$fmt = function ( $yen ) {
		// 以「万日元」为单位更符合中日两地的表达习惯。
		$man = $yen / 10000;
		return ( $man === floor( $man ) )
			? number_format( $man )
			: number_format( $man, 1 );
	};

	if ( $min > 0 && $max > 0 && $min !== $max ) {
		/* translators: 1: 下限, 2: 上限（单位：万日元/年） */
		return sprintf( __( '年間 %1$s〜%2$s 万円（目安）', 'sa-theme' ), $fmt( $min ), $fmt( $max ) );
	}

	$one = $min > 0 ? $min : $max;
	/* translators: %s: 金额（单位：万日元/年） */
	return sprintf( __( '年間 約%s 万円（目安）', 'sa-theme' ), $fmt( $one ) );
}

/* -------------------------------------------------------------------------
 * SEO
 * ---------------------------------------------------------------------- */

/**
 * 院校页的 canonical。
 *
 * 自定义端点无法由通用逻辑推导，必须显式指定。
 */
add_filter(
	'sa_canonical_url',
	function ( $url ) {
		if ( sa_is_school_page() ) {
			$school = sa_current_school();
			if ( $school && ! empty( $school['slug'] ) ) {
				return sa_school_url( $school['slug'] );
			}
			return $url;
		}

		if ( sa_is_schools_archive() ) {
			$base  = sa_schools_url();
			$paged = (int) get_query_var( 'sa_paged' );
			if ( $paged > 1 ) {
				$base = trailingslashit( $base ) . 'page/' . $paged . '/';
			}
			return $base;
		}

		return $url;
	}
);

/**
 * 院校页标题。
 *
 * 标题里带上院校类型与所在地：用户搜索时常带地域词（「東京 語学学校」
 * 「东京 语言学校」），把这些信息放进 title 能直接提升相关性与点击率。
 */
add_filter(
	'document_title_parts',
	function ( $parts ) {
		if ( sa_is_school_page() ) {
			$school = sa_current_school();
			if ( $school ) {
				$name = sa_school_name( $school );
				$bits = array_filter(
					array(
						sa_school_type_label( isset( $school['school_type'] ) ? $school['school_type'] : '' ),
						isset( $school['city'] ) ? $school['city'] : '',
					)
				);

				$parts['title'] = $name;
				if ( ! empty( $bits ) ) {
					/* translators: 1: 院校名, 2: 类型・所在地 */
					$parts['title'] = sprintf(
						__( '%1$s（%2$s）', 'sa-theme' ),
						$name,
						implode( ' · ', $bits )
					);
				}
				unset( $parts['tagline'] );
			}
		} elseif ( sa_is_schools_archive() ) {
			$parts['title'] = __( '日本の学校情報一覧', 'sa-theme' );
			unset( $parts['tagline'] );
		}

		return $parts;
	},
	20
);

/**
 * 院校页 meta description。
 *
 * 优先用院校自己的简介；没有简介时，用结构化字段拼一句可读的摘要，
 * 避免所有院校页共用站点默认描述（重复 description 会被搜索引擎忽略）。
 */
add_action(
	'wp',
	function () {
		if ( sa_is_schools_archive() ) {
			sa_set_meta_description(
				__( '語学学校から大学院まで、日本の学校情報を一覧でまとめています。学費・語学要件・所在地から比較でき、気になる学校は無料でマッチング診断を受けられます。掲載内容は各校の公表資料に基づく参考情報です。', 'sa-theme' )
			);
			return;
		}

		if ( ! sa_is_school_page() ) {
			return;
		}

		$school = sa_current_school();
		if ( ! $school ) {
			return;
		}

		$desc = trim( wp_strip_all_tags( sa_school_description( $school ) ) );

		if ( '' === $desc ) {
			$name = sa_school_name( $school );
			$type = sa_school_type_label( isset( $school['school_type'] ) ? $school['school_type'] : '' );
			$city = isset( $school['city'] ) ? $school['city'] : '';
			$lang = isset( $school['language_req'] ) ? $school['language_req'] : '';

			$bits = array_filter( array( $type, $city, $lang ) );
			/* translators: 1: 院校名, 2: 类型・所在地・语言要求 */
			$desc = sprintf(
				__( '%1$s（%2$s）の基本情報・出願条件・学費目安をまとめました。無料のマッチング診断で、あなたの条件に合うか確認できます。', 'sa-theme' ),
				$name,
				implode( ' / ', $bits )
			);
		}

		sa_set_meta_description( wp_trim_words( $desc, 60 ) );
	},
	20
);

/**
 * 院校详情页的结构化数据。
 *
 * 用 WebPage + about 而不是直接标 EducationalOrganization：
 * 本页是「关于某院校的页面」，不是该院校本身。若把页面 URL 直接标成
 * EducationalOrganization 的 url，等于声称本站就是这所院校，属于错误断言。
 *
 * 同时刻意不输出 offers / price 结构化数据：学费随年度与课程变动，
 * 价格类标记与实际页面或官方信息不一致会被 Google 判定为误导。
 * 学费仍在页面正文中展示，并标注为「目安」。
 */
add_action(
	'wp_head',
	function () {
		if ( ! sa_is_school_page() ) {
			return;
		}

		$school = sa_current_school();
		if ( ! $school ) {
			return;
		}

		$name = sa_school_name( $school );
		if ( '' === $name ) {
			return;
		}

		$type_map = array(
			'university' => 'CollegeOrUniversity',
			'graduate'   => 'CollegeOrUniversity',
			'junior'     => 'CollegeOrUniversity',
			'vocational' => 'EducationalOrganization',
			'language'   => 'EducationalOrganization',
		);
		$st       = sanitize_key( isset( $school['school_type'] ) ? $school['school_type'] : '' );
		$org_type = isset( $type_map[ $st ] ) ? $type_map[ $st ] : 'EducationalOrganization';

		$about = array(
			'@type' => $org_type,
			'name'  => $name,
		);

		/*
		 * about.url は「その学校自身の URL」であって、本ページの URL ではない。
		 *
		 * ここに本ページの URL を入れると、この学校の公式所在地が
		 * studyinjp.com であると宣言することになる。当サイトは代理店ですらなく、
		 * 各校の公表資料をまとめているだけなので、それは端的に虚偽の主張になる。
		 * 公式サイトが登録されている場合のみ url / sameAs を出し、
		 * 未登録なら name と address だけに留める（推測で埋めない）。
		 */
		$official = isset( $school['official_url'] ) ? trim( (string) $school['official_url'] ) : '';
		if ( '' !== $official ) {
			$about['url']    = $official;
			$about['sameAs'] = array( $official );
		}

		$locality = isset( $school['city'] ) ? trim( (string) $school['city'] ) : '';
		$region   = isset( $school['region'] ) ? trim( (string) $school['region'] ) : '';
		if ( '' !== $locality || '' !== $region ) {
			$address = array(
				'@type'          => 'PostalAddress',
				'addressCountry' => 'JP',
			);
			if ( '' !== $locality ) {
				$address['addressLocality'] = $locality;
			}
			if ( '' !== $region ) {
				$address['addressRegion'] = $region;
			}
			$about['address'] = $address;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'WebPage',
			'url'        => sa_school_url( $school['slug'] ),
			'name'       => $name,
			'inLanguage' => sa_locale_field( sa_current_locale(), 'hreflang', 'ja' ),
			'about'      => $about,
			'isPartOf'   => array(
				'@type' => 'WebSite',
				'name'  => get_bloginfo( 'name' ),
				'url'   => sa_home_url( '/' ),
			),
		);

		$schema = apply_filters( 'sa_schema_school_page', $schema, $school );

		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			. '</script>' . "\n";
	},
	5
);
