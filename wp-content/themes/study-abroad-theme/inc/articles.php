<?php
/**
 * 资讯文章：post type、路由、多语种、SEO。
 *
 * URL 结构：
 *   /guides/                 文章列表（日文＝默认语种）
 *   /guides/{slug}/          文章详情
 *   /en/guides/{slug}/       英文版
 *
 * 与院校页的关键差异（决定了这里为什么不照抄 inc/schools.php）：
 *
 *   院校页三语共用同一条数据，译文来自 name_i18n 等 JSON 字段与 .po；
 *   一条院校记录在三个语种下都必然存在对应页面。
 *
 *   文章不是。一篇文章的正文属于某一个语种，是独立撰写的。
 *   英文写了、中文没写，那么中文版就是不存在 —— 不是「未翻译」，是没有这个页面。
 *   所以文章需要：
 *     1. 每篇带 _sa_locale，只在对应语种下可访问，其余语种一律 404；
 *     2. hreflang 只输出同一 _sa_group 下真实存在的语种（见 sa_hreflang_locales）；
 *     3. 列表页按当前语种过滤。
 *
 * 为什么用 WordPress post type 而不是像院校那样自建表：
 *   院校是结构化数据，要参与匹配算法的打分，自建表合理。
 *   文章是长文本内容，WordPress 的编辑器、修订历史、草稿态、定时发布、
 *   REST 写入全是现成的 —— 自动化流水线要往站里写文章，这些全都用得上。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 文章 post type 的键。
 */
const SA_ARTICLE_PT = 'sa_article';

/* -------------------------------------------------------------------------
 * meta 键
 *
 * 全部以 _sa_ 开头（下划线前缀 = 不在自定义字段框里裸露），
 * 通过 register_post_meta 显式登记，使 REST 写入可用且带类型校验。
 * ---------------------------------------------------------------------- */

/**
 * 文章 meta 键 => register_post_meta 参数。
 *
 * @return array<string,array<string,mixed>>
 */
function sa_article_meta_schema() {
	return array(
		// 本篇所属语种，取值为 sa_locales() 的键（ja / zh_CN / en_US）。
		'_sa_locale'     => array( 'type' => 'string', 'single' => true, 'default' => '' ),

		// 翻译组。同一主题的各语种版本共用一个 group，用于生成 hreflang 集群。
		// 只有一个语种时也要填 —— 将来补译文时不必回头改数据。
		'_sa_group'      => array( 'type' => 'string', 'single' => true, 'default' => '' ),

		// 优化摘要：直接用作 meta description，同时在正文顶部展示。
		// 与 the_excerpt 分开存：excerpt 面向人读，这一条面向搜索结果页，
		// 两者的长度约束和写法都不同，混用会互相将就。
		'_sa_summary'    => array( 'type' => 'string', 'single' => true, 'default' => '' ),

		// 来源清单，JSON 数组，每项 { url, title, publisher, accessed }。
		// 存 JSON 字符串而非序列化数组：流水线是 PHP 之外也可能读写的，
		// JSON 跨语言可读，serialize() 不是。
		'_sa_sources'    => array( 'type' => 'string', 'single' => true, 'default' => '[]' ),

		// 对应 scripts/content/keywords/*.json 里的 keyword id，用于回溯选题依据。
		'_sa_keyword_id' => array( 'type' => 'string', 'single' => true, 'default' => '' ),

		// 流水线各项评分，JSON。{ ai_tell: n, quality: n, dedup: n, gates: {...} }
		'_sa_scores'     => array( 'type' => 'string', 'single' => true, 'default' => '{}' ),
	);
}

/* -------------------------------------------------------------------------
 * 注册
 * ---------------------------------------------------------------------- */

/**
 * 文章 URL 基础段。
 *
 * 改动后必须刷新固定链接：wp rewrite flush --hard
 *
 * @return string
 */
function sa_article_base() {
	return (string) apply_filters( 'sa_article_base', 'guides' );
}

