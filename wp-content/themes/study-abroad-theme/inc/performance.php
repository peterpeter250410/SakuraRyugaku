<?php
/**
 * 性能优化（Core Web Vitals 导向）。
 *
 * 修复要点：
 *   - 字体按语种加载。此前同时加载 Noto Sans JP + Noto Sans SC 两套 CJK 字体，
 *     而任一语种页面只会用到其中一套。CJK 字体体积远大于拉丁字体，
 *     多下载一整套是 LCP 的主要拖累。
 *   - 字重与实际用量对齐。此前加载 400/500/700/800，但 CSS 只用 600/700/800：
 *     500 是纯浪费，600 缺失导致浏览器合成字重（渲染质量差且有额外开销）。
 *   - 字体样式表异步加载，不再阻塞首屏渲染。
 *   - 英文语种不加载 CJK 网络字体，直接用系统字体栈。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 当前语种对应的 Google Fonts 字体族。
 *
 * 返回空字符串表示该语种不加载网络字体（使用系统字体栈）。
 *
 * @return string
 */
function sa_webfont_family() {
	$locale = sa_current_locale();

	$map = apply_filters(
		'sa_webfont_family_map',
		array(
			'ja'    => 'Noto+Sans+JP',
			'zh_CN' => 'Noto+Sans+SC',
			// 英文使用系统字体栈：拉丁字形系统自带，无需下载。
			'en_US' => '',
		)
	);

	return isset( $map[ $locale ] ) ? $map[ $locale ] : '';
}

/**
 * 是否启用网络字体。
 *
 * 可通过 add_filter( 'sa_enable_webfont', '__return_false' ) 全站关闭，
 * 改用系统字体栈 —— 这是 CJK 站点最直接的 LCP 优化手段。
 *
 * @return bool
 */
function sa_enable_webfont() {
	return (bool) apply_filters( 'sa_enable_webfont', true );
}

/**
 * 构造当前语种的 Google Fonts URL。
 *
 * 用可变字体的权重区间（wght@400..800），而不是逐个列出静态字重
 * （曾经写作 wght@400;600;700;800）。
 *
 * 原因是 CSS 体积。Noto Sans JP 按 Unicode 区段拆成约 124 个子集，
 * 列四个静态字重就要生成 4 × 124 = 496 个 @font-face 块；改成区间后
 * 只有 124 个，因为可变字体一个 @font-face 就覆盖整个区间
 * （返回的声明是 font-weight: 400 800）。
 *
 * 实测（2026-09，Chrome UA）：
 *     wght@400;600;700;800  原始 459 KB / gzip 122 KB / 496 个 @font-face
 *     wght@400..800         原始 115 KB / gzip  31 KB / 124 个 @font-face
 * PageSpeed 报的「未使用的 CSS 118.4 KiB」即前者，改后约 31 KB。
 *
 * style.css 里实际用到 400 / 600 / 700 / 800，全都落在区间内，
 * 因此视觉没有任何变化 —— 这一点比砍字重的方案重要：砍字重同样能减半，
 * 但会改变标题与按钮的粗细。
 *
 * 老浏览器：Google Fonts 按 User-Agent 分发，不支持可变字体的 UA
 * 会拿到静态回退。受影响的只有 2018 年前的版本，且最坏情况是中间字重
 * 由浏览器合成，不会缺字。
 *
 * @return string 空字符串表示无需加载。
 */
