<?php
/**
 * 前端埋点脚本注入：自建埋点 + GA4 事件桥接。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Tracker {

	/**
	 * 注入埋点脚本，并向前端传递 REST 端点与 nonce。
	 */
	public static function enqueue() {
		wp_register_script(
			'sa-tracker',
			SA_CORE_URL . 'assets/js/tracker.js',
			array(),
			SA_CORE_VERSION,
			true
		);

		$ga4_id = get_option( 'sa_ga4_measurement_id', '' );

		wp_localize_script(
			'sa-tracker',
			'SA_TRACK',
			array(
				'endpoint' => esc_url_raw( rest_url( 'sa/v1/track' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ga4'      => $ga4_id ? sanitize_text_field( $ga4_id ) : '',
			)
		);

		wp_enqueue_script( 'sa-tracker' );

		// 若配置了 GA4，注入 gtag 基础库。
		if ( $ga4_id ) {
			add_action( 'wp_head', array( __CLASS__, 'print_gtag' ), 1 );
		}
	}

	/**
	 * 输出 GA4 gtag 片段（measurement id 经过转义）。
	 */
	public static function print_gtag() {
		$id = get_option( 'sa_ga4_measurement_id', '' );
		if ( ! $id ) {
			return;
		}
		$id = esc_js( $id );
		echo "<!-- Study Abroad GA4 -->\n";
		echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $id ) . '"></script>' . "\n";
		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $id . "',{anonymize_ip:true});</script>\n";
	}
}
