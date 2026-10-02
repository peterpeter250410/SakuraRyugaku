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

/*
 * 先按 (_sa_group, _sa_locale) 找这篇稿件自己的既有文章。
 *
 * 发布命令会被重跑 —— 改了一处措辞重发、脚本报错后重试、循环里误敲两次，
 * 都是日常操作。没有这一步的话每次重跑都会多出一篇正文完全相同的文章，
 * 而且因为 slug 已被占用，新的那篇会挂上 "-en-us" 后缀，
 * 于是站点同时有两个 URL 在讲同一件事，还一起进 sitemap。
 * 这正是搜索引擎判重复内容的典型形态，发生在争收录的阶段代价最大。
 *
 * (group, locale) 而不是 slug 作为身份：slug 是可以改的（改标题时顺带改），
 * 改了 slug 还应当认出这是同一篇，否则又回到重复发布。
 */
$sa_group = isset( $sa_data['group'] ) && '' !== $sa_data['group']
	? (string) $sa_data['group']
	: sanitize_title( $sa_data['slug'] );

$sa_mine = get_posts(
	array(
		'post_type'        => SA_ARTICLE_PT,
		'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
		'numberposts'      => 2,
		'orderby'          => 'ID',
		'order'            => 'ASC',
		'suppress_filters' => true,
		'no_found_rows'    => true,
		'meta_query'       => array(
			'relation' => 'AND',
			array( 'key' => '_sa_group', 'value' => $sa_group ),
			array( 'key' => '_sa_locale', 'value' => $sa_data['locale'] ),
		),
	)
);

if ( count( $sa_mine ) > 1 ) {
	$sa_dupe_ids = implode( ', ', wp_list_pluck( $sa_mine, 'ID' ) );
	WP_CLI::warning(
		"group={$sa_group} locale={$sa_data['locale']} 下有多篇文章（ID: {$sa_dupe_ids}）—— "
		. '这是早先重复发布留下的。本次只更新 ID 最小的那篇，其余请人工确认后删除。'
	);
}

$sa_target = $sa_mine ? $sa_mine[0] : null;

$sa_slug = sanitize_title( $sa_data['slug'] );

if ( $sa_target ) {
	/*
	 * 更新既有文章时不动 slug。
	 *
	 * slug 就是线上 URL。已经发布、可能已被收录或被人引用的地址，
	 * 不该因为稿件里顺手改了个字段就换掉 —— 换掉等于一个 404 加一次重新收录。
	 * 真要改 URL，是一次需要考虑 301 的独立操作，不是重发的副作用。
	 */
	if ( $sa_target->post_name !== $sa_slug ) {
		WP_CLI::warning(
			"payload 里的 slug 是「{$sa_slug}」，线上是「{$sa_target->post_name}」—— "
			. '保留线上 slug 不变。要改 URL 请单独处理，并安排 301。'
		);
	}

	$sa_post_id = wp_update_post(
		array(
			'ID'           => $sa_target->ID,
			'post_status'  => 'publish',
			'post_title'   => sanitize_text_field( $sa_data['title'] ),
			'post_content' => $sa_body,
			'post_excerpt' => sanitize_text_field( $sa_data['summary'] ),
		),
		true
	);
	$sa_action = '已更新';
} else {
	// 这个 slug 被别的 group／语种占了，才追加语种后缀（三语同主题会撞车）。
	$sa_taken = get_page_by_path( $sa_slug, OBJECT, SA_ARTICLE_PT );
	if ( $sa_taken ) {
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
	$sa_action = '已新建';
}

if ( is_wp_error( $sa_post_id ) ) {
	WP_CLI::error( '写入失败：' . $sa_post_id->get_error_message() );
}

update_post_meta( $sa_post_id, '_sa_locale', $sa_data['locale'] );
update_post_meta( $sa_post_id, '_sa_group', $sa_group );
update_post_meta( $sa_post_id, '_sa_summary', wp_strip_all_tags( (string) $sa_data['summary'] ) );
update_post_meta( $sa_post_id, '_sa_keyword_id', isset( $sa_data['keyword_id'] ) ? $sa_data['keyword_id'] : '' );
update_post_meta(
	$sa_post_id,
	'_sa_sources',
	wp_json_encode( isset( $sa_data['sources'] ) ? $sa_data['sources'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
);

WP_CLI::log( "post_id={$sa_post_id}" );
WP_CLI::log( sa_article_url( $sa_post_id ) );
