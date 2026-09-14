<?php
/**
 * check-nav-i18n.php — 检查非日文语种的导航是否还在显示日文原文。
 *
 * 为什么需要：
 *
 *   导航菜单项存在数据库里，不走 gettext，要靠主题的 sa_content_label_map()
 *   逐条映射才能语种化。这张表是硬编码的 —— 任何人在 wp-admin 里新增一个
 *   菜单项，中文站与英文站就会照原样显示日文，而且没有任何报错。
 *
 *   实际发生过：后台手工加的「無料AI診断」「ご利用の流れ」两个子菜单项，
 *   在中文站上一直显示日文，直到有人肉眼看到才发现。
 *
 * 判据的选择（这里走过一次弯路，记下来）：
 *
 *   最初想「检测假名」—— 平假名片假名只存在于日文，出现即漏翻。
 *   但这条判据会漏掉「無料AI診断」：它由汉字与拉丁字母组成，一个假名都没有。
 *   恰恰是实际报出来的那个 bug，它检不出来。
 *
 *   改用对比法：把日文页与目标语种页的导航按链接路径配对，
 *   同一个链接在两个语种下文字完全相同 → 该条没有被翻译。
 *   这个判据与文字由什么字符组成无关，因此上面那类情况也能检出。
 *
 *   两处例外不算问题：
 *     · 纯 ASCII 标签（LINE / FAQ / WeChat 之类）各语种本就相同
 *     · 显式白名单（品牌名等）
 *   另外保留假名检查作为独立补充：即使配对失败也能兜住明显的漏翻。
 *
 * 用法：
 *   php scripts/lib/check-nav-i18n.php https://studyinjp.com/ https://studyinjp.com/zh/
 *
 * 退出码：0 未发现问题；1 发现未翻译项；2 抓取或解析失败。
 *
 * 兼容 PHP 7.4。
 *
 * @package StudyAbroadTheme
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 2 );
}

$base_url   = isset( $argv[1] ) ? $argv[1] : '';
$target_url = isset( $argv[2] ) ? $argv[2] : '';

if ( '' === $base_url || '' === $target_url ) {
	fwrite( STDERR, "用法: php scripts/lib/check-nav-i18n.php <日文页URL> <目标语种页URL>\n" );
	fwrite( STDERR, "例:   php scripts/lib/check-nav-i18n.php https://studyinjp.com/ https://studyinjp.com/zh/\n" );
	exit( 2 );
}

/** 各语种本就相同、不算漏翻的标签。 */
$allow_same = array( 'LINE', 'WeChat', 'WhatsApp', 'FAQ', 'AI', 'SNS' );

/**
 * 是否含平假名或片假名。
 *
 * 不含长音符 \x{30FC}（「ー」）—— 它在中文排版里偶尔也出现，
 * 单独一个不足以判定为日文。
 *
 * @param string $s 待检文本。
 * @return bool
 */
function nav_has_kana( $s ) {
	return (bool) preg_match( '/[\x{3041}-\x{3096}\x{30A1}-\x{30FA}]/u', $s );
}

/**
 * 抓取页面。
 *
 * @param string $url 目标地址。
 * @return string|false HTML，失败返回 false。
 */
function nav_fetch( $url ) {
	$ch = curl_init();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 25,
			CURLOPT_USERAGENT      => 'SakuraRyugaku-i18n-check/1.0',
		)
	);
	$html = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	return ( false !== $html && $code >= 200 && $code < 300 ) ? $html : false;
}

/**
 * 取出导航链接，返回 路径键 => 链接文字。
 *
 * 路径键剥掉语种前缀，使同一个链接在不同语种下得到相同的键，
 * 从而可以配对比较。
 *
 * 用 DOMDocument 解析而不是正则 —— 下拉子菜单是 ul 套 ul，
 * 正则很容易在嵌套结构上取错范围。
 *
 * @param string $html 页面 HTML。
 * @return array
 */
function nav_extract( $html ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();

	$xpath = new DOMXPath( $doc );
	$nodes = $xpath->query(
		"//nav[contains(@class,'sa-nav')]//a"
		. " | //footer[contains(@class,'sa-footer')]//div[contains(@class,'sa-footer__col')]//a"
	);

	$out = array();
	if ( ! $nodes ) {
		return $out;
	}

	foreach ( $nodes as $a ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
		if ( '' === $text ) {
			continue;
		}

		$href = $a->getAttribute( 'href' );
		$path = (string) parse_url( $href, PHP_URL_PATH );
		// 剥掉语种前缀，让同一链接在各语种下得到同一个键
		$path = preg_replace( '#^/(zh|en)(/|$)#', '/', $path );
		$path = '' === $path ? '/' : $path;

		/*
		 * 键必须带上 fragment。
		 *
		 * 首页上的锚点链接（/#lead-form、/#flow）路径都是 "/"，
		 * 只用路径做键会把它们合并成一条，后面的直接被丢掉 ——
		 * 实测就因此漏掉了「ご利用の流れ」这条未翻译项。
		 */
		$frag = (string) parse_url( $href, PHP_URL_FRAGMENT );
		$key  = '' !== $frag ? $path . '#' . $frag : $path;

		// 同一链接可能同时出现在主导航与页脚，保留首个即可
		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = $text;
		}
	}

	return $out;
}

$base_html   = nav_fetch( $base_url );
$target_html = nav_fetch( $target_url );

if ( false === $base_html || false === $target_html ) {
	fwrite( STDERR, "抓取失败（{$base_url} 或 {$target_url}）\n" );
	exit( 2 );
}

$base_nav   = nav_extract( $base_html );
$target_nav = nav_extract( $target_html );

if ( empty( $target_nav ) ) {
	fwrite( STDERR, "未找到导航链接 —— 页面结构可能已变，本检查需同步更新。\n" );
	exit( 2 );
}

$untranslated = array();
$kana_only    = array();

foreach ( $target_nav as $path => $text ) {
	// 纯 ASCII 标签各语种相同是正常的
	if ( preg_match( '/^[\x20-\x7E]+$/', $text ) ) {
		continue;
	}
	if ( in_array( $text, $allow_same, true ) ) {
		continue;
	}

	if ( isset( $base_nav[ $path ] ) && $base_nav[ $path ] === $text ) {
		$untranslated[ $text ] = $path;
	} elseif ( nav_has_kana( $text ) ) {
		// 配对不上（路径不同）但含假名 —— 独立兜底
		$kana_only[ $text ] = $path;
	}
}

$bad = $untranslated + $kana_only;

if ( empty( $bad ) ) {
	printf( "OK 导航 %d 项，均已语种化\n", count( $target_nav ) );
	exit( 0 );
}

printf( "FAIL 导航 %d 项中发现 %d 条仍显示日文原文：\n", count( $target_nav ), count( $bad ) );
foreach ( $untranslated as $t => $p ) {
	echo "  {$t}   （{$p} 在日文页与本页文字完全相同）\n";
}
foreach ( $kana_only as $t => $p ) {
	echo "  {$t}   （{$p} 含假名）\n";
}
echo "\n";
echo "这些是数据库里的菜单项文案，不走 gettext。修法：\n";
echo "  1. 把原文加入 wp-content/themes/study-abroad-theme/inc/i18n.php\n";
echo "     的 sa_content_label_map()，值写成 __( '原文', 'sa-theme' )\n";
echo "  2. 在 languages/sa-theme-zh_CN.po 与 -en_US.po 中补上译文\n";
echo "  3. bash scripts/i18n-build.sh compile\n";
exit( 1 );
