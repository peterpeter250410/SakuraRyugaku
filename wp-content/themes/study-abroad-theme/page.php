<?php
/**
 * 单页模板（关于/服务/隐私政策等）。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main class="sa-section">
	<div class="sa-container" style="max-width:820px;">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<h1 class="sa-section__title"><?php the_title(); ?></h1>
			<div class="sa-card__text"><?php the_content(); ?></div>
			<?php
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
