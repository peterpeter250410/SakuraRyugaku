<?php
/**
 * 离线校验一篇稿件：跑全部闸门，不调用任何 LLM。
 *
 * 用法：
 *   php scripts/content/check-article.php <article.json> [--corpus=<dump.json>]
 *
 * 为什么单独做一个脚本而不是放进 pipeline.php：
 *
 *   pipeline.php 一启动就要 ANTHROPIC_API_KEY，因为它的职责是「生成 + 校验 + 发布」。
 *   但校验这件事本身不需要模型 —— 来源抓取、数字核对、重复度、文风评分全是
 *   确定性计算。把它拆出来，就有了一条不需要密钥、也不会产生任何费用的路径：
 *
 *     文章在别处写好（比如在 Claude Code 会话里）→ 这里校验 → 服务器只负责发布。
 *
 *   服务器上于是一个 API 密钥都不用存。
 *
 * 输入 JSON 的字段与 publish-article.php 一致，便于校验通过后直接拿去发布：
 *   { title, slug, locale, group, summary, body_html, sources[], keyword_id }
 *
 * 退出码：
 *   0  全部闸门通过，且每条来源都在本机实际核对过
 *   1  有闸门不通过 —— 内容有问题，不要发布
 *   2  输入有问题（文件缺失、JSON 不合法、缺必填字段）
 *   3  内容未发现问题，但有来源**本机无法访问**，因而没有核对过
 *
 * 退出码 3 单独存在，是因为「没查」和「查过且通过」必须分开。
 * 生产服务器那台 CentOS 7 的 curl 7.29.0 / OpenSSL 1.0.2k 不支持 TLS 1.3，
 * 够不着要求 TLS 1.3 的学校官网 —— 把这种情况报成 0 等于谎称核对过，
 * 报成 1 又会让每篇文章都红，最后整道闸门被忽略。
 *
 * @package StudyAbroadContent
 */

define( 'SA_CONTENT_DIR', __DIR__ );

require_once SA_CONTENT_DIR . '/lib/class-ai-tell.php';
require_once SA_CONTENT_DIR . '/lib/class-source-gate.php';
require_once SA_CONTENT_DIR . '/lib/class-dedup.php';

/** 与 pipeline.php 保持一致。改阈值要两处一起改。 */
const SA_CHK_AI_TELL_MAX = 35;
const SA_CHK_DEDUP_MAX   = 0.28;

$argv0 = array_shift( $argv );

$file   = null;
$corpus_file = null;
foreach ( (array) $argv as $a ) {
	if ( 0 === strpos( $a, '--corpus=' ) ) {
		$corpus_file = substr( $a, 9 );
	} elseif ( 0 !== strpos( $a, '--' ) ) {
		$file = $a;
	}
}

if ( null === $file ) {
	fwrite( STDERR, "用法：php check-article.php <article.json> [--corpus=<dump.json>]\n" );
	exit( 2 );
}

if ( ! file_exists( $file ) ) {
	fwrite( STDERR, "找不到文件：{$file}\n" );
	exit( 2 );
}

$art = json_decode( (string) file_get_contents( $file ), true );
if ( ! is_array( $art ) ) {
	fwrite( STDERR, "不是合法 JSON：{$file}\n" );
	exit( 2 );
}

foreach ( array( 'title', 'slug', 'locale', 'summary', 'body_html' ) as $req ) {
	if ( empty( $art[ $req ] ) ) {
		fwrite( STDERR, "缺少必填字段：{$req}\n" );
		exit( 2 );
	}
}

$sources = isset( $art['sources'] ) ? (array) $art['sources'] : array();
$lang    = ( 0 === strpos( $art['locale'], 'en' ) ) ? 'en' : 'cjk';
$fails   = 0;
$unverified = 0;

echo "稿件：{$art['title']}\n";
echo "  slug={$art['slug']}  locale={$art['locale']}  来源 " . count( $sources ) . " 条\n";
echo str_repeat( '-', 72 ), "\n";

