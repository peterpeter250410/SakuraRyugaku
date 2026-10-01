<?php
/**
 * AI 文风标记检测。
 *
 * 这个类算的是「文本有多像机器写的」，分数越低越好。纯本地计算，不调任何 API。
 *
 * ── 先把它不是什么讲清楚 ──────────────────────────────────────────
 *
 * 这不是 AI 检测器，也做不到检测。市面上的 AI 检测器本身准确率就存疑，
 * 我不会假装这几个统计量能判定文本来源。
 *
 * 它测的是**文风标记**：句长是否单调、有没有堆砌套话、段落是否一样长。
 * 这些特征恰好在 LLM 输出里普遍偏高，但它们本质上是「难读」的特征 ——
 * 一个人类作者写出同样单调的句子，一样会得高分，而且那篇文章也一样不好读。
 *
 * 所以优化目标不是「骗过检测器」。Google 从不检测内容是否 AI 生成，
 * 它评估的是内容有没有用。为了压低这个分数去改写，唯一正当的做法是
 * 让句子长短有变化、去掉空话、把泛泛的陈述换成具体的。那些改动本身就有价值。
 *
 * 另一条红线：所谓「人性化」绝不包括编造亲身经历。
 * 「我去年在高田馬場租房时……」这种第一人称叙事是假的，不许写。
 * 具体化的正确方向是「申请时最常卡住的一步是……」—— 具体，但不假装亲历。
 *
 * @package StudyAbroadContent
 */

if ( ! defined( 'SA_CONTENT_DIR' ) ) {
	exit( "must be loaded by pipeline\n" );
}

class SA_AI_Tell {

	/**
	 * LLM 输出里高频、而人类写作中相对少见的套话。
	 *
	 * 这份表是按「说了等于没说」筛的，不是按「AI 专用词」筛的 ——
	 * 后者并不存在。命中多说明文章注水，与它由谁写的无关。
	 *
	 * @return array<int,string>
	 */
	public static function filler_phrases() {
		return array(
			// 万能过渡词：删掉句子照样成立。
			'additionally', 'moreover', 'furthermore', 'in addition to this',
			'it is important to note', "it's important to note",
			'it is worth noting', "it's worth noting",
			'that being said', 'with that said',
			'in conclusion', 'to sum up', 'in summary',
			'when it comes to', 'in terms of',
			// 被用滥的隐喻。
			'delve into', 'dive into', 'navigating the', 'the landscape of',
			'a tapestry of', 'a myriad of', 'plays a crucial role',
			'plays a vital role', 'is key to', 'the world of',
			// 空洞的价值判断。
			'it is essential', "it's essential", 'crucial to understand',
			'a game changer', 'unlock the', 'embark on',
			'whether you are', "whether you're",
		);
	}

	/**
	 * 评分。
	 *
	 * @param string $text 纯文本（调用方负责先去掉 HTML 标签）。
	 * @param string $lang 'en' 或 'cjk'（ja/zh 共用 CJK 分句规则）。
	 * @return array{
	 *   score:int, verdict:string,
	 *   metrics:array<string,array{value:float,points:int,note:string}>
	 * } score 0-100，越低越像人写的。
	 */
	public static function analyze( $text, $lang = 'en' ) {
		/*
		 * 归一化空白，但**必须保留换行**。
		 *
		 * 这里原先写的是 preg_replace('/\s+/u',' ')，把换行也压成了空格，
		 * 于是后面按空行切段永远只得到一段，paragraph_variance 指标恒为 0 分 ——
		 * 一个永远不报警的检查比没有这个检查更糟，因为它看起来是在工作。
		 */
		$text = wp_strip_tags_compat( $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );   // 行内空白折叠
		$text = preg_replace( '/[ \t]*\n[ \t]*/u', "\n", $text ); // 行首尾空白
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text ); // 多余空行
		$text = trim( $text );

		if ( '' === $text ) {
			return array(
				'score'   => 0,
				'verdict' => 'empty',
				'metrics' => array(),
			);
		}

		$sentences = self::split_sentences( $text, $lang );
		$metrics   = array();

