<?php
/**
 * 自建埋点数据访问：访问/行为事件落库，供自主分析与看板。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Analytics_Repo {

	/**
	 * 允许记录的事件类型（白名单，防注入垃圾事件）。
	 */
	const EVENT_TYPES = array(
		'pageview',
		'lp_view',
		'form_impression',
		'form_start',
		'form_submit',
		'cta_click',
		'scroll_depth',
	);

	/**
	 * 记录一条埋点事件。
	 *
	 * @param array $data 已从请求提取的原始数据。
	 * @return bool
	 */
	public static function record( array $data ) {
		global $wpdb;

		$event_type = isset( $data['event_type'] ) ? sanitize_key( $data['event_type'] ) : '';
		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
			return false;
		}

		$row = array(
			'event_type'   => $event_type,
			'session_key'  => isset( $data['session_key'] ) ? substr( sanitize_text_field( $data['session_key'] ), 0, 36 ) : '',
			'page_url'     => isset( $data['page_url'] ) ? esc_url_raw( $data['page_url'] ) : '',
			'referrer'     => isset( $data['referrer'] ) ? esc_url_raw( $data['referrer'] ) : '',
			'utm_source'   => isset( $data['utm_source'] ) ? sanitize_text_field( $data['utm_source'] ) : '',
			'utm_medium'   => isset( $data['utm_medium'] ) ? sanitize_text_field( $data['utm_medium'] ) : '',
			'utm_campaign' => isset( $data['utm_campaign'] ) ? sanitize_text_field( $data['utm_campaign'] ) : '',
			'device'       => self::normalize_device( isset( $data['device'] ) ? $data['device'] : '' ),
			'locale'       => isset( $data['locale'] ) ? sanitize_text_field( $data['locale'] ) : '',
			'country'      => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '',
			'ip_hash'      => self::ip_hash(),
			'meta'         => isset( $data['meta'] ) && is_array( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
			'created_at'   => SA_DB::now(),
		);

		return (bool) $wpdb->insert(
			SA_DB::table( 'analytics_events' ),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * 归一化设备类型。
	 */
	private static function normalize_device( $device ) {
		$device = sanitize_key( $device );
		return in_array( $device, array( 'pc', 'h5' ), true ) ? $device : '';
	}

	/**
	 * IP 哈希（不存明文，合规）。以站点盐加固，防彩虹表。
	 */
	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip    = trim( $parts[0] );
		}
		$ip = sanitize_text_field( $ip );
		if ( '' === $ip ) {
			return '';
		}
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	/**
	 * 统计某事件在时间范围内的计数（看板用）。
	 *
	 * @param string $event_type 事件类型。
	 * @param string $since      起始时间（mysql，UTC），可空。
	 * @return int
	 */
	public static function count_event( $event_type, $since = '' ) {
		global $wpdb;
		$table      = SA_DB::table( 'analytics_events' );
		$event_type = sanitize_key( $event_type );

		if ( $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND created_at >= %s",
					$event_type,
					$since
				)
			);
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type )
		);
	}

	/**
	 * 独立访客数（按 session_key 去重的 pageview）。
	 *
	 * @param string $since 起始时间，可空。
	 * @return int
	 */
	public static function unique_visitors( $since = '' ) {
		global $wpdb;
		$table = SA_DB::table( 'analytics_events' );

		if ( $since ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE event_type = 'pageview' AND created_at >= %s",
					$since
				)
			);
		}
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE event_type = 'pageview'"
		);
	}
}