/* ---- 闸门 1：来源 ------------------------------------------------------ */

echo "【闸门 1】来源（逐个抓取 + 数字核对）\n";

// 允许域：从词库里查这个选题的配置；查不到就只校验可达性与标记完整性。
$allowed = allowed_domains_for( isset( $art['keyword_id'] ) ? $art['keyword_id'] : '' );
if ( ! empty( $allowed ) ) {
	echo '  限定域名：' . implode( ', ', $allowed ) . "\n";
} else {
	echo "  未限定域名（词库里没有该 keyword_id 的配置）\n";
}

$gate = new SA_Source_Gate();
$r    = $gate->check( $art['body_html'], $sources, $allowed );

echo "  实际抓取 {$r['checked']} 个 URL\n";
foreach ( $r['warnings'] as $w ) {
	echo "  [警告] {$w}\n";
}

$unver = isset( $r['unverifiable'] ) ? (array) $r['unverifiable'] : array();
foreach ( $unver as $u ) {
	echo "  ⚠ {$u}\n";
}
$unverified = count( $unver );

if ( ! $r['pass'] ) {
	foreach ( $r['errors'] as $e ) {
		echo "  ✗ {$e}\n";
	}
	$fails++;
} elseif ( $unverified > 0 ) {
	echo "  ⚠ 本机够不着 {$unverified} 条来源，其中的数字**没有核对过**。\n";
	echo "    这不代表内容有误，只代表这台机器没能验证。\n";
} else {
	echo "  ✓ 通过\n";
}

/* ---- 闸门 2：重复度 ---------------------------------------------------- */

echo "\n【闸门 2】站内重复度\n";
if ( null === $corpus_file ) {
	echo "  跳过（未提供 --corpus=）。在服务器上先跑：\n";
	echo "    wp eval-file scripts/content/wp/dump-articles.php {$art['locale']} --allow-root > /tmp/corpus.json\n";
} elseif ( ! file_exists( $corpus_file ) ) {
	echo "  ✗ 找不到语料文件：{$corpus_file}\n";
	$fails++;
} else {
	$corpus = json_decode( (string) file_get_contents( $corpus_file ), true );
	$corpus = is_array( $corpus ) ? $corpus : array();
	if ( empty( $corpus ) ) {
		echo "  站内还没有同语种文章，跳过\n";
	} else {
		$dd = SA_Dedup::compare( $art['body_html'], $corpus, $lang );
		printf( "  与 %d 篇比对，最高 %.3f（%s）阈值 %.2f\n",
			count( $corpus ), $dd['max'], $dd['worst'], SA_CHK_DEDUP_MAX );
		if ( $dd['max'] > SA_CHK_DEDUP_MAX ) {
			echo "  ✗ 超过阈值\n";
			$fails++;
		} else {
			echo "  ✓ 通过\n";
		}
	}
}

/* ---- 闸门 3：文风 ------------------------------------------------------ */

echo "\n【闸门 3】文风评分\n";
/*
 * 文風を見る前に、本文を読者が読む形に戻す。
 *
 * ここは長らく body_html をそのまま渡していた。SA_AI_Tell の説明には
 * 「純文本（呼び出し側がタグを落とす）」と書いてあるのに、落としていなかった。
 *
 * 何が起きていたか：
 *   ・タグの文字列まで語数に数えるので、千語あたりの套话密度が薄まる
 *   ・body_html は改行を含まない一行なので、段落を \n\n で切る指標は
 *     常に「段落1つ」と判定し、点が入りようがなかった —— 死んだ指標
 *   ・[source:N] は発布時に剥がれるのに、評点のときだけ本文に混ざっていた
 *
 * 結果として闸门の報告する点数は実際より低く出ていた。
 * 正しく剥がすと 0 点だった記事が 11 点になる（それでも閾値内だが、
 * 「0 点だから手を入れる必要がない」と読んでいた判断の根拠が無かったことになる）。
 *
 * 発布経路（publish-article.php）と同じ変換をここでも行う：
 * 標記を外し、ブロック要素を段落の切れ目に変えてからタグを落とす。
 */
