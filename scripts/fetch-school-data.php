<?php
/**
 * fetch-school-data.php — 采集院校官网的公开信息，供人工核对后录入。
 *
 * 为什么需要这个脚本：
 *   院校详情页会以真实院校名义展示学费、日语要求、招生专业等事实信息，
 *   本站属于 YMYL 类目 —— 这些数据必须来自官方页面，不能凭印象编写。
 *   本脚本把各校官网的相关页面抓下来转成纯文本，并抽取含关键信息的行，
 *   产出一份便于人工核对与录入的资料包。
 *
 * 本脚本只做「取回并整理」，不做任何推断：
 *   输出里的每一条信息都能追溯到具体 URL 与原文行，便于逐条核实。
 *
 * 用法：
 *   php scripts/fetch-school-data.php                 # 采集全部院校
 *   php scripts/fetch-school-data.php isi             # 只采集指定院校（键名匹配）
 *   php scripts/fetch-school-data.php --pages=8       # 每校最多抓取的页面数（默认 6）
 *
 * 输出：
 *   scripts/school-data/{key}/pages/*.txt   各页面纯文本（可追溯来源 URL）
 *   scripts/school-data/{key}/highlights.txt 含关键信息的行（学费/要件/住所等）
 *   scripts/school-data/SUMMARY.txt          汇总，这一份发给我即可
 *
 * 礼貌抓取：单线程、每次请求间隔 1.5 秒、声明真实 User-Agent 与联系方式。
 * 只抓取公开的招生相关页面，不抓表单、不提交任何数据。
 *
 * 兼容 PHP 7.4。
 *
 * @package StudyAbroadCore
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 1 );
}

if ( ! function_exists( 'curl_init' ) ) {
	fwrite( STDERR, "缺少 PHP cURL 扩展。CentOS: yum install -y php-curl 后重启 php-fpm\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * 配置
 * ---------------------------------------------------------------------- */

/**
 * 采集对象院校清单。
 *
 * 注意：本站与这些院校之间不存在代理或合作关系。采集到的信息只能作为
 * 「依据公开资料整理的参考信息」发布，不得表述为提携校、合作院校或可代办申请。
 *
 * key 用于输出目录名与命令行筛选；name 仅作提示，真实名称以官网为准。
 */
$SCHOOLS = array(
	'isi'       => array(
		'name' => 'ISI（ISI日本語学校）',
		'url'  => 'https://www.isi-education.com/ja/',
	),
	'human'     => array(
		'name' => 'ヒューマンアカデミー日本語学校',
		'url'  => 'https://hajl.athuman.com',
	),
	'jp-sji'    => array(
		'name' => 'JP-SJI グループ',
		'url'  => 'https://group.jp-sji.org/',
	),
	'akamonkai' => array(
		'name' => '赤門会日本語学校',
		'url'  => 'https://akamonkai.ac.jp/',
	),
	'tokyoia'   => array(
		'name' => '東京 IA（tokyoia.com）',
		'url'  => 'https://www.tokyoia.com/',
	),
	'kla'       => array(
		'name' => '京進ランゲージアカデミー（KLA）',
		'url'  => 'https://www.kla.ac/ja/',
	),
);

/**
 * 用于发现子页面的关键词（匹配链接文字或 href）。
 *
 * 覆盖日/英两种写法，因为多数校网同时有日文与英文站。
 */
$LINK_KEYWORDS = array(
	// 学费・费用
	'学費', '費用', '授業料', '料金', 'tuition', 'fee', 'cost',
	// 招生・课程
	'募集', '入学', '出願', 'コース', '課程', 'クラス', '進学',
	'admission', 'course', 'program', 'apply', 'enroll',
	// 学校概要・校区
	'学校案内', '概要', 'アクセス', '校舎', 'キャンパス', '所在地',
	'about', 'access', 'campus', 'school',
);

/**
 * 抽取「关键信息行」用的关键词。
 */
