<?php
/**
 * 院校列表页（自定义端点 /schools/）。
 *
 * 由 inc/schools.php 的 template_include 加载，不是 WordPress 页面模板，
 * 因此后台不需要建页面，也不会出现在页面列表中。
 *
 * SEO：唯一 H1、独立 description、面包屑 + BreadcrumbList、
 *      每个院校卡片内链到详情页（详情页的唯一入口，保证爬虫可达）。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sa_per_page = 24;
$sa_paged    = max( 1, (int) get_query_var( 'sa_paged' ) );
$sa_total    = class_exists( 'SA_School_Repo' ) ? SA_School_Repo::count_published_schools() : 0;
$sa_pages    = $sa_total > 0 ? (int) ceil( $sa_total / $sa_per_page ) : 0;

$sa_schools = class_exists( 'SA_School_Repo' )
	? SA_School_Repo::get_published_schools(
		array(
			'limit'  => $sa_per_page,
			'offset' => ( $sa_paged - 1 ) * $sa_per_page,
		)
	)
	: array();

get_header();

get_template_part(
	'template-parts/site-page-head',
	null,
	array(
		'title' => __( '日本の学校情報一覧', 'sa-theme' ),
		'sub'   => __( '語学学校から大学院まで、日本の学校情報をまとめています。掲載内容は各校の公表資料に基づく参考情報です。', 'sa-theme' ),
		'crumb' => __( '学校情報', 'sa-theme' ),
	)
);
?>

<section class="sa-section">
	<div class="sa-container">

		<?php if ( empty( $sa_schools ) ) : ?>

			<div class="sa-card" style="max-width:820px;margin:0 auto;text-align:center;">
				<p class="sa-card__text">
					<?php esc_html_e( '現在公開中の学校情報はありません。無料のマッチング診断では、ご希望の条件に合う学校をご提案します。', 'sa-theme' ); ?>
				</p>
				<p style="margin-top:20px;">
					<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary" data-sa-cta="schools-empty">
						<?php esc_html_e( '無料で学校診断を受ける', 'sa-theme' ); ?>
					</a>
				</p>
			</div>

		<?php else : ?>

			<div class="sa-grid sa-grid--3 sa-school-grid">
				<?php
				foreach ( $sa_schools as $sa_school ) :
					$sa_name = sa_school_name( $sa_school );
					$sa_link = sa_school_url( $sa_school['slug'] );

					/*
					 * 卡片上的学费区间取该校各专业的最小下限与最大上限。
					 *
					 * 但只有在所有专业口径一致时才能这么合并 ——
					 * 把「年额」和「课程总额」放进同一个区间，无论标哪个单位
					 * 都会有一半的数字是错的。口径混杂时卡片不显示学费，
					 * 由详情页逐条呈现（那里每行都带自己的口径与注记）。
					 */
					$sa_programs = SA_School_Repo::get_school_programs( $sa_school['id'] );
					$sa_min      = 0;
					$sa_max      = 0;
					$sa_bases    = array();
					foreach ( $sa_programs as $sa_p ) {
						$pmin = (int) $sa_p['tuition_min'];
						$pmax = (int) $sa_p['tuition_max'];
						if ( $pmin <= 0 && $pmax <= 0 ) {
							continue; // 无学费数据的专业不参与，也不影响口径判断
						}
						$sa_bases[] = isset( $sa_p['tuition_basis'] ) ? $sa_p['tuition_basis'] : 'year';
						if ( $pmin > 0 && ( 0 === $sa_min || $pmin < $sa_min ) ) {
							$sa_min = $pmin;
						}
						if ( $pmax > $sa_max ) {
							$sa_max = $pmax;
						}
					}
					$sa_bases   = array_unique( $sa_bases );
					$sa_tuition = ( 1 === count( $sa_bases ) )
						? sa_tuition_range( $sa_min, $sa_max, reset( $sa_bases ) )
						: '';
					?>
					<article class="sa-card sa-school-card">
						<div class="sa-school-card__type">
							<?php echo esc_html( sa_school_type_label( $sa_school['school_type'] ) ); ?>
						</div>

						<h2 class="sa-card__title sa-school-card__name">
							<a href="<?php echo esc_url( $sa_link ); ?>"><?php echo esc_html( $sa_name ); ?></a>
						</h2>

						<ul class="sa-school-card__meta">
							<?php if ( ! empty( $sa_school['city'] ) || ! empty( $sa_school['region'] ) ) : ?>
								<li>
									<span><?php esc_html_e( '所在地', 'sa-theme' ); ?></span>
									<?php
									echo esc_html(
										implode(
											' ',
											array_filter( array( $sa_school['region'], $sa_school['city'] ) )
										)
									);
									?>
								</li>
							<?php endif; ?>

							<?php if ( ! empty( $sa_school['language_req'] ) ) : ?>
								<li>
									<span><?php esc_html_e( '語学要件', 'sa-theme' ); ?></span>
									<?php echo esc_html( $sa_school['language_req'] ); ?>
								</li>
							<?php endif; ?>

							<?php if ( '' !== $sa_tuition ) : ?>
								<li>
									<span><?php esc_html_e( '学費', 'sa-theme' ); ?></span>
									<?php echo esc_html( $sa_tuition ); ?>
								</li>
							<?php endif; ?>
						</ul>

						<a href="<?php echo esc_url( $sa_link ); ?>" class="sa-school-card__more">
							<?php esc_html_e( '詳細を見る', 'sa-theme' ); ?> →
						</a>
					</article>
				<?php endforeach; ?>
			</div>

			<?php if ( $sa_pages > 1 ) : ?>
				<nav class="sa-pagination" aria-label="<?php esc_attr_e( 'ページ送り', 'sa-theme' ); ?>">
					<?php
					for ( $i = 1; $i <= $sa_pages; $i++ ) {
						$url = 1 === $i
							? sa_schools_url()
							: trailingslashit( sa_schools_url() ) . 'page/' . $i . '/';

						if ( $i === $sa_paged ) {
							echo '<span aria-current="page">' . esc_html( $i ) . '</span>';
						} else {
							echo '<a href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
						}
					}
					?>
				</nav>
			<?php endif; ?>

		<?php endif; ?>

		<?php
		// 学校名を冠した情報を並べるページなので、一覧側にも関係性の全文開示が要る。
		get_template_part(
			'template-parts/relationship-disclosure',
			null,
			array( 'variant' => 'full' )
		);
		?>
	</div>
</section>

<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( 'どの学校が自分に合うか、まだ迷っていますか？', 'sa-theme' ); ?></h2>
		<p><?php esc_html_e( '予算と希望専攻を入力するだけ。AIがその場で候補校を診断します。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="schools-cta">
			<?php esc_html_e( '無料でAI診断を受ける', 'sa-theme' ); ?>
		</a>
	</div>
</section>

<?php
get_footer();
