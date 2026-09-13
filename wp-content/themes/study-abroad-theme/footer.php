<?php
/**
 * 页脚模板。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<footer class="sa-footer">
	<div class="sa-container">
		<div class="sa-footer__top">
			<div class="sa-footer__brand">
				<a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>" class="sa-logo">
					<span class="sa-logo__mark">●</span>
					<span><?php bloginfo( 'name' ); ?></span>
				</a>
				<p><?php echo esc_html( get_bloginfo( 'description' ) ); ?></p>
			</div>
			<div class="sa-footer__col">
				<h4><?php esc_html_e( 'ナビゲーション', 'sa-theme' ); ?></h4>
				<ul>
					<li><a href="<?php echo esc_url( sa_home_url( '/services/' ) ); ?>"><?php esc_html_e( 'サービス紹介', 'sa-theme' ); ?></a></li>
					<?php if ( function_exists( 'sa_schools_url' ) ) : ?>
						<?php // 院校列表页此前没有任何入口链接，是孤岛页面：只能靠 sitemap 被发现，
						// 权重几乎无法传递。页脚与主导航都必须有入口。 ?>
						<li><a href="<?php echo esc_url( sa_schools_url() ); ?>"><?php esc_html_e( '日本の学校情報一覧', 'sa-theme' ); ?></a></li>
					<?php endif; ?>
					<li><a href="<?php echo esc_url( sa_home_url( '/about/' ) ); ?>"><?php esc_html_e( '私たちについて', 'sa-theme' ); ?></a></li>
					<li><a href="<?php echo esc_url( sa_home_url( '/faq/' ) ); ?>"><?php esc_html_e( 'よくある質問', 'sa-theme' ); ?></a></li>
					<li><a href="<?php echo esc_url( sa_home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'お問い合わせ', 'sa-theme' ); ?></a></li>
				</ul>
			</div>
			<div class="sa-footer__col">
				<h4><?php esc_html_e( 'お問い合わせ', 'sa-theme' ); ?></h4>
				<ul>
					<li><?php esc_html_e( 'メール', 'sa-theme' ); ?>: info@example.com</li>
					<li>LINE / WeChat / WhatsApp</li>
				</ul>
			</div>
		</div>
		<?php
		/*
		 * 语种入口（页脚）
		 *
		 * 头部的语言切换器在下拉菜单里，虽然是真链接，但对搜索引擎而言
		 * 权重信号弱。hreflang 只声明「这些页面互为替代」，本身并不促使收录 ——
		 * 真正让 /zh/ 与 /en/ 被抓取的是稳定、全站可见的内链。
		 * 因此在页脚放一组始终可见、指向当前页各语种版本的链接。
		 */
		if ( function_exists( 'sa_current_url_in' ) ) :
			$sa_all_locales = sa_locales();
			if ( count( $sa_all_locales ) > 1 ) :
				?>
				<nav class="sa-footer__langs" aria-label="<?php esc_attr_e( '言語を選択', 'sa-theme' ); ?>">
					<span class="sa-footer__langs-label"><?php esc_html_e( '言語', 'sa-theme' ); ?></span>
					<ul>
						<?php
						$sa_cur = sa_current_locale();
						foreach ( $sa_all_locales as $sa_key => $sa_loc ) {
							printf(
								'<li><a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s</a></li>',
								esc_url( sa_current_url_in( $sa_key ) ),
								esc_attr( sa_locale_field( $sa_key, 'hreflang', $sa_key ) ),
								$sa_key === $sa_cur ? ' aria-current="true"' : '',
								esc_html( $sa_loc['label'] )
							);
						}
						?>
					</ul>
				</nav>
				<?php
			endif;
		endif;
		?>

		<?php
		// 全ページ共通の 1 行開示。学校名が出るページ以外でも立場を明示しておく。
		get_template_part(
			'template-parts/relationship-disclosure',
			null,
			array( 'variant' => 'compact' )
		);
		?>

		<div class="sa-footer__bottom">
			<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. All Rights Reserved.</span>
			<span>
				<a href="<?php echo esc_url( sa_home_url( '/privacy/' ) ); ?>"><?php esc_html_e( 'プライバシーポリシー', 'sa-theme' ); ?></a>
			</span>
		</div>
	</div>
</footer>

<!-- H5 底部固定 CTA -->
<div class="sa-mobile-cta">
	<a href="#lead-form" class="sa-btn sa-btn--primary" data-sa-cta="mobile-fixed"><?php esc_html_e( '無料で学校診断', 'sa-theme' ); ?></a>
</div>

<?php wp_footer(); ?>
</body>
</html>
