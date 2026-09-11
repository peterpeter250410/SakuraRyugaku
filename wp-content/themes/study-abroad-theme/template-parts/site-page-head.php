<?php
/**
 * 站点页统一页头：面包屑 + 页面标题带（H1 + 副标题）。
 *
 * 变量（通过 set_query_var 或全局传入）：
 *   $sa_head_title    string  H1 标题
 *   $sa_head_sub      string  副标题（可空）
 *   $sa_head_crumb    string  面包屑当前页名（可空，默认用标题）
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sa_head_title = isset( $args['title'] ) ? $args['title'] : get_the_title();
$sa_head_sub   = isset( $args['sub'] ) ? $args['sub'] : '';
$sa_head_crumb = isset( $args['crumb'] ) && '' !== $args['crumb'] ? $args['crumb'] : $sa_head_title;
?>
<div class="sa-page-head">
	<div class="sa-container">
		<?php
		if ( function_exists( 'sa_breadcrumb' ) ) {
			sa_breadcrumb(
				array(
					array( __( 'ホーム', 'sa-theme' ), sa_home_url( '/' ) ),
					array( $sa_head_crumb, '' ),
				)
			);
		}
		?>
		<h1 class="sa-page-head__title"><?php echo esc_html( $sa_head_title ); ?></h1>
		<?php if ( $sa_head_sub ) : ?>
			<p class="sa-page-head__sub"><?php echo esc_html( $sa_head_sub ); ?></p>
		<?php endif; ?>
	</div>
</div>