		/* ---- 1. 句长变异系数 ----------------------------------------
		 *
		 * 人写东西句子长短不一：一个短句，接一个铺陈的长句。
		 * LLM 输出的句长分布明显更集中。
		 *
		 * CV = 标准差 / 平均值。英文人类散文通常在 0.5 以上，
		 * 未经处理的模型输出常落在 0.3-0.45。
		 */
		$lens = array();
		foreach ( $sentences as $s ) {
			$n = self::unit_count( $s, $lang );
			if ( $n > 0 ) {
				$lens[] = $n;
			}
		}

		$cv = self::coeff_of_variation( $lens );
		// CV 越低越单调。0.55 以上认为正常，0.25 以下严重单调。
		$cv_pts = (int) round( self::clamp( ( 0.55 - $cv ) / 0.30, 0, 1 ) * 30 );
		$metrics['sentence_variance'] = array(
			'value' => round( $cv, 3 ),
			'points' => $cv_pts,
			'note'  => $cv < 0.35
				? '句子长度过于均匀，读起来像念稿。穿插短句。'
				: '句长有变化。',
		);

		/* ---- 2. 套话密度 -------------------------------------------- */
		$lower = mb_strtolower( $text, 'UTF-8' );
		$hits  = array();
		foreach ( self::filler_phrases() as $p ) {
			$c = substr_count( $lower, $p );
			if ( $c > 0 ) {
				$hits[ $p ] = $c;
			}
		}
		$hit_total = array_sum( $hits );
		$per_1k    = self::unit_count( $text, $lang ) > 0
			? $hit_total / ( self::unit_count( $text, $lang ) / 1000 )
			: 0;

		// 每千词 4 次以上算严重。
		$filler_pts = (int) round( self::clamp( $per_1k / 4.0, 0, 1 ) * 30 );
		arsort( $hits );
		$metrics['filler_density'] = array(
			'value'  => round( $per_1k, 2 ),
			'points' => $filler_pts,
			'note'   => $hit_total > 0
				? '套话（每千词 ' . round( $per_1k, 1 ) . ' 次）：' . implode( ', ', array_slice( array_keys( $hits ), 0, 6 ) )
				: '未发现套话。',
		);

		/* ---- 3. 段落长度均匀度 --------------------------------------
		 *
		 * 「每段都是 3-4 句」是模板化的典型症状。
		 */
		$paras = array_values( array_filter( array_map( 'trim', preg_split( '/\n\s*\n/u', $text ) ) ) );
		$plens = array();
		foreach ( $paras as $p ) {
			$plens[] = count( self::split_sentences( $p, $lang ) );
		}
		$p_cv     = self::coeff_of_variation( $plens );
		$para_pts = count( $plens ) >= 4
			? (int) round( self::clamp( ( 0.45 - $p_cv ) / 0.35, 0, 1 ) * 15 )
			: 0;
		$metrics['paragraph_variance'] = array(
			'value'  => round( $p_cv, 3 ),
			'points' => $para_pts,
			'note'   => $para_pts > 8 ? '每段长度几乎一样，像填模板。' : '段落长短正常。',
		);

		/* ---- 4. 三元排比 --------------------------------------------
		 *
		 * "X, Y, and Z" 这种三项并列，模型用得远超必要。
		 * 偶尔用是修辞，通篇都是就是套路。
		 */
		$triads = preg_match_all( '/\w+,\s+\w+,\s+and\s+\w+/i', $text );
		$tri_per_1k = self::unit_count( $text, $lang ) > 0
			? $triads / ( self::unit_count( $text, $lang ) / 1000 )
			: 0;
		$tri_pts = (int) round( self::clamp( $tri_per_1k / 3.0, 0, 1 ) * 10 );
		$metrics['triads'] = array(
			'value'  => round( $tri_per_1k, 2 ),
			'points' => $tri_pts,
			'note'   => $tri_pts > 5 ? '三项并列句式过多。' : '并列句式正常。',
		);

		/* ---- 5. 过渡词开头的句子比例 -------------------------------- */
		$starts = 0;
		$openers = array( 'additionally', 'moreover', 'furthermore', 'however', 'therefore', 'consequently', 'overall', 'ultimately' );
		foreach ( $sentences as $s ) {
			$w = mb_strtolower( trim( preg_replace( '/^\W+/u', '', $s ) ), 'UTF-8' );
			foreach ( $openers as $o ) {
				if ( 0 === strpos( $w, $o ) ) {
					$starts++;
					break;
				}
			}
		}
		$ratio     = count( $sentences ) > 0 ? $starts / count( $sentences ) : 0;
		$open_pts  = (int) round( self::clamp( $ratio / 0.15, 0, 1 ) * 15 );
		$metrics['transition_openers'] = array(
			'value'  => round( $ratio, 3 ),
			'points' => $open_pts,
			'note'   => $open_pts > 7 ? '太多句子以过渡词开头。' : '句首正常。',
		);

