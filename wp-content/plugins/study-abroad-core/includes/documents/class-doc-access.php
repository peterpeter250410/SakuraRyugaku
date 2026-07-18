<?php
/**
 * 资料下载端点：鉴权后解密并流式输出，不暴露物理路径。
 *
 * 流程（见 SECURITY-DESIGN.md 第 2.2 节）：
 *  1. 校验 nonce（CSRF 防护）。
 *  2. 取文档记录，SA_Access_Guard::require_access(文档 owner) 强制鉴权。
 *  3. 读密文文件（tag . cipher），切分后调 SA_Doc_Crypto 解密。
 *  4. 校验明文 SHA-256 checksum（完整性）。
 *  5. 设置正确 Content-Type / Content-Disposition，流式输出。
 *  6. SA_Audit_Log 记录 download_doc，随后 exit。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Doc_Access {

	/**
	 * nonce action 前缀。
	 */
	const NONCE_ACTION = 'sa_doc_download';

	/**
	 * 生成某文档的下载链接（带 nonce）。
	 *
	 * @param int $doc_id 文档 ID。
	 * @return string
	 */
	public static function download_url( $doc_id ) {
		$doc_id = absint( $doc_id );
		$nonce  = wp_create_nonce( self::NONCE_ACTION . '_' . $doc_id );

		return add_query_arg(
			array(
				'sa_doc'    => $doc_id,
				'_sa_nonce' => $nonce,
			),
			home_url( '/' )
		);
	}

	/**
	 * 若请求命中下载参数则处理下载，否则直接返回。
	 * 建议挂载到较早的 hook（如 template_redirect / init）。
	 */
	public static function maybe_handle_download() {
		if ( ! isset( $_GET['sa_doc'] ) ) {
			return;
		}

		$doc_id = absint( wp_unslash( $_GET['sa_doc'] ) );
		if ( ! $doc_id ) {
			wp_die( esc_html__( '无效的资料标识。', 'sa-core' ), 400 );
		}

		// 必须登录。
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( '请先登录。', 'sa-core' ), 401 );
		}

		// 校验 nonce（CSRF）。
		$nonce = isset( $_GET['_sa_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_sa_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $doc_id ) ) {
			wp_die( esc_html__( '安全校验失败或链接已过期。', 'sa-core' ), 403 );
		}

		// 取记录。
		$doc = SA_Doc_Repo::get( $doc_id );
		if ( ! $doc ) {
			wp_die( esc_html__( '资料不存在。', 'sa-core' ), 404 );
		}

		// 强制鉴权：本人 / 被分配顾问 / 管理员。越权将被 require_access 终止并记日志。
		SA_Access_Guard::require_access( (int) $doc['user_id'] );

		// 读取密文文件。
		$abs_path = SA_Doc_Repo::abs_path( $doc['stored_path'] );
		if ( ! is_readable( $abs_path ) ) {
			wp_die( esc_html__( '资料文件缺失。', 'sa-core' ), 404 );
		}

		$stored = file_get_contents( $abs_path );
		if ( false === $stored ) {
			wp_die( esc_html__( '读取资料失败。', 'sa-core' ), 500 );
		}

		// 切分 tag 与密文。
		$parts = SA_Doc_Crypto::unpack_stored( $stored );
		if ( false === $parts ) {
			wp_die( esc_html__( '资料数据损坏。', 'sa-core' ), 500 );
		}

		// 解密（enc_iv 为原始二进制）。
		$plaintext = SA_Doc_Crypto::decrypt( $parts['cipher'], $doc['enc_iv'], $parts['tag'] );
		if ( false === $plaintext ) {
			// GCM 认证失败：密文被篡改或密钥不匹配。
			SA_Audit_Log::record( 'download_doc_failed', 'document', $doc_id, array( 'reason' => 'decrypt' ) );
			wp_die( esc_html__( '资料解密失败。', 'sa-core' ), 500 );
		}

		// 完整性校验：比对明文 SHA-256。
		if ( ! empty( $doc['checksum'] ) && ! hash_equals( $doc['checksum'], hash( 'sha256', $plaintext ) ) ) {
			SA_Audit_Log::record( 'download_doc_failed', 'document', $doc_id, array( 'reason' => 'checksum' ) );
			wp_die( esc_html__( '资料完整性校验失败。', 'sa-core' ), 500 );
		}

		// 记录审计日志（在输出前记录，确保留痕）。
		SA_Audit_Log::record( 'download_doc', 'document', $doc_id, array( 'doc_type' => sanitize_key( $doc['doc_type'] ) ) );

		// 输出：设置正确 header，流式送出，随后 exit。
		self::stream_output( $doc, $plaintext );
	}

	/**
	 * 设置下载头并输出明文内容。
	 *
	 * @param array  $doc       文档记录。
	 * @param string $plaintext 解密后的明文。
	 */
	private static function stream_output( $doc, $plaintext ) {
		// 清理可能已有的输出缓冲，避免破坏二进制。
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$mime = ! empty( $doc['mime_type'] ) ? $doc['mime_type'] : 'application/octet-stream';
		// 下载文件名用原始名，做安全清洗防头注入。
		$filename = sanitize_file_name( $doc['original_name'] );
		if ( '' === $filename ) {
			$filename = 'document';
		}

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $plaintext ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo $plaintext; // phpcs:ignore WordPress.Security.EscapeOutput -- 二进制文件流，禁转义。
		exit;
	}
}
