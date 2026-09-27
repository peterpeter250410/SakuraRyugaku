<?php
/**
 * 导出站内已发布文章的正文，供重复度闸门比对。
 *
 * 由 pipeline.php 通过 WP-CLI 调用：
 *   wp eval-file scripts/content/wp/dump-articles.php <locale> --path=<wp>
 *
 * 输出 JSON：{ "slug": "正文纯文本", ... }
 *
 * @package StudyAbroadContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "必须通过 wp eval-file 运行\n" );
}

$sa_locale = isset( $args[0] ) ? $args[0] : '';

if ( ! defined( 'SA_ARTICLE_PT' ) ) {
	// 没有文章系统时返回空对象而不是报错 —— 首次运行时这是正常状态，
	// pipeline 会据此跳过重复度检查。
	echo "{}\n";
	return;
}

$sa_query = array(
	'post_type'        => SA_ARTICLE_PT,
	'post_status'      => 'publish',
	'posts_per_page'   => 500,
	'fields'           => 'ids',
	'no_found_rows'    => true,
	'suppress_filters' => false,
);

if ( '' !== $sa_locale ) {
	$sa_query['meta_key']   = '_sa_locale';
	$sa_query['meta_value'] = $sa_locale;
}

$sa_ids = get_posts( $sa_query );
$sa_out = array();

foreach ( $sa_ids as $sa_id ) {
	$sa_post = get_post( $sa_id );
	if ( ! $sa_post ) {
		continue;
	}
	$sa_out[ $sa_post->post_name ] = wp_strip_all_tags( $sa_post->post_content );
}

echo wp_json_encode( $sa_out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
