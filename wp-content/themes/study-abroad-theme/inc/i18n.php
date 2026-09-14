<?php
/**
 * 多语言：语种注册表、URL 语种路由、locale 切换、语种化 URL 工具。
 *
 * 路由策略（无需 rewrite rule、无需为每语种复制页面）：
 *   1. 在 WordPress 解析请求之前，从 REQUEST_URI 中剥离语种前缀（/zh、/en），
 *      并记录当前语种。WordPress 因此按「无前缀 URL」正常渲染既有页面，
 *      于是**每个已有页面自动获得三语版本**。
 *   2. 通过 locale 过滤器切换到对应语言包，模板中的 __() 输出对应语种文案。
 *   3. 通过 permalink 系列过滤器，把语种前缀加回所有前端链接，
 *      保证用户在 /zh/ 下浏览时不会被导航甩回日文版。
 *
 * 注意：**不** 过滤 home_url()，因为 rest_url()/admin_url() 均基于它，
 * 加前缀会破坏表单提交与后台。需要语种化链接时显式调用 sa_home_url() / sa_url()。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 语种注册表
 * ---------------------------------------------------------------------- */

/**
 * 语种注册表（配置驱动，新增语种只需追加一行）。
 *
 * @return array<string,array<string,mixed>>
 */
function sa_locales() {
	/**
	 * 过滤器 sa_locales：新增语种只需在此追加一行，无需改动任何逻辑。
	 *
	 * prefix 为空字符串者即默认语种（承载根路径 /）。
	 */
	return apply_filters(
		'sa_locales',
		array(
			// site_name：各语种的站点名称，用于 Logo 文字与 <title> 中的站点名部分。
			// blogname 是数据库里的单一值，无法随语种变化，故在此按语种覆盖。
			// 它会出现在每一个页面的 <title> 里，是重要的品牌与关键词位置，
			// 需要改品牌名时改这里即可（留空则回退到数据库中的 blogname）。
			'ja'    => array(
				'label'     => '日本語',
				'prefix'    => '',
				'wp_locale' => 'ja',
				'dir'       => 'ltr',
				'hreflang'  => 'ja',
				'og_locale' => 'ja_JP',
				'site_name' => '日本留学サポート',
				'default'   => true,
			),
			'zh_CN' => array(
				'label'     => '简体中文',
				'prefix'    => 'zh',
				'wp_locale' => 'zh_CN',
				'dir'       => 'ltr',
				'hreflang'  => 'zh-Hans',
				'og_locale' => 'zh_CN',
				'site_name' => '日本留学官网',
			),
			'en_US' => array(
				'label'     => 'English',
				'prefix'    => 'en',
				'wp_locale' => 'en_US',
				'dir'       => 'ltr',
				'hreflang'  => 'en',
				'og_locale' => 'en_US',
				'site_name' => 'Study in Japan',
			),
			// 后续扩展示例（需要时取消注释即可，路由/hreflang/sitemap 全部自动生效）：
			// 'ko_KR' => array( 'label' => '한국어', 'prefix' => 'ko', 'wp_locale' => 'ko_KR', 'dir' => 'ltr', 'hreflang' => 'ko', 'og_locale' => 'ko_KR' ),
		)
	);
}

/**
 * 默认语种 key（承载根路径）。
 *
 * @return string
 */
function sa_default_locale() {
	static $default = null;
	if ( null !== $default ) {
		return $default;
	}
	$default = 'ja';
	foreach ( sa_locales() as $key => $loc ) {
		if ( ! empty( $loc['default'] ) || '' === $loc['prefix'] ) {
			$default = $key;
			break;
		}
	}
	return $default;
}

/**
 * 前缀 => 语种 key 映射（不含默认语种的空前缀）。
 *
 * @return array<string,string>
 */
function sa_locale_prefix_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	foreach ( sa_locales() as $key => $loc ) {
		if ( ! empty( $loc['prefix'] ) ) {
			$map[ $loc['prefix'] ] = $key;
		}
	}
	return $map;
}

/**
 * 取语种的某个属性，带默认值回退。
 *
 * @param string $locale_key 语种 key。
 * @param string $field      字段名。
 * @param mixed  $fallback   回退值。
 * @return mixed
 */
function sa_locale_field( $locale_key, $field, $fallback = '' ) {
	$locales = sa_locales();
	return isset( $locales[ $locale_key ][ $field ] ) ? $locales[ $locale_key ][ $field ] : $fallback;
}

