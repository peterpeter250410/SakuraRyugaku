<?php
/**
 * 预算维度评分规则（P0）。
 *
 * 对比学生预算区间 [budget_min, budget_max] 与专业学费区间 [tuition_min, tuition_max]，
 * 输出归一化匹配度 [0,1]。评分逻辑见 MATCHING-ALGORITHM.md 第 4.1 节。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Budget_Rule implements SA_Rule_Interface {

	/**
	 * 规则键。
	 *
	 * @return string
	 */
	public function key() {
		return 'budget';
	}

	/**
	 * 计算预算维度得分。
	 *
	 * 情况 A：学费完全落入预算 → 1.0
	 * 情况 B：区间部分重叠      → 重叠比例
	 * 情况 C：学费高于预算上限  → 按超出幅度乘 penaltyFactor 衰减
	 * 情况 D：学费远低于预算    → 1.0（不惩罚便宜）
	 *
	 * @param array $student   学生 profile。
	 * @param array $program   专业行。
	 * @param array $threshold 阈值配置（penaltyFactor / hardExclude / hardExcludeRatio）。
	 * @return float [0,1]
	 */
	public function score( array $student, array $program, array $threshold ) {
		$b_min = isset( $student['budget_min'] ) ? (float) $student['budget_min'] : 0.0;
		$b_max = isset( $student['budget_max'] ) ? (float) $student['budget_max'] : 0.0;
		$t_min = isset( $program['tuition_min'] ) ? (float) $program['tuition_min'] : 0.0;
		$t_max = isset( $program['tuition_max'] ) ? (float) $program['tuition_max'] : 0.0;

		// 惩罚系数，默认 1.5。
		$penalty_factor = isset( $threshold['penaltyFactor'] ) ? (float) $threshold['penaltyFactor'] : 1.5;

		// 学生未填预算：信息不全，给中性分，不阻断匹配。
		if ( $b_max <= 0 ) {
			return 0.5;
		}

		// 专业学费缺失：无法判断，给中性分。
		if ( $t_max <= 0 ) {
			return 0.5;
		}

		// 规范化学费区间，容错 min>max。
		if ( $t_min > $t_max ) {
			$tmp   = $t_min;
			$t_min = $t_max;
			$t_max = $tmp;
		}

		// 情况 A：学费完全落入预算。
		if ( $t_max <= $b_max && $t_min >= $b_min ) {
			return 1.0;
		}

		// 情况 D：学费整体低于预算下限（比预算还便宜）→ 视为满分。
		if ( $t_max <= $b_min ) {
			return 1.0;
		}

		// 情况 C：学费整体高于预算上限 → 按超出幅度衰减。
		if ( $t_min > $b_max ) {
			$overflow = ( $t_min - $b_max ) / $b_max;

			// 硬排除：超出比例过大时可直接排除（返回 0）。
			$hard_exclude = ! empty( $threshold['hardExclude'] );
			$hard_ratio   = isset( $threshold['hardExcludeRatio'] ) ? (float) $threshold['hardExcludeRatio'] : 0.5;
			if ( $hard_exclude && $overflow >= $hard_ratio ) {
				return 0.0;
			}

			$score = 1.0 - ( $overflow * $penalty_factor );
			return max( 0.0, min( 1.0, $score ) );
		}

		// 情况 B：区间部分重叠 → 重叠长度 / 学费区间长度（重叠比例）。
		$overlap_lo = max( $b_min, $t_min );
		$overlap_hi = min( $b_max, $t_max );
		$overlap    = max( 0.0, $overlap_hi - $overlap_lo );

		$tuition_span = $t_max - $t_min;
		if ( $tuition_span <= 0 ) {
			// 学费为单点（min==max），落在预算内即满分，否则前面已处理超支/低于。
			return ( $t_min >= $b_min && $t_min <= $b_max ) ? 1.0 : 0.0;
		}

		$ratio = $overlap / $tuition_span;
		return max( 0.0, min( 1.0, $ratio ) );
	}
}
