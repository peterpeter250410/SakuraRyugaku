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
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="upload-reactivate">
				<?php esc_html_e( 'トップへ戻って再診断する', 'sa-theme' ); ?>
			</a>
		</div>

<?php else :

	// 取该用户的选校记录（join schools 取校名 + 资料清单）。
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
			  ORDER BY s.created_at DESC",
			absint( $sa_upload_uid )
		),
		ARRAY_A
	);

	// 已上传文档索引：key = selection_id . '|' . doc_type → status，用于回显。
	$uploaded = array();
	if ( class_exists( 'SA_Doc_Repo' ) ) {
		foreach ( (array) SA_Doc_Repo::list_by_user( $sa_upload_uid ) as $doc ) {
			$sel_id = isset( $doc['selection_id'] ) ? (int) $doc['selection_id'] : 0;
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
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sa-btn sa-btn--primary">
				<?php esc_html_e( 'AI診断を受ける', 'sa-theme' ); ?>
			</a>
		</div>

	<?php else : ?>

		<div data-sa-upload-form>
		<?php
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

						<?php if ( 'text' === $doc_kind ) : ?>
							<textarea id="<?php echo esc_attr( $field_id ); ?>" class="sa-upload-item__textarea" rows="4"
								placeholder="<?php esc_attr_e( 'こちらにご記入ください', 'sa-theme' ); ?>"></textarea>
						<?php else :
							$accept = isset( $accept_map[ $doc_kind ] ) ? $accept_map[ $doc_kind ] : '';
							?>
							<input type="file" id="<?php echo esc_attr( $field_id ); ?>" class="sa-upload-item__file"
								accept="<?php echo esc_attr( $accept ); ?>">
						<?php endif; ?>

						<div class="sa-upload-item__actions">
							<button type="button" class="sa-btn sa-btn--primary sa-btn--sm" data-sa-upload-submit>
								<?php echo $done ? esc_html__( '再提出', 'sa-theme' ) : esc_html__( '提出する', 'sa-theme' ); ?>
							</button>
							<span class="sa-upload-status<?php echo $done ? ' sa-upload-status--ok' : ''; ?>" data-sa-upload-status>
								<?php echo $done ? esc_html__( '提出済み', 'sa-theme' ) : ''; ?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>

			<div class="sa-upload-complete" data-sa-upload-complete hidden>
				<div class="sa-upload-complete__icon" aria-hidden="true">&#10003;</div>
				<h2 class="sa-upload-complete__title"><?php esc_html_e( '書類のご提出が完了しました', 'sa-theme' ); ?></h2>
				<p class="sa-upload-complete__lead">
					<?php esc_html_e( '必要書類をすべてお預かりしました。担当者が内容を確認のうえ、追ってご連絡いたします。', 'sa-theme' ); ?>
				</p>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sa-btn sa-btn--primary">
					<?php esc_html_e( 'トップページへ戻る', 'sa-theme' ); ?>
				</a>
			</div>
		</div>

	<?php endif; ?>

<?php endif; ?>

	</div>
</main>

<?php
get_footer();