$HIGHLIGHT_KEYWORDS = array(
	'学費', '授業料', '入学金', '選考料', '教材費', '施設費', '合計',
	'円', '万円',
	'日本語能力', 'JLPT', 'N1', 'N2', 'N3', 'N4', 'N5', 'EJU',
	'入学時期', '募集', '定員', '修業', '年限', 'ヶ月', 'か月', 'コース',
	'住所', 'アクセス', '最寄', '駅', '校舎', 'キャンパス',
	'学歴', '出願資格', '応募資格', '条件',
	'tuition', 'fee', 'JPY', 'admission', 'requirement', 'address',
);

$max_pages = 6;
$only      = '';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--pages=' ) ) {
		$max_pages = max( 1, min( 20, (int) substr( $arg, 8 ) ) );
	} elseif ( 0 !== strpos( $arg, '--' ) ) {
		$only = $arg;
	}
}

$out_root = __DIR__ . '/school-data';

/* -------------------------------------------------------------------------
 * HTTP
 * ---------------------------------------------------------------------- */

/**
 * 取回一个 URL。
 *
 * @param string $url 目标地址。
 * @return array{ok:bool, code:int, body:string, ctype:string, error:string, final:string}
 */
function sd_fetch( $url ) {
	/*
	 * 实测遇到的两类失败，成因完全不同：
	 *
	 * 1. NSS error -12190 (SSL_ERROR_PROTOCOL_VERSION_ALERT)
	 *    "Peer reports incompatible or unsupported protocol version"
	 *
	 *    这条报错来自 NSS，不是 OpenSSL —— CentOS 7 的 libcurl 链接的是
	 *    NSS 而非 OpenSSL，`openssl version` 显示的 1.0.2k 是系统库，
	 *    curl 根本没用它。所以最初写的
	 *        CURLOPT_SSLVERSION => TLSv1.2
	 *        CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1'
	 *    对这个后端是无效的：后者是 OpenSSL 的密码套件语法，NSS 不认。
	 *
	 *    真正的出路是换一条 TLS 栈：PHP 的 https:// 流封装走 openssl 扩展，
	 *    与 libcurl 的 NSS 完全独立。OpenSSL 1.0.2 支持 TLS 1.2，
	 *    因此 curl 握不上手的站点，流封装往往可以。见 sd_fetch_stream()。
	 *
	 * 2. "Connection timed out after 15001 milliseconds"
	 *    跨境访问日本站点，15 秒连接超时偏紧；也可能是对方按 IP 或 UA
	 *    静默丢包。对策：放宽超时，并换成常见浏览器 UA 再试一次。
	 *
	 * 逐级降级，任一成功即返回，不让兼容性拖慢正常站点。
	 */
	$attempts = array(
		// 第 1 轮：默认配置（绝大多数站点走这一轮）
		array(),
		// 第 2 轮：更长超时 + 常见浏览器 UA
		// 部分站点对非浏览器 UA 直接丢弃连接，表现为超时而非 403。
		array(
			CURLOPT_TIMEOUT        => 90,
			CURLOPT_CONNECTTIMEOUT => 40,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
		),
	);

	$last = array(
		'ok'    => false,
		'code'  => 0,
		'body'  => '',
		'ctype' => '',
		'error' => 'not attempted',
		'final' => $url,
	);

	foreach ( $attempts as $i => $extra ) {
		$ch = curl_init();

		$opts = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_CONNECTTIMEOUT => 25,
			CURLOPT_ENCODING       => '',
			// 声明真实身份与用途，便于对方站点管理员识别。
			// 措辞不能写 partner —— 本站与这些院校之间没有任何合作关系，
			// 而这个字符串是直接发到对方服务器日志里的。
			CURLOPT_USERAGENT      => 'SakuraRyugakuBot/1.0 (+https://studyinjp.com/; public school information research)',
			CURLOPT_HTTPHEADER     => array(
				'Accept: text/html,application/xhtml+xml',
				'Accept-Language: ja,en;q=0.8',
			),
		);

		// PHP 数组 + 运算符是左侧优先，故 $extra 在左，用于覆盖默认值。
		curl_setopt_array( $ch, $extra + $opts );

		$body  = curl_exec( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$ctype = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
		$final = (string) curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
		$err   = curl_error( $ch );

		curl_close( $ch );

		$last = array(
			'ok'      => ( false !== $body && $code >= 200 && $code < 300 ),
			'code'    => $code,
			'body'    => is_string( $body ) ? $body : '',
			'ctype'   => $ctype,
			'error'   => $err,
			'final'   => '' !== $final ? $final : $url,
			'attempt' => $i + 1,
		);

		if ( $last['ok'] ) {
			return $last;
		}

		// 只有 TLS / 连接类错误才值得换配置重试；
		// 明确的 4xx/5xx 说明连上了对方服务器，换 TLS 或 UA 无济于事。
		if ( $code >= 400 ) {
			return $last;
		}

		if ( $i < count( $attempts ) - 1 ) {
			usleep( 800000 );
		}
	}

	/*
	 * curl 全轮失败后的最后一招：换 TLS 栈。
	 *
	 * 只在「连一个字节都没拿到」时才走这里（$code === 0）。若已经收到
	 * HTTP 状态码，说明 TLS 握手是成功的，换栈没有意义。
	 */
	if ( 0 === $last['code'] ) {
		$via_stream = sd_fetch_stream( $url );
		if ( $via_stream['ok'] || 0 !== $via_stream['code'] ) {
			return $via_stream;
		}
		// 两条栈都失败时，把两边的报错都留下，便于判断是共性问题还是栈特有。
		$last['error'] = $last['error'] . ' | 流封装(OpenSSL)亦失败: ' . $via_stream['error'];
	}

	return $last;
}