function sa_webfont_url() {
	if ( ! sa_enable_webfont() ) {
		return '';
	}
	$family = sa_webfont_family();
	if ( '' === $family ) {
		return '';
	}
	/*
	 * display 用 optional，不用 swap。
	 *
	 * 这一条是冲着 LCP 来的。把七轮实测排开看：
	 *     FCP  5.6 → 5.6 → 5.2 → 5.1 → 3.9 → 2.7
	 *     LCP  6.1 → 5.9 → 5.3 → 5.3 → 5.4 → 5.3
	 * FCP 被一路优化掉了一半以上，LCP 却始终钉在 5.3 秒附近，
	 * 说明它被某个与页面资源无关的固定成本锁住。
	 *
	 * 那个固定成本就是网络字体的链路：HTML 解析完才开始去
	 * fonts.googleapis.com 取 CSS（第三方源，一轮 DNS+TCP+TLS），
	 * 再从 fonts.gstatic.com 取字体文件（又一个第三方源）。
	 *
	 * swap 的行为是：文字先用回退字体画出来（这就是 FCP），等网络字体
	 * 到达后重新绘制一次。而 LCP 元素是 hero 里的标题文字块 ——
	 * 重绘会把 LCP 的时间戳推到字体替换那一刻。于是无论首屏多快，
	 * LCP 都等于「字体到达时间」。
	 *
	 * optional 的行为：字体没能在极短的窗口内就绪就直接放弃本次使用，
	 * 继续用回退字体，不再重绘 —— 字体因此彻底离开 LCP 路径。
	 * 下载仍会继续并进入缓存，再次访问时就能用上。
	 *
	 * 代价要说清楚：慢速首次访问会看到系统字体而不是 Noto Sans JP。
	 * 对 CJK 而言这个代价不大 —— Android 自带 Noto Sans CJK（与之几乎同源），
	 * iOS 是 Hiragino Sans，Windows 是游ゴシック，都是正经的日文字体，
	 * 字体栈里已按此顺序排好。若宁可要字体一致性，把这里改回 swap 即可。
	 */
	return 'https://fonts.googleapis.com/css2?family=' . $family . ':wght@400..800&display=optional';
}

/**
 * 资源加载。
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$theme_dir = get_template_directory();
		$theme_uri = get_template_directory_uri();

		// --- 主样式表（用 filemtime 做版本号，确保发布后缓存立即失效）---
		$css_path = $theme_dir . '/style.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : SA_THEME_VERSION;
		wp_enqueue_style( 'sa-theme', get_stylesheet_uri(), array(), $css_ver );

		// --- 网络字体（按语种，异步加载）---
		$font_url = sa_webfont_url();
		if ( $font_url ) {
			// 以 preload 方式注册，再由 filter 改写为异步加载，避免阻塞首屏渲染。
			wp_enqueue_style( 'sa-fonts', $font_url, array(), null );
		}

		// --- 主脚本（页脚加载 + defer）---
		$js_path = $theme_dir . '/assets/js/main.js';
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : SA_THEME_VERSION;
		wp_enqueue_script( 'sa-theme', $theme_uri . '/assets/js/main.js', array(), $js_ver, true );

		// 向前端暴露接口端点。
		// 注意：REST 端点不带语种前缀（API 不参与语种路由），此处直接用 rest_url()。
		wp_localize_script(
			'sa-theme',
			'SA_LP',
			array(
				'leadEndpoint'     => esc_url_raw( rest_url( 'sa/v1/lead' ) ),
				'diagnoseEndpoint' => esc_url_raw( rest_url( 'sa/v1/diagnose' ) ),
				'claimEndpoint'    => esc_url_raw( rest_url( 'sa/v1/claim' ) ),
				'uploadEndpoint'   => esc_url_raw( rest_url( 'sa/v1/upload-doc' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'locale'           => sa_current_locale(),
				'thanksUrl'        => esc_url_raw( sa_home_url( '/thanks/' ) ),
				'i18n'             => array(
					'submitting'    => __( '提交中…', 'sa-theme' ),
					'success'       => __( '提交成功！我们会尽快与您联系。', 'sa-theme' ),
					'error'         => __( '提交失败，请稍后重试。', 'sa-theme' ),
					'required'      => __( '请填写姓名与联系方式并同意隐私政策。', 'sa-theme' ),
					'diagnosing'    => __( 'AI診断中…', 'sa-theme' ),
					'noResult'      => __( '条件に合う学校が見つかりませんでした。条件を変えて再度お試しください。', 'sa-theme' ),
					'matchLabel'    => __( 'マッチ度', 'sa-theme' ),
					'selectSchool'  => __( 'この学校を選んで書類を提出', 'sa-theme' ),
					'selecting'     => __( '手続き中…', 'sa-theme' ),
					'uploadOk'      => __( '提出しました。', 'sa-theme' ),
					'uploadErr'     => __( '提出に失敗しました。もう一度お試しください。', 'sa-theme' ),
					'uploadedLabel' => __( '提出済み', 'sa-theme' ),
					'reselect'      => __( 'ファイルを選択', 'sa-theme' ),
				),
			)
		);
	},
	20
);

/**
 * 字体样式表异步加载。
 *
 * 用 media="print" + onload 切回 all 的经典手法：浏览器以最低优先级下载，
 * 不阻塞首屏渲染；配合 font-display:swap，文字立即以系统字体显示。
 */
