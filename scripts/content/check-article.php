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
 * 退出码：0 = 全部通过，1 = 有闸门不通过，2 = 输入有问题。
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
if ( $r['pass'] ) {
	echo "  ✓ 通过\n";
} else {
	foreach ( $r['errors'] as $e ) {
		echo "  ✗ {$e}\n";
	}
	$fails++;
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
$tell = SA_AI_Tell::analyze( $art['body_html'], $lang );
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
$sum_len = ( 'en' === $lang )
	? strlen( $art['summary'] )
	: mb_strlen( $art['summary'], 'UTF-8' );
$lo = ( 'en' === $lang ) ? 120 : 60;
$hi = ( 'en' === $lang ) ? 165 : 85;

printf( "  长度 %d（建议 %d–%d）\n", $sum_len, $lo, $hi );
if ( $sum_len < $lo || $sum_len > $hi ) {
	// 这一项只警告不拦截：摘要长短是呈现效果问题，不是事实性问题。
	echo "  [警告] 超出建议区间。过短浪费搜索结果里的展示位，过长会被截断。\n";
} else {
	echo "  ✓ 合适\n";
}

/* ---- 结论 -------------------------------------------------------------- */

echo "\n", str_repeat( '=', 72 ), "\n";
if ( 0 === $fails ) {
	echo "全部闸门通过。可以发布：\n";
	echo "  wp eval-file scripts/content/wp/publish-article.php " . escapeshellarg( $file ) . " --allow-root\n";
	exit( 0 );
}
echo "{$fails} 道闸门未通过，不要发布。\n";
exit( 1 );

/**
 * 从词库里查该选题允许引用的域名。
 *
 * @param string $keyword_id keyword id。
 * @return array<int,string>
 */
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
				// official_school_site 是占位符不是域名，去掉。
				return array_values( array_filter( $dom, function ( $d ) {
					return 'official_school_site' !== $d;
				} ) );
			}
		}
	}

	return array();
}