$tell_text = preg_replace( '/\s*\[source:\s*\d+\s*\]/i', '', $art['body_html'] );
$tell_text = wp_strip_tags_compat( $tell_text );
$tell      = SA_AI_Tell::analyze( $tell_text, $lang );
printf( "  总分 %d（%s）阈值 %d\n", $tell['score'], $tell['verdict'], SA_CHK_AI_TELL_MAX );
foreach ( $tell['metrics'] as $name => $m ) {
	printf( "    %-20s value=%-8s %2d 分  %s\n", $name, $m['value'], $m['points'], $m['note'] );
}
if ( $tell['score'] > SA_CHK_AI_TELL_MAX ) {
	echo "  ✗ 超标，需要改写\n";
	$fails++;
} else {
	echo "  ✓ 通过\n";
}

/* ---- 附加检查：摘要长度 ------------------------------------------------ */

echo "\n【附加】摘要\n";
/*
 * 文字数で数える。バイト数ではない。
 *
 * もともと英語だけ strlen() を使っていた —— ラテン文字は1バイトという前提。
 * その前提は、ダッシュ（—）や曲がったアポストロフィ（’）を一つ使った
 * 時点で崩れる。どちらも UTF-8 で3バイトあり、本サイトの摘要は
 * ダッシュを多用する。実際 159 文字の摘要が 161 バイトと数えられ、
 * 上限超過として誤って警告された。
 *
 * テーマの sa_trim_meta_description() は mb_strlen で数えて切る。
 * 切る側と測る側が別の単位を使っていれば、どちらの数字も信用できない。
 */
$sum_len = mb_strlen( $art['summary'], 'UTF-8' );
$lo = ( 'en' === $lang ) ? 120 : 60;

/*
 * 上限はテーマの切り詰め幅と一致させる。
 *
 * sa_trim_meta_description()（inc/seo.php）はラテン文字を 160、
 * CJK を 90 で切る。闸门の上限はもともと 165 で、その 5 文字の隙間に
 * 入った摘要は闸门を通ってから線上で文中で切られていた ——
 * 実際に 4 本が「What that means in…」「you have to ask…」のように
 * 最後の語を失った状態で公開された。
 *
 * 閾値が二箇所にあって一致していなければ、緩いほうが事実上の仕様になる。
 * ここを厳しいほう（テーマの実際の切り詰め幅）に合わせる。
 * テーマ側を変えたときはここも変える必要がある。
 */
$hi = ( 'en' === $lang ) ? 160 : 90;

printf( "  长度 %d（上限 %d＝テーマの切り詰め幅、下限 %d）\n", $sum_len, $hi, $lo );
if ( $sum_len > $hi ) {
	/*
	 * 超過は「呈現の好み」ではない。テーマが問答無用で切るので、
	 * 読者が見る description は文中で途切れた文字列になる。
	 * 拦截しないのは事実性の問題ではないからだが、文面は実害として書く。
	 */
	echo "  [警告] 上限超過。テーマが {$hi} 文字で切るため、線上では文中で途切れた description になる。短くすること。\n";
} elseif ( $sum_len < $lo ) {
	echo "  [警告] 下限未満。搜索结果里的展示位を使い切れていない。\n";
} else {
	echo "  ✓ 合适\n";
}

/* ---- 结论 -------------------------------------------------------------- */

echo "\n", str_repeat( '=', 72 ), "\n";

if ( $fails > 0 ) {
	echo "{$fails} 道闸门未通过，不要发布。\n";
	exit( 1 );
}