/* -------------------------------------------------------------------------
 * 请求引导：剥离 URL 语种前缀
 * ---------------------------------------------------------------------- */

/**
 * 站点安装基路径（支持子目录安装），形如 '/' 或 '/sub/'。
 *
 * 主题加载阶段 home_url() 的过滤器链尚未就绪，故直接读 option。
 *
 * @return string
 */
function sa_home_path() {
	static $path = null;
	if ( null !== $path ) {
		return $path;
	}
	$home = get_option( 'home' );
	$p    = is_string( $home ) ? wp_parse_url( $home, PHP_URL_PATH ) : '';
	$path = '/' . ltrim( trailingslashit( (string) $p ), '/' );
	return $path;
}

/**
 * 解析并剥离当前请求的语种前缀。
 *
 * 在主题 functions.php 加载时立即执行（早于 WordPress 解析请求），
 * 结果缓存在全局，供 sa_current_locale() 等函数读取。
 *
 * @return void
 */
function sa_bootstrap_locale() {
	if ( isset( $GLOBALS['sa_locale_bootstrapped'] ) ) {
		return;
	}
	$GLOBALS['sa_locale_bootstrapped'] = true;

	// 默认值：默认语种 + 原样路径。
	$GLOBALS['sa_current_locale'] = sa_default_locale();
	$GLOBALS['sa_request_path']   = '/';

	// 后台、REST、AJAX、CLI、cron 不参与语种路由。
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( ! is_string( $request_uri ) || '' === $request_uri ) {
		return;
	}

	// 拆分 path 与 query。
	$query = '';
	$path  = $request_uri;
	$qpos  = strpos( $request_uri, '?' );
	if ( false !== $qpos ) {
		$path  = substr( $request_uri, 0, $qpos );
		$query = substr( $request_uri, $qpos ); // 含 '?'
	}

	// REST / 后台 / 登录等路径不参与语种路由。
	if ( preg_match( '#/(wp-json|wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-content|wp-includes)(/|$)#', $path ) ) {
		return;
	}

	$base = sa_home_path();

	// 请求路径必须位于站点基路径下。
	if ( 0 !== strpos( $path, $base ) ) {
		$GLOBALS['sa_request_path'] = $path;
		return;
	}

	$relative = substr( $path, strlen( $base ) );          // 'zh/about/' 形式
	$relative = ltrim( (string) $relative, '/' );
	$segments = '' === $relative ? array() : explode( '/', $relative );
	$first    = isset( $segments[0] ) ? $segments[0] : '';

	$map = sa_locale_prefix_map();

	if ( '' !== $first && isset( $map[ $first ] ) ) {
		// 命中语种前缀：记录语种并从请求中剥离。
		$GLOBALS['sa_current_locale'] = $map[ $first ];
		array_shift( $segments );
		$stripped = implode( '/', $segments );

		// 保留原始尾斜杠语义：目录式路径补回尾斜杠。
		if ( '' !== $stripped && '/' === substr( $path, -1 ) ) {
			$stripped = trailingslashit( $stripped );
		}

		$new_path = $base . $stripped;

		// 供 WordPress 按无前缀 URL 正常解析。
		$_SERVER['REQUEST_URI'] = $new_path . $query;
		$GLOBALS['sa_request_path'] = $new_path;
	} else {
		$GLOBALS['sa_request_path'] = $path;
	}
}

/**
 * 当前语种 key。
 *
 * @return string
 */
function sa_current_locale() {
	if ( ! isset( $GLOBALS['sa_current_locale'] ) ) {
		sa_bootstrap_locale();
	}
	return $GLOBALS['sa_current_locale'];
}

/**
 * 当前语种的 URL 前缀（默认语种为空字符串）。
 *
 * @return string
 */
function sa_current_prefix() {
	return (string) sa_locale_field( sa_current_locale(), 'prefix', '' );
}

/**
 * 当前请求剥离语种前缀后的路径（形如 '/about/'）。
 *
 * @return string
 */
function sa_request_path() {
	if ( ! isset( $GLOBALS['sa_request_path'] ) ) {
		sa_bootstrap_locale();
	}
	return $GLOBALS['sa_request_path'];
}

/* -------------------------------------------------------------------------
 * locale 切换
 * ---------------------------------------------------------------------- */

/**
 * 按当前语种切换 WordPress locale，使 __() 取到对应语言包。
 */
