<?php
/**
 * 感谢页 / 转化确认页（L-13）。
 *
 * 用途：意向表单提交后作为独立可访问的转化确认落地（供 GA4 转化归因、
 * 邮件/广告二跳、以及无 JS 场景回退）。页面 noindex（见 functions.php robots 判断），
 * 不参与排名但可访问、可埋点。
 *
 * 绑定：创建 slug 为 `thanks` 的页面并指定本模板，或直接命名页面 slug=thanks 自动匹配。
 *
 * 埋点：data-sa-thanks 标记感谢页视图，供 tracker.js 上报显式转化确认。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 页面级 SEO 描述（即便 noindex，也保持语义完整）。
sa_set_meta_description( __( 'お申し込みありがとうございます。担当アドバイザーより順次ご連絡いたします。', 'sa-theme' ) );

get_header();
?>

<!-- 感谢页视图埋点标记（tracker.js 监听，上报转化确认） -->
<div data-sa-thanks="1" aria-hidden="true"></div>

<main class="sa-thanks">
	<div class="sa-container sa-thanks__inner">
		<div class="sa-thanks__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="56" height="56" role="img" aria-label="<?php esc_attr_e( '完了', 'sa-theme' ); ?>">
				<circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3"></circle>
				<path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
			</svg>
		</div>

		<h1 class="sa-thanks__title"><?php esc_html_e( 'お申し込みありがとうございます', 'sa-theme' ); ?></h1>
		<p class="sa-thanks__lead">
			<?php esc_html_e( 'ご入力いただいた情報をもとに、担当アドバイザーより順次ご連絡いたします。今しばらくお待ちください。', 'sa-theme' ); ?>
		</p>

		<!-- 次のステップ（安心感 + 内链，利于用户与 SEO 内链结构） -->
		<div class="sa-thanks__steps">
			<h2 class="sa-thanks__subtitle"><?php esc_html_e( 'このあとの流れ', 'sa-theme' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'ご入力内容をもとにシステムが候補校をマッチングします。', 'sa-theme' ); ?></li>
				<li><?php esc_html_e( '担当アドバイザーがご希望を確認し、最適なプランをご提案します。', 'sa-theme' ); ?></li>
				<li><?php esc_html_e( '学校選び・出願書類の準備をサポートします。', 'sa-theme' ); ?></li>
			</ol>
		</div>

		<div class="sa-thanks__actions">
			<a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="thanks-home">
				<?php esc_html_e( 'トップへ戻る', 'sa-theme' ); ?>
			</a>
			<a href="<?php echo esc_url( sa_home_url( '/faq/' ) ); ?>" class="sa-btn sa-btn--ghost sa-btn--lg" data-sa-cta="thanks-faq">
				<?php esc_html_e( 'よくある質問を見る', 'sa-theme' ); ?>
			</a>
		</div>

		<?php
		// 若页面正文有额外内容（如联系方式补充），一并输出。
		while ( have_posts() ) :
			the_post();
			$content = get_the_content();
			if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
				echo '<div class="sa-thanks__extra">' . wp_kses_post( apply_filters( 'the_content', $content ) ) . '</div>';
			}
		endwhile;
		?>
	</div>
</main>

<?php
get_footer();
