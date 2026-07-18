<?php
/**
 * 专业维度评分规则（P0）。
 *
 * 对比学生意向专业标签集合与专业标签集合，输出集合相似度 [0,1]。
 * 支持 coverage（覆盖率，默认）与 jaccard 两种模式，见 MATCHING-ALGORITHM.md 第 4.2 节。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Major_Rule implements SA_Rule_Interface {

	/**
	 * 规则键。
	 *
	 * @return string
	 */
	public function key() {
		return 'major';
	}

	/**
	 * 计算专业维度得分。
	 *
	 * coverage 模式：|S ∩ P| / |S|（学生意向被专业覆盖的比例）。
	 * jaccard  模式：|S ∩ P| / |S ∪ P|。
	 * 学生无标签兜底：得分记为中性 0.5。
	 *
	 * @param array $student   学生 profile（含 intended_major_tags）。
	 * @param array $program   专业行（含 major_tags）。
	 * @param array $threshold 阈值配置（mode）。
	 * @return float [0,1]
	 */
	public function score( array $student, array $program, array $threshold ) {
		$student_tags = self::parse_tags( isset( $student['intended_major_tags'] ) ? $student['intended_major_tags'] : null );
		$program_tags = self::parse_tags( isset( $program['major_tags'] ) ? $program['major_tags'] : null );

		// 学生未提供标签：无法判断意向，给中性分兜底，不阻断匹配。
		if ( empty( $student_tags ) ) {
			return 0.5;
		}

		// 专业无标签：无法覆盖学生意向，视为不匹配。
		if ( empty( $program_tags ) ) {
			return 0.0;
		}

		// 交集大小。
		$intersect = array_values( array_intersect( $student_tags, $program_tags ) );
		$inter_n   = count( $intersect );

		if ( 0 === $inter_n ) {
			return 0.0;
		}

		$mode = isset( $threshold['mode'] ) ? (string) $threshold['mode'] : 'coverage';

		if ( 'jaccard' === $mode ) {
			// 并集大小。
			$union_n = count( array_unique( array_merge( $student_tags, $program_tags ) ) );
			if ( $union_n <= 0 ) {
				return 0.0;
			}
			return max( 0.0, min( 1.0, $inter_n / $union_n ) );
		}

		// 默认 coverage：交集 / 学生标签数。
		$student_n = count( $student_tags );
		if ( $student_n <= 0 ) {
			return 0.5;
		}
		return max( 0.0, min( 1.0, $inter_n / $student_n ) );
	}

	/**
	 * 将标签字段解析为去重、去空的字符串数组。
	 *
	 * 支持三种输入：已是数组、JSON 字符串、逗号分隔字符串。
	 *
	 * @param mixed $raw 原始标签值。
	 * @return array 标签字符串数组。
	 */
	private static function parse_tags( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}

		$tags = array();

		if ( is_array( $raw ) ) {
			$tags = $raw;
		} elseif ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$tags = $decoded;
			} else {
				// 非 JSON：按逗号/中文逗号切分兜底。
				$tags = preg_split( '/[,，]/u', $raw );
			}
		}

		// 规范化：转字符串、去首尾空白、过滤空值、去重。
		$clean = array();
		foreach ( (array) $tags as $tag ) {
			if ( is_array( $tag ) ) {
				continue;
			}
			$tag = trim( (string) $tag );
			if ( '' !== $tag ) {
				$clean[] = $tag;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