add_filter(
	'style_loader_tag',
	function ( $tag, $handle ) {
		if ( 'sa-fonts' !== $handle ) {
			return $tag;
		}
		$tag = str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $tag );
		$tag = str_replace( 'media="all"', 'media="print" onload="this.media=\'all\'"', $tag );
		// 关闭 JS 时的回退。
		$noscript = '<noscript>' . str_replace( array( "media='print' onload=\"this.media='all'\"", 'media="print" onload="this.media=\'all\'"' ), '', $tag ) . '</noscript>';
		return $tag . $noscript;
	},
	10,
	2
);

/**
 * 主脚本加 defer，减少主线程阻塞（改善 INP / LCP）。
 */
add_filter(
	'script_loader_tag',
	function ( $tag, $handle ) {
		if ( 'sa-theme' !== $handle ) {
			return $tag;
		}
		if ( false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}
		return str_replace( ' src=', ' defer src=', $tag );
	},
	10,
	2
);

/**
 * 资源提示：只对当前语种真正会用到的字体域名做 preconnect。
 *
 * 不需要的 preconnect 会占用连接数，反而拖慢首屏。
 */
add_filter(
	'wp_resource_hints',
	function ( $hints, $relation_type ) {
		if ( 'preconnect' !== $relation_type ) {
			return $hints;
		}
		if ( '' === sa_webfont_url() ) {
			// 本语种不用网络字体，移除字体域名的 preconnect。
			$hints = array_filter(
				$hints,
				function ( $hint ) {
					$url = is_array( $hint ) && isset( $hint['href'] ) ? $hint['href'] : $hint;
					return false === strpos( (string) $url, 'fonts.g' );
				}
			);
			return array_values( $hints );
		}

		/*
		 * 两个域名都由这里输出（header.php 原本硬编码过一份，已移除）。
		 *
		 * 顺序有讲究：先连 googleapis（取样式表），样式表里再指向 gstatic
		 * （取字体文件）。gstatic 必须带 crossorigin —— 字体是匿名跨域请求，
		 * 不带的话预连接的是另一个连接池，等于白连一次。
		 *
		 * 先去重再追加：主题或插件可能已经加过，重复的 preconnect 在
		 * PageSpeed 的「预连接的源」里会各占一行，也浪费连接数。
		 */
		$hints = array_values(
			array_filter(
				$hints,
				function ( $hint ) {
					$url = is_array( $hint ) && isset( $hint['href'] ) ? $hint['href'] : $hint;
					return false === strpos( (string) $url, 'fonts.g' );
				}
			)
		);

		$hints[] = 'https://fonts.googleapis.com';
		$hints[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
		return $hints;
	},
	10,
	2
);

/**
 * 为内容中的图片补 width/height 与 lazy/async 解码，抑制布局偏移（CLS）。
 *
 * WordPress 核心已对多数场景生效，此处兜底处理主题模板直出的图片。
 */
add_filter( 'wp_lazy_loading_enabled', '__return_true' );

/**
 * 首屏图片不应 lazy-load（否则 LCP 变差）。
 *
 * 轮播首帧属于首屏内容，交由模板显式控制 loading 属性。
 */
add_filter(
	'wp_img_tag_add_loading_attr',
	function ( $value, $image, $context ) {
		if ( 'sa-hero' === $context ) {
			return false;
		}
		return $value;
	},
	10,
	3
);

/* -------------------------------------------------------------------------
 * 前端资源瘦身
 *
 * 本主题为完全自定义模板，不依赖区块编辑器样式，也不使用 jQuery。
 * 默认卸载这些用不上的资源，直接减少首屏请求数与字节数。
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_admin() ) {
			return;
		}

		// --- 区块编辑器样式 ---
		// 页面内容确实包含区块时保留，否则卸载（wp-block-library 约 90KB CSS）。
		$needs_blocks = false;
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
				$needs_blocks = true;
			}
		}

		/**
		 * 过滤器 sa_keep_block_assets：需要强制保留区块样式时返回 true。
		 */
		if ( ! apply_filters( 'sa_keep_block_assets', $needs_blocks ) ) {
			wp_dequeue_style( 'wp-block-library' );
			wp_dequeue_style( 'wp-block-library-theme' );
			wp_dequeue_style( 'wc-block-style' );
			wp_dequeue_style( 'classic-theme-styles' );
			// 全局样式（theme.json 派生），自定义主题用不到。
			wp_dequeue_style( 'global-styles' );
		}

		// --- jQuery ---
		// 主题与核心插件均为原生 JS，不依赖 jQuery。
		// 但第三方插件可能依赖，因此只在确认无其它依赖时卸载。
		/**
		 * 过滤器 sa_dequeue_jquery：装了依赖 jQuery 的插件后，
		 * 用 add_filter( 'sa_dequeue_jquery', '__return_false' ) 关闭本优化。
		 */
		if ( apply_filters( 'sa_dequeue_jquery', true ) ) {
			$jquery = wp_scripts()->query( 'jquery-core', 'registered' );
			$has_dependents = false;

			if ( $jquery ) {
				foreach ( wp_scripts()->queue as $handle ) {
					$item = wp_scripts()->query( $handle, 'registered' );
					if ( $item && ! empty( $item->deps ) && array_intersect( array( 'jquery', 'jquery-core' ), $item->deps ) ) {
						$has_dependents = true;
						break;
					}
				}
			}

			if ( ! $has_dependents ) {
				wp_dequeue_script( 'jquery' );
				wp_dequeue_script( 'jquery-core' );
				wp_dequeue_script( 'jquery-migrate' );
			}
		}
	},
	100
);

