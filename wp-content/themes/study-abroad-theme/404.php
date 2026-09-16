<?php
/**
 * 404 页面。
 *
 * 为什么需要专门的模板：
 *
 *   此前没有 404.php，WordPress 会回落到 index.php —— 那是文章列表模板，
 *   在一个没有博客文章的站点上会渲染成一个空页面。用户撞上它只能关掉浏览器。
 *
 *   404 的发生频率并不低：院校页是自定义端点，未发布或已下架的院校一律
 *   返回 404（这是有意设计，见 inc/schools.php）；外部链接与搜索结果里
 *   也会残留已失效的地址。这些访客是带着明确意图来的，把他们接住
 *   比让他们离开划算得多。
 *
 * SEO：
 *   本页由 WordPress 以 HTTP 404 返回，sa_is_noindex() 也已包含 is_404()，
 *   因此不会被索引 —— 这正是期望行为，不需要额外处理。
 *   真正要避免的是「软 404」：返回 200 却显示「找不到」。本站的院校端点
 *   在 template_redirect 阶段显式置 404，就是为了避免那种情况。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="sa-main">

<div class="sa-page-head">
	<div class="sa-container">
		<?php
		sa_breadcrumb(
			array(
				array( __( 'ホーム', 'sa-theme' ), sa_home_url( '/' ) ),
				array( __( 'ページが見つかりません', 'sa-theme' ), '' ),
			)
		);
		?>
		<h1 class="sa-page-head__title"><?php esc_html_e( 'ページが見つかりません', 'sa-theme' ); ?></h1>
		<p class="sa-page-head__sub">
			<?php esc_html_e( 'お探しのページは移動または削除された可能性があります。', 'sa-theme' ); ?>
		</p>
	</div>
</div>

<section class="sa-section">
	<div class="sa-container" style="max-width:820px;">

		<?php
		/*
		 * 站内搜索。
		 *
		 * action 必须指向当前语种的首页：搜索结果页同样走语种前缀路由，
		 * 指向默认语种会把中文/英文访客甩回日文站。
		 */
		?>
		<form role="search" method="get" class="sa-404-search" action="<?php echo esc_url( sa_home_url( '/' ) ); ?>">
			<label for="sa-404-s" class="screen-reader-text"><?php esc_html_e( 'サイト内を検索', 'sa-theme' ); ?></label>
			<input type="search" id="sa-404-s" name="s"
				placeholder="<?php esc_attr_e( 'キーワードで検索', 'sa-theme' ); ?>"
				value="<?php echo esc_attr( get_search_query() ); ?>">
			<button type="submit" class="sa-btn sa-btn--primary"><?php esc_html_e( '検索', 'sa-theme' ); ?></button>
		</form>

		<h2 class="sa-card__title" style="margin-top:36px;"><?php esc_html_e( 'よく見られているページ', 'sa-theme' ); ?></h2>
		<ul class="sa-404-links">
			<li><a href="<?php echo esc_url( sa_home_url( '/' ) ); ?>"><?php esc_html_e( 'ホーム', 'sa-theme' ); ?></a></li>
			<?php if ( function_exists( 'sa_schools_url' ) ) : ?>
				<li><a href="<?php echo esc_url( sa_schools_url() ); ?>"><?php esc_html_e( '日本の学校情報一覧', 'sa-theme' ); ?></a></li>
			<?php endif; ?>
			<li><a href="<?php echo esc_url( sa_home_url( '/services/' ) ); ?>"><?php esc_html_e( 'サービス紹介', 'sa-theme' ); ?></a></li>
			<li><a href="<?php echo esc_url( sa_home_url( '/faq/' ) ); ?>"><?php esc_html_e( 'よくある質問', 'sa-theme' ); ?></a></li>
			<li><a href="<?php echo esc_url( sa_home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'お問い合わせ', 'sa-theme' ); ?></a></li>
		</ul>

		<?php
		/*
		 * 院校页因未发布而 404 的情况不少，这里补一句说明并给出列表页入口。
		 * 访客多半是从外部链接或旧的搜索结果过来的，直接告诉他去哪里找，
		 * 比只说「找不到」有用。
		 */
		if ( function_exists( 'sa_is_school_page' ) && sa_is_school_page() ) :
			?>
			<p class="sa-note" style="margin-top:28px;">
				<?php esc_html_e( 'お探しの学校ページは、現在公開されていないか、掲載を終了した可能性があります。掲載中の学校は一覧からご確認ください。', 'sa-theme' ); ?>
			</p>
		<?php endif; ?>
	</div>
</section>

<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( 'どの学校が自分に合うか、無料で診断できます', 'sa-theme' ); ?></h2>
		<p><?php esc_html_e( '予算と希望専攻を入力するだけ。入力は30秒です。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="404-cta">
			<?php esc_html_e( '無料でAI診断を受ける', 'sa-theme' ); ?>
		</a>
	</div>
</section>

</main>
<?php
get_footer();
