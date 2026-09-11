<?php
/**
 * .po / .pot 解析与转义工具（供 po-merge.php 与 po-stat.php 共用）。
 *
 * 必须按语法解析而不能用 grep：gettext 工具（msgmerge / msgcat）会把长字符串
 * 折成多行，形如
 *
 *     msgid ""
 *     "第一段"
 *     "第二段"
 *     msgstr ""
 *     "译文第一段"
 *     "译文第二段"
 *
 * 此时 `grep '^msgstr ""$'` 会把已翻译的折行条目误判为未翻译。
 *
 * 兼容 PHP 7.4。
 *
 * @package StudyAbroadTheme
 */

if ( ! function_exists( 'po_unescape' ) ) {
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
}

if ( ! function_exists( 'po_escape' ) ) {
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
}

if ( ! function_exists( 'po_parse' ) ) {
	/**
	 * 解析 .po / .pot 文件，正确处理折行字符串。
	 *
	 * @param string $path 文件路径。
	 * @return array{header:string, entries:array<int,array<string,mixed>>}
	 */
	function po_parse( $path ) {
		$lines   = explode( "\n", (string) file_get_contents( $path ) );
		$entries = array();
		$header  = '';

		$blank = array(
			'comments' => array(),
			'ctxt'     => null,
			'id'       => null,
			'str'      => null,
			'fuzzy'    => false,
		);
		$cur   = $blank;
		$mode  = null;
		$buf   = '';

		$flush = function () use ( &$cur, &$entries, &$header, $blank ) {
			if ( null === $cur['id'] ) {
				$cur = $blank;
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
					'fuzzy'    => $cur['fuzzy'],
				);
			}
			$cur = $blank;
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
				if ( 0 === strpos( $st, '#,' ) && false !== strpos( $st, 'fuzzy' ) ) {
					$cur['fuzzy'] = true;
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

			// 折行续行：以引号开头结尾，追加到当前缓冲区。
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
}
