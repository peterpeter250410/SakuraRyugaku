<?php
/**
 * REST：AI 诊断（即时匹配）端点。
 * 路由：POST /wp-json/sa/v1/diagnose
 *
 * 复用 SA_Scoring（零 DB 依赖，仅吃内存数组）对 active 专业逐条评分，
 * 取 Top N 返回。不落库、不建号——只是即时展示匹配结果。
 * 安全基线照抄 SA_Rest_Leads：nonce + 蜜罐 + IP 限流。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Rest_Diagnose {

	/** 返回结果数量上限。 */
	const TOP_N = 6;

	public static function register_routes() {
		register_rest_route(
			'sa/v1',
			'/diagnose',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'diagnose' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * 权限：校验 REST nonce（防 CSRF），允许游客。
	 */
	public static function permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'sa_bad_nonce', __( '请求校验失败。', 'sa-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 处理诊断请求。
	 */
	public static function diagnose( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		// 蜜罐：静默成功迷惑机器人。
		if ( ! empty( $params['website'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'results' => array(), 'count' => 0 ), 200 );
		}

		// 独立限流：60 秒 20 次。
		if ( self::is_rate_limited() ) {
			return new WP_Error( 'sa_rate_limited', __( '操作过于频繁，请稍后再试。', 'sa-core' ), array( 'status' => 429 ) );
		}
		self::mark_rate();

		// 构造内存学生档案（不落库）。
		$student = self::build_student( $params );

		$scoring = new SA_Scoring();
		if ( ! $scoring->has_rules() ) {
			return new WP_Error(
				'sa_no_rules',
				__( '匹配规则未配置，请联系管理员。', 'sa-core' ),
				array( 'status' => 503 )
			);
		}

		$programs = SA_School_Repo::get_active_programs();
		if ( empty( $programs ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'results' => array(),
					'count'   => 0,
					'message' => __( '暂无可匹配的院校，请稍后再试。', 'sa-core' ),
				),
				200
			);
		}

		$max_possible = $scoring->get_max_possible();
		$max_possible = $max_possible > 0 ? $max_possible : 1;

		$scored = array();
		foreach ( $programs as $program ) {
			$result  = $scoring->score_program( $student, $program );
			$percent = (int) round( ( $result['total'] / $max_possible ) * 100 );
			$percent = max( 0, min( 100, $percent ) );

			$scored[] = array(
				'school_id'    => (int) $program['school_id'],
				'program_id'   => (int) $program['program_id'],
				'school_name'  => $program['school_name'],
				'program_name' => $program['program_name'],
				'region'       => trim( $program['school_region'] . ' ' . $program['school_city'] ),
				'tuition_min'  => (int) $program['tuition_min'],
				'tuition_max'  => (int) $program['tuition_max'],
				'percent'      => $percent,
				'_total'       => $result['total'],
			);
		}

		// 按总分降序取 Top N。
		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['_total'] === $b['_total'] ) {
					return 0;
				}
				return ( $a['_total'] < $b['_total'] ) ? 1 : -1;
			}
		);
		$top = array_slice( $scored, 0, self::TOP_N );

		// 清理内部字段并格式化学费显示。
		$results = array();
		foreach ( $top as $row ) {
			unset( $row['_total'] );
			$row['tuition_label'] = self::format_tuition( $row['tuition_min'], $row['tuition_max'] );
			$results[]            = $row;
		}

		// 埋点（不含个人身份信息）。
		SA_Analytics_Repo::record(
			array(
				'event_type'  => 'diagnose',
				'session_key' => isset( $params['session_key'] ) ? sanitize_text_field( $params['session_key'] ) : '',
				'page_url'    => isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : '',
				'meta'        => array( 'count' => count( $results ) ),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'results' => $results,
				'count'   => count( $results ),
			),
			200
		);
	}

	/**
	 * 从请求参数构造内存学生档案（供 SA_Scoring）。
	 *
	 * 仅用 budget_min/max + intended_major_tags 两个维度，
	 * 与 seed 的规则（budget/major）对齐。
	 *
	 * @param array $params 请求参数。
	 * @return array
	 */
	private static function build_student( $params ) {
		list( $budget_min, $budget_max ) = self::parse_budget( $params );

		$major = isset( $params['intended_major'] ) ? sanitize_text_field( $params['intended_major'] ) : '';
		$tags  = array();
		if ( '' !== $major ) {
			// 按逗号/中文逗号/空白切分为标签。
			$parts = preg_split( '/[\s,，、]+/u', $major );
			foreach ( (array) $parts as $p ) {
				$p = trim( $p );
				if ( '' !== $p ) {
					$tags[] = $p;
				}
			}
		}

		return array(
			'budget_min'          => $budget_min,
			'budget_max'          => $budget_max,
			'intended_major_tags' => array_values( array_unique( $tags ) ),
		);
	}

	/**
	 * 解析预算：优先 budget_range（"min-max" 万円），否则 budget_min/max（日元）。
	 *
	 * @param array $params 请求参数。
	 * @return array{0:int,1:int} [min_yen, max_yen]
	 */
	private static function parse_budget( $params ) {
		if ( ! empty( $params['budget_range'] ) && is_string( $params['budget_range'] ) ) {
			$range = sanitize_text_field( $params['budget_range'] );
			if ( preg_match( '/^(\d+)-(\d+)$/', $range, $m ) ) {
				// 表单单位为「万円」，换算为日元。
				return array( (int) $m[1] * 10000, (int) $m[2] * 10000 );
			}
		}

		$min = isset( $params['budget_min'] ) ? absint( $params['budget_min'] ) : 0;
		$max = isset( $params['budget_max'] ) ? absint( $params['budget_max'] ) : 0;
		return array( $min, $max );
	}

	/**
	 * 格式化学费展示。
	 *
	 * @param int $min 最低（日元）。
	 * @param int $max 最高（日元）。
	 * @return string
	 */
	private static function format_tuition( $min, $max ) {
		if ( ! $min && ! $max ) {
			return __( '要問合せ', 'sa-core' );
		}
		$fmt = function ( $yen ) {
			return number_format( $yen ) . __( '円/年', 'sa-core' );
		};
		if ( $min === $max ) {
			return $fmt( $min );
		}
		return $fmt( $min ) . ' 〜 ' . $fmt( $max );
	}

	/**
	 * 频率限制：基于 IP 的 transient，60 秒内最多 20 次。
	 */
	private static function is_rate_limited() {
		$key   = 'sa_diag_rate_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		return $count >= 20;
	}

	private static function mark_rate() {
		$key   = 'sa_diag_rate_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 60 );
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip );
	}
}