add_action(
	'init',
	function () {
		$base = sa_article_base();

		register_post_type(
			SA_ARTICLE_PT,
			array(
				'labels'       => array(
					'name'          => __( 'ガイド記事', 'sa-theme' ),
					'singular_name' => __( 'ガイド記事', 'sa-theme' ),
					'add_new_item'  => __( '記事を追加', 'sa-theme' ),
					'edit_item'     => __( '記事を編集', 'sa-theme' ),
					'search_items'  => __( '記事を検索', 'sa-theme' ),
				),
				'public'       => true,
				'has_archive'  => $base,
				'rewrite'      => array( 'slug' => $base, 'with_front' => false ),
				'menu_icon'    => 'dashicons-media-document',
				'menu_position' => 26,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),

				// REST 必须开：自动化流水线通过 REST 写入草稿与发布。
				'show_in_rest' => true,
			)
		);

		foreach ( sa_article_meta_schema() as $key => $args ) {
			register_post_meta(
				SA_ARTICLE_PT,
				$key,
				array_merge(
					$args,
					array(
						'show_in_rest'  => true,
						// 写 meta 需要编辑该文章的权限，不是「登录即可」。
						'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				)
			);
		}
	},
	5
);

/* -------------------------------------------------------------------------
 * 取值辅助
 * ---------------------------------------------------------------------- */

/**
 * 文章所属语种。
 *
 * 未设置时回退到默认语种 —— 手工在后台新建的文章不会有这个 meta，
 * 直接返回空会让它在所有语种下都 404，等于凭空消失。
 *
 * @param int|WP_Post|null $post 文章。
 * @return string sa_locales() 的键。
 */
function sa_article_locale( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return sa_default_locale();
	}
	$loc     = (string) get_post_meta( $post->ID, '_sa_locale', true );
	$locales = sa_locales();
	return isset( $locales[ $loc ] ) ? $loc : sa_default_locale();
}

/**
 * 文章翻译组。
 *
 * @param int|WP_Post|null $post 文章。
 * @return string 未设置时回退为文章自身 slug。
 */
function sa_article_group( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$group = (string) get_post_meta( $post->ID, '_sa_group', true );
	return '' !== $group ? $group : $post->post_name;
}

/**
 * 文章摘要（用于 meta description 与正文顶部）。
 *
 * @param int|WP_Post|null $post 文章。
 * @return string
 */
function sa_article_summary( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$sum = trim( (string) get_post_meta( $post->ID, '_sa_summary', true ) );
	if ( '' !== $sum ) {
		return $sum;
	}
	// 没写摘要时退回 excerpt，再退回正文截断 —— 但不静默：
	// 缺摘要是内容缺陷，由 seo-audit.sh 报出来，这里只保证页面不空着。
	$ex = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
	return '' !== $ex ? $ex : sa_trim_meta_description( $post->post_content );
}

/**
 * 文章来源清单。
 *
 * @param int|WP_Post|null $post 文章。
 * @return array<int,array<string,string>> 每项含 url/title/publisher/accessed。
 */
function sa_article_sources( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array();
	}

	$raw = (string) get_post_meta( $post->ID, '_sa_sources', true );
	if ( '' === $raw ) {
		return array();
	}

	$list = json_decode( $raw, true );
	if ( ! is_array( $list ) ) {
		return array();
	}

	$out = array();
	foreach ( $list as $item ) {
		if ( ! is_array( $item ) || empty( $item['url'] ) ) {
			continue;
		}
		// URL 必须是 http(s)：来源区块会渲染成可点链接，
		// javascript: 之类的伪协议不能进到 href 里。
		$url = esc_url_raw( (string) $item['url'], array( 'http', 'https' ) );
		if ( '' === $url ) {
			continue;
		}
		$out[] = array(
			'url'       => $url,
			'title'     => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : $url,
			'publisher' => isset( $item['publisher'] ) ? sanitize_text_field( (string) $item['publisher'] ) : '',
			'accessed'  => isset( $item['accessed'] ) ? sanitize_text_field( (string) $item['accessed'] ) : '',
		);
	}

	return $out;
}

