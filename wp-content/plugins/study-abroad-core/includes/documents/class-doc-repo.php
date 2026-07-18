<?php
/**
 * 申请资料数据访问与上传处理：校验、加密、落盘、落库。
 *
 * 安全要点（见 SECURITY-DESIGN.md 第 2/6 节）：
 *  - 扩展名白名单 + 大小限制 + MIME 内容嗅探校验，拒绝可执行/脚本文件。
 *  - 文件名随机化，杜绝路径穿越。
 *  - 内容经 SA_Doc_Crypto（AES-256-GCM）加密后写入受保护目录，明文不落盘。
 *  - 存明文 SHA-256 checksum 用于下载时完整性校验。
 *  - 全部 SQL 走 $wpdb->prepare()。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Doc_Repo {

	/**
	 * 允许的扩展名白名单。
	 */
	const ALLOWED_EXT = array( 'pdf', 'jpg', 'jpeg', 'png' );

	/**
	 * 最大文件大小：10MB。
	 */
	const MAX_SIZE = 10485760;

	/**
	 * 允许的真实 MIME 类型白名单（扩展名 => 允许的 MIME 列表）。
	 */
	const ALLOWED_MIME = array(
		'pdf'  => array( 'application/pdf' ),
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
	);

	/**
	 * 插入一条文档记录。
	 *
	 * @param array $data 已准备好的字段。
	 * @return int|false 新文档 ID，失败 false。
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = array(
			'user_id'       => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0,
			'selection_id'  => isset( $data['selection_id'] ) ? absint( $data['selection_id'] ) : 0,
			'doc_type'      => isset( $data['doc_type'] ) ? substr( sanitize_key( $data['doc_type'] ), 0, 64 ) : '',
			'original_name' => isset( $data['original_name'] ) ? substr( sanitize_file_name( $data['original_name'] ), 0, 255 ) : '',
			'stored_path'   => isset( $data['stored_path'] ) ? substr( sanitize_text_field( $data['stored_path'] ), 0, 255 ) : '',
			'mime_type'     => isset( $data['mime_type'] ) ? substr( sanitize_text_field( $data['mime_type'] ), 0, 128 ) : '',
			'file_size'     => isset( $data['file_size'] ) ? absint( $data['file_size'] ) : 0,
			'checksum'      => isset( $data['checksum'] ) ? substr( sanitize_text_field( $data['checksum'] ), 0, 64 ) : '',
			'enc_algo'      => isset( $data['enc_algo'] ) ? substr( sanitize_text_field( $data['enc_algo'] ), 0, 32 ) : '',
			'enc_iv'        => isset( $data['enc_iv'] ) ? $data['enc_iv'] : null,
			'key_ref'       => isset( $data['key_ref'] ) ? substr( sanitize_text_field( $data['key_ref'] ), 0, 64 ) : '',
			'status'        => isset( $data['status'] ) ? substr( sanitize_key( $data['status'] ), 0, 16 ) : 'uploaded',
			'version'       => isset( $data['version'] ) ? absint( $data['version'] ) : 1,
			'created_at'    => SA_DB::now(),
			'updated_at'    => SA_DB::now(),
		);

		// enc_iv 为 VARBINARY，需以 %s 传入原始二进制。
		$ok = $wpdb->insert(
			SA_DB::table( 'documents' ),
			$row,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * 按 ID 取单条文档记录。
	 *
	 * @param int $id 文档 ID。
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = SA_DB::table( 'documents' );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);
	}

	/**
	 * 取指定用户的全部文档（按创建时间倒序）。
	 *
	 * @param int $user_id 学生 user_id。
	 * @return array
	 */
	public static function list_by_user( $user_id ) {
		global $wpdb;
		$table = SA_DB::table( 'documents' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
				absint( $user_id )
			),
			ARRAY_A
		);
	}

	/**
	 * 更新文档审核状态。
	 *
	 * @param int    $id          文档 ID。
	 * @param string $status      新状态（如 approved / rejected / uploaded）。
	 * @param string $note        审核备注。
	 * @param int    $reviewer_id 审核人 user_id。
	 * @return bool
	 */
	public static function update_status( $id, $status, $note, $reviewer_id ) {
		global $wpdb;

		$ok = $wpdb->update(
			SA_DB::table( 'documents' ),
			array(
				'status'      => substr( sanitize_key( $status ), 0, 16 ),
				'review_note' => sanitize_textarea_field( $note ),
				'reviewed_by' => absint( $reviewer_id ),
				'updated_at'  => SA_DB::now(),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $ok;
	}

	/**
	 * 处理一次文件上传：校验 -> 加密 -> 写入受保护目录 -> 落库。
	 *
	 * @param int    $user_id      归属学生 user_id。
	 * @param array  $file         $_FILES 中的单个文件项。
	 * @param string $doc_type     资料类型（如 passport / transcript）。
	 * @param int    $selection_id 关联的选校记录 ID（可为 0）。
	 * @return int|WP_Error 成功返回文档 ID，失败返回 WP_Error。
	 */
	public static function handle_upload( $user_id, $file, $doc_type, $selection_id = 0 ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'sa_doc_no_user', __( '缺少归属用户。', 'sa-core' ) );
		}

		// 上传错误校验。
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['error'] ) ) {
			return new WP_Error( 'sa_doc_bad_file', __( '无效的上传数据。', 'sa-core' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'sa_doc_upload_err', __( '文件上传失败。', 'sa-core' ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'sa_doc_not_uploaded', __( '非法的上传来源。', 'sa-core' ) );
		}

		// 大小限制。
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > self::MAX_SIZE ) {
			return new WP_Error(
				'sa_doc_too_large',
				sprintf(
					/* translators: %d 为最大 MB 数 */
					__( '文件大小超出限制（最大 %dMB）。', 'sa-core' ),
					(int) ( self::MAX_SIZE / 1048576 )
				)
			);
		}

		// 扩展名白名单。
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
			return new WP_Error( 'sa_doc_bad_ext', __( '不支持的文件类型。', 'sa-core' ) );
		}

		// 真实 MIME 内容嗅探（不只信扩展名）。
		$real_mime = self::sniff_mime( $file['tmp_name'] );
		$allowed   = isset( self::ALLOWED_MIME[ $ext ] ) ? self::ALLOWED_MIME[ $ext ] : array();
		if ( ! $real_mime || ! in_array( $real_mime, $allowed, true ) ) {
			return new WP_Error( 'sa_doc_bad_mime', __( '文件内容与类型不符。', 'sa-core' ) );
		}

		// 二次用 WP 校验函数确认扩展名/类型一致，进一步防伪装。
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $check['ext'] ) || ! in_array( strtolower( $check['ext'] ), self::ALLOWED_EXT, true ) ) {
			return new WP_Error( 'sa_doc_bad_type', __( '文件类型校验未通过。', 'sa-core' ) );
		}

		// 读取明文内容。
		$plaintext = file_get_contents( $file['tmp_name'] );
		if ( false === $plaintext ) {
			return new WP_Error( 'sa_doc_read_fail', __( '无法读取上传文件。', 'sa-core' ) );
		}

		// 明文 SHA-256 校验和（用于下载时完整性验证）。
		$checksum = hash( 'sha256', $plaintext );

		// 加密。
		$enc = SA_Doc_Crypto::encrypt( $plaintext );
		if ( false === $enc ) {
			return new WP_Error( 'sa_doc_encrypt_fail', __( '加密失败。', 'sa-core' ) );
		}

		// 落盘内容 = tag . cipher。
		$stored_blob = SA_Doc_Crypto::pack_stored( $enc['cipher'], $enc['tag'] );

		// 随机化文件名，写入受保护目录。
		$secure_dir = self::secure_dir();
		if ( ! wp_mkdir_p( $secure_dir ) ) {
			return new WP_Error( 'sa_doc_dir_fail', __( '受保护目录不可写。', 'sa-core' ) );
		}

		$stored_name = self::random_stored_name( $ext );
		$abs_path    = trailingslashit( $secure_dir ) . $stored_name;

		$written = file_put_contents( $abs_path, $stored_blob, LOCK_EX );
		if ( false === $written ) {
			return new WP_Error( 'sa_doc_write_fail', __( '写入失败。', 'sa-core' ) );
		}
		// 尽量收紧文件权限（仅属主可读写）。
		@chmod( $abs_path, 0600 );

		// 落库：stored_path 仅存相对文件名，不暴露物理绝对路径。
		$doc_id = self::create(
			array(
				'user_id'       => $user_id,
				'selection_id'  => absint( $selection_id ),
				'doc_type'      => $doc_type,
				'original_name' => $file['name'],
				'stored_path'   => $stored_name,
				'mime_type'     => $real_mime,
				'file_size'     => $size,
				'checksum'      => $checksum,
				'enc_algo'      => $enc['algo'],
				'enc_iv'        => $enc['iv'],
				'key_ref'       => SA_Doc_Crypto::key_ref(),
				'status'        => 'uploaded',
				'version'       => 1,
			)
		);

		if ( ! $doc_id ) {
			// 落库失败则回滚已写文件，避免孤儿密文。
			@unlink( $abs_path );
			return new WP_Error( 'sa_doc_db_fail', __( '保存记录失败。', 'sa-core' ) );
		}

		// 记录审计日志。
		SA_Audit_Log::record( 'upload_doc', 'document', $doc_id, array( 'doc_type' => sanitize_key( $doc_type ) ) );

		return (int) $doc_id;
	}

	/**
	 * 受保护目录绝对路径。
	 *
	 * @return string
	 */
	public static function secure_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . SA_SECURE_DIRNAME;
	}

	/**
	 * 由 stored_path 拼出物理绝对路径。
	 *
	 * @param string $stored_path 存库的随机文件名。
	 * @return string
	 */
	public static function abs_path( $stored_path ) {
		return trailingslashit( self::secure_dir() ) . basename( $stored_path );
	}

	/**
	 * 生成不可猜测的随机文件名。
	 *
	 * @param string $ext 扩展名（仅用于可读性；密文本身不可直连）。
	 * @return string
	 */
	private static function random_stored_name( $ext ) {
		// 32 字节随机 -> 64 位十六进制，附加 .enc 后缀标识密文。
		$rand = bin2hex( random_bytes( 32 ) );
		return $rand . '-' . sanitize_key( $ext ) . '.enc';
	}

	/**
	 * 内容嗅探真实 MIME 类型。
	 *
	 * @param string $tmp_path 临时文件路径。
	 * @return string|false
	 */
	private static function sniff_mime( $tmp_path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_file( $finfo, $tmp_path );
				finfo_close( $finfo );
				if ( $mime ) {
					return $mime;
				}
			}
		}

		// 回退到 WP 的类型探测。
		$type = wp_check_filetype_and_ext( $tmp_path, basename( $tmp_path ) );
		return ! empty( $type['type'] ) ? $type['type'] : false;
	}
}