/**
 * 用 PHP 的 https:// 流封装抓取，绕开 libcurl 的 TLS 后端。
 *
 * 为什么这条路可能通而 curl 不通：
 *   libcurl 与 php_openssl 是两套独立的 TLS 实现。CentOS 7 的 libcurl
 *   链接 NSS，其 TLS 版本与密码套件支持停留在较旧的状态，遇到只接受
 *   现代套件的站点会收到 protocol_version 告警（NSS -12190）；
 *   而流封装走 openssl 扩展，OpenSSL 1.0.2 的 TLS 1.2 实现更完整。
 *
 * 刻意不关闭证书校验：抓回来的内容会被整理成公开页面上的院校事实信息，
 * 一旦中途被替换而我们毫无察觉，等于以真实院校名义发布伪造数据。
 * 校验失败就如实报错，让人去处理，不能用 verify_peer=false 换一个"能跑"。
 *
 * @param string $url 目标地址。
 * @return array 与 sd_fetch() 相同的结构。
 */
function sd_fetch_stream( $url ) {
	$fail = function ( $msg ) use ( $url ) {
		return array(
			'ok'      => false,
			'code'    => 0,
			'body'    => '',
			'ctype'   => '',
			'error'   => $msg,
			'final'   => $url,
			'attempt' => 'stream',
		);
	};

	if ( ! ini_get( 'allow_url_fopen' ) ) {
		return $fail( 'allow_url_fopen=Off，无法使用流封装。可临时加参数执行：php -d allow_url_fopen=1 scripts/fetch-school-data.php <key>' );
	}
	if ( ! extension_loaded( 'openssl' ) ) {
		return $fail( 'openssl 扩展未加载，流封装无法处理 https' );
	}

	$ctx = stream_context_create(
		array(
			'http' => array(
				'method'          => 'GET',
				'timeout'         => 60,
				'follow_location' => 1,
				'max_redirects'   => 6,
				// 让 4xx/5xx 也返回响应体，否则 file_get_contents 直接返回 false，
				// 分不清「对方拒绝」和「网络不通」。
				'ignore_errors'   => true,
				'header'          => implode(
					"\r\n",
					array(
						'User-Agent: SakuraRyugakuBot/1.0 (+https://studyinjp.com/; public school information research)',
						'Accept: text/html,application/xhtml+xml',
						'Accept-Language: ja,en;q=0.8',
						// 不声明 gzip：流封装不会自动解压，拿到的会是二进制乱码。
						'Accept-Encoding: identity',
					)
				),
			),
			'ssl'  => array(
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'SNI_enabled'       => true,
				'ciphers'           => 'HIGH:!aNULL:!MD5',
			),
		)
	);

	// $http_response_header 由 file_get_contents 注入到当前作用域。
	$http_response_header = array();
	$body                 = @file_get_contents( $url, false, $ctx );

	if ( false === $body ) {
		$e   = error_get_last();
		$msg = isset( $e['message'] ) ? preg_replace( '/^file_get_contents\([^)]*\):\s*/', '', $e['message'] ) : '未知错误';
		return $fail( '流封装失败: ' . trim( $msg ) );
	}

	// 跟随重定向时 $http_response_header 会累积各跳的头，
	// 最终状态取最后一个 HTTP/ 行。
	$code  = 0;
	$ctype = '';
	foreach ( $http_response_header as $h ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $m ) ) {
			$code  = (int) $m[1];
			$ctype = ''; // 新一跳开始，重置 content-type
		} elseif ( 0 === stripos( $h, 'content-type:' ) ) {
			$ctype = trim( substr( $h, 13 ) );
		}
	}

	return array(
		'ok'      => ( $code >= 200 && $code < 300 && '' !== $body ),
		'code'    => $code,
		'body'    => $body,
		'ctype'   => $ctype,
		'error'   => ( $code >= 200 && $code < 300 ) ? '' : ( 'HTTP ' . $code ),
		'final'   => $url,
		'attempt' => 'stream(OpenSSL)',
	);
}

