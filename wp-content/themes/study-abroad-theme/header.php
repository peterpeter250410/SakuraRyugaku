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
	<meta name="theme-color" content="#d4372c">
	<meta name="format-detection" content="telephone=no">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="sa-header">
	<div class="sa-container sa-header__inner">
		<a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>" class="sa-logo">
			<span class="sa-logo__mark">●</span>
			<span><?php bloginfo( 'name' ); ?></span>
		</a>

		<button class="sa-menu-btn" type="button" aria-label="<?php esc_attr_e( 'メニュー', 'sa-theme' ); ?>" aria-expanded="false" data-sa-drawer-toggle><span></span><span></span><span></span></button>

		<div class="sa-drawer" data-sa-drawer>
			<div class="sa-drawer__head">
				<a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>" class="sa-logo">
					<span class="sa-logo__mark">●</span>
					<span><?php bloginfo( 'name' ); ?></span>
				</a>
				<button class="sa-drawer__close" type="button" aria-label="<?php esc_attr_e( '閉じる', 'sa-theme' ); ?>" data-sa-drawer-close>&times;</button>
			</div>

			<nav class="sa-nav" aria-label="<?php esc_attr_e( '主导航', 'sa-theme' ); ?>">
				<?php
				if ( has_nav_menu( 'primary' ) ) {
					wp_nav_menu( array(
						'theme_location' => 'primary',
						'container'      => false,
						'items_wrap'     => '<ul>%3$s</ul>',
						'depth'          => 2,
					) );
				} else {
					echo '<ul>';
					echo '<li><a href="#services">' . esc_html__( 'サービス', 'sa-theme' ) . '</a></li>';
					echo '<li><a href="#flow">' . esc_html__( '流れ', 'sa-theme' ) . '</a></li>';
					// 院校列表页需要主导航入口，否则是只能靠 sitemap 发现的孤岛页面。
					if ( function_exists( 'sa_schools_url' ) ) {
						echo '<li><a href="' . esc_url( sa_schools_url() ) . '">' . esc_html__( '対応院校一覧', 'sa-theme' ) . '</a></li>';
					}
					echo '<li><a href="#faq">' . esc_html__( 'よくある質問', 'sa-theme' ) . '</a></li>';
					echo '</ul>';
				}
				?>
			</nav>

			<div class="sa-drawer__foot">
				<div class="sa-lang">
					<?php
					$sa_locales_all = sa_locales();
					$sa_cur_locale  = sa_current_locale();
					?>
					<button class="sa-lang__btn" type="button" aria-haspopup="true" aria-expanded="false">
						<?php echo esc_html( isset( $sa_locales_all[ $sa_cur_locale ]['label'] ) ? $sa_locales_all[ $sa_cur_locale ]['label'] : $sa_cur_locale ); ?> ▾
					</button>
					<ul class="sa-lang__menu">
						<?php
						foreach ( $sa_locales_all as $sa_key => $sa_loc ) {
							// 切换语种时停留在当前页面的对应语种版本，而不是一律跳回首页。
							// 把用户从内页甩回首页既伤转化，也让搜索引擎难以建立页面级语种对应关系。
							$sa_lang_url = sa_current_url_in( $sa_key );
							$sa_is_cur   = ( $sa_key === $sa_cur_locale );

							printf(
								'<li><a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s</a></li>',
								esc_url( $sa_lang_url ),
								esc_attr( sa_locale_field( $sa_key, 'hreflang', $sa_key ) ),
								$sa_is_cur ? ' aria-current="true"' : '',
								esc_html( $sa_loc['label'] )
							);
						}
						?>
					</ul>
				</div>
				<a href="#lead-form" class="sa-btn sa-btn--primary" data-sa-cta="header" data-sa-drawer-close>
					<?php esc_html_e( '無料相談', 'sa-theme' ); ?>
				</a>
			</div>
		</div>

		<div class="sa-drawer__overlay" data-sa-drawer-close hidden></div>
	</div>
</header>
