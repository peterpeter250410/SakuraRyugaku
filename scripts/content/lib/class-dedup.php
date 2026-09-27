<?php
/**
 * 重复度闸门：新稿与站内已有文章的相似度。
 *
 * 为什么需要：批量生成最典型的失败模式不是「写错」，而是「写重」——
 * 十篇文章翻来覆去讲同一件事，各自换个标题。这正是 Google 2024 年写进
 * 垃圾内容政策的 scaled content abuse，命中是站点级处罚，不是单页降权。
 *
 * 方法：w-gram shingling + Jaccard 相似度。
 *   把文本切成连续 w 个词的窗口，两篇文章的窗口集合取交并比。
 *   比逐句比对稳健（改几个词就绕过不了），也比向量相似度轻 —— 不需要模型。
 *
 * 局限：它比的是字面重合，不是观点重合。两篇用词完全不同、结论一模一样的
 * 文章，这里会得低分。那种重复要靠选题阶段避免（词库里一个 keyword 只出一篇），
 * 不是这道闸门的职责。
 *
 * @package StudyAbroadContent
 */

if ( ! defined( 'SA_CONTENT_DIR' ) ) {
	exit( "must be loaded by pipeline\n" );
}

class SA_Dedup {

	/** 窗口宽度。5 个词是常用取值：够长到不会被常见搭配误伤，够短到改写绕不过。 */
	const SHINGLE_W = 5;

	/**
	 * 与一组已有文本比对，返回最高相似度。
	 *
	 * @param string            $text     新稿纯文本。
	 * @param array<string,string> $corpus 已有文章，键为标识（slug），值为纯文本。
	 * @param string            $lang     'en' 或 'cjk'。
	 * @return array{max:float,worst:string,scores:array<string,float>}
	 */
	public static function compare( $text, array $corpus, $lang = 'en' ) {
		$a = self::shingles( $text, $lang );

		$scores = array();
		$max    = 0.0;
		$worst  = '';

		foreach ( $corpus as $key => $other ) {
			$b = self::shingles( $other, $lang );
			$j = self::jaccard( $a, $b );

			$scores[ $key ] = round( $j, 4 );
			if ( $j > $max ) {
				$max   = $j;
				$worst = $key;
			}
		}

		arsort( $scores );

		return array(
			'max'    => round( $max, 4 ),
			'worst'  => $worst,
			'scores' => $scores,
		);
	}

	/**
	 * 切 shingle 集合。
	 *
	 * @param string $text 文本。
	 * @param string $lang 'en' 或 'cjk'。
	 * @return array<string,true> 用作集合（键去重，比 array_unique 快）。
	 */
	private static function shingles( $text, $lang ) {
		$t = mb_strtolower( wp_strip_tags_compat( $text ), 'UTF-8' );

		if ( 'cjk' === $lang ) {
			// 中日文没有词边界，按字符切。去掉空白与标点后取字符窗口。
			$t     = preg_replace( '/[\s\p{P}\p{S}]+/u', '', $t );
			$chars = preg_split( '//u', $t, -1, PREG_SPLIT_NO_EMPTY );
			$units = is_array( $chars ) ? $chars : array();
		} else {
			$t     = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $t );
			$units = preg_split( '/\s+/u', trim( $t ), -1, PREG_SPLIT_NO_EMPTY );
			$units = is_array( $units ) ? $units : array();
		}

		$w   = self::SHINGLE_W;
		$set = array();
		$n   = count( $units );

		if ( $n < $w ) {
			// 太短，整段当一个 shingle，避免返回空集合导致相似度恒为 0。
			if ( $n > 0 ) {
				$set[ implode( ' ', $units ) ] = true;
			}
			return $set;
		}

		for ( $i = 0; $i + $w <= $n; $i++ ) {
			$set[ implode( ' ', array_slice( $units, $i, $w ) ) ] = true;
		}

		return $set;
	}

	/**
	 * Jaccard 相似度 |A∩B| / |A∪B|。
	 *
	 * @param array<string,true> $a 集合 A。
	 * @param array<string,true> $b 集合 B。
	 * @return float 0..1
	 */
	private static function jaccard( array $a, array $b ) {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$inter = count( array_intersect_key( $a, $b ) );
		$union = count( $a ) + count( $b ) - $inter;
		return $union > 0 ? $inter / $union : 0.0;
	}
}
