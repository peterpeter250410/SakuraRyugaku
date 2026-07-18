<?php
/**
 * REST：落地页意向表单提交端点。
 * 路由：POST /wp-json/sa/v1/lead
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Rest_Leads {

	public static function register_routes() {
		register_rest_route(
			'sa/v1',
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * 权限：校验 REST nonce（防 CSRF），允许游客提交（落地页免注册留资）。
	 */
	public static function permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'sa_bad_nonce', __( '请求校验失败。', 'sa-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 处理提交：校验、防刷、落库、通知。
	 */
	public static function submit( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		// 蜜罐字段：正常用户不会填，机器人常会填。
		if ( ! empty( $params['website'] ) ) {
			// 静默丢弃，返回成功以迷惑机器人。
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// 简单频率限制：同一 IP 短时间内限制提交次数。
		if ( self::is_rate_limited() ) {
			return new WP_Error( 'sa_rate_limited', __( '提交过于频繁，请稍后再试。', 'sa-core' ), array( 'status' => 429 ) );
		}

		// 必填校验：姓名 + 联系方式 + 隐私同意
		$name          = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$contact_value = isset( $params['contact_value'] ) ? sanitize_text_field( $params['contact_value'] ) : '';
		$consent       = ! empty( $params['consent'] );

		if ( '' === $name || '' === $contact_value ) {
			return new WP_Error( 'sa_missing_fields', __( '请填写姓名与联系方式。', 'sa-core' ), array( 'status' => 400 ) );
		}
		if ( ! $consent ) {
			return new WP_Error( 'sa_no_consent', __( '请先同意隐私政策。', 'sa-core' ), array( 'status' => 400 ) );
		}

		// 联系方式格式校验（email 时校验格式）。
		$contact_type = isset( $params['contact_type'] ) ? sanitize_key( $params['contact_type'] ) : 'email';
		if ( 'email' === $contact_type && ! is_email( $contact_value ) ) {
			return new WP_Error( 'sa_bad_email', __( '邮箱格式不正确。', 'sa-core' ), array( 'status' => 400 ) );
		}

		$lead_id = SA_Lead_Repo::create(
			array(
				'session_key'    => isset( $params['session_key'] ) ? $params['session_key'] : '',
				'name'           => $name,
				'contact_type'   => $contact_type,
				'contact_value'  => $contact_value,
				'budget_min'     => isset( $params['budget_min'] ) ? $params['budget_min'] : 0,
				'budget_max'     => isset( $params['budget_max'] ) ? $params['budget_max'] : 0,
				'intended_major' => isset( $params['intended_major'] ) ? $params['intended_major'] : '',
				'extra'          => isset( $params['extra'] ) && is_array( $params['extra'] ) ? $params['extra'] : array(),
				'lp_variant'     => isset( $params['lp_variant'] ) ? $params['lp_variant'] : '',
				'utm_source'     => isset( $params['utm_source'] ) ? $params['utm_source'] : '',
				'utm_medium'     => isset( $params['utm_medium'] ) ? $params['utm_medium'] : '',
				'utm_campaign'   => isset( $params['utm_campaign'] ) ? $params['utm_campaign'] : '',
			)
		);

		if ( ! $lead_id ) {
			return new WP_Error( 'sa_save_failed', __( '提交失败，请重试。', 'sa-core' ), array( 'status' => 500 ) );
		}

		// 记录转化埋点事件。
		SA_Analytics_Repo::record(
			array(
				'event_type'   => 'form_submit',
				'session_key'  => isset( $params['session_key'] ) ? $params['session_key'] : '',
				'page_url'     => isset( $params['page_url'] ) ? $params['page_url'] : '',
				'utm_source'   => isset( $params['utm_source'] ) ? $params['utm_source'] : '',
				'utm_medium'   => isset( $params['utm_medium'] ) ? $params['utm_medium'] : '',
				'utm_campaign' => isset( $params['utm_campaign'] ) ? $params['utm_campaign'] : '',
				'meta'         => array( 'lead_id' => $lead_id ),
			)
		);

		// 通知运营/顾问有新线索。
		SA_Notifier::notify_new_lead( $lead_id, $name );

		self::mark_rate();

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'lead_id' => $lead_id,
				'message' => __( '提交成功，我们会尽快与您联系。', 'sa-core' ),
			),
			200
		);
	}

	/**
	 * 频率限制：基于 IP 的 transient，60 秒内最多 5 次。
	 */
	private static function is_rate_limited() {
		$key   = 'sa_lead_rate_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		return $count >= 5;
	}

	private static function mark_rate() {
		$key   = 'sa_lead_rate_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 60 );
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return sanitize_text_field( $ip );
	}
}
