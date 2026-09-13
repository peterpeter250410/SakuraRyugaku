<?php
/**
 * Template Name: サービス紹介ページ
 *
 * 服务介绍页（A-02）。留学服务流程、优势说明。
 * SEO：唯一 H1、独立 description、面包屑 + BreadcrumbList、内链回落地页表单。
 *
 * 绑定：后台新建页面（建议 slug=services）→ 模板选「サービス紹介ページ」。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

sa_set_meta_description( __( '日本留学の学校マッチングから出願書類サポートまで。予算と希望専攻に合わせて最適な進学ルートをご提案する、桜留学のサービス内容をご紹介します。', 'sa-theme' ) );

get_header();

get_template_part(
	'template-parts/site-page-head',
	null,
	array(
		'title' => __( 'サービス紹介', 'sa-theme' ),
		'sub'   => __( '留学の「わからない」を、データとプロの力で最後までサポート。', 'sa-theme' ),
	)
);
?>

<!-- サービス一覧 -->
<section class="sa-section">
	<div class="sa-container">
		<div class="sa-section__head">
			<span class="sa-section__tag">Services</span>
			<h2 class="sa-section__title"><?php esc_html_e( '提供サービス', 'sa-theme' ); ?></h2>
			<?php // 「留学のすべてをワンストップで」は過大表示。入学審査も学費の収受も
			// 学校側が行い、当方の関与範囲は出願書類の準備と取次ぎまで。 ?>
			<p class="sa-section__desc"><?php esc_html_e( '学校選びから出願書類の準備・取次ぎまで、留学の準備段階をサポートします。', 'sa-theme' ); ?></p>
		</div>
		<div class="sa-grid sa-grid--3">
			<?php
			// 翻译函数必须接收字面量，gettext 才能提取；传变量会导致文案永远不被翻译。
			$services = array(
				array( __( '無料学校マッチング', 'sa-theme' ), __( '予算・希望専攻をもとに、システムが最適な候補校を自動でご提案します。', 'sa-theme' ) ),
				array( __( '出願書類サポート', 'sa-theme' ), __( '複雑な出願手続きや必要書類の準備を、専任スタッフが丁寧にサポート。', 'sa-theme' ) ),
				array( __( '進学プラン設計', 'sa-theme' ), __( '語学学校・専門学校・大学・大学院まで、目標に合わせた進学ルートを設計。', 'sa-theme' ) ),
				array( __( '多言語での相談', 'sa-theme' ), __( '母国語で安心してご相談いただけるよう、多言語対応を順次拡大中です。', 'sa-theme' ) ),
				array( __( '安心の情報管理', 'sa-theme' ), __( 'お預かりする個人情報は暗号化して安全に管理します。', 'sa-theme' ) ),
				array( __( '入学後フォロー', 'sa-theme' ), __( '渡日後の生活立ち上げに関するご相談にも対応します。', 'sa-theme' ) ),
			);
			foreach ( $services as $s ) {
				echo '<div class="sa-card">';
				echo '<h3 class="sa-card__title">' . esc_html( $s[0] ) . '</h3>';
				echo '<p class="sa-card__text">' . esc_html( $s[1] ) . '</p>';
				echo '</div>';
			}
			?>
		</div>
	</div>
</section>

<!-- 流れ -->
<section class="sa-section sa-section--soft">
	<div class="sa-container">
		<div class="sa-section__head">
			<span class="sa-section__tag">Flow</span>
			<h2 class="sa-section__title"><?php esc_html_e( 'ご利用の流れ', 'sa-theme' ); ?></h2>
		</div>
		<div class="sa-steps">
			<?php
			$steps = array(
				array( __( '情報入力', 'sa-theme' ), __( '予算・希望専攻など基本情報を入力。', 'sa-theme' ) ),
				array( __( '無料マッチング', 'sa-theme' ), __( 'システムが最適な候補校をご提案。', 'sa-theme' ) ),
				array( __( '学校を選ぶ', 'sa-theme' ), __( '気になる学校を選択して相談。', 'sa-theme' ) ),
				array( __( '出願サポート', 'sa-theme' ), __( '書類準備から出願までサポート。', 'sa-theme' ) ),
			);
			$n = 0;
			foreach ( $steps as $s ) {
				$n++;
				echo '<div class="sa-step">';
				echo '<div class="sa-step__num">' . esc_html( $n ) . '</div>';
				echo '<div class="sa-step__title">' . esc_html( $s[0] ) . '</div>';
				echo '<div class="sa-step__text">' . esc_html( $s[1] ) . '</div>';
				echo '</div>';
			}
			?>
		</div>
	</div>
</section>

<?php
// 页面正文（管理员可补充）。
while ( have_posts() ) :
	the_post();
	$content = get_the_content();
	if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
		echo '<section class="sa-section"><div class="sa-container" style="max-width:820px;">';
		echo '<div class="sa-card__text">' . wp_kses_post( apply_filters( 'the_content', $content ) ) . '</div>';
		echo '</div></section>';
	}
endwhile;
?>

<!-- サービスの範囲＝関係性の開示。サービス紹介ページにこそ必要。 -->
<section class="sa-section">
	<div class="sa-container" style="max-width:820px;">
		<?php
		get_template_part(
			'template-parts/relationship-disclosure',
			null,
			array( 'variant' => 'full' )
		);
		?>
	</div>
</section>

<!-- CTA 内链回落地页表单 -->
<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( 'まずは無料で、あなたに合う学校を見つけよう', 'sa-theme' ); ?></h2>
		<p><?php esc_html_e( '入力は30秒。しつこい勧誘はありません。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="services-cta"><?php esc_html_e( '無料で学校診断を受ける', 'sa-theme' ); ?></a>
	</div>
</section>

<?php
get_footer();
