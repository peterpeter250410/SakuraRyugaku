<?php
/**
 * 文章详情页（/guides/{slug}/）。
 *
 * 由 inc/articles.php 的 template_include 加载。
 * 语种不匹配的请求在 template_redirect 阶段已置 404，不会走到这里。
 *
 * 页面结构的取舍：
 *   摘要放在 H1 之下、正文之上。它同时是 meta description，
 *   页面上也给读者看 —— 搜索结果里承诺了什么，点进来第一眼就该看到什么。
 *
 *   出典放在正文之后。来源是支撑材料，挡在内容前面只会增加跳出。
 *   但它必须在页面上，不能只存在于结构化数据里：读者要能核对。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();

	$sa_post    = get_post();
	$sa_summary = sa_article_summary( $sa_post );
	$sa_updated = get_post_modified_time( 'U', true, $sa_post );
	$sa_created = get_post_time( 'U', true, $sa_post );
	?>

<div class="sa-page-head">
	<div class="sa-container">
		<?php
		sa_breadcrumb(
			array(
				array( __( 'ホーム', 'sa-theme' ), sa_home_url( '/' ) ),
				array( __( '日本留学ガイド', 'sa-theme' ), sa_articles_url() ),
				array( get_the_title(), '' ),
			)
		);
		?>

		<h1 class="sa-page-head__title"><?php the_title(); ?></h1>

		<p class="sa-article__dates">
			<?php
			/*
			 * 同时给出发布与更新时间。
			 *
			 * 只给发布时间，读者无从判断内容是否过期 —— 对签证、在留资格这类
			 * 逐年变动的主题，"什么时候核对过" 比 "什么时候写的" 更重要。
			 * 两者相同时只显示一个，避免出现「发布 X / 更新 X」这种废话。
			 */
			printf(
				/* translators: %s: 日期 */
				esc_html__( '公開：%s', 'sa-theme' ),
				esc_html( wp_date( get_option( 'date_format' ), $sa_created ) )
			);

			if ( $sa_updated - $sa_created > DAY_IN_SECONDS ) {
				echo ' · ';
				printf(
					/* translators: %s: 日期 */
					esc_html__( '最終更新：%s', 'sa-theme' ),
					esc_html( wp_date( get_option( 'date_format' ), $sa_updated ) )
				);
			}
			?>
		</p>
	</div>
</div>

<main id="sa-main" class="sa-section">
	<div class="sa-container sa-container--narrow">

		<?php if ( '' !== $sa_summary ) : ?>
			<div class="sa-article__summary">
				<?php echo esc_html( $sa_summary ); ?>
			</div>
		<?php endif; ?>

		<article class="sa-article__body">
			<?php the_content(); ?>
		</article>

		<?php sa_article_sources_block( $sa_post ); ?>

		<?php
		/*
		 * 内容生成方式的说明。
		 *
		 * 文章由站方编制并经流水线的来源核对后发布。把这件事写在页面上，
		 * 而不是含糊过去 —— 读者有权知道自己在读什么，这也是我们愿意
		 * 把每一条出典都列出来的同一个理由。
		 */
		?>
		<p class="sa-article__disclosure">
			<?php esc_html_e( '本記事は当サイト編集部が公的機関・各校の公表資料をもとに作成し、出典を明記しています。制度や費用は変更されることがあるため、出願前に必ず一次情報をご確認ください。', 'sa-theme' ); ?>
		</p>

		<p class="sa-article__back">
			<a href="<?php echo esc_url( sa_articles_url() ); ?>">
				<?php esc_html_e( '← ガイド記事一覧へ', 'sa-theme' ); ?>
			</a>
		</p>

	</div>
</main>

	<?php
endwhile;

get_footer();
