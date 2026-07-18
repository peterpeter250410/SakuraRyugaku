<?php
/**
 * 通用回退模板。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main class="sa-section">
	<div class="sa-container">
		<?php
		if ( have_posts() ) :
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class( 'sa-card' ); ?> style="margin-bottom:24px;">
					<h2 class="sa-card__title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</h2>
					<div class="sa-card__text"><?php the_excerpt(); ?></div>
				</article>
				<?php
			endwhile;

			the_posts_pagination();
		else :
			echo '<p>' . esc_html__( 'コンテンツが見つかりませんでした。', 'sa-theme' ) . '</p>';
		endif;
		?>
	</div>
</main>
<?php
get_footer();
