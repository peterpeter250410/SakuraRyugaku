<?php
/**
 * REST：资料上传端点（multipart）。
 * 路由：POST /wp-json/sa/v1/upload-doc
 *
 * 学生在专属上传页按每校资料清单逐项提交：文件走加密上传管线，
 * 文本走 store_text（同样加密落库）。
 *
 * 安全：登录 + nonce + Access_Guard 本人 + doc_type 属于该 selection 对应
 * 院校的资料清单（防越权提交任意 doc_type）。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Rest_Upload {

	public static function register_routes() {
		register_rest_route(
			'sa/v1',
			'/upload-doc',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * 权限：必须登录 + REST nonce。
	 */
	public static function permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'sa_not_logged_in', __( '请先通过专属链接进入。', 'sa-core' ), array( 'status' => 401 ) );
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'sa_bad_nonce', __( '请求校验失败。', 'sa-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 处理上传。
	 */
	public static function upload( WP_REST_Request $request ) {
		$current = get_current_user_id();

		// 目标用户：默认当前用户；若显式传入需与当前一致（防越权）。
		$user_id = $request->get_param( 'user_id' );
		$user_id = $user_id ? absint( $user_id ) : $current;

		if ( $user_id !== $current || ! SA_Access_Guard::can_access_student( $user_id ) ) {
			return new WP_Error( 'sa_forbidden', __( '无权提交他人资料。', 'sa-core' ), array( 'status' => 403 ) );
		}

		$selection_id = absint( $request->get_param( 'selection_id' ) );
		$doc_type     = sanitize_key( $request->get_param( 'doc_type' ) );

		if ( ! $selection_id || '' === $doc_type ) {
			return new WP_Error( 'sa_missing', __( '缺少必要参数。', 'sa-core' ), array( 'status' => 400 ) );
		}

		// 校验 selection 归属本人，并取其 school_id。
		$selection = self::get_owned_selection( $selection_id, $user_id );
		if ( ! $selection ) {
			return new WP_Error( 'sa_bad_selection', __( '选校记录无效。', 'sa-core' ), array( 'status' => 403 ) );
		}

		// 校验 doc_type ∈ 该院校资料清单（防越权提交任意类型）。
		$required = SA_School_Repo::get_required_docs( (int) $selection['school_id'] );
		$doc_meta = null;
		foreach ( $required as $item ) {
			if ( $item['key'] === $doc_type ) {
				$doc_meta = $item;
				break;
			}
		}
		if ( ! $doc_meta ) {
			return new WP_Error( 'sa_bad_doctype', __( '该资料项不在清单内。', 'sa-core' ), array( 'status' => 400 ) );
		}

		// 文本类型走 store_text。
		if ( 'text' === $doc_meta['type'] ) {
			$text   = (string) $request->get_param( 'text' );
			$result = SA_Doc_Repo::store_text( $user_id, $text, $doc_type, $selection_id );
		} else {
			$files = $request->get_file_params();
			if ( empty( $files['file'] ) ) {
				return new WP_Error( 'sa_no_file', __( '请选择要上传的文件。', 'sa-core' ), array( 'status' => 400 ) );
			}
			$result = SA_Doc_Repo::handle_upload( $user_id, $files['file'], $doc_type, $selection_id );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'doc_id'   => (int) $result,
				'doc_type' => $doc_type,
				'status'   => 'uploaded',
				'message'  => __( '已成功提交。', 'sa-core' ),
			),
			200
		);
	}

	/**
	 * 取归属本人的选校记录（含 school_id）。
	 *
	 * @param int $selection_id 选校 ID。
	 * @param int $user_id      用户 ID。
	 * @return array|null
	 */
	private static function get_owned_selection( $selection_id, $user_id ) {
		global $wpdb;
		$table = SA_DB::table( 'selections' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
				absint( $selection_id ),
				absint( $user_id )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}
}
