<?php
/**
 * 语种路由隔离测试：桩掉 WordPress 依赖，验证 i18n.php 的核心逻辑。
 * 在 PHP 7.4 语义范围内运行。
 */

define( 'ABSPATH', '/tmp/' );

$GLOBALS['__options'] = array( 'home' => 'https://studyinjp.com' );

function get_option( $k ) { return isset( $GLOBALS['__options'][ $k ] ) ? $GLOBALS['__options'][ $k ] : false; }
function home_url( $path = '/' ) {
	$base = rtrim( $GLOBALS['__options']['home'], '/' );
	return $base . '/' . ltrim( (string) $path, '/' );
}
function apply_filters( $tag, $value ) { return $value; }
function add_filter() { return true; }
function add_action() { return true; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function __( $s, $d = '' ) { return $s; }

$pass = 0; $fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		printf( "  \033[32m[OK]\033[0m   %s\n", $label );
	} else {
		$fail++;
		printf( "  \033[31m[FAIL]\033[0m %s\n         期望: %s\n         实际: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

function reset_state( $uri ) {
	unset( $GLOBALS['sa_locale_bootstrapped'], $GLOBALS['sa_current_locale'], $GLOBALS['sa_request_path'] );
	$_SERVER['REQUEST_URI'] = $uri;
	sa_bootstrap_locale();
}

require dirname( __DIR__, 2 ) . '/wp-content/themes/study-abroad-theme/inc/i18n.php';

echo "\n=== 1. 语种识别与前缀剥离 ===\n";

reset_state( '/' );
check( '/ → 语种',            sa_current_locale(), 'ja' );
check( '/ → REQUEST_URI 不变', $_SERVER['REQUEST_URI'], '/' );

reset_state( '/zh/' );
check( '/zh/ → 语种',          sa_current_locale(), 'zh_CN' );
check( '/zh/ → 剥离为 /',      $_SERVER['REQUEST_URI'], '/' );

reset_state( '/en/' );
check( '/en/ → 语种',          sa_current_locale(), 'en_US' );
check( '/en/ → 剥离为 /',      $_SERVER['REQUEST_URI'], '/' );

reset_state( '/zh/about/' );
check( '/zh/about/ → 语种',    sa_current_locale(), 'zh_CN' );
check( '/zh/about/ → 剥离',    $_SERVER['REQUEST_URI'], '/about/' );

reset_state( '/en/faq/' );
check( '/en/faq/ → 剥离',      $_SERVER['REQUEST_URI'], '/faq/' );

echo "\n=== 2. query 参数必须保留（表单/追踪不能丢） ===\n";

reset_state( '/zh/services/?utm_source=baidu&gclid=abc' );
check( '带参数 → 语种',        sa_current_locale(), 'zh_CN' );
check( '带参数 → 参数保留',    $_SERVER['REQUEST_URI'], '/services/?utm_source=baidu&gclid=abc' );

echo "\n=== 3. 不得误伤非语种路径 ===\n";

reset_state( '/english-blog/' );
check( '/english-blog/ 不是 /en',  sa_current_locale(), 'ja' );
check( '/english-blog/ 路径不变',  $_SERVER['REQUEST_URI'], '/english-blog/' );

reset_state( '/zhuanye/' );
check( '/zhuanye/ 不是 /zh',       sa_current_locale(), 'ja' );
check( '/zhuanye/ 路径不变',       $_SERVER['REQUEST_URI'], '/zhuanye/' );

echo "\n=== 4. REST / 后台路径不参与语种路由（否则表单提交会坏） ===\n";

reset_state( '/wp-json/sa/v1/lead' );
check( 'REST 路径不变',        $_SERVER['REQUEST_URI'], '/wp-json/sa/v1/lead' );
check( 'REST 语种为默认',      sa_current_locale(), 'ja' );

reset_state( '/wp-admin/admin-ajax.php' );
check( 'admin-ajax 路径不变',  $_SERVER['REQUEST_URI'], '/wp-admin/admin-ajax.php' );

echo "\n=== 5. sa_url() 语种化链接 ===\n";

reset_state( '/' );
check( 'ja: 首页',             sa_url( home_url( '/' ), 'ja' ),    'https://studyinjp.com/' );
check( 'zh: 首页',             sa_url( home_url( '/' ), 'zh_CN' ), 'https://studyinjp.com/zh/' );
check( 'en: 首页',             sa_url( home_url( '/' ), 'en_US' ), 'https://studyinjp.com/en/' );
check( 'zh: 内页',             sa_url( home_url( '/about/' ), 'zh_CN' ), 'https://studyinjp.com/zh/about/' );
check( 'zh: 路径写法',         sa_url( '/faq/', 'zh_CN' ),         'https://studyinjp.com/zh/faq/' );

echo "\n=== 6. 防重复前缀（sa_url 幂等性） ===\n";
check( '已带 /zh/ 再套 zh',    sa_url( 'https://studyinjp.com/zh/about/', 'zh_CN' ), 'https://studyinjp.com/zh/about/' );
check( '/zh/ 转 en',           sa_url( 'https://studyinjp.com/zh/about/', 'en_US' ), 'https://studyinjp.com/en/about/' );
check( '/zh/ 转回 ja',         sa_url( 'https://studyinjp.com/zh/about/', 'ja' ),    'https://studyinjp.com/about/' );

echo "\n=== 7. 站外链接不得被改写 ===\n";
check( '站外 URL 原样',        sa_url( 'https://google.com/x', 'zh_CN' ), 'https://google.com/x' );
check( 'mailto 原样',          sa_url( 'mailto:a@b.com', 'zh_CN' ),       'mailto:a@b.com' );

echo "\n=== 8. sa_current_url_in()：切语种必须停留在当前页 ===\n";

reset_state( '/zh/services/' );
check( '中文服务页 → 日文版',  sa_current_url_in( 'ja' ),    'https://studyinjp.com/services/' );
check( '中文服务页 → 英文版',  sa_current_url_in( 'en_US' ), 'https://studyinjp.com/en/services/' );
check( '中文服务页 → 自身',    sa_current_url_in( 'zh_CN' ), 'https://studyinjp.com/zh/services/' );

reset_state( '/faq/' );
check( '日文FAQ → 中文版',     sa_current_url_in( 'zh_CN' ), 'https://studyinjp.com/zh/faq/' );
check( '日文FAQ → 自身',       sa_current_url_in( 'ja' ),    'https://studyinjp.com/faq/' );

echo "\n=== 9. hreflang 双向一致性（Google 的硬性要求） ===\n";
// 规则：A 页面指向 B，则 B 页面也必须指回 A，且各自都含自引用。
$pages = array( '/', '/zh/', '/en/', '/about/', '/zh/about/', '/en/faq/' );
$consistent = true;
foreach ( $pages as $p ) {
	reset_state( $p );
	$set = array();
	foreach ( array_keys( sa_locales() ) as $k ) {
		$set[ $k ] = sa_current_url_in( $k );
	}
	// 自引用检查
	$self = sa_current_url_in( sa_current_locale() );
	if ( ! in_array( $self, $set, true ) ) {
		$consistent = false;
		echo "    缺自引用: $p\n";
	}
	// 反向检查：从各语种版本再算回来，集合必须相同
	foreach ( $set as $k => $u ) {
		$path = parse_url( $u, PHP_URL_PATH );
		reset_state( $path );
		$back = array();
		foreach ( array_keys( sa_locales() ) as $k2 ) {
			$back[ $k2 ] = sa_current_url_in( $k2 );
		}
		if ( $back !== $set ) {
			$consistent = false;
			echo "    不对称: $p ←→ $u\n";
			echo "      原集合: " . implode( ', ', $set ) . "\n";
			echo "      回算后: " . implode( ', ', $back ) . "\n";
		}
	}
}
check( '全部页面 hreflang 双向对称且含自引用', $consistent, true );

echo "\n=== 10. 子目录安装兼容 ===\n";
$GLOBALS['__options']['home'] = 'https://example.com/sub';
// 清掉 sa_home_path 的静态缓存需要新进程，这里仅验证不崩溃
echo "  （子目录场景依赖 sa_home_path() 静态缓存，需独立进程验证，此处跳过）\n";

echo "\n============================================================\n";
printf( "  通过 %d 项，失败 %d 项\n", $pass, $fail );
echo "============================================================\n\n";
exit( $fail > 0 ? 1 : 0 );
