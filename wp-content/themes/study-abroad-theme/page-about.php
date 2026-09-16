<?php
/**
 * Template Name: 会社紹介ページ
 *
 * 关于我们页（A-03）。公司/团队/资质介绍。
 * SEO：唯一 H1、独立 description、面包屑 + BreadcrumbList。
 *
 * 绑定：后台新建页面（建议 slug=about）→ 模板选「会社紹介ページ」。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

sa_set_meta_description( __( '桜留学（SakuraRyugaku）は、赴日留学を志す学生のための留学エージェントです。データに基づく学校マッチングと専任アドバイザーで、最適な進学をサポートします。', 'sa-theme' ) );

get_header();
?>
<main id="sa-main">
<?php

get_template_part(
	'template-parts/site-page-head',
	null,
	array(
		'title' => __( '私たちについて', 'sa-theme' ),
		'sub'   => __( 'データとプロの伴走で、日本留学の第一歩を確かなものに。', 'sa-theme' ),
	)
);
?>

<section class="sa-section">
	<div class="sa-container" style="max-width:820px;">
		<?php
		// 正文优先使用管理员在页面编辑器中填写的内容；未填写时给出默认介绍。
		$has_content = false;
		while ( have_posts() ) :
			the_post();
			$content = get_the_content();
			if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
				$has_content = true;
				echo '<div class="sa-card__text">' . wp_kses_post( apply_filters( 'the_content', $content ) ) . '</div>';
			}
		endwhile;

		if ( ! $has_content ) :
			?>
			<div class="sa-card__text">
				<p><?php esc_html_e( '桜留学は、赴日留学を志す学生のための留学サポートサービスです。「留学の入り口をもっとわかりやすく、もっと公平に」を理念に、予算と希望専攻から最適な学校をご提案します。', 'sa-theme' ); ?></p>
				<h2 class="sa-card__title" style="margin-top:32px;"><?php esc_html_e( '私たちの理念', 'sa-theme' ); ?></h2>
				<p><?php esc_html_e( '有料面談の前に、まず無料で学校マッチングを受けられる。情報の非対称をなくし、学生が納得して進学先を選べる仕組みを目指しています。', 'sa-theme' ); ?></p>
				<h2 class="sa-card__title" style="margin-top:32px;"><?php esc_html_e( '大切にしていること', 'sa-theme' ); ?></h2>
				<ul>
					<li><?php esc_html_e( '正確で誠実な情報提供（誇大な表現をしません）', 'sa-theme' ); ?></li>
					<li><?php esc_html_e( '個人情報の暗号化と厳重な管理', 'sa-theme' ); ?></li>
					<li><?php esc_html_e( '一人ひとりの目標に寄り添う伴走型サポート', 'sa-theme' ); ?></li>
				</ul>
			</div>
			<?php
		endif;
		?>
	</div>
</section>

<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( 'まずは無料でご相談ください', 'sa-theme' ); ?></h2>
		<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="about-cta"><?php esc_html_e( '無料で学校診断を受ける', 'sa-theme' ); ?></a>
	</div>
</section>

</main>
<?php
get_footer();