$publish_cmd = '  wp eval-file scripts/content/wp/publish-article.php '
	. escapeshellarg( $file ) . " --allow-root\n";

if ( $unverified > 0 ) {
	echo "内容未发现问题，但有 {$unverified} 条来源本机访问不了，其中的数字没有核对过。\n\n";
	echo "发布前请确认这些数字已经在**别的机器上**核对过（退出码 3）。\n";
	echo "若已确认，可以发布：\n" . $publish_cmd;
	exit( 3 );
}

echo "全部闸门通过，每条来源都已实际核对。可以发布：\n" . $publish_cmd;
exit( 0 );

/**
 * 从词库里查该选题允许引用的域名。
 *
 * @param string $keyword_id keyword id。
 * @return array<int,string>
 */
/**
 * 收录校の官網ドメイン一覧（scripts/schools.json の official_url から）。
 *
 * ここを手で列挙しないのは、学校を追加したときに更新を忘れるから。
 * 忘れた結果どうなるかというと、正しく官網を引いた記事が
 * 「域名が範囲外」で落ちる —— 誤拦截も故障であって、
 * しかも原因が分かりにくい方向の故障になる。
 *
 * @return array<int,string>
 */
function school_official_domains() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();
	$file  = dirname( SA_CONTENT_DIR ) . '/schools.json';
	if ( ! file_exists( $file ) ) {
		return $cache;
	}

	$data = json_decode( (string) file_get_contents( $file ), true );
	$rows = isset( $data['schools'] ) ? $data['schools'] : $data;
	if ( ! is_array( $rows ) ) {
		return $cache;
	}

	foreach ( $rows as $s ) {
		if ( ! is_array( $s ) || empty( $s['official_url'] ) ) {
			continue;
		}
		$host = parse_url( (string) $s['official_url'], PHP_URL_HOST );
		if ( $host ) {
			// www. は host_allowed() が後方一致で見るので落としておく。
			$cache[] = preg_replace( '/^www\./i', '', $host );
		}
	}

	$cache = array_values( array_unique( $cache ) );
	return $cache;
}

function allowed_domains_for( $keyword_id ) {
	if ( '' === $keyword_id ) {
		return array();
	}

	foreach ( (array) glob( SA_CONTENT_DIR . '/keywords/*.json' ) as $f ) {
		$data = json_decode( (string) file_get_contents( $f ), true );
		if ( ! is_array( $data ) || empty( $data['clusters'] ) ) {
			continue;
		}
		foreach ( $data['clusters'] as $cluster ) {
			$c_dom = isset( $cluster['sources_required'] ) ? (array) $cluster['sources_required'] : array();
			foreach ( (array) $cluster['keywords'] as $k ) {
				if ( ! isset( $k['id'] ) || $k['id'] !== $keyword_id ) {
					continue;
				}
				$dom = isset( $k['sources_required'] ) ? (array) $k['sources_required'] : $c_dom;

				/*
				 * official_school_site は域名ではなく「校方官网であること」という指定。
				 *
				 * 以前はこれを単に捨てていた。結果、たとえば
				 * schools-with-dormitory（sources_required が
				 * official_school_site だけ）の白名単は空になり、
				 * 「域名を制限しない」と同じ扱いになっていた。
				 *
				 * これは一番弱い方向に倒れている。学校を列挙する記事こそ、
				 * まとめブログや留学斡旋業者の二次情報で埋められやすい。
				 * 指定が最も効くべき選題で、実際には何も効いていなかった。
				 *
				 * schools.json の official_url から実際の校方ドメインに展開する。
				 * 収録校の官網だけが通り、二次情報は落ちる。
				 */
				$out = array();
				foreach ( $dom as $d ) {
					if ( 'official_school_site' === $d ) {
						$out = array_merge( $out, school_official_domains() );
						continue;
					}
					$out[] = $d;
				}
				return array_values( array_unique( $out ) );
			}
		}
	}

	return array();
}
