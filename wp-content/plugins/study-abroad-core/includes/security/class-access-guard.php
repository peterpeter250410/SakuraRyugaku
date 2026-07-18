<?php
/**
 * 访问守卫：统一的越权防护。顾问只能访问被分配的学生数据。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Access_Guard {

	/**
	 * 当前用户是否可访问指定学生的数据。
	 *
	 * 规则：
	 *  - 管理员（sa_manage_all）：全部放行。
	 *  - 学生本人：放行本人数据。
	 *  - 顾问：仅当 assignments 中存在 active 分配关系时放行。
	 *
	 * @param int $student_id 目标学生 user_id。
	 * @return bool
	 */
	public static function can_access_student( $student_id ) {
		$student_id = absint( $student_id );
		$current    = get_current_user_id();

		if ( ! $current || ! $student_id ) {
			return false;
		}

		// 管理员
		if ( current_user_can( 'sa_manage_all' ) ) {
			return true;
		}

		// 学生本人
		if ( $current === $student_id ) {
			return true;
		}

		// 顾问：校验分配关系
		if ( current_user_can( 'sa_view_assigned' ) ) {
			return self::is_assigned( $current, $student_id );
		}

		return false;
	}

	/**
	 * 判断顾问是否被分配了该学生。
	 *
	 * @param int $advisor_id 顾问 user_id。
	 * @param int $student_id 学生 user_id。
	 * @return bool
	 */
	public static function is_assigned( $advisor_id, $student_id ) {
		global $wpdb;
		$table = SA_DB::table( 'assignments' );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE advisor_id = %d AND student_id = %d AND status = 'active' LIMIT 1",
				absint( $advisor_id ),
				absint( $student_id )
			)
		);

		return ! empty( $found );
	}

	/**
	 * 断言可访问，否则终止（用于敏感端点）。
	 *
	 * @param int $student_id 学生 user_id。
	 */
	public static function require_access( $student_id ) {
		if ( ! self::can_access_student( $student_id ) ) {
			SA_Audit_Log::record( 'access_denied', 'student', $student_id );
			wp_die( esc_html__( '无权访问该资源。', 'sa-core' ), 403 );
		}
	}
}
