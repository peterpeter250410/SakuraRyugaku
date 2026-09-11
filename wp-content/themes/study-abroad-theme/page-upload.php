<?php
/**
 * 学生资料上传页（slug: upload）。
 *
 * 免密进入：诊断后「选校」触发 claim，返回专属链接 /upload/?u={uid}&k={token}。
 * 本页顶部读 u/k → SA_Student_Onboard::verify_token → 通过则 login_as 设置登录态，
 * 否则展示「链接失效，重新诊断」CTA。
 *
 * 成功后按该用户的每条选校记录（selections join schools），用院校资料清单
 * （SA_School_Repo::get_required_docs）渲染逐项上传控件：
 *   - image/pdf/office → <input type="file" accept>
 *   - text            → <textarea>
 *   - required 标 *；已提交项显示状态。
 *
 * 提交由 main.js（[data-sa-upload-form]）逐项 POST 到 sa/v1/upload-doc。
 *
 * SEO：本页 noindex（functions.php robots 判断已含 slug 'upload'）。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

sa_set_meta_description( __( '出願書類の提出ページです。', 'sa-theme' ) );

/*
 * 免密登录处理：仅在核心插件可用时进行。
 * 优先信任已登录学生（刷新页面时不必重复带 token）；
 * 否则用链接中的 u/k 校验后 login_as。
 */
$sa_upload_ready = false;
$sa_upload_uid   = 0;

