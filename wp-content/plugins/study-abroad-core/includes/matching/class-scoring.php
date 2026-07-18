<?php
/**
 * 加权评分器。
 *
 * 从 sa_match_rules 读取「已启用」的维度及其权重/阈值，注册对应 Rule 实例，
 * 计算某学生对某专业的总分与各维度明细。是匹配主流程的核心计算组件。
 *
 * 总分公式：TotalScore = Σ ( weight_i × ruleScore_i )，见 MATCHING-ALGORITHM.md 第 3 节。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Scoring {

	/**
	 * 已启用规则配置数组。
	 *
	 * 每项结构：array( 'key' => string, 'weight' => float, 'threshold' => array, 'rule' => SA_Rule_Interface )
	 *
	 * @var array
	 */
	private $rules = array();

	/**
	 * 启用维度权重之和，用于展示归一化（maxPossible）。
	 *
	 * @var float
	 */
	private $max_possible = 0.0;

	/**
	 * 构造时载入启用规则。
	 */
	public function __construct() {
		$this->load_rules();
	}

	/**
	 * rule_key → Rule 类名 的可用规则映射。
	 *
	 * 新增维度：实现 SA_Rule_Interface 并在此登记类名，再于 sa_match_rules 开启即可。
	 *
	 * @return array
	 */
	private function rule_registry() {
		return array(
			'budget' => 'SA_Budget_Rule',
			'major'  => 'SA_Major_Rule',
		);
	}

	/**
	 * 从 sa_match_rules 读取 enabled=1 且 weight>0 的规则，实例化并缓存。
	 */
	private function load_rules() {
		global $wpdb;

		$table    = SA_DB::table( 'match_rules' );
		$registry = $this->rule_registry();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rule_key, weight, threshold FROM {$table} WHERE enabled = %d AND weight > 0 ORDER BY id ASC",
				1
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$key = isset( $row['rule_key'] ) ? $row['rule_key'] : '';

			// 无对应实现类的规则跳过（配置存在但代码未注册）。
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}

			$class = $registry[ $key ];
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$weight    = isset( $row['weight'] ) ? (float) $row['weight'] : 0.0;
			$threshold = self::decode_threshold( isset( $row['threshold'] ) ? $row['threshold'] : null );

			$instance = new $class();
			if ( ! ( $instance instanceof SA_Rule_Interface ) ) {
				continue;
			}

			$this->rules[] = array(
				'key'       => $key,
				'weight'    => $weight,
				'threshold' => $threshold,
				'rule'      => $instance,
			);

			$this->max_possible += $weight;
		}
	}

	/**
	 * 是否存在可用于计算的启用规则。
	 *
	 * @return bool
	 */
	public function has_rules() {
		return ! empty( $this->rules );
	}

	/**
	 * 启用维度权重之和（maxPossible）。
	 *
	 * @return float
	 */
	public function get_max_possible() {
		return $this->max_possible;
	}

	/**
	 * 生成当前规则快照（存入 match_results.rule_snapshot），便于结果可追溯。
	 *
	 * @return array
	 */
	public function get_rule_snapshot() {
		$snapshot = array();
		foreach ( $this->rules as $r ) {
			$snapshot[] = array(
				'key'       => $r['key'],
				'weight'    => $r['weight'],
				'threshold' => $r['threshold'],
			);
		}
		return $snapshot;
	}

	/**
	 * 计算某学生对某专业的总分与各维度明细。
	 *
	 * @param array $student 学生 profile。
	 * @param array $program 专业行（含院校字段）。
	 * @return array{total:float, detail:array} total 为加权总分；detail 为各维度明细。
	 */
	public function score_program( array $student, array $program ) {
		$total  = 0.0;
		$detail = array();

		foreach ( $this->rules as $r ) {
			/** @var SA_Rule_Interface $rule */
			$rule = $r['rule'];

			$raw = (float) $rule->score( $student, $program, $r['threshold'] );
			// 防御性夹取到 [0,1]。
			$raw = max( 0.0, min( 1.0, $raw ) );

			$weighted = $r['weight'] * $raw;
			$total   += $weighted;

			$detail[ $r['key'] ] = array(
				'raw'      => round( $raw, 4 ),
				'weight'   => $r['weight'],
				'weighted' => round( $weighted, 4 ),
			);
		}

		return array(
			'total'  => round( $total, 4 ),
			'detail' => $detail,
		);
	}

	/**
	 * 解码 threshold JSON 为数组。
	 *
	 * @param mixed $raw 原始 threshold 值。
	 * @return array
	 */
	private static function decode_threshold( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}
}
