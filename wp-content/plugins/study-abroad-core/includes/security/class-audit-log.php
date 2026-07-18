<?php
/**
 * 审计日志：记录敏感操作（资料查看/下载、审核、权限变更等）。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Audit_Log {

	/**
	 * 记录一条审计日志。
	 *
	 * @param string $action      动作，如 'download_doc'。
	 * @param string $object_type 对象类型，如 'document'。
	 * @param int    $object_id   对象 ID。
	 * @param array  $meta        附加信息。
	 */
	public static function record( $action, $object_type = '', $object_id = 0, $meta = array() ) {
		global $wpdb;

		$wpdb->insert(
			SA_DB::table( 'audit_logs' ),
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => sanitize_key( $action ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => absint( $object_id ),
				'ip'          => self::client_ip(),
				'meta'        => $meta ? wp_json_encode( $meta ) : null,
				'created_at'  => SA_DB::now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * 获取客户端 IP（考虑常见反代头，仅取首个）。
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip    = trim( $parts[0] );
		}
		return substr( sanitize_text_field( $ip ), 0, 45 );
	}
}
