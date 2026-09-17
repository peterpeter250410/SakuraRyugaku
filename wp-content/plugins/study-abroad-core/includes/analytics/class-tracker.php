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

		/*
		 * defer 加载。
		 *
		 * 脚本本来就注册在页脚，但页脚的 <script src> 仍会在解析到那一行时
		 * 阻塞解析器，直到下载并执行完。PageSpeed 的关键路径里 tracker.js
		 * 一直挂在 HTML 之后，就是这个缘故 —— 埋点不该出现在关键路径上。
		 *
		 * 主题里对 sa-theme 已经做过同样处理（inc/performance.php 的
		 * script_loader_tag 过滤器），但那个过滤器只认 sa-theme 这一个句柄，
		 * 管不到插件注册的脚本，所以这里各自处理。
		 *
		 * strategy 参数是 WordPress 6.3 起支持的，本站运行 7.0。
		 * 更早的版本上这行会被忽略（属性不输出），脚本退回为普通页脚脚本 ——
		 * 行为与改动前一致，不会出错，只是拿不到 defer 的好处。
		 */
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'sa-tracker', 'strategy', 'defer' );
		}

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
