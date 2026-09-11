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

/**
 * 反转义 .po 字符串字面量。
 *
 * @param string $s 原始字符串。
 * @return string
 */
function po_unescape( $s ) {
	$s = str_replace( '\\\\', "\x00", $s );
	$s = str_replace( array( '\\n', '\\t', '\\"' ), array( "\n", "\t", '"' ), $s );
	return str_replace( "\x00", '\\', $s );
}

/**
 * 转义为 .po 字符串字面量。
 *
 * @param string $s 原始字符串。
 * @return string
 */
function po_escape( $s ) {
	$s = str_replace( '\\', '\\\\', $s );
	$s = str_replace( '"', '\\"', $s );
	$s = str_replace( "\n", '\\n', $s );
	return str_replace( "\t", '\\t', $s );
}

/**
 * 解析 .po / .pot 文件。
 *
 * @param string $path 文件路径。
 * @return array{header:string, entries:array<int,array<string,mixed>>}
 */
function po_parse( $path ) {
	$lines   = explode( "\n", (string) file_get_contents( $path ) );
	$entries = array();
	$header  = '';

	$cur = array(
		'comments' => array(),
		'ctxt'     => null,
		'id'       => null,
		'str'      => null,
	);
	$mode = null;
	$buf  = '';

	$flush = function () use ( &$cur, &$entries, &$header ) {
		if ( null === $cur['id'] ) {
			$cur = array(
				'comments' => array(),
				'ctxt'     => null,
				'id'       => null,
				'str'      => null,
			);
			return;
		}
		if ( '' === $cur['id'] && null === $cur['ctxt'] ) {
			$header = (string) $cur['str'];
		} else {
			$entries[] = array(
				'comments' => $cur['comments'],
				'ctxt'     => $cur['ctxt'],
				'id'       => $cur['id'],
				'str'      => null === $cur['str'] ? '' : $cur['str'],
			);
		}
		$cur = array(
			'comments' => array(),
			'ctxt'     => null,
			'id'       => null,
			'str'      => null,
		);
	};

	foreach ( $lines as $line ) {
		$st = trim( $line );

		if ( '' === $st ) {
			if ( 'str' === $mode ) {
				$cur['str'] = $buf;
			} elseif ( 'id' === $mode ) {
				$cur['id'] = $buf;
			}
			$mode = null;
			$flush();
			continue;
		}

		if ( 0 === strpos( $st, '#' ) ) {
			if ( 'str' === $mode ) {
				$cur['str'] = $buf;
				$mode       = null;
				$flush();
			}
			$cur['comments'][] = $line;
			continue;
		}

		if ( 0 === strpos( $st, 'msgctxt ' ) ) {
			$mode = 'ctxt';
			$buf  = po_unescape( substr( trim( substr( $st, 8 ) ), 1, -1 ) );
			continue;
		}

		if ( 0 === strpos( $st, 'msgid_plural ' ) ) {
			$mode = 'skip';
			$buf  = '';
			continue;
		}

		if ( 0 === strpos( $st, 'msgid ' ) ) {
			if ( 'ctxt' === $mode ) {
				$cur['ctxt'] = $buf;
			}
			$mode = 'id';
			$buf  = po_unescape( substr( trim( substr( $st, 6 ) ), 1, -1 ) );
			continue;
		}

		if ( 0 === strpos( $st, 'msgstr' ) ) {
			if ( 'id' === $mode ) {
				$cur['id'] = $buf;
			}
			$mode = 'str';
			$pos  = strpos( $st, '"' );
			$buf  = ( false !== $pos ) ? po_unescape( substr( $st, $pos + 1, -1 ) ) : '';
			continue;
		}

		if ( '"' === substr( $st, 0, 1 ) && '"' === substr( $st, -1 ) && $mode ) {
			$buf .= po_unescape( substr( $st, 1, -1 ) );
			continue;
		}
	}

	if ( 'str' === $mode ) {
		$cur['str'] = $buf;
	} elseif ( 'id' === $mode ) {
		$cur['id'] = $buf;
	}
	$flush();

	return array(
		'header'  => $header,
		'entries' => $entries,
	);
}

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
