<?php
/**
 * 匹配主流程。
 *
 * 读取学生 profile → 遍历所有 active 专业逐一评分 → 排序取 Top N →
 * 生成 batch_id 并将结果写入 sa_match_results（含 score_detail、rule_snapshot、rank_no）。
 *
 * 流程规范见 MATCHING-ALGORITHM.md 第 6 节。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Matcher {

	/**
	 * 默认取前多少条结果。
	 */
	const TOP_N = 20;

	/**
	 * 为指定学生执行一次完整匹配。
	 *
	 * @param int $user_id 学生 WP 用户 ID。
	 * @return array{
	 *     batch_id:string,
	 *     count:int,
	 *     max_possible:float,
	 *     results:array
	 * }|WP_Error 成功返回结果结构；无 profile/无规则/无候选时返回 WP_Error。
	 */
	public function run_for_student( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'sa_invalid_user', __( '无效的用户。', 'sa-core' ) );
		}

		// 1. 读取学生 profile。
		$student = $this->get_student_profile( $user_id );
		if ( ! $student ) {
			return new WP_Error( 'sa_no_profile', __( '未找到学生档案，无法匹配。', 'sa-core' ) );
		}

		// 2. 载入启用规则。
		$scoring = new SA_Scoring();
		if ( ! $scoring->has_rules() ) {
			return new WP_Error( 'sa_no_rules', __( '未配置可用的匹配规则。', 'sa-core' ) );
		}

		// 3. 取候选专业（active 院校下的 active 专业，含院校信息）。
		$programs = SA_School_Repo::get_active_programs();
		if ( empty( $programs ) ) {
			return new WP_Error( 'sa_no_programs', __( '暂无可匹配的院校专业。', 'sa-core' ) );
		}

		// 4. 遍历打分。
		$scored = array();
		foreach ( $programs as $program ) {
			$result = $scoring->score_program( $student, $program );

			$scored[] = array(
				'school_id'   => isset( $program['school_id'] ) ? absint( $program['school_id'] ) : 0,
				'program_id'  => isset( $program['program_id'] ) ? absint( $program['program_id'] ) : 0,
				'total_score' => (float) $result['total'],
				'score_detail'=> $result['detail'],
			);
		}

		// 5. 按总分降序排序，取 Top N。
		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['total_score'] === $b['total_score'] ) {
					return 0;
				}
				return ( $a['total_score'] < $b['total_score'] ) ? 1 : -1;
			}
		);

		$top = array_slice( $scored, 0, self::TOP_N );

		// 6. 生成 batch_id 并写入结果。
		$batch_id      = wp_generate_uuid4();
		$rule_snapshot = $scoring->get_rule_snapshot();
		$max_possible  = $scoring->get_max_possible();

		$this->persist_results( $user_id, $batch_id, $top, $rule_snapshot );

		return array(
			'batch_id'     => $batch_id,
			'count'        => count( $top ),
			'max_possible' => $max_possible,
			'results'      => $top,
		);
	}

	/**
	 * 读取最近一批匹配结果（按 rank_no 升序）。
	 *
	 * @param int $user_id 学生 WP 用户 ID。
	 * @return array 结果行数组（ARRAY_A，含解码后的 score_detail），无结果返回空数组。
	 */
	public function get_latest_results( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$table = SA_DB::table( 'match_results' );

		// 先定位该用户最近一批的 batch_id。
		$batch_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT batch_id FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT 1",
				$user_id
			)
		);

		if ( ! $batch_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND batch_id = %s ORDER BY rank_no ASC",
				$user_id,
				$batch_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// 解码 JSON 字段，便于上层直接使用。
		foreach ( $rows as &$row ) {
			$row['score_detail']  = self::decode_json( isset( $row['score_detail'] ) ? $row['score_detail'] : null );
			$row['rule_snapshot'] = self::decode_json( isset( $row['rule_snapshot'] ) ? $row['rule_snapshot'] : null );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * 读取学生扩展档案。
	 *
	 * @param int $user_id 学生 WP 用户 ID。
	 * @return array|null
	 */
	private function get_student_profile( $user_id ) {
		global $wpdb;

		$table = SA_DB::table( 'student_profiles' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", absint( $user_id ) ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * 将 Top N 结果批量写入 sa_match_results，rank_no 从 1 递增。
	 *
	 * @param int    $user_id       学生 WP 用户 ID。
	 * @param string $batch_id      本批次 UUID。
	 * @param array  $results       已排序的结果数组。
	 * @param array  $rule_snapshot 规则快照。
	 */
	private function persist_results( $user_id, $batch_id, array $results, array $rule_snapshot ) {
		global $wpdb;

		$table          = SA_DB::table( 'match_results' );
		$now            = SA_DB::now();
		$snapshot_json  = wp_json_encode( $rule_snapshot );
		$rank           = 1;

		foreach ( $results as $item ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'       => absint( $user_id ),
					'batch_id'      => $batch_id,
					'school_id'     => absint( $item['school_id'] ),
					'program_id'    => absint( $item['program_id'] ),
					'total_score'   => (float) $item['total_score'],
					'score_detail'  => wp_json_encode( $item['score_detail'] ),
					'rank_no'       => $rank,
					'rule_snapshot' => $snapshot_json,
					'created_at'    => $now,
				),
				array( '%d', '%s', '%d', '%d', '%f', '%s', '%d', '%s', '%s' )
			);
			$rank++;
		}
	}

	/**
	 * 安全解码 JSON 字符串为数组。
	 *
	 * @param mixed $raw 原始值。
	 * @return array
	 */
	private static function decode_json( $raw ) {
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