/**
 * 文章 URL（带本篇自己的语种前缀）。
 *
 * 不能直接用 get_permalink()：i18n 的 permalink 过滤器只在「当前浏览语种」
 * 非默认时加前缀。从日文首页链到一篇英文文章时，当前前缀是空的，
 * 得到的会是 /guides/xxx/ —— 那个 URL 在日文下是 404。
 *
 * @param int|WP_Post|null $post 文章。
 * @return string
 */
function sa_article_url( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	// 先取不带前缀的原始 permalink，再按文章自身语种加前缀。
	$raw = home_url( '/' . sa_article_base() . '/' . $post->post_name . '/' );
	return sa_url( $raw, sa_article_locale( $post ) );
}

/**
 * 文章列表页 URL。
 *
 * @param string|null $locale 目标语种，默认当前语种。
 * @return string
 */
function sa_articles_url( $locale = null ) {
	return sa_url( home_url( '/' . sa_article_base() . '/' ), $locale );
}

/**
 * 同一翻译组下各语种的文章。
 *
 * @param string $group 翻译组。
 * @return array<string,WP_Post> 语种键 => 文章。
 */
function sa_article_translations( $group ) {
	$group = (string) $group;
	if ( '' === $group ) {
		return array();
	}

	static $cache = array();
	if ( isset( $cache[ $group ] ) ) {
		return $cache[ $group ];
	}

	$posts = get_posts(
		array(
			'post_type'        => SA_ARTICLE_PT,
			'post_status'      => 'publish',
			'numberposts'      => count( sa_locales() ),
			'meta_key'         => '_sa_group',
			'meta_value'       => $group,
			'suppress_filters' => false,
			'no_found_rows'    => true,
		)
	);

	$map = array();
	foreach ( $posts as $p ) {
		$map[ sa_article_locale( $p ) ] = $p;
	}

	$cache[ $group ] = $map;
	return $map;
}

/* -------------------------------------------------------------------------
 * 语种隔离
 * ---------------------------------------------------------------------- */

/**
 * 当前请求是否为文章详情页。
 *
 * @return bool
 */
function sa_is_article() {
	return is_singular( SA_ARTICLE_PT );
}

/**
 * 当前请求是否为文章列表页。
 *
 * @return bool
 */
function sa_is_article_archive() {
	return is_post_type_archive( SA_ARTICLE_PT );
}

/**
 * 列表页只显示当前语种的文章。
 */
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive( SA_ARTICLE_PT ) ) {
			return;
		}

		$meta = (array) $query->get( 'meta_query' );

		/*
		 * 兼容没有 _sa_locale 的历史文章：视同默认语种。
		 * 用 NOT EXISTS 而不是只比对值，否则后台手工建的文章会在所有语种下消失，
		 * 编辑会以为文章没保存成功。
		 */
		if ( sa_current_locale() === sa_default_locale() ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => '_sa_locale', 'value' => sa_default_locale() ),
				array( 'key' => '_sa_locale', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_sa_locale', 'value' => '' ),
			);
		} else {
			$meta[] = array( 'key' => '_sa_locale', 'value' => sa_current_locale() );
		}

		$query->set( 'meta_query', $meta );
	}
);

/**
 * 语种不匹配的文章详情页返回 404。
 *
 * 不这样做的话，同一篇英文文章会在 /guides/x/、/zh/guides/x/、/en/guides/x/
 * 三个 URL 上各出现一次 —— 内容完全相同，是标准的重复内容问题，
 * 而且三个 URL 会互相稀释权重。
 *
 * 用真 404 而不是 301 到正确语种：URL 里的语种前缀是用户或链接给出的明确意图，
 * 悄悄改掉会让「切换语种」这个动作的结果变得不可预期。
 */
