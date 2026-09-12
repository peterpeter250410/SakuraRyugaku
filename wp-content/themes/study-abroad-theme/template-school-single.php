<?php
/**
 * 院校详情页（自定义端点 /schools/{slug}/）。
 *
 * 由 inc/schools.php 的 template_include 加载。
 * 未发布或不存在的院校在 template_redirect 阶段已被置为 404，不会走到这里。
 *
 * SEO：唯一 H1（院校名）、逐校独立 description、canonical 指向本页、
 *      面包屑 + BreadcrumbList、WebPage/about 结构化数据（见 inc/schools.php）。
 *
 * 内容准确性：学费一律以「目安」呈现。这些数据来自院校库，
 * 发布开关默认关闭，须业务方核实后逐校开启，详见 inc/schools.php 顶部说明。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sa_school = sa_current_school();

if ( ! $sa_school ) {
	// 兜底：正常流程不会到这里（template_redirect 已置 404）。
	get_header();
	echo '<main class="sa-section"><div class="sa-container"><p>'
		. esc_html__( 'コンテンツが見つかりませんでした。', 'sa-theme' )
		. '</p></div></main>';
	get_footer();
	return;
}

$sa_name     = sa_school_name( $sa_school );
$sa_desc     = sa_school_description( $sa_school );
$sa_programs = class_exists( 'SA_School_Repo' ) ? SA_School_Repo::get_school_programs( $sa_school['id'] ) : array();
$sa_docs     = class_exists( 'SA_School_Repo' ) ? SA_School_Repo::get_required_docs( $sa_school['id'] ) : array();
$sa_type     = sa_school_type_label( isset( $sa_school['school_type'] ) ? $sa_school['school_type'] : '' );

// 未发布院校的管理员预览：明确标示，避免误以为已对外可见。
$sa_is_preview = empty( $sa_school['published'] );

get_header();
?>

<div class="sa-page-head">
	<div class="sa-container">
		<?php
		sa_breadcrumb(
			array(
				array( __( 'ホーム', 'sa-theme' ), sa_home_url( '/' ) ),
				array( __( '対応院校', 'sa-theme' ), sa_schools_url() ),
				array( $sa_name, '' ),
			)
		);
		?>

		<?php if ( $sa_is_preview ) : ?>
			<p class="sa-preview-notice">
				<?php esc_html_e( '【未公開プレビュー】この院校ページはまだ公開されていません。管理者のみ閲覧できます。', 'sa-theme' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $sa_type ) : ?>
			<span class="sa-school-type-badge"><?php echo esc_html( $sa_type ); ?></span>
		<?php endif; ?>

		<h1 class="sa-page-head__title"><?php echo esc_html( $sa_name ); ?></h1>

		<?php if ( ! empty( $sa_school['region'] ) || ! empty( $sa_school['city'] ) ) : ?>
			<p class="sa-page-head__sub">
				<?php
				echo esc_html(
					implode( ' ', array_filter( array( $sa_school['region'], $sa_school['city'] ) ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</div>

<section class="sa-section">
	<div class="sa-container sa-school-detail">

		<!-- 基本情報 -->
		<h2 class="sa-school-detail__h2"><?php esc_html_e( '基本情報', 'sa-theme' ); ?></h2>
		<div class="sa-school-facts">
			<dl>
				<?php if ( '' !== $sa_type ) : ?>
					<div><dt><?php esc_html_e( '学校種別', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_type ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['region'] ) ) : ?>
					<div><dt><?php esc_html_e( '地域', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_school['region'] ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['city'] ) ) : ?>
					<div><dt><?php esc_html_e( '所在地', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_school['city'] ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['language_req'] ) ) : ?>
					<div><dt><?php esc_html_e( '語学要件', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_school['language_req'] ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['min_education'] ) ) : ?>
					<div><dt><?php esc_html_e( '最低学歴', 'sa-theme' ); ?></dt><dd><?php echo esc_html( sa_min_education_label( $sa_school['min_education'] ) ); ?></dd></div>
				<?php endif; ?>
			</dl>
		</div>

		<!-- 学校紹介 -->
		<?php if ( '' !== trim( wp_strip_all_tags( $sa_desc ) ) ) : ?>
			<h2 class="sa-school-detail__h2"><?php esc_html_e( '学校紹介', 'sa-theme' ); ?></h2>
			<div class="sa-card__text sa-school-detail__body">
				<?php echo wp_kses_post( wpautop( $sa_desc ) ); ?>
			</div>
		<?php endif; ?>

		<!-- 募集専攻 -->
		<?php if ( ! empty( $sa_programs ) ) : ?>
			<h2 class="sa-school-detail__h2"><?php esc_html_e( '募集専攻・学費目安', 'sa-theme' ); ?></h2>
			<div class="sa-table-wrap">
				<table class="sa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '専攻', 'sa-theme' ); ?></th>
							<th><?php esc_html_e( '学費（目安）', 'sa-theme' ); ?></th>
							<th><?php esc_html_e( '語学要件', 'sa-theme' ); ?></th>
							<th><?php esc_html_e( '修業年限', 'sa-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sa_programs as $sa_p ) : ?>
							<tr>
								<td><?php echo esc_html( sa_program_name( $sa_p ) ); ?></td>
								<td>
									<?php
									$sa_t = sa_tuition_range( $sa_p['tuition_min'], $sa_p['tuition_max'] );
									echo '' !== $sa_t ? esc_html( $sa_t ) : '—';
									?>
								</td>
								<td><?php echo ! empty( $sa_p['language_req'] ) ? esc_html( $sa_p['language_req'] ) : '—'; ?></td>
								<td><?php echo ! empty( $sa_p['duration'] ) ? esc_html( $sa_p['duration'] ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="sa-note">
				<?php esc_html_e( '※ 学費は目安です。入学金・教材費などが別途必要な場合があります。最新の金額は各校の募集要項をご確認ください。', 'sa-theme' ); ?>
			</p>
		<?php endif; ?>

		<!-- 出願に必要な書類 -->
		<?php if ( ! empty( $sa_docs ) ) : ?>
			<h2 class="sa-school-detail__h2"><?php esc_html_e( '出願に必要な書類', 'sa-theme' ); ?></h2>
			<ul class="sa-doc-list">
				<?php foreach ( $sa_docs as $sa_doc ) : ?>
					<?php if ( empty( $sa_doc['label'] ) ) { continue; } ?>
					<li>
						<?php echo esc_html( $sa_doc['label'] ); ?>
						<?php if ( ! empty( $sa_doc['required'] ) ) : ?>
							<span class="sa-doc-list__req"><?php esc_html_e( '必須', 'sa-theme' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="sa-note">
				<?php esc_html_e( '※ 学歴や国籍により追加書類を求められる場合があります。詳細は無料相談でご案内します。', 'sa-theme' ); ?>
			</p>
		<?php endif; ?>

		<!-- 内链：回列表 -->
		<p class="sa-school-detail__back">
			<a href="<?php echo esc_url( sa_schools_url() ); ?>">← <?php esc_html_e( '対応院校一覧に戻る', 'sa-theme' ); ?></a>
		</p>
	</div>
</section>

<section class="sa-cta-band">
	<div class="sa-container">
		<h2>
			<?php
			/* translators: %s: 院校名 */
			printf( esc_html__( '%s に出願できるか、無料で診断', 'sa-theme' ), esc_html( $sa_name ) );
			?>
		</h2>
		<p><?php esc_html_e( '予算・日本語レベル・希望専攻を入力するだけ。マッチ度をその場で確認できます。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="school-detail-cta">
			<?php esc_html_e( '無料でAI診断を受ける', 'sa-theme' ); ?>
		</a>
	</div>
</section>

<?php
get_footer();
