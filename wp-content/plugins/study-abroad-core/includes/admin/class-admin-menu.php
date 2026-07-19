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
			__( '院校管理', 'sa-core' ),
			__( '院校管理', 'sa-core' ),
			'sa_manage_schools',
			'sa-schools',
			array( __CLASS__, 'render_schools' )
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

	/**
	 * 院校管理页：列表 + 新增/编辑表单（含每校资料清单编辑器）。
	 */
	public static function render_schools() {
		if ( ! current_user_can( 'sa_manage_schools' ) ) {
			wp_die( esc_html__( '无权访问。', 'sa-core' ) );
		}

		$notice = '';

		// 填充演示数据。
		if (
			isset( $_POST['sa_seed_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sa_seed_nonce'] ) ), 'sa_seed_schools' )
		) {
			$res    = SA_School_Repo::seed_demo();
			$notice = sprintf(
				/* translators: 1: 院校数, 2: 专业数 */
				esc_html__( '已填充演示数据：院校 %1$d 所、专业 %2$d 个。', 'sa-core' ),
				(int) $res['schools'],
				(int) $res['programs']
			);
		}

		// 保存（新增 / 编辑）。
		if (
			isset( $_POST['sa_school_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sa_school_nonce'] ) ), 'sa_save_school' )
		) {
			$notice = self::handle_save_school();
		}

		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? SA_School_Repo::get_school( $edit_id ) : null;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '院校管理', 'sa-core' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
		}

		self::render_school_list();
		self::render_school_form( $editing );

		echo '</div>';
	}

	/**
	 * 处理院校保存，返回提示文案。
	 *
	 * @return string
	 */
	private static function handle_save_school() {
		$id = isset( $_POST['school_id'] ) ? absint( $_POST['school_id'] ) : 0;

		$data = array(
			'name'          => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'school_type'   => isset( $_POST['school_type'] ) ? sanitize_text_field( wp_unslash( $_POST['school_type'] ) ) : '',
			'region'        => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '',
			'city'          => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
			'language_req'  => isset( $_POST['language_req'] ) ? sanitize_text_field( wp_unslash( $_POST['language_req'] ) ) : '',
			'min_education' => isset( $_POST['min_education'] ) ? sanitize_text_field( wp_unslash( $_POST['min_education'] ) ) : '',
			'status'        => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active',
			'sort_order'    => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			'required_docs' => self::collect_required_docs(),
		);

		if ( $id ) {
			SA_School_Repo::update_school( $id, $data );
			return __( '院校已更新。', 'sa-core' );
		}

		$new_id = SA_School_Repo::create_school( $data );
		return $new_id ? __( '院校已创建。', 'sa-core' ) : __( '创建失败。', 'sa-core' );
	}

	/**
	 * 从 POST 收集资料清单（docs[i][key|label|type|required]），规范化后返回数组。
	 *
	 * @return array
	 */
	private static function collect_required_docs() {
		if ( empty( $_POST['docs'] ) || ! is_array( $_POST['docs'] ) ) {
			return array();
		}

		$allowed_types = array( 'image', 'pdf', 'office', 'text' );
		$out           = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 逐字段单独清洗于下。
		foreach ( wp_unslash( $_POST['docs'] ) as $item ) {
			if ( empty( $item['key'] ) ) {
				continue;
			}
			$out[] = array(
				'key'      => sanitize_key( $item['key'] ),
				'label'    => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
				'type'     => ( isset( $item['type'] ) && in_array( $item['type'], $allowed_types, true ) ) ? $item['type'] : 'pdf',
				'required' => ! empty( $item['required'] ) ? 1 : 0,
			);
		}

		return $out;
	}

	/**
	 * 渲染院校列表。
	 */
	private static function render_school_list() {
		$schools = SA_School_Repo::all_schools();

		echo '<h2>' . esc_html__( '院校列表', 'sa-core' ) . '</h2>';

		if ( empty( $schools ) ) {
			echo '<p>' . esc_html__( '暂无院校。可点击下方“填充演示数据”快速初始化，或使用表单新增。', 'sa-core' ) . '</p>';

			echo '<form method="post" style="margin:12px 0;">';
			wp_nonce_field( 'sa_seed_schools', 'sa_seed_nonce' );
			echo '<button type="submit" class="button">' . esc_html__( '填充演示数据', 'sa-core' ) . '</button>';
			echo '</form>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '名称', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '类型', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '地区', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '资料项数', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '状态', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'sa-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $schools as $s ) {
			$docs      = SA_School_Repo::get_required_docs( $s['id'] );
			$edit_url  = add_query_arg(
				array( 'page' => 'sa-schools', 'edit' => $s['id'] ),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td>' . esc_html( $s['id'] ) . '</td>';
			echo '<td>' . esc_html( $s['name'] ) . '</td>';
			echo '<td>' . esc_html( $s['school_type'] ) . '</td>';
			echo '<td>' . esc_html( trim( $s['region'] . ' ' . $s['city'] ) ) . '</td>';
			echo '<td>' . esc_html( count( $docs ) ) . '</td>';
			echo '<td>' . esc_html( $s['status'] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( '编辑', 'sa-core' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * 渲染新增/编辑表单（含资料清单编辑器 + 原生“加一行”JS）。
	 *
	 * @param array|null $editing 编辑中的院校（null 为新增）。
	 */
	private static function render_school_form( $editing ) {
		$is_edit = ! empty( $editing );
		$docs    = $is_edit ? SA_School_Repo::get_required_docs( $editing['id'] ) : SA_School_Repo::default_required_docs();

		$val = function ( $key ) use ( $editing ) {
			return $editing && isset( $editing[ $key ] ) ? $editing[ $key ] : '';
		};

		echo '<hr style="margin:24px 0;">';
		echo '<h2>' . ( $is_edit ? esc_html__( '编辑院校', 'sa-core' ) : esc_html__( '新增院校', 'sa-core' ) ) . '</h2>';

		echo '<form method="post">';
		wp_nonce_field( 'sa_save_school', 'sa_school_nonce' );

		if ( $is_edit ) {
			echo '<input type="hidden" name="school_id" value="' . esc_attr( $editing['id'] ) . '" />';
		}

		echo '<table class="form-table"><tbody>';
		self::form_text_row( 'name', __( '名称', 'sa-core' ), $val( 'name' ) );
		self::form_text_row( 'school_type', __( '类型（如 university / language_school）', 'sa-core' ), $val( 'school_type' ) );
		self::form_text_row( 'region', __( '地区（如 関東）', 'sa-core' ), $val( 'region' ) );
		self::form_text_row( 'city', __( '城市（如 東京）', 'sa-core' ), $val( 'city' ) );
		self::form_text_row( 'language_req', __( '语言要求（如 JLPT N2）', 'sa-core' ), $val( 'language_req' ) );
		self::form_text_row( 'min_education', __( '最低学历（如 high_school）', 'sa-core' ), $val( 'min_education' ) );
		self::form_text_row( 'sort_order', __( '排序', 'sa-core' ), $val( 'sort_order' ) );

		// 状态
		$status = $val( 'status' ) ? $val( 'status' ) : 'active';
		echo '<tr><th><label>' . esc_html__( '状态', 'sa-core' ) . '</label></th><td>';
		echo '<select name="status">';
		foreach ( array( 'active' => __( '启用', 'sa-core' ), 'inactive' => __( '停用', 'sa-core' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '</tbody></table>';

		// 资料清单编辑器
		echo '<h3>' . esc_html__( '资料清单（学生选校后按此逐项上传）', 'sa-core' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'key 为英文标识（上传校验用），label 为前台显示名，type 决定上传控件（image/pdf/office/text）。', 'sa-core' ) . '</p>';

		echo '<table class="widefat" id="sa-docs-table" style="max-width:820px;"><thead><tr>';
		echo '<th>' . esc_html__( '标识 key', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '显示名 label', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '类型 type', 'sa-core' ) . '</th>';
		echo '<th>' . esc_html__( '必填', 'sa-core' ) . '</th>';
		echo '</tr></thead><tbody id="sa-docs-body">';

		$i = 0;
		foreach ( $docs as $d ) {
			self::doc_row( $i, $d );
			$i++;
		}

		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="sa-add-doc">' . esc_html__( '+ 添加一行', 'sa-core' ) . '</button></p>';

		submit_button( $is_edit ? __( '更新院校', 'sa-core' ) : __( '创建院校', 'sa-core' ) );
		echo '</form>';

		// 原生“加一行”脚本（无依赖）。
		$type_options = '<option value="image">image</option><option value="pdf">pdf</option><option value="office">office</option><option value="text">text</option>';
		?>
		<script>
		(function () {
			var body = document.getElementById('sa-docs-body');
			var btn  = document.getElementById('sa-add-doc');
			if (!body || !btn) { return; }
			btn.addEventListener('click', function () {
				var i = body.querySelectorAll('tr').length;
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td><input type="text" name="docs[' + i + '][key]" value="" /></td>' +
					'<td><input type="text" name="docs[' + i + '][label]" value="" /></td>' +
					'<td><select name="docs[' + i + '][type]"><?php echo $type_options; // phpcs:ignore ?></select></td>' +
					'<td><input type="checkbox" name="docs[' + i + '][required]" value="1" /></td>';
				body.appendChild(tr);
			});
		})();
		</script>
		<?php
	}

	/** 资料清单单行。 */
	private static function doc_row( $i, $d ) {
		$i     = (int) $i;
		$key   = isset( $d['key'] ) ? $d['key'] : '';
		$label = isset( $d['label'] ) ? $d['label'] : '';
		$type  = isset( $d['type'] ) ? $d['type'] : 'pdf';
		$req   = ! empty( $d['required'] );

		echo '<tr>';
		echo '<td><input type="text" name="docs[' . $i . '][key]" value="' . esc_attr( $key ) . '" /></td>';
		echo '<td><input type="text" name="docs[' . $i . '][label]" value="' . esc_attr( $label ) . '" /></td>';
		echo '<td><select name="docs[' . $i . '][type]">';
		foreach ( array( 'image', 'pdf', 'office', 'text' ) as $t ) {
			echo '<option value="' . esc_attr( $t ) . '" ' . selected( $type, $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="checkbox" name="docs[' . $i . '][required]" value="1" ' . checked( $req, true, false ) . ' /></td>';
		echo '</tr>';
	}

	/** 表单文本行。 */
	private static function form_text_row( $name, $label, $value ) {
		echo '<tr><th><label for="sa-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="text" class="regular-text" id="sa-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" /></td></tr>';
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
