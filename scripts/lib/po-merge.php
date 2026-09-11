<?php
/**
 * po-merge.php — 在没有 gettext（msgmerge）的环境下合并 .po 与 .pot。
 *
 * 行为与 msgmerge 一致的部分：
 *   - .pot 中新增的字符串，追加到 .po 并留空 msgstr（待翻译）
 *   - .po 中已有的翻译，原样保留
 *   - .pot 中已不存在的字符串（源码里删掉的文案），从 .po 中移除
 *
 * 用法：php po-merge.php <target.po> <source.pot>
 *
 * 兼容 PHP 7.4（生产环境版本）。
 *
 * @package StudyAbroadTheme
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 1 );
}

if ( $argc < 3 ) {
	fwrite( STDERR, "用法: php po-merge.php <target.po> <source.pot>\n" );
	exit( 1 );
}

$po_path  = $argv[1];
$pot_path = $argv[2];

foreach ( array( $po_path, $pot_path ) as $p ) {
	if ( ! is_readable( $p ) ) {
		fwrite( STDERR, "无法读取: {$p}\n" );
		exit( 1 );
	}
}

// 解析与转义逻辑抽取到共享文件，避免与 po-stat.php 出现两份实现而逐渐走偏。
require_once __DIR__ . '/po-parser.php';
$po  = po_parse( $po_path );
$pot = po_parse( $pot_path );

// 建立「已有翻译」索引：ctxt \x04 msgid => msgstr
$existing = array();
foreach ( $po['entries'] as $e ) {
	$key = ( null === $e['ctxt'] ? '' : $e['ctxt'] ) . "\x04" . $e['id'];
	if ( '' !== $e['str'] ) {
		$existing[ $key ] = $e['str'];
	}
}

$out       = array();
$kept      = 0;
$added     = 0;
$header    = '' !== $po['header'] ? $po['header'] : $pot['header'];
$out[]     = "msgid \"\"\nmsgstr \"\"\n" . implode(
	"\n",
	array_map(
		function ( $l ) {
			return '"' . po_escape( $l ) . '\n"';
		},
		array_filter( explode( "\n", rtrim( $header, "\n" ) ), 'strlen' )
	)
);

foreach ( $pot['entries'] as $e ) {
	$key = ( null === $e['ctxt'] ? '' : $e['ctxt'] ) . "\x04" . $e['id'];
	$str = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
	if ( '' !== $str ) {
		++$kept;
	} else {
		++$added;
	}

	$block = array();
	foreach ( $e['comments'] as $c ) {
		// 只保留来源引用与提取注释，丢弃 fuzzy 等状态标记。
		if ( 0 === strpos( $c, '#:' ) || 0 === strpos( $c, '#.' ) ) {
			$block[] = $c;
		}
	}
	if ( null !== $e['ctxt'] ) {
		$block[] = 'msgctxt "' . po_escape( $e['ctxt'] ) . '"';
	}
	$block[] = 'msgid "' . po_escape( $e['id'] ) . '"';
	$block[] = 'msgstr "' . po_escape( $str ) . '"';

	$out[] = implode( "\n", $block );
}

$removed = max( 0, count( $po['entries'] ) - $kept );

file_put_contents( $po_path, implode( "\n\n", $out ) . "\n" );

printf(
	"    保留已有翻译 %d 条，新增待翻译 %d 条，移除失效 %d 条\n",
	$kept,
	$added,
	$removed
);