/* -------------------------------------------------------------------------
 * 首屏背景图 preload
 * ---------------------------------------------------------------------- */

/**
 * 预加载 hero 背景图。
 *
 * 为什么需要：
 *
 *   .sa-hero 的背景是 CSS background-image。浏览器的 preload scanner 只扫
 *   HTML，扫不到 CSS 里的 url() —— 它必须先下载完整个 style.css、解析、
 *   算出该元素用哪张图，才知道要去取这张图。于是形成一条串行链：
 *       HTML → style.css → 解析 CSS → 下载背景图
 *   三次往返之后首屏大图才开始下载。改成 <img> 可以绕开，但首屏这张图是
 *   铺满区块的装饰背景，用 <img> 得额外套定位，得不偿失。
 *   用 preload 把它提到 HTML 里声明，链路就变成两条并行的。
 *
 *   实测参考：PageSpeed 手机端 LCP 6.1s，比 FCP 只晚 0.5s —— 说明图片
 *   本身不是大头，但这 0.5s 里有一部分就是上面这条串行链。
 *
 * media 必须与 style.css 里的三档断点严格一致，否则会预载一张
 * 页面根本不会用的图 —— 在慢速链路上白占带宽，反而拖慢 LCP。
 *
 * 只输出 WebP：不支持 WebP 的浏览器会忽略带 type 的 preload，回落到
 * CSS 原本的路径，行为不变。当前主流浏览器均支持。
 */
add_action(
	'wp_head',
	function () {
		if ( ! is_front_page() ) {
			return;
		}

		$base = get_template_directory_uri() . '/assets/images/';
		$dir  = get_template_directory() . '/assets/images/';

		// 与 style.css 的 .sa-hero 三档断点一一对应
		$variants = array(
			'hero-bg-640w.webp'  => '(max-width: 640px)',
			'hero-bg-1280w.webp' => '(min-width: 641px) and (max-width: 1280px)',
			'hero-bg-1920w.webp' => '(min-width: 1281px)',
		);

		foreach ( $variants as $file => $media ) {
			// 文件不存在就不输出 —— preload 一个 404 只会浪费一次请求。
			if ( ! file_exists( $dir . $file ) ) {
				continue;
			}
			/*
			 * fetchpriority="high" 是必须的，不是锦上添花。
			 *
			 * preload 只解决「什么时候被发现」，不改变优先级 —— 图片的默认
			 * 优先级是 Low，浏览器会排在 CSS、脚本之后才取它。
			 * Lighthouse 对此有一条专门的审核项（「应将 fetchpriority=high
			 * 应用于图片预加载请求」），上一版漏了这个属性，那一项是不通过的。
			 *
			 * 全站只有这一处用 high：优先级是相对的，标得越多越等于没标。
			 */
			printf(
				'<link rel="preload" as="image" href="%s" type="image/webp" media="%s" fetchpriority="high">' . "\n",
				esc_url( $base . $file ),
				esc_attr( $media )
			);
		}
	},
	2
);

/* -------------------------------------------------------------------------
 * 主样式表内嵌
 * ---------------------------------------------------------------------- */

/**
 * 是否内嵌主样式表。
 *
 * @return bool
 */
function sa_should_inline_css() {
	return (bool) apply_filters( 'sa_inline_critical_css', true );
}

