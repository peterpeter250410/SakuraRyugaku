<?php
/**
 * 把一篇稿件写入 WordPress。
 *
 * 由 pipeline.php 通过 WP-CLI 调用：
 *   wp eval-file scripts/content/wp/publish-article.php <payload.json> --path=<wp>
 *
 * 用 WP-CLI 而不是 REST：脚本与站点在同一台机器上，走 REST 还要处理
 * 应用密码、nonce、权限，凭空多出一条要维护的认证链路，且密钥要落盘。
 * eval-file 直接拿到完整的 WordPress 运行环境，权限由 shell 用户决定。
 *
 * 成功时在最后一行输出文章 URL（pipeline 靠这个判断成败）。
 *
 * @package StudyAbroadContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "必须通过 wp eval-file 运行\n" );
}

$sa_payload_file = isset( $args[0] ) ? $args[0] : '';

if ( '' === $sa_payload_file || ! file_exists( $sa_payload_file ) ) {
	WP_CLI::error( "找不到 payload 文件：{$sa_payload_file}" );
}

$sa_data = json_decode( (string) file_get_contents( $sa_payload_file ), true );
if ( ! is_array( $sa_data ) ) {
	WP_CLI::error( 'payload 不是合法 JSON。' );
}

foreach ( array( 'title', 'slug', 'locale', 'body_html', 'summary' ) as $sa_req ) {
	if ( empty( $sa_data[ $sa_req ] ) ) {
		WP_CLI::error( "payload 缺少必填字段：{$sa_req}" );
	}
}

if ( ! defined( 'SA_ARTICLE_PT' ) ) {
	WP_CLI::error( 'sa_article post type 未注册 —— 主题里的 inc/articles.php 没加载。请确认主题已启用。' );
}

$sa_locales = sa_locales();
if ( ! isset( $sa_locales[ $sa_data['locale'] ] ) ) {
	WP_CLI::error( "未知语种：{$sa_data['locale']}（可用：" . implode( ', ', array_keys( $sa_locales ) ) . '）' );
}

/*
 * 正文清洗。
 *
 * 稿件来自模型，不能当作可信 HTML 直接入库。这里按白名单过滤标签，
 * 只留文章真正需要的那几个 —— 比 wp_kses_post() 更紧，
 * 因为文章里不该出现 iframe、form、style 这些东西。
 */
$sa_allowed = array(
	'p'      => array(),
	'h2'     => array( 'id' => true ),
	'h3'     => array( 'id' => true ),
	'ul'     => array(),
	'ol'     => array(),
	'li'     => array(),
	'strong' => array(),
	'em'     => array(),
	'a'      => array( 'href' => true, 'title' => true, 'rel' => true ),
	'blockquote' => array(),
	'table'  => array(),
	'thead'  => array(),
	'tbody'  => array(),
	'tr'     => array(),
	'th'     => array(),
	'td'     => array(),
);

$sa_body = wp_kses( (string) $sa_data['body_html'], $sa_allowed );

/*
 * 站内相对链接补语种前缀。
 *
 * 模型按提示写的是 "schools/isi-japanese-language-school" 这样的相对路径。
 * 英文文章里的站内链接必须指向 /en/schools/...，指向无前缀版本会落到日文页。
 */
$sa_body = preg_replace_callback(
	'#href="(?!https?://|/|\#|mailto:)([^"]+)"#i',
	function ( $m ) use ( $sa_data ) {
		return 'href="' . esc_url( sa_url( home_url( '/' . ltrim( $m[1], '/' ) . '/' ), $sa_data['locale'] ) ) . '"';
	},
	$sa_body
);

/*
 * [source:N] 标记不进正文。
 *
 * 它是给闸门用的机器标记，读者看到「[source:1]」只会困惑。
 * 出处通过页面底部的来源区块呈现（见 inc/articles.php 的 sa_article_sources_block）。
 *
 * 注意顺序：闸门是在清洗之前、带着标记的正文上跑完的，此处才剥离。
 */
$sa_body = preg_replace( '/\s*\[source:\s*\d+\s*\]/i', '', $sa_body );
$sa_body = preg_replace( '/\s+([.。,、])/u', '$1', $sa_body );

// slug 冲突时追加语种后缀：三语同主题的 slug 可能撞车。
$sa_slug     = sanitize_title( $sa_data['slug'] );
$sa_existing = get_page_by_path( $sa_slug, OBJECT, SA_ARTICLE_PT );
if ( $sa_existing ) {
	$sa_slug .= '-' . strtolower( str_replace( '_', '-', $sa_data['locale'] ) );
}

$sa_post_id = wp_insert_post(
	array(
		'post_type'    => SA_ARTICLE_PT,
		'post_status'  => 'publish',
		'post_title'   => sanitize_text_field( $sa_data['title'] ),
		'post_name'    => $sa_slug,
		'post_content' => $sa_body,
		'post_excerpt' => sanitize_text_field( $sa_data['summary'] ),
	),
	true
);

if ( is_wp_error( $sa_post_id ) ) {
	WP_CLI::error( '写入失败：' . $sa_post_id->get_error_message() );
}

update_post_meta( $sa_post_id, '_sa_locale', $sa_data['locale'] );
update_post_meta( $sa_post_id, '_sa_group', isset( $sa_data['group'] ) ? $sa_data['group'] : $sa_slug );
update_post_meta( $sa_post_id, '_sa_summary', wp_strip_all_tags( (string) $sa_data['summary'] ) );
update_post_meta( $sa_post_id, '_sa_keyword_id', isset( $sa_data['keyword_id'] ) ? $sa_data['keyword_id'] : '' );
update_post_meta(
	$sa_post_id,
	'_sa_sources',
	wp_json_encode( isset( $sa_data['sources'] ) ? $sa_data['sources'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
);

WP_CLI::log( "post_id={$sa_post_id}" );
WP_CLI::log( sa_article_url( $sa_post_id ) );