add_action(
	'template_redirect',
	function () {
		if ( ! sa_is_article() ) {
			return;
		}

		if ( sa_article_locale( get_queried_object() ) === sa_current_locale() ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	},
	1
);

/* -------------------------------------------------------------------------
 * SEO
 * ---------------------------------------------------------------------- */

/**
 * canonical。
 *
 * 文章的 canonical 必须带自己的语种前缀，不能跟随当前浏览语种。
 */
add_filter(
	'sa_canonical_url',
	function ( $url ) {
		if ( sa_is_article() ) {
			$a = get_queried_object();
			return $a ? sa_article_url( $a ) : $url;
		}
		if ( sa_is_article_archive() ) {
			return sa_articles_url();
		}
		return $url;
	}
);

/**
 * hreflang 集群：只列出同组下真实存在的语种。
 *
 * 这是文章与站内其它页面最大的区别，理由见文件头注释。
 */
add_filter(
	'sa_hreflang_locales',
	function ( $locales ) {
		if ( ! sa_is_article() ) {
			return $locales;
		}
		$found = array_keys( sa_article_translations( sa_article_group( get_queried_object() ) ) );
		// 兜底：查不到任何译文时，至少声明自己这一种，而不是输出空集群。
		return ! empty( $found ) ? $found : array( sa_article_locale( get_queried_object() ) );
	}
);

/**
 * hreflang 的 URL：指向该语种下那一篇的真实 URL。
 *
 * 默认实现 sa_current_url_in() 只是替换路径前缀，对文章不成立 ——
 * 各语种版本的 slug 是各自独立的（英文 slug 与日文 slug 不会相同）。
 */
add_filter(
	'sa_hreflang_url',
	function ( $url, $locale_key ) {
		if ( ! sa_is_article() ) {
			return $url;
		}
		$map = sa_article_translations( sa_article_group( get_queried_object() ) );
		return isset( $map[ $locale_key ] ) ? sa_article_url( $map[ $locale_key ] ) : $url;
	},
	10,
	2
);

/**
 * 语言切换器的 URL。
 *
 * 与上面的 sa_hreflang_url 过滤器用同一份翻译组数据，但行为必须不同：
 *
 *   hreflang 是机器读的对应关系声明。没有译文时就不该声明 ——
 *   sa_hreflang_locales 已经把不存在的语种从集群里剔掉了。
 *
 *   切换器是人点的。没有译文时不能留一个指向 404 的链接，
 *   也不该把那个语种从菜单里拿掉 —— 读英文文章的人想看日文版时，
 *   菜单里只剩「English」一项会像是功能坏了。
 *
 * 所以没有译文时落到该语种的文章列表页：不是死胡同，
 * 落点是同一主题、目标语种的内容，而且读者能看出发生了什么。
 *
 * 刻意不做 301 到正确语种的文章 —— 那是 template_redirect 那段注释
 * 讲过的理由：URL 里的语种前缀是明确意图，悄悄改掉会让
 * 「切换语种」这个动作的结果变得不可预期。这里是换链接的落点，
 * 不是在用户已经点下去之后改变目的地。
 */
add_filter(
	'sa_locale_switch_url',
	function ( $url, $locale_key ) {
		if ( ! sa_is_article() ) {
			return $url;
		}

		$article = get_queried_object();

		/*
		 * 当前语种那一项永远指向这篇自己。
		 *
		 * 后台手工建的文章可能没有 _sa_group，翻译组查出来是空的 ——
		 * 没有这个分支的话，连「正在看的这个语种」都会落到列表页，
		 * 菜单里那个 aria-current 的项目指不回当前页面。
		 */
		if ( $locale_key === sa_article_locale( $article ) ) {
			return sa_article_url( $article );
		}

		$map = sa_article_translations( sa_article_group( $article ) );

		return isset( $map[ $locale_key ] )
			? sa_article_url( $map[ $locale_key ] )
			: sa_articles_url( $locale_key );
	},
	10,
	2
);

/**
 * 标题与 meta description。
 */
add_filter(
	'document_title_parts',
	function ( $parts ) {
		if ( sa_is_article_archive() ) {
			$parts['title'] = __( '日本留学ガイド', 'sa-theme' );
			unset( $parts['tagline'] );
		}
		return $parts;
	},
	20
);

add_action(
	'wp',
	function () {
		if ( sa_is_article() ) {
			$sum = sa_article_summary( get_queried_object() );
			if ( '' !== $sum ) {
				sa_set_meta_description( sa_trim_meta_description( $sum ) );
			}
			return;
		}

		if ( sa_is_article_archive() ) {
			sa_set_meta_description(
				__( '日本留学の手続き・在留資格・学校選び・現地生活について、公的機関や各校の公表資料を出典として明記したうえでまとめています。', 'sa-theme' )
			);
		}
	},
	20
);

/**
 * 文章页结构化数据。
 *
 * 用 Article + citation：
 *   citation 把正文引用的官方来源写进结构化数据，与页面底部的来源区块一致。
 *   这不是为了拿富媒体摘要（Article 早就不给了），而是让来源关系机器可读。
 *
 * author 标 Organization 而不是编个人名。
 *   本站文章由站方编制，没有真实的署名作者。捏造一个「山田太郎 / 留学顾问」
 *   去凑 E-E-A-T，是伪造作者身份，不做。
 */
add_action(
	'wp_head',
	function () {
		if ( ! sa_is_article() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post ) {
			return;
		}

		$url    = sa_article_url( $post );
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $url ),
			'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
			'inLanguage'       => sa_locale_field( sa_article_locale( $post ), 'hreflang', 'ja' ),
			'datePublished'    => get_post_time( DATE_W3C, true, $post ),
			'dateModified'     => get_post_modified_time( DATE_W3C, true, $post ),
			'author'           => array(
				'@type' => 'Organization',
				'@id'   => home_url( '/#organization' ),
			),
			'publisher'        => array( '@id' => home_url( '/#organization' ) ),
		);

		$sum = wp_strip_all_tags( sa_article_summary( $post ) );
		if ( '' !== $sum ) {
			$schema['description'] = $sum;
		}

		$sources = sa_article_sources( $post );
		if ( ! empty( $sources ) ) {
			$schema['citation'] = array_map(
				function ( $s ) {
					$c = array(
						'@type' => 'CreativeWork',
						'url'   => $s['url'],
						'name'  => $s['title'],
					);
					if ( '' !== $s['publisher'] ) {
						$c['publisher'] = array( '@type' => 'Organization', 'name' => $s['publisher'] );
					}
					return $c;
				},
				$sources
			);
		}

		$schema = apply_filters( 'sa_schema_article', $schema, $post );

		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			. '</script>' . "\n";
	},
	5
);

