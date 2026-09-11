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
			'ja'    => array(
				'label'     => '日本語',
				'prefix'    => '',
				'wp_locale' => 'ja',
				'dir'       => 'ltr',
				'hreflang'  => 'ja',
				'og_locale' => 'ja_JP',
				'default'   => true,
			),
			'zh_CN' => array(
				'label'     => '简体中文',
				'prefix'    => 'zh',
				'wp_locale' => 'zh_CN',
				'dir'       => 'ltr',
				'hreflang'  => 'zh-Hans',
				'og_locale' => 'zh_CN',
			),
			'en_US' => array(
				'label'     => 'English',
				'prefix'    => 'en',
				'wp_locale' => 'en_US',
				'dir'       => 'ltr',
				'hreflang'  => 'en',
				'og_locale' => 'en_US',
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
		if ( is_admin() || '' === sa_current_prefix() ) {
			return $item;
		}
		if ( isset( $item->url ) && sa_is_internal_url( $item->url ) ) {
			$item->url = sa_url( $item->url );
		}
		return $item;
	},
	20
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
