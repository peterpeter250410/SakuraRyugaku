<?php
/**
 * 文章列表页（/guides/）。
 *
 * 由 inc/articles.php 的 template_include 加载。
 * 只列出当前语种的文章 —— 过滤在 inc/articles.php 的 pre_get_posts 中完成，
 * 这里直接用主查询即可。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="sa-page-head">
	<div class="sa-container">
		<?php
		sa_breadcrumb(
			array(
				array( __( 'ホーム', 'sa-theme' ), sa_home_url( '/' ) ),
				array( __( '日本留学ガイド', 'sa-theme' ), '' ),
			)
		);
		?>
		<h1 class="sa-page-head__title"><?php esc_html_e( '日本留学ガイド', 'sa-theme' ); ?></h1>
		<p class="sa-page-head__sub">
			<?php esc_html_e( '手続き・在留資格・学校選び・現地生活について、出典を明記してまとめています。', 'sa-theme' ); ?>
		</p>
	</div>
</div>

<main id="sa-main" class="sa-section">
	<div class="sa-container">

		<?php if ( have_posts() ) : ?>

			<div class="sa-article-list">
				<?php
				while ( have_posts() ) :
					the_post();
					$sa_p       = get_post();
					$sa_summary = sa_article_summary( $sa_p );
					$sa_src_n   = count( sa_article_sources( $sa_p ) );
					?>
					<article class="sa-article-card">
						<h2 class="sa-article-card__title">
							<a href="<?php echo esc_url( sa_article_url( $sa_p ) ); ?>"><?php the_title(); ?></a>
						</h2>

						<?php if ( '' !== $sa_summary ) : ?>
							<p class="sa-article-card__summary">
								<?php echo esc_html( wp_trim_words( $sa_summary, 45 ) ); ?>
							</p>
						<?php endif; ?>

						<p class="sa-article-card__meta">
							<time datetime="<?php echo esc_attr( get_post_modified_time( DATE_W3C, true, $sa_p ) ); ?>">
								<?php echo esc_html( get_the_modified_date() ); ?>
							</time>
							<?php if ( $sa_src_n > 0 ) : ?>
								<span class="sa-article-card__sources">
									<?php
									printf(
										/* translators: %d: 出典件数 */
										esc_html( _n( '出典 %d 件', '出典 %d 件', $sa_src_n, 'sa-theme' ) ),
										(int) $sa_src_n
									);
									?>
								</span>
							<?php endif; ?>
						</p>
					</article>
					<?php
				endwhile;
				?>
			</div>

			<?php
			the_posts_pagination(
				array(
					'mid_size'  => 2,
					'prev_text' => __( '前へ', 'sa-theme' ),
					'next_text' => __( '次へ', 'sa-theme' ),
				)
			);
			?>

		<?php else : ?>

			<p><?php esc_html_e( 'まだ記事がありません。', 'sa-theme' ); ?></p>

		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
