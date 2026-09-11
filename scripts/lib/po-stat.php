<?php
/**
 * po-stat.php — 统计 .po 文件的翻译完成度。
 *
 * 必须按语法解析：gettext 的 msgmerge 会把长字符串折行，折行后译文的首行
 * 恰好是 `msgstr ""`，用 grep 统计会把已翻译条目误判为未翻译。
 *
 * 用法：
 *   php po-stat.php <file.po>            输出: <未翻译数> <总数>
 *   php po-stat.php <file.po> --list     额外逐行打印未翻译的 msgid
 *
 * 兼容 PHP 7.4。
 *
 * @package StudyAbroadTheme
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 1 );
}

if ( $argc < 2 ) {
	fwrite( STDERR, "用法: php po-stat.php <file.po> [--list]\n" );
	exit( 1 );
}

$po_path = $argv[1];
$do_list = in_array( '--list', $argv, true );

if ( ! is_readable( $po_path ) ) {
	fwrite( STDERR, "无法读取: {$po_path}\n" );
	exit( 1 );
}

require_once __DIR__ . '/po-parser.php';

$po = po_parse( $po_path );

$total       = 0;
$untranslated = array();
$fuzzy        = 0;

foreach ( $po['entries'] as $e ) {
	if ( '' === $e['id'] ) {
		continue; // header
	}
	++$total;
	if ( ! empty( $e['fuzzy'] ) ) {
		++$fuzzy;
	}
	if ( '' === $e['str'] ) {
		$untranslated[] = $e['id'];
	}
}

// 首行输出机器可读的三个数字，供 shell 脚本直接读取。
printf( "%d %d %d\n", count( $untranslated ), $total, $fuzzy );

if ( $do_list && ! empty( $untranslated ) ) {
	foreach ( $untranslated as $id ) {
		// 截断过长字符串，保持输出可读。
		$show = str_replace( "\n", ' ', $id );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $show, 'UTF-8' ) > 70 ) {
			$show = mb_substr( $show, 0, 70, 'UTF-8' ) . '…';
		}
		echo $show . "\n";
	}
}
