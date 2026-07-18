<?php
/**
 * REST：自建埋点事件上报端点。
 * 路由：POST /wp-json/sa/v1/track
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Rest_Analytics {

	public static function register_routes() {
		register_rest_route(
			'sa/v1',
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * 权限：校验 REST nonce。埋点允许游客上报。
	 */
	public static function permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'sa_bad_nonce', __( '请求校验失败。', 'sa-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 记录埋点事件（事件类型白名单在 repo 内校验）。
	 */
	public static function track( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$ok = SA_Analytics_Repo::record( is_array( $params ) ? $params : array() );

		return new WP_REST_Response( array( 'ok' => (bool) $ok ), $ok ? 200 : 400 );
	}
}
