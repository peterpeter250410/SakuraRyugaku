<?php
/**
 * Template Name: よくある質問ページ
 *
 * FAQ 独立页（A-07）。承接留学长尾问题词，利于 SEO 收录与转化。
 * SEO：唯一 H1、独立 description、面包屑 + BreadcrumbList、FAQPage JSON-LD。
 *
 * 绑定：后台新建页面（建议 slug=faq）→ 模板选「よくある質問ページ」。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

sa_set_meta_description( __( '日本留学の費用・語学要件・出願手続き・情報の安全性など、よくいただくご質問をまとめました。留学の疑問を解消して、次の一歩へ。', 'sa-theme' ) );

// FAQ 数据（展示 + FAQPage 结构化数据共用）。落地页 FAQ 的扩展版，承接更多长尾。
$faqs = array(
	array(
		'q' => __( '日本留学の相談は無料ですか？', 'sa-theme' ),
		'a' => __( 'はい。学校マッチングと初回相談は無料です。まずはお気軽に意向情報をご入力ください。', 'sa-theme' ),
	),
	array(
		'q' => __( '日本語ができなくても留学できますか？', 'sa-theme' ),
		'a' => __( '語学学校からのスタートも可能です。あなたのレベルと目標に合わせて最適な進学ルートをご提案します。', 'sa-theme' ),
	),
	array(
		'q' => __( '予算に合う学校を紹介してもらえますか？', 'sa-theme' ),
		'a' => __( 'ご予算と希望専攻をもとに、システムが自動で候補校をマッチングします。無理のないプランをご提案します。', 'sa-theme' ),
	),
	array(
		'q' => __( '高校卒業後すぐに日本へ留学できますか？', 'sa-theme' ),
		'a' => __( '可能です。語学学校・専門学校・大学など、学歴と目標に応じた進学ルートをご案内します。', 'sa-theme' ),
	),
	array(
		'q' => __( '文系でも日本の大学院に進学できますか？', 'sa-theme' ),
		'a' => __( '文系専攻の進学実績も多数あります。研究計画や希望分野に合わせて候補校をご提案します。', 'sa-theme' ),
	),
	array(
		'q' => __( '出願書類の準備もサポートしてもらえますか？', 'sa-theme' ),
		'a' => __( 'はい。必要書類の確認から作成のサポートまで、専任スタッフが対応します。', 'sa-theme' ),
	),
	array(
		'q' => __( '入力した個人情報は安全ですか？', 'sa-theme' ),
		'a' => __( 'お預かりする情報は暗号化して安全に管理し、プライバシーポリシーに従って取り扱います。', 'sa-theme' ),
	),
);

get_header();

get_template_part(
	'template-parts/site-page-head',
	null,
	array(
		'title' => __( 'よくある質問', 'sa-theme' ),
		'sub'   => __( '費用・語学要件・手続き・安全性など、よくいただくご質問にお答えします。', 'sa-theme' ),
	)
);
?>

<section class="sa-section">
	<div class="sa-container">
		<div class="sa-faq">
			<?php foreach ( $faqs as $faq ) : ?>
				<div class="sa-faq__item">
					<h2 class="sa-faq__q"><?php echo esc_html( $faq['q'] ); ?></h2>
					<p class="sa-faq__a"><?php echo esc_html( $faq['a'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		// 管理员可在页面正文补充更多问答。
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

<?php
// FAQPage 结构化数据。
if ( function_exists( 'sa_output_faq_schema' ) ) {
	sa_output_faq_schema( $faqs );
}
?>

<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( '疑問が解消したら、次の一歩へ', 'sa-theme' ); ?></h2>
		<p><?php esc_html_e( '無料の学校マッチングで、あなたに合う学校を見つけましょう。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="faq-cta"><?php esc_html_e( '無料で学校診断を受ける', 'sa-theme' ); ?></a>
	</div>
</section>

<?php
get_footer();
