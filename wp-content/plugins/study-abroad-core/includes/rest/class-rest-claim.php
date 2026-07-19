<?php
/**
 * REST：选校建号（claim）端点。
 * 路由：POST /wp-json/sa/v1/claim
 *
 * 用户在诊断结果中点「选择该校」时触发：
 *  1. 建号或复用学生账号（SA_Student_Onboard）。
 *  2. 落一条选校记录（selections，唯一键防重）。
 *  3. 同步落线索并通知（线索不丢）。
 *  4. 签发免密 token，返回专属上传页链接。
 *
 * 安全：nonce + 蜜罐 + 严限流(60s/3) + consent 强校验 + school/program 存在校验。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Rest_Claim {

	public static function register_routes() {
		register_rest_route(
			'sa/v1',
			'/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'claim' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * 权限：REST nonce（防 CSRF），允许游客（内部建号）。
	 */
	public static function permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'sa_bad_nonce', __( '请求校验失败。', 'sa-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 处理选校建号。
	 */
	public static function claim( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		// 蜜罐：静默成功。
		if ( ! empty( $params['website'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// 严格限流：60 秒 3 次（会建号，重点防刷）。
		if ( self::is_rate_limited() ) {
			return new WP_Error( 'sa_rate_limited', __( '操作过于频繁，请稍后再试。', 'sa-core' ), array( 'status' => 429 ) );
		}

		// 必填 + consent 强校验。
		$name          = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$contact_type  = isset( $params['contact_type'] ) ? sanitize_key( $params['contact_type'] ) : 'email';
		$contact_value = isset( $params['contact_value'] ) ? sanitize_text_field( $params['contact_value'] ) : '';
		$consent       = ! empty( $params['consent'] );

		if ( '' === $name || '' === $contact_value ) {
			return new WP_Error( 'sa_missing_fields', __( '请填写姓名与联系方式。', 'sa-core' ), array( 'status' => 400 ) );
		}
		if ( ! $consent ) {
			return new WP_Error( 'sa_no_consent', __( '请先同意隐私政策。', 'sa-core' ), array( 'status' => 400 ) );
		}
		if ( 'email' === $contact_type && ! is_email( $contact_value ) ) {
			return new WP_Error( 'sa_bad_email', __( '邮箱格式不正确。', 'sa-core' ), array( 'status' => 400 ) );
		}

		// 校验 school/program 存在且属于同一院校。
		$school_id  = isset( $params['school_id'] ) ? absint( $params['school_id'] ) : 0;
		$program_id = isset( $params['program_id'] ) ? absint( $params['program_id'] ) : 0;

		$school = SA_School_Repo::get_school( $school_id );
		if ( ! $school ) {
			return new WP_Error( 'sa_bad_school', __( '所选院校不存在。', 'sa-core' ), array( 'status' => 400 ) );
		}
		$program = $program_id ? SA_School_Repo::get_program( $program_id ) : null;
		if ( $program && (int) $program['school_id'] !== $school_id ) {
			return new WP_Error( 'sa_bad_program', __( '专业与院校不匹配。', 'sa-core' ), array( 'status' => 400 ) );
		}

		self::mark_rate();

		// 解析预算（供 profile）。
		list( $budget_min, $budget_max ) = self::parse_budget( $params );
		$major = isset( $params['intended_major'] ) ? sanitize_text_field( $params['intended_major'] ) : '';

		// 建号 / 复用。
		$user_id = SA_Student_Onboard::create_or_get_student(
			$name,
			$contact_type,
			$contact_value,
			array(
				'budget_min'     => $budget_min,
				'budget_max'     => $budget_max,
				'intended_major' => $major,
				'consent'        => 1,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'sa_onboard_failed', __( '建号失败，请重试。', 'sa-core' ), array( 'status' => 500 ) );
		}

		// 落选校记录（唯一键防重）。
		$selection_id = self::insert_selection( $user_id, $school_id, $program_id );

		// 同步落线索 + 通知（线索不丢）。
		$lead_id = SA_Lead_Repo::create(
			array(
				'session_key'    => isset( $params['session_key'] ) ? $params['session_key'] : '',
				'name'           => $name,
				'contact_type'   => $contact_type,
				'contact_value'  => $contact_value,
				'budget_min'     => $budget_min,
				'budget_max'     => $budget_max,
				'intended_major' => $major,
				'extra'          => array(
					'source'     => 'claim',
					'school_id'  => $school_id,
					'program_id' => $program_id,
				),
				'lp_variant'     => isset( $params['lp_variant'] ) ? $params['lp_variant'] : '',
				'utm_source'     => isset( $params['utm_source'] ) ? $params['utm_source'] : '',
				'utm_medium'     => isset( $params['utm_medium'] ) ? $params['utm_medium'] : '',
				'utm_campaign'   => isset( $params['utm_campaign'] ) ? $params['utm_campaign'] : '',
			)
		);
		if ( $lead_id ) {
			SA_Notifier::notify_new_lead( $lead_id, $name );
		}

		// 埋点。
		SA_Analytics_Repo::record(
			array(
				'event_type'  => 'select_school',
				'session_key' => isset( $params['session_key'] ) ? sanitize_text_field( $params['session_key'] ) : '',
				'meta'        => array( 'school_id' => $school_id, 'program_id' => $program_id ),
			)
		);

		// 签发免密 token，拼上传页链接。
		$raw      = SA_Student_Onboard::issue_login_token( $user_id, $selection_id );
		$redirect = add_query_arg(
			array(
				'u' => $user_id,
				'k' => $raw,
			),
			home_url( '/upload/' )
		);

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'redirect' => $redirect,
				'message'  => __( '已为您创建专属资料提交页。', 'sa-core' ),
			),
			200
		);
	}

	/**
	 * 插入选校记录（唯一键 uniq_choice 防重复），返回 selection_id。
	 *
	 * @param int $user_id    用户 ID。
	 * @param int $school_id  院校 ID。
	 * @param int $program_id 专业 ID。
	 * @return int
	 */
	private static function insert_selection( $user_id, $school_id, $program_id ) {
		global $wpdb;
		$table = SA_DB::table( 'selections' );
		$now   = SA_DB::now();

		// 已存在则返回既有 id。
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND school_id = %d AND program_id = %d",
				absint( $user_id ),
				absint( $school_id ),
				absint( $program_id )
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'    => absint( $user_id ),
				'school_id'  => absint( $school_id ),
				'program_id' => absint( $program_id ),
				'status'     => 'selected',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * 解析预算：优先 budget_range（"min-max" 万円），否则 budget_min/max（日元）。
	 *
	 * @param array $params 请求参数。
	 * @return array{0:int,1:int}
	 */
	private static function parse_budget( $params ) {
		if ( ! empty( $params['budget_range'] ) && is_string( $params['budget_range'] ) ) {
			$range = sanitize_text_field( $params['budget_range'] );
			if ( preg_match( '/^(\d+)-(\d+)$/', $range, $m ) ) {
				return array( (int) $m[1] * 10000, (int) $m[2] * 10000 );
			}
		}
		$min = isset( $params['budget_min'] ) ? absint( $params['budget_min'] ) : 0;
		$max = isset( $params['budget_max'] ) ? absint( $params['budget_max'] ) : 0;
		return array( $min, $max );
	}

	/**
	 * 频率限制：基于 IP 的 transient，60 秒内最多 3 次。
	 */
	private static function is_rate_limited() {
		$key   = 'sa_claim_rate_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		return $count >= 3;
	}

	private static function mark_rate() {
		$key   = 'sa_claim_rate_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 60 );
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip );
	}
}