if ( class_exists( 'SA_Student_Onboard' ) ) {
	// 已登录用户直接放行（token 首次进入后已 set_auth_cookie）。
	if ( is_user_logged_in() ) {
		$sa_upload_uid   = get_current_user_id();
		$sa_upload_ready = true;
	} else {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 免密链接自身即凭证（token 常量时间比对）。
		$u = isset( $_GET['u'] ) ? absint( wp_unslash( $_GET['u'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$k = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';

		if ( $u && '' !== $k && SA_Student_Onboard::verify_token( $u, $k ) ) {
			SA_Student_Onboard::login_as( $u );
			$sa_upload_uid   = $u;
			$sa_upload_ready = true;
		}
	}
}

get_header();
?>

<!-- 上传页不索引 -->
<div data-sa-upload-page="1" aria-hidden="true"></div>

<main class="sa-upload">
	<div class="sa-container sa-upload-wrap">

<?php if ( ! $sa_upload_ready ) : ?>

		<div class="sa-upload-expired">
			<h1 class="sa-upload-expired__title"><?php esc_html_e( 'リンクが無効です', 'sa-theme' ); ?></h1>
			<p class="sa-upload-expired__lead">
				<?php esc_html_e( 'この提出リンクは期限切れか、無効になっています。トップページで再度 AI 診断を受け、学校を選択すると新しいリンクが発行されます。', 'sa-theme' ); ?>
			</p>
			<a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="upload-reactivate">
				<?php esc_html_e( 'トップへ戻って再診断する', 'sa-theme' ); ?>
			</a>
		</div>

<?php else :

	// 取该用户「最新选的一所」院校（join schools 取校名 + 资料清单）。
	// 业务约定：一个用户仅对应最近一次选校，故 LIMIT 1 作为兜底。
	global $wpdb;
	$sel_table    = SA_DB::table( 'selections' );
	$school_table = SA_DB::table( 'schools' );
	$prog_table   = SA_DB::table( 'programs' );

	$selections = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.id AS selection_id, s.school_id, s.program_id, s.status,
			        sc.name AS school_name, p.name AS program_name
			   FROM {$sel_table} s
			   LEFT JOIN {$school_table} sc ON sc.id = s.school_id
			   LEFT JOIN {$prog_table} p ON p.id = s.program_id
			  WHERE s.user_id = %d
			  ORDER BY s.created_at DESC
			  LIMIT 1",
			absint( $sa_upload_uid )
		),
		ARRAY_A
	);

	// 当前有效 selection 白名单：仅回显属于当前展示学校的文档，
	// 杜绝换校后历史文档（旧 selection）串显「提出済み」。
	$valid_sel_ids = array();
	foreach ( (array) $selections as $sel ) {
		$valid_sel_ids[ (int) $sel['selection_id'] ] = true;
	}

	// 已上传文档索引：key = selection_id . '|' . doc_type → status，用于回显。
	$uploaded = array();
	if ( class_exists( 'SA_Doc_Repo' ) ) {
		foreach ( (array) SA_Doc_Repo::list_by_user( $sa_upload_uid ) as $doc ) {
			$sel_id = isset( $doc['selection_id'] ) ? (int) $doc['selection_id'] : 0;
			// 跳过不属于当前展示学校的历史文档。
			if ( ! isset( $valid_sel_ids[ $sel_id ] ) ) {
				continue;
			}
			$dtype  = isset( $doc['doc_type'] ) ? (string) $doc['doc_type'] : '';
			$uploaded[ $sel_id . '|' . $dtype ] = isset( $doc['status'] ) ? (string) $doc['status'] : 'uploaded';
		}
	}

	// 各文档类型 → <input accept> 映射。
	$accept_map = array(
		'image'  => 'image/jpeg,image/png,image/webp,image/heic,.jpg,.jpeg,.png,.webp,.heic',
		'pdf'    => 'application/pdf,.pdf',
		'office' => '.doc,.docx,.xls,.xlsx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);
	?>

		<header class="sa-upload__header">
			<h1 class="sa-upload__title"><?php esc_html_e( '出願書類のご提出', 'sa-theme' ); ?></h1>
			<p class="sa-upload__lead">
				<?php esc_html_e( '選択された学校ごとに、必要書類をアップロードしてください。ファイルは暗号化して安全に保管されます。', 'sa-theme' ); ?>
			</p>
		</header>

	<?php if ( empty( $selections ) ) : ?>

		<div class="sa-upload-empty">
			<p><?php esc_html_e( '提出対象の学校がまだありません。トップページで AI 診断を受けて学校を選択してください。', 'sa-theme' ); ?></p>
			<a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>" class="sa-btn sa-btn--primary">
				<?php esc_html_e( 'AI診断を受ける', 'sa-theme' ); ?>
			</a>
		</div>

	<?php else : ?>

		<div data-sa-upload-form>
		<?php
		$sa_text_items = array(); // 收集文本项（如志望理由），页尾统一作为「最终提交」。
		foreach ( $selections as $sel ) :
			$selection_id = (int) $sel['selection_id'];
			$school_id    = (int) $sel['school_id'];
			$school_name  = $sel['school_name'] ? $sel['school_name'] : __( '（学校）', 'sa-theme' );
			$program_name = $sel['program_name'] ? $sel['program_name'] : '';
			$docs         = SA_School_Repo::get_required_docs( $school_id );
			?>
			<section class="sa-upload-school">
				<h2 class="sa-upload-school__name">
					<?php echo esc_html( $school_name ); ?>
					<?php if ( $program_name ) : ?>
						<span class="sa-upload-school__program"><?php echo esc_html( $program_name ); ?></span>
					<?php endif; ?>
				</h2>

				<div class="sa-upload-items">
				<?php foreach ( $docs as $doc ) :
					$doc_type = $doc['key'];
					$doc_kind = $doc['type']; // image|pdf|office|text
					$label    = $doc['label'];
					$required = ! empty( $doc['required'] );
					$done     = isset( $uploaded[ $selection_id . '|' . $doc_type ] );
					$field_id = 'sa-doc-' . $selection_id . '-' . $doc_type;

					// 文本项延后到页尾「最终提交」区，这里跳过。
					if ( 'text' === $doc_kind ) {
						$sa_text_items[] = array(
							'selection_id' => $selection_id,
							'doc_type'     => $doc_type,
							'label'        => $label,
							'field_id'     => $field_id,
							'done'         => $done,
							'value'        => $done && isset( $uploaded[ $selection_id . '|' . $doc_type . '|text' ] )
								? $uploaded[ $selection_id . '|' . $doc_type . '|text' ] : '',
						);
						continue;
					}

					$accept = isset( $accept_map[ $doc_kind ] ) ? $accept_map[ $doc_kind ] : '';
					?>
					<div class="sa-upload-item<?php echo $done ? ' is-uploaded' : ''; ?>"
						data-sa-upload-item
						data-doc-type="<?php echo esc_attr( $doc_type ); ?>"
						data-doc-kind="<?php echo esc_attr( $doc_kind ); ?>"
						data-required="<?php echo $required ? '1' : '0'; ?>"
						data-selection-id="<?php echo esc_attr( $selection_id ); ?>"
						data-user-id="<?php echo esc_attr( $sa_upload_uid ); ?>">

						<label class="sa-upload-item__label" for="<?php echo esc_attr( $field_id ); ?>">
							<?php echo esc_html( $label ); ?>
							<?php if ( $required ) : ?>
								<span class="sa-upload-item__req" aria-hidden="true">*</span>
							<?php endif; ?>
						</label>

						<label class="sa-dropzone<?php echo $done ? ' is-uploaded' : ''; ?>" for="<?php echo esc_attr( $field_id ); ?>" data-sa-dropzone>
							<span class="sa-dropzone__icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
									<polyline points="17 8 12 3 7 8"></polyline>
									<line x1="12" y1="3" x2="12" y2="15"></line>
								</svg>
							</span>
							<span class="sa-dropzone__text">
								<span class="sa-dropzone__title" data-sa-dropzone-title><?php esc_html_e( 'ファイルを選択', 'sa-theme' ); ?></span>
								<span class="sa-dropzone__hint"><?php esc_html_e( 'クリックまたはドラッグ＆ドロップ', 'sa-theme' ); ?></span>
							</span>
							<input type="file" id="<?php echo esc_attr( $field_id ); ?>" class="sa-upload-item__file"
								accept="<?php echo esc_attr( $accept ); ?>" data-sa-upload-file
								<?php echo $done ? 'disabled' : ''; ?>>
						</label>

						<div class="sa-upload-item__actions">
							<span class="sa-upload-status<?php echo $done ? ' sa-upload-status--ok' : ''; ?>" data-sa-upload-status>
								<?php echo $done ? esc_html__( '提出済み', 'sa-theme' ) : ''; ?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>

			<?php
			// -------- 页尾「最终提交」区：可选文本项 + 总提交按钮 --------
			// 默认置灰；main.js 在全部必交附件上传成功后启用；点击后（含文本）跳转成功页。
			$sa_first_text = ! empty( $sa_text_items ) ? $sa_text_items[0] : null;
			?>
			<section class="sa-upload-final" data-sa-upload-final
				<?php if ( $sa_first_text ) : ?>
					data-selection-id="<?php echo esc_attr( $sa_first_text['selection_id'] ); ?>"
					data-doc-type="<?php echo esc_attr( $sa_first_text['doc_type'] ); ?>"
					data-user-id="<?php echo esc_attr( $sa_upload_uid ); ?>"
				<?php endif; ?>>

				<?php if ( $sa_first_text ) : ?>
					<label class="sa-upload-item__label" for="<?php echo esc_attr( $sa_first_text['field_id'] ); ?>">
						<?php echo esc_html( $sa_first_text['label'] ); ?>
					</label>
					<textarea id="<?php echo esc_attr( $sa_first_text['field_id'] ); ?>"
						class="sa-upload-item__textarea" rows="4" data-sa-upload-text
						placeholder="<?php esc_attr_e( 'こちらにご記入ください', 'sa-theme' ); ?>"><?php echo esc_textarea( $sa_first_text['value'] ); ?></textarea>
				<?php endif; ?>

				<p class="sa-upload-final__hint" data-sa-upload-hint>
					<?php esc_html_e( '必須書類（*）をすべてアップロードすると、下のボタンで提出を完了できます。', 'sa-theme' ); ?>
				</p>

				<button type="button" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-upload-final-submit disabled>
					<?php esc_html_e( '提出する', 'sa-theme' ); ?>
				</button>
				<span class="sa-upload-status" data-sa-upload-final-status></span>
			</section>
		</div>

	<?php endif; ?>

<?php endif; ?>

	</div>
</main>

<?php
get_footer();