add_filter(
	'locale',
	function ( $locale ) {
		// 后台保持管理员自身语言，不受前台语种路由影响。
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $locale;
		}
		$wp_locale = sa_locale_field( sa_current_locale(), 'wp_locale', '' );
		return $wp_locale ? $wp_locale : $locale;
	},
	5
);

/* -------------------------------------------------------------------------
 * 语种化 URL 工具
 * ---------------------------------------------------------------------- */

/**
 * 判断 URL 是否为站内 URL。
 *
 * @param string $url URL。
 * @return bool
 */
function sa_is_internal_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}
	$home = home_url( '/' );
	return 0 === strpos( $url, $home );
}

/**
 * 给站内 URL 套上指定语种前缀。
 *
 * 已带前缀的 URL 会先被归一化，避免出现 /zh/zh/ 这类重复前缀。
 *
 * @param string      $url        站内绝对 URL 或以 '/' 开头的路径；留空表示首页。
 * @param string|null $locale_key 目标语种，默认当前语种。
 * @return string
 */
function sa_url( $url = '', $locale_key = null ) {
	$locale_key = $locale_key ? $locale_key : sa_current_locale();
	$prefix     = (string) sa_locale_field( $locale_key, 'prefix', '' );

	$home = home_url( '/' );

	if ( '' === $url ) {
		$url = $home;
	} elseif ( '/' === substr( $url, 0, 1 ) ) {
		$url = home_url( $url );
	} elseif ( ! sa_is_internal_url( $url ) ) {
		// 站外链接、mailto、锚点等原样返回。
		return $url;
	}

	if ( ! sa_is_internal_url( $url ) ) {
		return $url;
	}

	$rest = substr( $url, strlen( $home ) ); // 'about/?x=1'
	$rest = ltrim( (string) $rest, '/' );

	// 归一化：若已含某语种前缀，先剥离。
	$map      = sa_locale_prefix_map();
	$segments = '' === $rest ? array() : explode( '/', $rest );
	if ( isset( $segments[0] ) && isset( $map[ $segments[0] ] ) ) {
		array_shift( $segments );
		$rest = implode( '/', $segments );
	}

	if ( '' === $prefix ) {
		return $home . $rest;
	}
	return $home . $prefix . '/' . $rest;
}

/**
 * 语种化的 home_url()。
 *
 * @param string      $path       路径。
 * @param string|null $locale_key 目标语种，默认当前语种。
 * @return string
 */
function sa_home_url( $path = '/', $locale_key = null ) {
	return sa_url( home_url( $path ), $locale_key );
}

/**
 * 当前页面在指定语种下的 URL（用于 hreflang 与语言切换器）。
 *
 * 保持当前访问的页面路径，而不是一律跳回首页。
 *
 * @param string $locale_key 目标语种。
 * @return string
 */
function sa_current_url_in( $locale_key ) {
	$base = sa_home_path();
	$path = sa_request_path();

	$relative = 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
	$relative = ltrim( (string) $relative, '/' );

	return sa_url( home_url( '/' . $relative ), $locale_key );
}

/* -------------------------------------------------------------------------
 * 把语种前缀加回前端链接
 * ---------------------------------------------------------------------- */

/**
 * 前端 permalink 系列过滤：保证用户在 /zh/ 下浏览时链接不掉回默认语种。
 *
 * 仅作用于前台页面链接；REST/后台/AJAX 的 URL 不受影响。
 */
function sa_filter_permalink( $permalink ) {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $permalink;
	}
	if ( '' === sa_current_prefix() ) {
		return $permalink;
	}
	return sa_url( $permalink );
}

foreach ( array( 'page_link', 'post_link', 'post_type_link', 'term_link', 'attachment_link', 'year_link', 'month_link', 'day_link', 'search_link' ) as $sa_link_filter ) {
	add_filter( $sa_link_filter, 'sa_filter_permalink', 20 );
}
unset( $sa_link_filter );

/**
 * 导航菜单项 URL 语种化（菜单项 URL 走 nav_menu 自己的字段，不经 page_link）。
 */
add_filter(
	'wp_setup_nav_menu_item',
	function ( $item ) {
		if ( is_admin() ) {
			return $item;
		}

		// 菜单项标题存在数据库里，不经过 gettext，需在此按语种替换。
		if ( isset( $item->title ) ) {
			$item->title = sa_translate_content_label( $item->title );
		}
		if ( isset( $item->attr_title ) ) {
			$item->attr_title = sa_translate_content_label( $item->attr_title );
		}

		// 链接加语种前缀（默认语种无前缀，跳过）。
		if ( '' !== sa_current_prefix() && isset( $item->url ) && sa_is_internal_url( $item->url ) ) {
			$item->url = sa_url( $item->url );
		}

		return $item;
	},
	20
);

