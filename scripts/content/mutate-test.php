<?php
/**
 * 変異テスト —— 記事中の数字を一つずつ書き換え、闸门が気づくかを調べる。
 *
 * なぜ必要か：
 *
 *   「闸门を通った」は「数字が正しい」を意味しない。核対されていない数字も
 *   同じ沈黙で通る。書き手の側からは区別がつかない —— そして区別がつかない
 *   検査は、あってもなくても同じである。
 *
 *   唯一の確かめ方は、わざと壊した稿を通してみることである。
 *   壊しても通るなら、その数字はこの闸门に守られていない。
 *
 * 使い方：
 *   php scripts/content/mutate-test.php scripts/content/articles/en_US/foo.json
 *
 * 出力の読み方：
 *   [捕获]  —— 書き換えが検出された。その数字は実際に核対されている。
 *   [漏网]  —— 書き換えても黙っていた。要調査。
 *
 *   「漏网」は必ずしも闸门の欠陥ではない。書き換えた先の数字が偶然
 *   出典ページに（別の意味で）載っていることもある。報告を見て、
 *   出典を狭める（PDF ならページ範囲を切る）か、本文に量詞を書くか、
 *   闸门に量詞を足すかを判断する。
 *
 * @package StudyAbroadContent
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI 専用\n" );
}

$path = isset( $argv[1] ) ? $argv[1] : '';
if ( '' === $path || ! is_readable( $path ) ) {
	fwrite( STDERR, "使い方: php scripts/content/mutate-test.php <記事 JSON>\n" );
	exit( 2 );
}

$root = dirname( dirname( __DIR__ ) );
$art  = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $art ) || ! isset( $art['body_html'] ) ) {
	fwrite( STDERR, "JSON を読めない、または body_html が無い\n" );
	exit( 2 );
}

/*
 * 元の稿がまず通ることを確かめる。
 * 通らない稿で変異テストをしても、何が原因の誤りか分からない。
 */
$tmp_dir = sys_get_temp_dir() . '/sa-mutate-' . getmypid();
@mkdir( $tmp_dir, 0700, true );
$base = $tmp_dir . '/base.json';
file_put_contents( $base, json_encode( $art, JSON_UNESCAPED_UNICODE ) );

$check = static function ( $file ) use ( $root ) {
	$out = array();
	$rc  = 0;
	@exec(
		'php ' . escapeshellarg( $root . '/scripts/content/check-article.php' )
		. ' ' . escapeshellarg( $file ) . ' 2>&1',
		$out,
		$rc
	);
	$txt = implode( "\n", $out );
	return array(
		'rc'   => $rc,
		'fail' => ( false !== strpos( $txt, '✗' ) ),
		/*
		 * 判定保留。出典のどれかが取得できなかった回である。
		 * 「闸门が気づかなかった」とは意味が違う ——
		 * そもそも照らす相手が無かったのだから、何も分かっていない。
		 */
		'held' => ( 3 === $rc || false !== strpos( $txt, '⚠' ) ),
		'out'  => $txt,
	);
};

$base_res = $check( $base );
if ( $base_res['fail'] ) {
	echo "元の稿が闸门を通らない。変異テストの前にそれを直すこと：\n";
	foreach ( explode( "\n", $base_res['out'] ) as $l ) {
		if ( false !== strpos( $l, '✗' ) ) {
			echo '  ' . trim( $l ) . "\n";
		}
	}
	exit( 1 );
}

/*
 * 本文から数字を拾う。
 *
 * [source:N] のマーカーは除く —— あれは事実ではなく参照であり、
 * 書き換えると「出典が足りない」という別の誤りになって、
 * 数字が核対されているかどうかの答えにならない。
 *
 * HTML タグの中（href の数字など）も対象外。
 */
$body   = preg_replace( '/\[source:\s*\d+\s*\]/i', ' ', $art['body_html'] );
$body   = preg_replace( '#<a\b[^>]*>#i', ' ', $body );
$body   = preg_replace( '/<[^>]+>/', ' ', $body );

preg_match_all( '/\d[\d,]*(?:\.\d+)?/u', $body, $m );

/*
 * 末尾のカンマは数字の一部ではない。落とさないと「119,」と「119」が
 * 別の数字として二重に試され、報告が読みにくくなる。
 */
$nums = array();
foreach ( $m[0] as $raw ) {
	$raw = rtrim( $raw, ',' );
	if ( '' !== $raw ) {
		$nums[ $raw ] = true;
	}
}
$nums = array_keys( $nums );

/*
 * 書き換え先の作り方。
 *
 * 「1 を 2 に」では弱い。隣の数字は出典ページに載っている確率が高く、
 * 捕まらなかったときに闸门の問題なのか偶然なのか切り分けられない。
 * 桁を保ったまま、十分離れた値にする。
 */
