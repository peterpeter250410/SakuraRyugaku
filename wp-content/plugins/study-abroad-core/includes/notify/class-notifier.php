<?php
/**
 * 通知模块：站内信（sa_notifications）+ 邮件（wp_mail）。
 *
 * 安全要点（见 SECURITY-DESIGN.md 第 7 节）：
 *  - 邮件正文不含敏感资料明文，仅提示与站内链接。
 *  - 所有落库与输出统一清洗/转义，防 XSS 与 SQL 注入。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Notifier {

	/**
	 * 写入一条站内通知。
	 *
	 * @param int    $user_id 接收者 user_id。
	 * @param string $type    通知类型（如 new_lead / match_done）。
	 * @param string $title   标题。
	 * @param string $body    正文。
	 * @param string $link    相关链接。
	 * @param string $channel 渠道（默认 inbox）。
	 * @return int|false 新通知 ID，失败 false。
	 */
	public static function notify( $user_id, $type, $title, $body, $link = '', $channel = 'inbox' ) {
		global $wpdb;

		$ok = $wpdb->insert(
			SA_DB::table( 'notifications' ),
			array(
				'user_id'    => absint( $user_id ),
				'type'       => substr( sanitize_key( $type ), 0, 64 ),
				'title'      => substr( sanitize_text_field( $title ), 0, 191 ),
				'body'       => sanitize_textarea_field( $body ),
				'link'       => esc_url_raw( $link ),
				'is_read'    => 0,
				'channel'    => substr( sanitize_key( $channel ), 0, 32 ),
				'created_at' => SA_DB::now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * 发送邮件（对主题与正文做转义清洗）。
	 *
	 * @param string $to      收件地址。
	 * @param string $subject 主题。
	 * @param string $body    正文（纯文本）。
	 * @return bool
	 */
	public static function send_email( $to, $subject, $body ) {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$subject = wp_strip_all_tags( $subject );
		$body    = wp_strip_all_tags( $body );

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * 通知管理员：有新的留学意向线索。
	 *
	 * @param int    $lead_id 线索 ID。
	 * @param string $name    线索姓名。
	 * @return void
	 */
	public static function notify_new_lead( $lead_id, $name ) {
		$lead_id = absint( $lead_id );
		$name    = sanitize_text_field( $name );

		$admin_email = get_option( 'admin_email' );

		$subject = sprintf(
			/* translators: %s 为线索姓名 */
			__( '【新线索】收到新的留学意向：%s', 'sa-core' ),
			$name
		);

		$body = sprintf(
			/* translators: 1: 姓名, 2: 线索 ID */
			__( "收到一条新的留学意向线索。\n姓名：%1\$s\n线索编号：#%2\$d\n请登录后台查看详情。", 'sa-core' ),
			$name,
			$lead_id
		);

		// 邮件通知管理员。
		self::send_email( $admin_email, $subject, $body );

		// 若管理员有 WP 账号，同时写一条站内通知。
		$admin_user = get_user_by( 'email', $admin_email );
		if ( $admin_user ) {
			self::notify(
				$admin_user->ID,
				'new_lead',
				$subject,
				$body,
				admin_url( 'admin.php?page=sa-leads' ),
				'inbox'
			);
		}
	}

	/**
	 * 通知学生：院校匹配已完成。
	 *
	 * @param int $user_id 学生 user_id。
	 * @return int|false
	 */
	public static function notify_match_done( $user_id ) {
		$user_id = absint( $user_id );

		$title = __( '院校匹配已完成', 'sa-core' );
		$body  = __( '您的院校匹配结果已生成，请登录查看推荐方案。', 'sa-core' );
		$link  = home_url( '/my/matches/' );

		$id = self::notify( $user_id, 'match_done', $title, $body, $link, 'inbox' );

		// 同时给学生发一封提醒邮件（不含敏感内容）。
		$user = get_user_by( 'id', $user_id );
		if ( $user && is_email( $user->user_email ) ) {
			self::send_email( $user->user_email, $title, $body . "\n" . $link );
		}

		return $id;
	}

	/**
	 * 统计某用户未读通知数。
	 *
	 * @param int $user_id 用户 ID。
	 * @return int
	 */
	public static function unread_count( $user_id ) {
		global $wpdb;
		$table = SA_DB::table( 'notifications' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
				absint( $user_id )
			)
		);
	}

	/**
	 * 将某条通知标记为已读（仅限本人）。
	 *
	 * @param int $id      通知 ID。
	 * @param int $user_id 用户 ID（用于归属校验，防越权）。
	 * @return bool
	 */
	public static function mark_read( $id, $user_id ) {
		global $wpdb;

		$ok = $wpdb->update(
			SA_DB::table( 'notifications' ),
			array( 'is_read' => 1 ),
			array(
				'id'      => absint( $id ),
				'user_id' => absint( $user_id ),
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $ok;
	}
}
