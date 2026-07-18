<?php
/**
 * 意向线索数据访问：落地页表单提交的核心产出。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Lead_Repo {

	/**
	 * 允许的联系方式类型。
	 */
	const CONTACT_TYPES = array( 'email', 'phone', 'line', 'wechat', 'whatsapp' );

	/**
	 * 创建一条线索。
	 *
	 * @param array $data 已清洗的字段。
	 * @return int|false 新线索 ID，失败返回 false。
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = array(
			'session_key'    => isset( $data['session_key'] ) ? substr( sanitize_text_field( $data['session_key'] ), 0, 36 ) : '',
			'name'           => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'contact_type'   => self::normalize_contact_type( isset( $data['contact_type'] ) ? $data['contact_type'] : '' ),
			'contact_value'  => isset( $data['contact_value'] ) ? sanitize_text_field( $data['contact_value'] ) : '',
			'budget_min'     => isset( $data['budget_min'] ) ? absint( $data['budget_min'] ) : 0,
			'budget_max'     => isset( $data['budget_max'] ) ? absint( $data['budget_max'] ) : 0,
			'intended_major' => isset( $data['intended_major'] ) ? sanitize_text_field( $data['intended_major'] ) : '',
			'extra'          => isset( $data['extra'] ) && is_array( $data['extra'] ) ? wp_json_encode( $data['extra'] ) : null,
			'lp_variant'     => isset( $data['lp_variant'] ) ? sanitize_text_field( $data['lp_variant'] ) : '',
			'utm_source'     => isset( $data['utm_source'] ) ? sanitize_text_field( $data['utm_source'] ) : '',
			'utm_medium'     => isset( $data['utm_medium'] ) ? sanitize_text_field( $data['utm_medium'] ) : '',
			'utm_campaign'   => isset( $data['utm_campaign'] ) ? sanitize_text_field( $data['utm_campaign'] ) : '',
			'lead_status'    => 'new',
			'created_at'     => SA_DB::now(),
		);

		$ok = $wpdb->insert(
			SA_DB::table( 'leads' ),
			$row,
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * 归一化联系方式类型，非法则回退 'email'。
	 */
	private static function normalize_contact_type( $type ) {
		$type = sanitize_key( $type );
		return in_array( $type, self::CONTACT_TYPES, true ) ? $type : 'email';
	}

	/**
	 * 统计线索总数（可按状态）。
	 *
	 * @param string $status 可选状态过滤。
	 * @return int
	 */
	public static function count( $status = '' ) {
		global $wpdb;
		$table = SA_DB::table( 'leads' );

		if ( $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE lead_status = %s", sanitize_key( $status ) )
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * 分页获取线索（后台看板用）。
	 *
	 * @param int $limit  每页条数。
	 * @param int $offset 偏移。
	 * @return array
	 */
	public static function paged( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = SA_DB::table( 'leads' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				absint( $limit ),
				absint( $offset )
			),
			ARRAY_A
		);
	}
}