/* -------------------------------------------------------------------------
 * 数据库内容的语种化
 *
 * 站点名称、导航菜单项、页面标题都存在数据库里，不经过 gettext，
 * 因此语言包翻不到它们 —— 表现为：按钮、正文已经是中文/英文，
 * 但 Logo 和导航栏仍是建站时录入的日文。
 *
 * 这里在输出阶段按当前语种替换。
 * ---------------------------------------------------------------------- */

/**
 * 数据库文案 → 当前语种文案的映射表。
 *
 * 键是数据库中存的原文（建站时录入的日文），值必须是**字面量** __() 调用，
 * 这样 gettext 才能提取，翻译才会进入语言包。
 *
 * 新增菜单项或页面后，把它的标题按同样格式加到这里即可。
 *
 * @return array<string,string>
 */
function sa_content_label_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array(
		// 导航菜单项（见 scripts/wp-cli-setup.sh 中建立的 Primary / Footer 菜单）
		'サービス紹介'         => __( 'サービス紹介', 'sa-theme' ),
		'よくある質問'         => __( 'よくある質問', 'sa-theme' ),
		'お問い合わせ'         => __( 'お問い合わせ', 'sa-theme' ),
		'私たちについて'       => __( '私たちについて', 'sa-theme' ),
		'会社概要'             => __( '会社概要', 'sa-theme' ),
		'会社案内'             => __( '会社案内', 'sa-theme' ),
		'ホーム'               => __( 'ホーム', 'sa-theme' ),
		'無料相談'             => __( '無料相談', 'sa-theme' ),
		'サービス'             => __( 'サービス', 'sa-theme' ),
		'流れ'                 => __( '流れ', 'sa-theme' ),

		/*
		 * 以下是在后台手工添加的子菜单项。
		 *
		 * wp-cli-setup.sh 只建了三个主菜单项，这几条是后来在 wp-admin 里
		 * 加的 —— 它们不在本表内，于是中文站与英文站的下拉菜单一直显示日文。
		 *
		 * 这说明一个结构性问题：任何人在后台新增菜单项，都会重新制造这个
		 * 缺口，而且不会有任何报错。因此 seo-audit.sh 的 B4b 增加了一项
		 * 检查：抓取 /zh/ 与 /en/ 的导航，一旦发现假名就报错。
		 * 新增菜单项后若被那条检查拦下，把文案补到这里即可。
		 */
		'無料AI診断'           => __( '無料AI診断', 'sa-theme' ),
		'ご利用の流れ'         => __( 'ご利用の流れ', 'sa-theme' ),
		'無料診断'             => __( '無料診断', 'sa-theme' ),
		'AI診断'               => __( 'AI診断', 'sa-theme' ),
		'料金'                 => __( '料金', 'sa-theme' ),
		'アクセス'             => __( 'アクセス', 'sa-theme' ),

		// 页面标题
		'プライバシーポリシー' => __( 'プライバシーポリシー', 'sa-theme' ),
		'出願書類のご提出'     => __( '出願書類のご提出', 'sa-theme' ),

		'お申し込みありがとうございます' => __( 'お申し込みありがとうございます', 'sa-theme' ),
	);

	/**
	 * 过滤器 sa_content_label_map：追加自定义的数据库文案映射。
	 */
	$map = apply_filters( 'sa_content_label_map', $map );

	return $map;
}

/**
 * 按当前语种替换数据库文案。
 *
 * 映射表里没有的原样返回，因此新增内容不会被破坏，只是暂时不翻译。
 *
 * @param string $text 原文。
 * @return string
 */
function sa_translate_content_label( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return $text;
	}
	if ( is_admin() ) {
		return $text; // 后台保持原文，否则编辑菜单/页面时会看到被替换的标题。
	}
	$map = sa_content_label_map();
	return isset( $map[ $text ] ) ? $map[ $text ] : $text;
}

// 页面与文章标题（含 <title> 标签中的单页标题）。
add_filter( 'the_title', 'sa_translate_content_label', 20 );
add_filter( 'single_post_title', 'sa_translate_content_label', 20 );