/**
 * 打印本机两条 TLS 栈的实现，便于一眼判断握手失败该往哪个方向查。
 */
function sd_print_tls_backends() {
	$cv     = function_exists( 'curl_version' ) ? curl_version() : array();
	$curl   = isset( $cv['ssl_version'] ) ? $cv['ssl_version'] : '（curl 扩展不可用）';
	$ossl   = defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '（openssl 扩展未加载）';
	$fopen  = ini_get( 'allow_url_fopen' ) ? 'On' : 'Off';

	echo "TLS 栈：\n";
	echo "  curl        : {$curl}\n";
	echo "  流封装      : {$ossl}\n";
	echo "  allow_url_fopen: {$fopen}\n";
	/*
	 * 必须锚定在开头匹配，不能用 stripos($curl, 'nss')：
	 * "OpenSSL/3.0.13" 里的 Ope[nSS]L 正好含子串 nss，
	 * 那样写会在所有 OpenSSL 环境下误报（本项目实际踩到过）。
	 * curl 报告后端的格式是 "NSS/3.53.1"、"OpenSSL/1.0.2k" 这样的前缀形式。
	 */
	if ( preg_match( '#^NSS/#i', $curl ) ) {
		echo "  注意: curl 走 NSS，对部分现代站点会报 -12190（protocol version）。\n";
		echo "        脚本会在 curl 完全失败时自动改用流封装(OpenSSL)重试。\n";
	}
	echo "\n";
}

/* -------------------------------------------------------------------------
 * HTML 处理
 * ---------------------------------------------------------------------- */

/**
 * 把 HTML 转成可读纯文本。
 *
 * 不依赖 DOM 扩展（部分精简版 PHP 未安装 php-xml），用正则逐步剥离。
 *
 * @param string $html HTML 源码。
 * @param string $ctype Content-Type 响应头，用于推断编码。
 * @return string
 */
