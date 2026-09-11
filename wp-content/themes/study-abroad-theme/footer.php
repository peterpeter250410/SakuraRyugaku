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
