<?php
/**
 * 学生建号 + 免密登录链接。
 *
 * 用户在诊断后「选校」时才建号（sa_student 角色 + 随机强密码），
 * 并签发一次性、限期、绑定 uid 的免密 token（明文仅在链接中出现，
 * 库里只存 wp_hash 后的哈希），用于直接进入专属资料上传页。
 *
 * 安全要点：
 *  - token 256bit（random_bytes(32)），wp_hash 存储，hash_equals 常量时间比对。
 *  - 绑定 uid + 限期（默认 3 天）。
 *  - 仅在 verify_token 通过后才 login_as 设置登录态。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Student_Onboard {

	/** 免密 token 存储的 user_meta 键。 */
	const META_TOKEN = 'sa_upload_token';
	const META_EXP   = 'sa_upload_token_exp';
	const META_SEL   = 'sa_upload_token_sel';

	/** 默认 token 有效期（秒）。 */
	const TOKEN_TTL = 259200; // 3 * DAY_IN_SECONDS

	/**
	 * 建号或取已有学生账号。
	 *
	 * email 类联系方式走 email 去重；非 email（LINE/微信/电话）用确定性
	 * 占位邮箱 sa_{hash}@no-reply.local 去重，避免为同一人重复建号。
	 *
	 * @param string $name          姓名。
	 * @param string $contact_type  联系方式类型（email/line/wechat/whatsapp/phone）。
	 * @param string $contact_value 联系方式值。
	 * @param array  $profile       附加档案（budget_min/max、intended_major、consent 等）。
	 * @return int|WP_Error user_id 或错误。
	 */
	public static function create_or_get_student( $name, $contact_type, $contact_value, array $profile = array() ) {
		$name          = sanitize_text_field( $name );
		$contact_type  = sanitize_key( $contact_type );
		$contact_value = sanitize_text_field( $contact_value );

		if ( '' === $name || '' === $contact_value ) {
			return new WP_Error( 'sa_missing', __( '缺少必要信息。', 'sa-core' ) );
		}

		// 决定用于去重/建号的邮箱。
		if ( 'email' === $contact_type && is_email( $contact_value ) ) {
			$email = sanitize_email( $contact_value );
		} else {
			// 非 email：确定性占位邮箱，保证同一联系方式复用同一账号。
			$hash  = substr( hash( 'sha256', $contact_type . '|' . strtolower( $contact_value ) ), 0, 24 );
			$email = 'sa_' . $hash . '@no-reply.local';
		}

		// 已存在则复用。
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			self::upsert_profile( $existing->ID, $name, $profile );
			return $existing->ID;
		}

		// 生成唯一用户名。
		$base_login = 'sa_' . substr( md5( $email ), 0, 12 );
		$login      = $base_login;
		$i          = 1;
		while ( username_exists( $login ) ) {
			$login = $base_login . '_' . $i;
			$i++;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => $name,
				'role'         => 'sa_student',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::upsert_profile( $user_id, $name, $profile );

		return (int) $user_id;
	}

	/**
	 * upsert 学生扩展档案（student_profiles，UNIQUE(user_id)）。
	 *
	 * @param int    $user_id 用户 ID。
	 * @param string $name    姓名。
	 * @param array  $profile 档案字段。
	 * @return void
	 */
	private static function upsert_profile( $user_id, $name, array $profile ) {
		global $wpdb;

		$table   = SA_DB::table( 'student_profiles' );
		$user_id = absint( $user_id );
		$now     = SA_DB::now();

		$budget_min = isset( $profile['budget_min'] ) ? absint( $profile['budget_min'] ) : 0;
		$budget_max = isset( $profile['budget_max'] ) ? absint( $profile['budget_max'] ) : 0;
		$major      = isset( $profile['intended_major'] ) ? sanitize_text_field( $profile['intended_major'] ) : '';
		$consent    = ! empty( $profile['consent'] ) ? 1 : 0;

		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id )
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'full_name'       => sanitize_text_field( $name ),
					'budget_min'      => $budget_min,
					'budget_max'      => $budget_max,
					'intended_major'  => $major,
					'consent_privacy' => $consent,
					'updated_at'      => $now,
				),
				array( 'user_id' => $user_id ),
				array( '%s', '%d', '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'user_id'         => $user_id,
				'full_name'       => sanitize_text_field( $name ),
				'budget_min'      => $budget_min,
				'budget_max'      => $budget_max,
				'intended_major'  => $major,
				'consent_privacy' => $consent,
				'consent_at'      => $consent ? $now : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * 为某用户签发一次性免密 token（明文仅返回一次）。
	 *
	 * @param int $user_id      用户 ID。
	 * @param int $selection_id 关联的选校 ID（可选，用于上传页定位）。
	 * @param int $ttl          有效期秒数。
	 * @return string 明文 token（放进链接）。
	 */
	public static function issue_login_token( $user_id, $selection_id = 0, $ttl = self::TOKEN_TTL ) {
		$user_id = absint( $user_id );
		$raw     = bin2hex( random_bytes( 32 ) ); // 256bit

		update_user_meta( $user_id, self::META_TOKEN, wp_hash( $raw ) );
		update_user_meta( $user_id, self::META_EXP, time() + absint( $ttl ) );
		update_user_meta( $user_id, self::META_SEL, absint( $selection_id ) );

		return $raw;
	}

	/**
	 * 校验免密 token（常量时间比对 + 未过期）。
	 *
	 * @param int    $user_id 用户 ID。
	 * @param string $raw     链接中的明文 token。
	 * @return bool
	 */
	public static function verify_token( $user_id, $raw ) {
		$user_id = absint( $user_id );
		$raw     = is_string( $raw ) ? $raw : '';

		if ( '' === $raw || ! ctype_xdigit( $raw ) ) {
			return false;
		}

		$stored = get_user_meta( $user_id, self::META_TOKEN, true );
		$exp    = (int) get_user_meta( $user_id, self::META_EXP, true );

		if ( empty( $stored ) || $exp <= 0 ) {
			return false;
		}
		if ( time() > $exp ) {
			return false;
		}

		return hash_equals( (string) $stored, wp_hash( $raw ) );
	}

	/**
	 * 在 token 校验通过后设置登录态。调用前务必先 verify_token。
	 *
	 * @param int $user_id 用户 ID。
	 * @return bool
	 */
	public static function login_as( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		wp_set_current_user( $user_id );

		// 在本次请求内回填 logged-in cookie，使随后渲染页面时
		// wp_create_nonce('wp_rest') 使用新登录身份计算，避免上传请求
		// 出现「Cookie 检查失败」（nonce 与真实 cookie 身份不匹配）。
		$backfill = static function ( $logged_in_cookie ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
		};
		add_action( 'set_logged_in_cookie', $backfill );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		remove_action( 'set_logged_in_cookie', $backfill );

		return true;
	}

	/**
	 * 取 token 绑定的 selection_id。
	 *
	 * @param int $user_id 用户 ID。
	 * @return int
	 */
	public static function token_selection( $user_id ) {
		return (int) get_user_meta( absint( $user_id ), self::META_SEL, true );
	}
}