/**
 * 站点名称（Logo 文字与 <title> 中的站点名部分）。
 *
 * blogname 是数据库选项，一个站点只有一个值，无法随语种变化。
 * 这里按语种覆盖；未配置 site_name 的语种回退到数据库中的值。
 */
add_filter(
	'option_blogname',
	function ( $value ) {
		// 后台、REST、定时任务、CLI 一律保持数据库原值：
		// 这些场景（尤其是留资邮件通知的发件人名）不参与前台语种路由，
		// 若跟着变会造成站点名在不同渠道不一致。
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $value;
		}
		$name = sa_locale_field( sa_current_locale(), 'site_name', '' );
		return $name ? $name : $value;
	}
);

/* -------------------------------------------------------------------------
 * 语言包加载
 *
 * 不依赖 load_theme_textdomain()。
 *
 * WordPress 6.7 起改为「按需加载」：早于 init 的加载请求只登记路径，
 * 不立即加载，留待第一次 __() 调用时由 _load_textdomain_just_in_time()
 * 处理。在 WordPress 7.0 上实测该路径没有命中本主题的语言包 ——
 * 表现为 locale 正确（get_locale / determine_locale 均为 zh_CN）、
 * Domain Path 已声明、.mo 文件存在且可读，但 is_textdomain_loaded()
 * 恒为 false，页面全部回退到源语言（日文）。
 *
 * 而直接调用 load_textdomain() 并显式传入文件路径与 locale 则一次成功。
 * 因此这里走确定性的显式加载，不再依赖那层间接机制。
 * ---------------------------------------------------------------------- */

/**
 * 显式加载主题语言包。
 *
 * 加载顺序遵循 WordPress 惯例：
 *   1. wp-content/languages/themes/  —— 用户自定义覆盖优先
 *   2. 主题自带 languages/
 *
 * @return bool 是否成功加载。
 */
function sa_load_theme_translations() {
	static $attempted = array();

	if ( is_textdomain_loaded( 'sa-theme' ) ) {
		return true;
	}

	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

	// 同一 locale 只尝试一次，避免在多个钩子上重复做文件检查。
	if ( isset( $attempted[ $locale ] ) ) {
		return $attempted[ $locale ];
	}
	$attempted[ $locale ] = false;

	// 源语言（日语）没有语言包文件，属正常情况，不必尝试。
	$default_wp_locale = sa_locale_field( sa_default_locale(), 'wp_locale', 'ja' );
	if ( $locale === $default_wp_locale ) {
		$attempted[ $locale ] = true;
		return true;
	}

	$candidates = array(
		WP_LANG_DIR . '/themes/sa-theme-' . $locale . '.mo',
		get_template_directory() . '/languages/sa-theme-' . $locale . '.mo',
	);

	foreach ( $candidates as $mofile ) {
		if ( is_readable( $mofile ) && load_textdomain( 'sa-theme', $mofile, $locale ) ) {
			$attempted[ $locale ] = true;
			return true;
		}
	}

	return false;
}

// 挂在多个时机上：任一时机成功即短路，后续调用直接返回。
// after_setup_theme 是常规时机；init 与 template_redirect 作为兜底，
// 确保模板开始渲染前语言包一定已就位。
add_action( 'after_setup_theme', 'sa_load_theme_translations', 5 );
add_action( 'init', 'sa_load_theme_translations', 0 );
add_action( 'template_redirect', 'sa_load_theme_translations', 0 );

/* -------------------------------------------------------------------------
 * 语种诊断探针
 *
 * 访问任意页面并附加 ?sa_locale_debug=1，会在 HTML 中输出一段注释，
 * 说明语种识别与语言包加载的真实状态。
 *
 * 用途：区分两类完全不同的故障 ——
 *   (a) URL 语种识别失败      → sa_current_locale 不对
 *   (b) 语言包未加载          → sa_current_locale 对，但 get_locale 或
 *                               is_textdomain_loaded 不对
 * 只输出诊断信息，不含任何敏感数据。
 * ---------------------------------------------------------------------- */