/**
 * 把 style.css 的内容内嵌进 <head>，取代 <link rel="stylesheet">。
 *
 * 为什么这么做：
 *
 *   PageSpeed 的「渲染阻塞请求」一节里，全站只有 style.css 一个条目
 *   （13.2 KiB / 300 毫秒），而它给出的建议原文就是「延迟或内嵌可以将这些
 *   网络请求移出关键路径」。内嵌之后首屏渲染不再需要第二次往返 ——
 *   HTML 一到就能绘制。
 *
 *   取舍要说清楚：CSS 不再能被单独缓存，跨页浏览时每个页面都会重新传一份
 *   （gzip 后约 11 KB）。本站是投放落地页，多数访客只看一个页面就决定去留，
 *   首屏时间比跨页缓存值钱。要改回去把 sa_inline_critical_css 过滤成 false 即可。
 *
 * 相对路径必须改写：
 *
 *   style.css 里有 6 处 url("assets/images/hero-bg-*.webp|jpg")。作为外链样式表
 *   时它们相对于样式表自身解析；一旦内嵌进 HTML，就变成相对于文档地址 ——
 *   会去请求 https://站点/assets/images/...，全部 404，hero 背景图直接消失。
 *   所以这里统一改写为绝对地址。data: 与 http(s): 开头的不动。
 *
 * @param string $tag    原始 <link> 标签。
 * @param string $handle 句柄。
 * @return string
 */
add_filter(
	'style_loader_tag',
	function ( $tag, $handle ) {
		if ( 'sa-theme' !== $handle || is_admin() || ! sa_should_inline_css() ) {
			return $tag;
		}

		static $inline = null;
		if ( null === $inline ) {
			$inline = '';
			$path   = get_stylesheet_directory() . '/style.css';

			// 安全阀：文件异常大时不内嵌，退回外链。内嵌是为了省一次往返，
			// 把几百 KB 塞进每个 HTML 响应反而更慢。
			if ( is_readable( $path ) && filesize( $path ) <= 120 * 1024 ) {
				$css = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 本地主题资源。
				if ( is_string( $css ) && '' !== $css ) {
					$base   = trailingslashit( get_stylesheet_directory_uri() );
					$inline = preg_replace_callback(
						'#url\(\s*([\'"]?)(?!data:|https?:|//|/)([^\'")]+)\1\s*\)#i',
						function ( $m ) use ( $base ) {
							return 'url(' . $m[1] . $base . $m[2] . $m[1] . ')';
						},
						$css
					);

					/*
					 * 压缩：去注释 + 收缩空白。
					 *
					 * 内嵌之后 CSS 就躺在每个 HTML 响应里，体积直接计入关键路径，
					 * 所以这一步比它作为外链时值钱得多。
					 *
					 * 实测本文件：44,034 → 32,292 字节，gzip 后 12,795 → 6,673，
					 * 省掉 48%。本主题的注释写得很详细，占了将近一半 ——
					 * 那些注释对读代码的人有用，对浏览器没用，源文件原样保留。
					 *
					 * 只做这两件事，不动标点周围的空格。像把「: 」压成「:」这类
					 * 改写看着能再省一点，但在选择器、媒体查询、data: URI 里都可能
					 * 压坏语法，收益不值这个风险。
					 *
					 * (?!\!) 保留 /*! 开头的许可证注释，这是通行约定。
					 */
					if ( apply_filters( 'sa_minify_inline_css', true ) ) {
						$min = preg_replace( '#/\*(?!\!).*?\*/#s', '', $inline );
						$min = preg_replace( '#[ \t]+#', ' ', $min );
						$min = preg_replace( '#\s*\n\s*#', "\n", $min );
						$min = preg_replace( '#\n{2,}#', "\n", $min );

						/*
						 * 只有在花括号仍然配平时才采用压缩结果。
						 *
						 * 压坏的 CSS 不会报错，只会让整个页面失去样式 —— 这种事故
						 * 靠肉眼复查很难提前发现，所以留一道自检：数量对不上就
						 * 退回未压缩的版本，宁可多传几 KB。
						 */
						if ( is_string( $min )
							&& substr_count( $min, '{' ) === substr_count( $min, '}' )
							&& substr_count( $min, '{' ) > 0 ) {
							$inline = trim( $min );
						}
					}
				}
			}
		}

		if ( '' === $inline ) {
			return $tag;
		}

		// </style> 不会出现在合法 CSS 里，但内容来自文件，仍做一次防御性处理。
		return '<style id="sa-theme-inline">' . str_replace( '</style', '<\/style', $inline ) . "</style>\n";
	},
	10,
	2
);