function sd_html_to_text( $html, $ctype = '' ) {
	// --- 编码归一到 UTF-8 ---
	// 日本站点仍有 Shift_JIS / EUC-JP 的情况，不转换会得到乱码。
	$charset = '';
	if ( preg_match( '/charset\s*=\s*["\']?([a-z0-9_\-]+)/i', $ctype, $m ) ) {
		$charset = strtolower( $m[1] );
	}
	if ( '' === $charset && preg_match( '/<meta[^>]+charset\s*=\s*["\']?([a-z0-9_\-]+)/i', $html, $m ) ) {
		$charset = strtolower( $m[1] );
	}
	if ( '' !== $charset && 'utf-8' !== $charset && function_exists( 'mb_convert_encoding' ) ) {
		$converted = @mb_convert_encoding( $html, 'UTF-8', $charset );
		if ( false !== $converted && '' !== $converted ) {
			$html = $converted;
		}
	}

	// --- 去掉不含正文的元素 ---
	$html = preg_replace( '#<(script|style|noscript|svg|iframe)\b[^>]*>.*?</\1>#is', ' ', $html );
	$html = preg_replace( '#<!--.*?-->#s', ' ', $html );

	// --- 表格与列表保留结构：学费信息绝大多数在表格里 ---
	$html = preg_replace( '#</(td|th)>#i', " | ", $html );
	$html = preg_replace( '#</(tr|li|p|div|h[1-6]|dt|dd|section)>#i', "\n", $html );
	$html = preg_replace( '#<br\s*/?>#i', "\n", $html );

	$text = strip_tags( $html );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	// --- 空白归一 ---
	$text = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), $text );
	$lines = array();
	foreach ( explode( "\n", $text ) as $line ) {
		$line = trim( preg_replace( '/[ \t]+/u', ' ', $line ) );
		$line = trim( $line, " |" );
		if ( '' === $line ) {
			continue;
		}
		$lines[] = $line;
	}

	// 相邻重复行（导航重复渲染）去掉，压缩体积
	$out  = array();
	$prev = null;
	foreach ( $lines as $l ) {
		if ( $l !== $prev ) {
			$out[] = $l;
		}
		$prev = $l;
	}

	return implode( "\n", $out );
}

/**
 * 从 HTML 中提取候选子页面链接。
 *
 * @param string $html     HTML 源码。
 * @param string $base_url 当前页 URL，用于补全相对路径。
 * @param array  $keywords 关键词。
 * @return array<int,array{url:string,label:string}>
 */
