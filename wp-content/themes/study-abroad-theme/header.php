<?php
/**
 * 页头模板。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="sa-header">
	<div class="sa-container sa-header__inner">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="sa-logo">
			<span class="sa-logo__mark">●</span>
			<span><?php bloginfo( 'name' ); ?></span>
		</a>

		<nav class="sa-nav" aria-label="<?php esc_attr_e( '主导航', 'sa-theme' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'container'      => false,
					'items_wrap'     => '<ul>%3$s</ul>',
					'depth'          => 1,
				) );
			} else {
				echo '<ul>';
				echo '<li><a href="#services">' . esc_html__( 'サービス', 'sa-theme' ) . '</a></li>';
				echo '<li><a href="#flow">' . esc_html__( '流れ', 'sa-theme' ) . '</a></li>';
				echo '<li><a href="#faq">' . esc_html__( 'よくある質問', 'sa-theme' ) . '</a></li>';
				echo '</ul>';
			}
			?>
		</nav>

		<div class="sa-header__cta">
			<div class="sa-lang">
				<button class="sa-lang__btn" type="button" aria-haspopup="true">
					<?php
					$locales = sa_locales();
					$cur     = sa_current_locale();
					echo esc_html( isset( $locales[ $cur ]['label'] ) ? $locales[ $cur ]['label'] : $cur );
					?> ▾
				</button>
				<ul class="sa-lang__menu">
					<?php
					$home = home_url( '/' );
					foreach ( sa_locales() as $key => $loc ) {
						$url = '' === $loc['prefix'] ? $home : trailingslashit( $home . $loc['prefix'] );
						echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $loc['label'] ) . '</a></li>';
					}
					?>
				</ul>
			</div>
			<a href="#lead-form" class="sa-btn sa-btn--primary" data-sa-cta="header">
				<?php esc_html_e( '無料相談', 'sa-theme' ); ?>
			</a>
			<button class="sa-menu-btn" aria-label="Menu"><span></span><span></span><span></span></button>
		</div>
	</div>
</header>