$mutate_value = static function ( $raw ) {
	$has_comma = ( false !== strpos( $raw, ',' ) );
	$has_dot   = ( false !== strpos( $raw, '.' ) );
	$plain     = str_replace( ',', '', $raw );

	if ( $has_dot ) {
		list( $int, $frac ) = explode( '.', $plain, 2 );
		$new_frac = str_pad( (string) ( ( (int) $frac + 3 ) % (int) pow( 10, strlen( $frac ) ) ), strlen( $frac ), '0', STR_PAD_LEFT );
		$out      = $int . '.' . $new_frac;
		return $has_comma ? number_format( (float) $out, strlen( $frac ) ) : $out;
	}

	$len = strlen( $plain );
	if ( 1 === $len ) {
		// 1桁は 3 足す（9 なら 6 引く）。0 や同値にしない。
		$v = (int) $plain;
		$v = ( $v <= 6 ) ? $v + 3 : $v - 3;
		return (string) $v;
	}

	/*
	 * 2桁以上は先頭の桁を変える。末尾をいじると
	 * 「1,280 → 1,283」のように千位区切りの形が残って
	 * 次級証拠の経路で通ってしまうことがある。
	 */
	$first = (int) $plain[0];
	$first = ( $first <= 6 ) ? $first + 3 : $first - 3;
	if ( 0 === $first ) {
		$first = 1;
	}
	$out = (string) $first . substr( $plain, 1 );
	return $has_comma ? number_format( (float) $out ) : $out;
};

printf( "%s\n", basename( $path ) );
printf( "数字 %d 個を一つずつ書き換える\n\n", count( $nums ) );

$leaks = array();
$held  = array();

foreach ( $nums as $raw ) {
	$new = $mutate_value( $raw );
	if ( $new === $raw ) {
		continue;
	}

	/*
	 * 単語境界を見て置換する。「1」が「13」の一部を書き換えないように。
	 * カンマや小数点を含む形もそのまま扱えるよう、数字の前後に
	 * 数字が続かないことだけを条件にする。
	 */
	/*
	 * 置換は本文に対してだけ行う。
	 *
	 * [source:N] のマーカーと HTML タグの中にも数字がある。素朴に
	 * 「最初の一致」を置き換えると、本文の数字ではなくマーカーの番号や
	 * href の中身を書き換えてしまい、「数字が核対されているか」とは
	 * 別のことを測ってしまう。実際に起きた：2 の変異が [source:2] に当たり、
	 * 本文の「2nd floor」は無傷のまま通って「漏网」と報告された。
	 *
	 * マーカーとタグを一度プレースホルダに退避し、置換後に戻す。
	 */
	$shield = array();
	$masked = preg_replace_callback(
		'/\[source:\s*\d+\s*\]|<[^>]+>/i',
		static function ( $mm ) use ( &$shield ) {
			/*
			 * プレースホルダに数字を入れてはいけない。
			 * 入れると変異の正規表現がプレースホルダの番号に当たり、
			 * 本文の数字は無傷のまま「漏网」と報告される —— 実際に起きた。
			 * 添字は英字に写す。
			 */
			$k            = "\x01" . strtr( (string) count( $shield ), '0123456789', 'ABCDEFGHIJ' ) . "\x02";
			$shield[ $k ] = $mm[0];
			return $k;
		},
		$art['body_html']
	);

	$pat    = '/(?<![\d,.])' . preg_quote( $raw, '/' ) . '(?![\d,.])/u';
	$masked = preg_replace( $pat, $new, $masked, 1, $cnt );
	if ( ! $cnt ) {
		continue;
	}
	$mut = strtr( $masked, $shield );

	$copy              = $art;
	$copy['body_html'] = $mut;
	$f                 = $tmp_dir . '/m.json';
	file_put_contents( $f, json_encode( $copy, JSON_UNESCAPED_UNICODE ) );

	$res = $check( $f );

	/*
	 * 取得できなかった出典があるなら、一度だけ引き直す。
	 *
	 * これが無いと、間欠的な通信失敗が「闸门の穴」として報告される ——
	 * 実際に起きた：千駄ヶ谷の定員 900 を 600 に書き換えた回で
	 * group.jp-sji.org の取得が落ち、[漏网] と出た。
	 * あとで単体で確かめたら闸门は正しく捕まえていた。
	 *
	 * 道具が嘘の警告を出すなら、その道具で得た結論は全部疑わしくなる。
	 */
	if ( ! $res['fail'] && $res['held'] ) {
		$res = $check( $f );
	}

	if ( $res['fail'] ) {
		printf( "  [捕获] %s → %s\n", $raw, $new );
	} elseif ( $res['held'] ) {
		printf( "  [保留] %s → %s（出典を取得できず、判定が成立しない）\n", $raw, $new );
		$held[] = $raw;
	} else {
		printf( "  [漏网] %s → %s\n", $raw, $new );
		$leaks[] = $raw;
	}
}

@unlink( $tmp_dir . '/m.json' );
@unlink( $base );
@rmdir( $tmp_dir );

echo "\n";

if ( ! empty( $held ) ) {
	printf(
		"%d 個は出典を取得できず判定できなかった（通信の問題で、闸门の穴ではない）： %s\n",
		count( $held ),
		implode( ', ', $held )
	);
}

if ( empty( $leaks ) ) {
	printf( "書き換えを検出できなかった数字は無い（判定できた %d 個中）。\n", count( $nums ) - count( $held ) );
	exit( empty( $held ) ? 0 : 3 );
}

printf( "%d 個が書き換えても検出されなかった： %s\n", count( $leaks ), implode( ', ', $leaks ) );
echo "出典を狭める／本文に量詞を書く／闸门に量詞を足す、のいずれかを検討すること。\n";
exit( 1 );