		$score = $cv_pts + $filler_pts + $para_pts + $tri_pts + $open_pts;

		return array(
			'score'   => (int) min( 100, $score ),
			'verdict' => self::verdict( $score ),
			'metrics' => $metrics,
		);
	}

	/**
	 * 分数 → 结论。
	 *
	 * @param int $score 分数。
	 * @return string
	 */
	private static function verdict( $score ) {
		if ( $score <= 25 ) {
			return 'good';
		}
		if ( $score <= 45 ) {
			return 'acceptable';
		}
		return 'needs_rewrite';
	}

	/**
	 * 分句。
	 *
	 * @param string $text 文本。
	 * @param string $lang 'en' 或 'cjk'。
	 * @return array<int,string>
	 */
	private static function split_sentences( $text, $lang ) {
		$pattern = ( 'cjk' === $lang )
			? '/(?<=[。！？])\s*/u'
			: '/(?<=[.!?])\s+(?=[A-Z"\'(])/u';

		$parts = preg_split( $pattern, $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? array_values( array_filter( array_map( 'trim', $parts ) ) ) : array();
	}

	/**
	 * 计量单位数：英文按词，CJK 按字符。
	 *
	 * 中日文没有空格分词，用 str_word_count 会得到 0 或 1，
	 * 所有基于「每千词」的指标都会失真。
	 *
	 * @param string $text 文本。
	 * @param string $lang 'en' 或 'cjk'。
	 * @return int
	 */
	private static function unit_count( $text, $lang ) {
		if ( 'cjk' === $lang ) {
			return (int) mb_strlen( preg_replace( '/\s+/u', '', $text ), 'UTF-8' );
		}
		return (int) count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * 变异系数（标准差 / 平均值）。
	 *
	 * @param array<int,int|float> $xs 样本。
	 * @return float 样本不足时返回 1.0（视作「无异常」，不误判）。
	 */
	private static function coeff_of_variation( array $xs ) {
		$n = count( $xs );
		if ( $n < 3 ) {
			// 样本太少，任何离散度都没有统计意义。返回正常值而不是 0，
			// 否则一段短文会被判成「极度单调」。
			return 1.0;
		}
		$mean = array_sum( $xs ) / $n;
		if ( $mean <= 0 ) {
			return 1.0;
		}
		$var = 0.0;
		foreach ( $xs as $x ) {
			$var += pow( $x - $mean, 2 );
		}
		return sqrt( $var / $n ) / $mean;
	}

	/**
	 * 夹到区间内。
	 *
	 * @param float $v   值。
	 * @param float $min 下限。
	 * @param float $max 上限。
	 * @return float
	 */
	private static function clamp( $v, $min, $max ) {
		return max( $min, min( $max, (float) $v ) );
	}
}

/**
 * 去 HTML 标签（CLI 环境不能依赖 WordPress 的 wp_strip_all_tags）。
 *
 * @param string $html HTML。
 * @return string
 */
function wp_strip_tags_compat( $html ) {
	$s = (string) $html;

	/*
	 * 表格要在去标签之前处理掉。
	 *
	 * 单元格之间没有任何分隔符，直接 strip_tags 会把一行数字粘成
	 * 「40,000100,000750,000」这种东西 —— 既切不出句子，数字匹配也会错乱
	 * （来源页抓下来的表格同样受影响，不只是我们自己的正文）。
	 *
	 * 行末换段、格间加竖线，让每一行成为独立的切分单元。
	 */
	$s = preg_replace( '#</t[dh]>#i', ' | ', $s );
	$s = preg_replace( '#</tr>#i', "\n\n", $s );

	// 块级标签换成空行，否则段落会粘成一句，段落长度指标全废。
	$s = preg_replace( '#</(p|div|h[1-6]|li|blockquote|section|table)>#i', "\n\n", $s );
	$s = preg_replace( '#<br\s*/?>#i', "\n", $s );

	return html_entity_decode( strip_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}