add_action(
	'wp_head',
	function () {
		if ( empty( $_GET['sa_locale_debug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$cur       = sa_current_locale();
		$wp_locale = sa_locale_field( $cur, 'wp_locale', '' );
		$mo        = get_template_directory() . '/languages/sa-theme-' . $wp_locale . '.mo';
		$l10n_php  = get_template_directory() . '/languages/sa-theme-' . $wp_locale . '.l10n.php';

		// 取一条确定存在于语言包中的字符串做实测。
		$probe_src = '日本留学を、<em>最適な一校</em>から始めよう';
		$probe_out = __( '日本留学を、<em>最適な一校</em>から始めよう', 'sa-theme' );

		$lines = array(
			'SA-LOCALE-DEBUG',
			'WordPress 版本            : ' . get_bloginfo( 'version' ),
			'站点语言 (WPLANG)         : ' . get_option( 'WPLANG' ),
			'--- 语种识别（本主题）---',
			'sa_current_locale()       : ' . $cur,
			'sa_request_path()         : ' . sa_request_path(),
			'期望的 wp_locale          : ' . $wp_locale,
			'--- WordPress 语言状态 ---',
			'get_locale()              : ' . get_locale(),
			'determine_locale()        : ' . ( function_exists( 'determine_locale' ) ? determine_locale() : 'n/a' ),
			'is_textdomain_loaded()    : ' . ( is_textdomain_loaded( 'sa-theme' ) ? 'YES' : 'NO' ),
			// Domain Path 缺失是语言包加载失败最常见的原因：
			// WordPress 依据它定位主题语言包目录。
			'主题 Text Domain          : ' . wp_get_theme()->get( 'TextDomain' ),
			'主题 Domain Path          : ' . ( wp_get_theme()->get( 'DomainPath' ) ? wp_get_theme()->get( 'DomainPath' ) : '(未声明 ← 必须为 /languages)' ),
			'--- 语言包文件 ---',
			// 输出相对路径而非绝对路径：绝对路径会暴露服务器目录结构，
			// 对排查毫无帮助，却给攻击者提供了可用信息。
			'mo 路径                   : ' . ltrim( str_replace( ABSPATH, '', $mo ), '/' ),
			'mo 存在                   : ' . ( file_exists( $mo ) ? 'YES' : 'NO' ),
			'mo 可读                   : ' . ( is_readable( $mo ) ? 'YES' : 'NO' ),
			'mo 大小                   : ' . ( file_exists( $mo ) ? filesize( $mo ) : 0 ),
			'l10n.php 存在             : ' . ( file_exists( $l10n_php ) ? 'YES' : 'NO' ),
			'l10n.php 可读             : ' . ( is_readable( $l10n_php ) ? 'YES' : 'NO' ),
			'--- 实测翻译 ---',
			'原文                      : ' . $probe_src,
			'译文                      : ' . $probe_out,
			'翻译是否生效              : ' . ( $probe_out !== $probe_src ? 'YES' : 'NO' ),
		);

		// 若翻译仍未生效，就地做一次显式加载并复测，
		// 用以区分「语言包文件本身有问题」与「加载时机/路径配置有问题」。
		if ( $probe_out === $probe_src ) {
			$forced = load_textdomain( 'sa-theme', $mo, $wp_locale );
			$retry  = __( '日本留学を、<em>最適な一校</em>から始めよう', 'sa-theme' );

			$lines[] = '--- 显式加载复测 ---';
			$lines[] = 'load_textdomain() 返回    : ' . ( $forced ? 'true' : 'false' );
			$lines[] = '复测译文                  : ' . $retry;
			$lines[] = '复测是否生效              : ' . ( $retry !== $probe_src ? 'YES' : 'NO' );
			$lines[] = '';
			if ( $forced && $retry !== $probe_src ) {
				$lines[] = '结论: 语言包文件正常，问题出在加载时机或 Domain Path 配置。';
			} elseif ( ! $forced ) {
				$lines[] = '结论: load_textdomain 加载失败，需检查 .mo 文件本身是否损坏。';
			} else {
				$lines[] = '结论: 文件已加载但取不到译文，需核对 msgid 与源码字符串是否完全一致。';
			}
		}

		echo "\n<!--\n" . esc_html( implode( "\n", $lines ) ) . "\n-->\n";
	},
	999
);

/* -------------------------------------------------------------------------
 * 规范化跳转保护
 * ---------------------------------------------------------------------- */

/**
 * 非默认语种下关闭 WordPress 的规范化跳转。
 *
 * 请求前缀已被剥离，WordPress 看到的是无前缀 URL，
 * 若任其执行 redirect_canonical，可能把 /zh/about/ 跳到 /about/，
 * 导致语种页面无法访问（并向搜索引擎发出错误的规范化信号）。
 */
add_filter(
	'redirect_canonical',
	function ( $redirect_url ) {
		if ( '' !== sa_current_prefix() ) {
			return false;
		}
		return $redirect_url;
	},
	10
);
