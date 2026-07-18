<?php
/**
 * Template Name: お問い合わせページ
 *
 * 联系我们页（A-04）。联系方式、在线咨询入口、意向表单入口。
 * SEO：唯一 H1、独立 description、面包屑 + BreadcrumbList。
 *
 * 绑定：后台新建页面（建议 slug=contact）→ 模板选「お問い合わせページ」。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

sa_set_meta_description( __( '桜留学へのお問い合わせ。メール・LINE・WeChat・WhatsApp でご相談いただけます。無料の学校マッチングもこちらから。', 'sa-theme' ) );

get_header();

get_template_part(
	'template-parts/site-page-head',
	null,
	array(
		'title' => __( 'お問い合わせ', 'sa-theme' ),
		'sub'   => __( 'ご相談はお気軽に。無料の学校マッチングもこちらから。', 'sa-theme' ),
	)
);
?>

<section class="sa-section">
	<div class="sa-container">
		<div class="sa-grid sa-grid--3">
			<div class="sa-card">
				<h2 class="sa-card__title"><?php esc_html_e( 'メール', 'sa-theme' ); ?></h2>
				<p class="sa-card__text"><a href="mailto:info@sakuraryugaku.com">info@sakuraryugaku.com</a></p>
			</div>
			<div class="sa-card">
				<h2 class="sa-card__title"><?php esc_html_e( 'メッセージアプリ', 'sa-theme' ); ?></h2>
				<p class="sa-card__text"><?php esc_html_e( 'LINE / WeChat（微信）/ WhatsApp でのご相談も承っています。', 'sa-theme' ); ?></p>
			</div>
			<div class="sa-card">
				<h2 class="sa-card__title"><?php esc_html_e( '受付時間', 'sa-theme' ); ?></h2>
				<p class="sa-card__text"><?php esc_html_e( '平日 10:00〜19:00（日本時間）', 'sa-theme' ); ?></p>
			</div>
		</div>

		<?php
		// 管理员补充内容（地图嵌入、地址等）。
		while ( have_posts() ) :
			the_post();
			$content = get_the_content();
			if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
				echo '<div class="sa-card__text" style="max-width:820px;margin:32px auto 0;">' . wp_kses_post( apply_filters( 'the_content', $content ) ) . '</div>';
			}
		endwhile;
		?>
	</div>
</section>

<!-- 意向表单入口（内链回落地页核心转化） -->
<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( 'フォームから無料相談を申し込む', 'sa-theme' ); ?></h2>
		<p><?php esc_html_e( 'お名前と連絡先をご入力いただくだけ。担当より順次ご連絡します。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="contact-cta"><?php esc_html_e( '無料相談フォームへ', 'sa-theme' ); ?></a>
	</div>
</section>

<?php
get_footer();