/* -------------------------------------------------------------------------
 * 展示
 * ---------------------------------------------------------------------- */

/**
 * 渲染来源区块。
 *
 * 位置在正文之后：来源是支撑材料，不该挡在内容前面。
 * 但它必须出现在页面上而不只是结构化数据里 —— 读者要能核对，
 * 「有来源」和「读者能看到来源」是两回事。
 *
 * @param int|WP_Post|null $post 文章。
 */
function sa_article_sources_block( $post = null ) {
	$sources = sa_article_sources( $post );
	if ( empty( $sources ) ) {
		return;
	}

	echo '<aside class="sa-article__sources">';
	echo '<h2 class="sa-article__sources-title">' . esc_html__( '出典', 'sa-theme' ) . '</h2>';
	echo '<ol class="sa-article__sources-list">';

	foreach ( $sources as $s ) {
		echo '<li>';
		printf(
			/*
			 * rel="nofollow" 不加：这些是官方机构与学校官网，
			 * 是我们主动选择引用的可信来源，正常的出站链接。
			 * 加 nofollow 反而是在告诉搜索引擎「这链接我们自己也不信」。
			 */
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $s['url'] ),
			esc_html( $s['title'] )
		);
		$note = array_filter( array( $s['publisher'], $s['accessed'] ) );
		if ( ! empty( $note ) ) {
			echo ' <span class="sa-article__source-meta">（' . esc_html( implode( '・', $note ) ) . '）</span>';
		}
		echo '</li>';
	}

	echo '</ol></aside>';
}

/**
 * 模板加载。
 */
add_filter(
	'template_include',
	function ( $template ) {
		if ( is_404() ) {
			return $template;
		}
		if ( sa_is_article() ) {
			$found = locate_template( 'template-article-single.php' );
			if ( $found ) {
				return $found;
			}
		}
		if ( sa_is_article_archive() ) {
			$found = locate_template( 'template-articles.php' );
			if ( $found ) {
				return $found;
			}
		}
		return $template;
	},
	20
);
