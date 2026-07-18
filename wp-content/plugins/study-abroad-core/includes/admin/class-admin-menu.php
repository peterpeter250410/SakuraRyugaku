<?php
/**
 * 后台菜单：市场验证数据看板（线索 + 流量/转化概览）与设置。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Admin_Menu {

	/**
	 * 注册后台菜单。
	 */
	public static function register() {
		add_menu_page(
			__( '留学中介', 'sa-core' ),
			__( '留学中介', 'sa-core' ),
			'sa_view_analytics',
			'sa-dashboard',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-welcome-learn-more',
			26
		);

		add_submenu_page(
			'sa-dashboard',
			__( '数据看板', 'sa-core' ),
			__( '数据看板', 'sa-core' ),
			'sa_view_analytics',
			'sa-dashboard',
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			'sa-dashboard',
			__( '意向线索', 'sa-core' ),
			__( '意向线索', 'sa-core' ),
			'sa_view_analytics',
			'sa-leads',
			array( __CLASS__, 'render_leads' )
		);

		add_submenu_page(
			'sa-dashboard',
			__( '设置', 'sa-core' ),
			__( '设置', 'sa-core' ),
			'sa_manage_all',
			'sa-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * 数据看板：UV / PV / 线索 / 转化率概览。
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'sa_view_analytics' ) ) {
			wp_die( esc_html__( '无权访问。', 'sa-core' ) );
		}

		// 统计近 7 日（UTC）
		$since_7d = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		$uv_total    = SA_Analytics_Repo::unique_visitors();
		$uv_7d       = SA_Analytics_Repo::unique_visitors( $since_7d );
		$pv_total    = SA_Analytics_Repo::count_event( 'pageview' );
		$pv_7d       = SA_Analytics_Repo::count_event( 'pageview', $since_7d );
		$lp_view     = SA_Analytics_Repo::count_event( 'lp_view' );
		$form_imp    = SA_Analytics_Repo::count_event( 'form_impression' );
		$form_start  = SA_Analytics_Repo::count_event( 'form_start' );
		$form_submit = SA_Analytics_Repo::count_event( 'form_submit' );
		$leads_total = SA_Lead_Repo::count();
		$leads_valid = SA_Lead_Repo::count( 'valid' );

		$conv_rate = $uv_total > 0 ? round( ( $leads_total / $uv_total ) * 100, 2 ) : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '留学中介 · 市场验证数据看板', 'sa-core' ) . '</h1>';
		echo '<p>' . esc_html__( '本看板用于判断项目是否值得继续投入：关注流量、转化率与有效线索。', 'sa-core' ) . '</p>';

		echo '<div style="display:flex;flex-wrap:wrap;gap:16px;margin:20px 0;">';
		self::stat_card( __( '独立访客 UV（累计）', 'sa-core' ), $uv_total, __( '近7日', 'sa-core' ) . ': ' . $uv_7d );
		self::stat_card( __( '页面浏览 PV（累计）', 'sa-core' ), $pv_total, __( '近7日', 'sa-core' ) . ': ' . $pv_7d );
		self::stat_card( __( '意向线索数', 'sa-core' ), $leads_total, __( '有效', 'sa-core' ) . ': ' . $leads_valid );
		self::stat_card( __( '转化率（线索/UV）', 'sa-core' ), $conv_rate . '%', '' );
		echo '</div>';

		echo '<h2>' . esc_html__( '转化漏斗', 'sa-core' ) . '</h2>';
		echo '<table class="widefat" style="max-width:640px;">';
		echo '<thead><tr><th>' . esc_html__( '环节', 'sa-core' ) . '</th><th>' . esc_html__( '数量', 'sa-core' ) . '</th></tr></thead><tbody>';
		self::funnel_row( __( '落地页浏览', 'sa-core' ), $lp_view );
		self::funnel_row( __( '表单曝光', 'sa-core' ), $form_imp );
		self::funnel_row( __( '开始填写', 'sa-core' ), $form_start );
		self::funnel_row( __( '提交成功（线索）', 'sa-core' ), $form_submit );
		echo '</tbody></table>';

		echo '<p style="margin-top:16px;color:#666;">' . esc_html__( '说明：GA4 与 Search Console 数据请在对应平台查看；此处为自建埋点的自主统计。', 'sa-core' ) . '</p>';
		echo '</div>';
	}

	/**
	 * 意向线索列表。
	 */
	public static function render_leads() {
		if ( ! current_user_can( 'sa_view_analytics' ) ) {
			wp_die( esc_html__( '无权访问。', 'sa-core' ) );
		}

		$per_page = 50;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$total = SA_Lead_Repo::count();
		$rows  = SA_Lead_Repo::paged( $per_page, $offset );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '意向线索', 'sa-core' ) . ' <span class="count">(' . esc_html( $total ) . ')</span></h1>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( '时间', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '姓名', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '联系方式', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '预算', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '意向专业', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '来源', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '状态', 'sa-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( '暂无线索。', 'sa-core' ) . '</td></tr>';
		} else {
			foreach ( $rows as $r ) {
				$budget = ( $r['budget_min'] || $r['budget_max'] )
					? esc_html( number_format( $r['budget_min'] ) . ' ~ ' . number_format( $r['budget_max'] ) )
					: '—';
				echo '<tr>';
				echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
				echo '<td>' . esc_html( $r['name'] ) . '</td>';
				echo '<td>' . esc_html( $r['contact_type'] . ': ' . $r['contact_value'] ) . '</td>';
				echo '<td>' . $budget . '</td>'; // phpcs:ignore already escaped
				echo '<td>' . esc_html( $r['intended_major'] ? $r['intended_major'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $r['utm_source'] ? $r['utm_source'] : 'direct' ) . '</td>';
				echo '<td>' . esc_html( $r['lead_status'] ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		// 分页
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg( array( 'page' => 'sa-leads', 'paged' => $i ), admin_url( 'admin.php' ) );
				if ( $i === $paged ) {
					echo '<span class="button button-primary" style="margin:0 2px;">' . esc_html( $i ) . '</span>';
				} else {
					echo '<a class="button" style="margin:0 2px;" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
				}
			}
			echo '</div></div>';
		}

		echo '<p style="color:#a00;">' . esc_html__( '注意：线索含个人联系方式，属敏感数据，请遵守隐私合规，勿外泄。', 'sa-core' ) . '</p>';
		echo '</div>';
	}

	/**
	 * 设置页：GA4 测量 ID 等。
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'sa_manage_all' ) ) {
			wp_die( esc_html__( '无权访问。', 'sa-core' ) );
		}

		// 保存
		if ( isset( $_POST['sa_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sa_settings_nonce'] ) ), 'sa_save_settings' ) ) {
			$ga4 = isset( $_POST['sa_ga4'] ) ? sanitize_text_field( wp_unslash( $_POST['sa_ga4'] ) ) : '';
			update_option( 'sa_ga4_measurement_id', $ga4 );
			echo '<div class="notice notice-success"><p>' . esc_html__( '已保存。', 'sa-core' ) . '</p></div>';
		}

		$ga4 = get_option( 'sa_ga4_measurement_id', '' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '留学中介 · 设置', 'sa-core' ) . '</h1>';
		echo '<form method="post">';
		wp_nonce_field( 'sa_save_settings', 'sa_settings_nonce' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="sa_ga4">' . esc_html__( 'GA4 测量 ID', 'sa-core' ) . '</label></th>';
		echo '<td><input name="sa_ga4" id="sa_ga4" type="text" class="regular-text" placeholder="G-XXXXXXXXXX" value="' . esc_attr( $ga4 ) . '" />';
		echo '<p class="description">' . esc_html__( '填入后前台自动注入 GA4（已开启 IP 匿名化）。留空则仅使用自建埋点。', 'sa-core' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/** 统计卡片。 */
	private static function stat_card( $label, $value, $sub ) {
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;min-width:200px;flex:1;">';
		echo '<div style="font-size:13px;color:#666;">' . esc_html( $label ) . '</div>';
		echo '<div style="font-size:32px;font-weight:700;margin:8px 0;">' . esc_html( $value ) . '</div>';
		if ( $sub ) {
			echo '<div style="font-size:12px;color:#999;">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}

	/** 漏斗行。 */
	private static function funnel_row( $label, $count ) {
		echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( number_format( $count ) ) . '</td></tr>';
	}
}