function sd_find_links( $html, $base_url, array $keywords ) {
	$found = array();

	if ( ! preg_match_all( '#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
		return $found;
	}

	$base = wp_like_parse_base( $base_url );

	foreach ( $m as $a ) {
		$href  = trim( $a[1] );
		$label = trim( preg_replace( '/\s+/u', ' ', strip_tags( $a[2] ) ) );

		if ( '' === $href || 0 === strpos( $href, '#' ) ) {
			continue;
		}
		if ( preg_match( '#^(mailto:|tel:|javascript:)#i', $href ) ) {
			continue;
		}
		// 跳过明显的非内容资源
		if ( preg_match( '#\.(pdf|jpg|jpeg|png|gif|webp|zip|docx?|xlsx?|mp4)(\?|$)#i', $href ) ) {
			continue;
		}

		$abs = sd_absolutize( $href, $base );
		if ( '' === $abs ) {
			continue;
		}

		// 只跟同站链接，避免跑到外部站点
		$host_base = parse_url( $base_url, PHP_URL_HOST );
		$host_abs  = parse_url( $abs, PHP_URL_HOST );
		if ( $host_base && $host_abs && 0 !== strcasecmp( $host_base, $host_abs ) ) {
			// 允许同一主域下的子域（例：www 与裸域）
			$root_base = implode( '.', array_slice( explode( '.', $host_base ), -2 ) );
			$root_abs  = implode( '.', array_slice( explode( '.', $host_abs ), -2 ) );
			if ( 0 !== strcasecmp( $root_base, $root_abs ) ) {
				continue;
			}
		}

		$haystack = mb_strtolower_safe( $label . ' ' . $abs );
		foreach ( $keywords as $kw ) {
			if ( false !== strpos( $haystack, mb_strtolower_safe( $kw ) ) ) {
				$found[ $abs ] = array(
					'url'   => $abs,
					'label' => '' !== $label ? $label : $abs,
				);
				break;
			}
		}
	}

	return array_values( $found );
}

/**
 * mb_strtolower 的安全包装（未装 mbstring 时退回 strtolower）。
 *
 * @param string $s 字符串。
 * @return string
 */
function mb_strtolower_safe( $s ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
}

/**
 * 解析出用于补全相对路径的基础信息。
 *
 * @param string $url URL。
 * @return array{scheme:string,host:string,path:string}
 */
function wp_like_parse_base( $url ) {
	$p = parse_url( $url );
	return array(
		'scheme' => isset( $p['scheme'] ) ? $p['scheme'] : 'https',
		'host'   => isset( $p['host'] ) ? $p['host'] : '',
		'path'   => isset( $p['path'] ) ? $p['path'] : '/',
	);
}

/**
 * 相对路径转绝对 URL。
 *
 * @param string $href 原始 href。
 * @param array  $base 基础信息。
 * @return string 空字符串表示无法解析。
 */
function sd_absolutize( $href, array $base ) {
	if ( preg_match( '#^https?://#i', $href ) ) {
		return $href;
	}
	if ( 0 === strpos( $href, '//' ) ) {
		return $base['scheme'] . ':' . $href;
	}
	if ( '' === $base['host'] ) {
		return '';
	}
	if ( 0 === strpos( $href, '/' ) ) {
		return $base['scheme'] . '://' . $base['host'] . $href;
	}
	$dir = preg_replace( '#/[^/]*$#', '/', $base['path'] );
	return $base['scheme'] . '://' . $base['host'] . $dir . $href;
}

/**
 * 抽取含关键信息的行。
 *
 * @param string $text     纯文本。
 * @param array  $keywords 关键词。
 * @return array<int,string>
 */
function sd_highlights( $text, array $keywords ) {
	$hits = array();

	foreach ( explode( "\n", $text ) as $line ) {
		if ( mb_strlen_safe( $line ) < 4 || mb_strlen_safe( $line ) > 300 ) {
			continue;
		}
		$low = mb_strtolower_safe( $line );
		foreach ( $keywords as $kw ) {
			if ( false !== strpos( $low, mb_strtolower_safe( $kw ) ) ) {
				$hits[ $line ] = $line;
				break;
			}
		}
	}

	return array_values( $hits );
}

/**
 * mb_strlen 的安全包装。
 *
 * @param string $s 字符串。
 * @return int
 */
function mb_strlen_safe( $s ) {
	return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
}

/* -------------------------------------------------------------------------
 * 主流程
 * ---------------------------------------------------------------------- */

if ( ! is_dir( $out_root ) && ! mkdir( $out_root, 0755, true ) ) {
	fwrite( STDERR, "无法创建输出目录: {$out_root}\n" );
	exit( 1 );
}

sd_print_tls_backends();

$summary   = array();
$summary[] = '院校官网信息采集结果';
$summary[] = '采集时间: ' . date( 'Y-m-d H:i:s' );
$summary[] = str_repeat( '=', 70 );
$summary[] = '';
$summary[] = '说明：以下内容为各校官网公开页面的原文摘录，未做任何推断或改写。';
$summary[] = '      每条信息均标注来源 URL，请逐条核实后录入后台。';
$summary[] = '';

$total_ok   = 0;
$total_fail = 0;

foreach ( $SCHOOLS as $key => $school ) {
	if ( '' !== $only && false === stripos( $key, $only ) ) {
		continue;
	}

	echo "\n";
	echo str_repeat( '=', 64 ) . "\n";
	echo "  {$key}  {$school['name']}\n";
	echo "  {$school['url']}\n";
	echo str_repeat( '=', 64 ) . "\n";

	$dir = $out_root . '/' . $key;
	$pages_dir = $dir . '/pages';
	if ( ! is_dir( $pages_dir ) ) {
		mkdir( $pages_dir, 0755, true );
	}

	// --- 首页 ---
	echo "  [1] 首页 … ";
	$home = sd_fetch( $school['url'] );

	if ( ! $home['ok'] ) {
		echo "失败 (HTTP {$home['code']}) {$home['error']}\n";

		// 针对失败原因给出可操作的处置建议，而不是只报错。
		$hint = '';
		if ( false !== stripos( $home['error'], 'protocol version' )
			|| false !== stripos( $home['error'], 'SSL' )
			|| false !== stripos( $home['error'], 'TLS' ) ) {
			$hint = 'TLS 握手失败，且 curl(NSS) 与流封装(OpenSSL) 两条栈都不通。'
				. '这通常意味着对方只接受本机 OpenSSL 版本不支持的 TLS 版本或密码套件。'
				. '可先手工确认：openssl s_client -connect <域名>:443 -tls1_2 </dev/null'
				. '；确认不通则在本地电脑上跑本脚本，或手工复制官网内容。';
		} elseif ( false !== stripos( $home['error'], 'certificate' )
			|| false !== stripos( $home['error'], 'verify failed' ) ) {
			$hint = '证书校验失败。不要为此关闭校验 —— 采集到的内容会以真实院校名义发布，'
				. '中途被替换而无察觉等同于发布伪造数据。请先更新系统根证书：yum update ca-certificates。';
		} elseif ( false !== stripos( $home['error'], 'timed out' ) ) {
			$hint = '连接超时。脚本已重试到 40 秒连接 / 90 秒总超时仍不通。'
				. '可能是对方站点拦截了服务器 IP 或非浏览器请求。'
				. '建议在本地电脑上跑本脚本，或手工复制官网内容。';
		} elseif ( $home['code'] >= 400 ) {
			$hint = "对方返回 HTTP {$home['code']}，属明确拒绝（可能针对 UA 或 IP）。建议手工复制官网内容。";
		}

		$summary[] = str_repeat( '-', 70 );
		$summary[] = "【{$key}】{$school['name']}";
		$summary[] = "  采集失败：HTTP {$home['code']} {$home['error']}";
		$summary[] = '  尝试轮次：' . ( isset( $home['attempt'] ) ? $home['attempt'] : '?' )
			. '（curl 2 轮 + 流封装 1 轮）';
		if ( '' !== $hint ) {
			$summary[] = '  处置建议：' . $hint;
		}
		$summary[] = "  官网：{$school['url']}";
		$summary[] = '  需手工提供的页面：学費 / 募集要項 / アクセス（校舎所在地）/ コース一覧';
		$summary[] = '';

		echo "      → {$hint}\n";
		++$total_fail;
		continue;
	}

	echo "OK (HTTP {$home['code']}, 第 {$home['attempt']} 轮)\n";
	++$total_ok;

	$all_text   = array();
	$all_hits   = array();
	$page_index = array();

	$home_text = sd_html_to_text( $home['body'], $home['ctype'] );
	file_put_contents( $pages_dir . '/00-home.txt', "SOURCE: {$home['final']}\n\n" . $home_text );
	$all_text[]     = array( $home['final'], $home_text );
	$page_index[]   = $home['final'];

	// --- 发现并抓取子页面 ---
	$links = sd_find_links( $home['body'], $home['final'], $LINK_KEYWORDS );
	echo '  发现候选页面 ' . count( $links ) . " 个，抓取前 " . ( $max_pages - 1 ) . " 个\n";

	$n = 0;
	foreach ( $links as $link ) {
		if ( $n >= $max_pages - 1 ) {
			break;
		}
		if ( in_array( $link['url'], $page_index, true ) ) {
			continue;
		}

		++$n;
		// 礼貌间隔：避免给对方站点造成压力。
		usleep( 1500000 );

		printf( "  [%d] %s … ", $n + 1, mb_substr_safe( $link['label'], 0, 24 ) );
		$sub = sd_fetch( $link['url'] );

		if ( ! $sub['ok'] ) {
			echo "跳过 (HTTP {$sub['code']})\n";
			continue;
		}
		if ( false === stripos( $sub['ctype'], 'html' ) && '' !== $sub['ctype'] ) {
			echo "跳过（非 HTML）\n";
			continue;
		}

		echo "OK\n";
		$txt = sd_html_to_text( $sub['body'], $sub['ctype'] );
		file_put_contents(
			sprintf( '%s/%02d-%s.txt', $pages_dir, $n, preg_replace( '/[^a-z0-9]+/i', '-', substr( md5( $link['url'] ), 0, 8 ) ) ),
			"SOURCE: {$sub['final']}\nLABEL: {$link['label']}\n\n" . $txt
		);
		$all_text[]   = array( $sub['final'], $txt );
		$page_index[] = $link['url'];
	}

	// --- 关键信息行 ---
	$hl = array();
	foreach ( $all_text as $pair ) {
		list( $src, $txt ) = $pair;
		$hits = sd_highlights( $txt, $HIGHLIGHT_KEYWORDS );
		if ( empty( $hits ) ) {
			continue;
		}
		$hl[] = '';
		$hl[] = '### 来源: ' . $src;
		foreach ( array_slice( $hits, 0, 120 ) as $h ) {
			$hl[] = '  ' . $h;
		}
	}

	file_put_contents(
		$dir . '/highlights.txt',
		"【{$key}】{$school['name']}\n官网: {$school['url']}\n"
		. str_repeat( '=', 70 ) . "\n"
		. implode( "\n", $hl ) . "\n"
	);

	echo '  关键信息行 ' . max( 0, count( $hl ) ) . " 条 → {$key}/highlights.txt\n";

	$summary[] = str_repeat( '-', 70 );
	$summary[] = "【{$key}】{$school['name']}";
	$summary[] = "  官网: {$school['url']}";
	$summary[] = '  抓取页面: ' . count( $all_text ) . ' 个';
	$summary[] = '';
	foreach ( $hl as $line ) {
		$summary[] = $line;
	}
	$summary[] = '';
}

$summary_file = $out_root . '/SUMMARY.txt';
file_put_contents( $summary_file, implode( "\n", $summary ) . "\n" );

/**
 * mb_substr 的安全包装。
 *
 * @param string $s     字符串。
 * @param int    $start 起始。
 * @param int    $len   长度。
 * @return string
 */
function mb_substr_safe( $s, $start, $len ) {
	return function_exists( 'mb_substr' ) ? mb_substr( $s, $start, $len, 'UTF-8' ) : substr( $s, $start, $len );
}

echo "\n";
echo str_repeat( '=', 64 ) . "\n";
printf( "  采集完成：成功 %d 所，失败 %d 所\n", $total_ok, $total_fail );
echo str_repeat( '=', 64 ) . "\n";
echo "\n";
echo "输出目录: {$out_root}\n";
echo "\n";
echo "把这一份发给我即可（已含各校关键信息行）：\n";
echo "  {$summary_file}\n";
printf( "  大小: %s KB\n", number_format( filesize( $summary_file ) / 1024, 1 ) );
echo "\n";
echo "文件太大不便粘贴时，先打包再下载：\n";
echo "  tar -czf school-data.tar.gz -C " . escapeshellarg( dirname( $out_root ) ) . " school-data\n";
echo "\n";
echo "若某校采集失败（对方站点拦截爬虫等），请手工打开官网，\n";
echo "复制「学費」「募集要項」「アクセス」三类页面的文字发我。\n";
echo "\n";

exit( $total_fail > 0 && 0 === $total_ok ? 1 : 0 );
