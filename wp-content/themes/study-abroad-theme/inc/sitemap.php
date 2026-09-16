<?php
/**
 * Sitemap 增强（基于 WordPress 核心 wp-sitemap.xml）。
 *
 * 修复要点：
 *   - 把 noindex 页面（隐私政策、感谢页、资料上传页等）排除出 sitemap。
 *     sitemap 的语义是「我希望你收录这些」，把 noindex 页放进去是自相矛盾的信号。
 *   - 为非默认语种（/zh/、/en/）注册独立 sitemap 分组，使各语种页面均可被发现。
 *     此前 sitemap 只含日文版 URL，中英文页面对搜索引擎不可见。
 *   - 移除 users / 作者归档 sitemap（本站为中介落地页，作者页无 SEO 价值且泄露账号）。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 排除无价值 / noindex 内容
 * ---------------------------------------------------------------------- */

/**
 * 从 sitemap 中排除 noindex 页面。
 */
add_filter(
	'wp_sitemaps_posts_query_args',
	function ( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}

		$slugs = sa_noindex_slugs();
		if ( empty( $slugs ) ) {
			return $args;
		}

		$exclude = array();
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post ) {
				$exclude[] = (int) $page->ID;
			}
		}

		if ( ! empty( $exclude ) ) {
			$existing            = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'] = array_merge( $existing, $exclude );
		}

		return $args;
	},
	10,
	2
);

/**
 * 移除作者 sitemap：中介站的作者归档无 SEO 价值，且会暴露登录名。
 */
add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		if ( 'users' === $name ) {
			return false;
		}
		return $provider;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * 多语种 sitemap 提供者
 * ---------------------------------------------------------------------- */

if ( class_exists( 'WP_Sitemaps_Provider' ) ) {

	/**
	 * 为每个非默认语种输出一份 URL 清单。
	 *
	 * 语种前缀由主题在请求阶段剥离，因此任意已发布页面天然拥有
	 * /zh/xxx 与 /en/xxx 两个可访问版本，此处将其显式暴露给搜索引擎。
	 */
	class SA_Sitemap_Locale_Provider extends WP_Sitemaps_Provider {

		/**
		 * 构造。
		 */
		public function __construct() {
			$this->name        = 'locales';
			$this->object_type = 'locale';
		}

		/**
		 * 子类型列表：每个非默认语种一组。
		 *
		 * @return array<string,array<string,string>>
		 */
		public function get_object_subtypes() {
			$subtypes = array();
			foreach ( sa_locales() as $key => $loc ) {
				if ( empty( $loc['prefix'] ) ) {
					continue; // 默认语种由核心 sitemap 覆盖。
				}
				$subtypes[ $loc['prefix'] ] = array(
					'name'  => $loc['prefix'],
					'label' => isset( $loc['label'] ) ? $loc['label'] : $key,
				);
			}
			return $subtypes;
		}

		/**
		 * 取得某语种某页的 URL 列表。
		 *
		 * @param int    $page_num     页码（从 1 开始）。
		 * @param string $object_subtype 语种前缀。
		 * @return array<int,array<string,string>>
		 */
		public function get_url_list( $page_num, $object_subtype = '' ) {
			$locale_key = $this->resolve_locale_key( $object_subtype );
			if ( '' === $locale_key ) {
				return array();
			}

			$url_list = array();

			$query = new WP_Query( $this->build_query_args( $page_num ) );

			/*
			 * lastmod 取自 post_modified。
			 *
			 * 核心自带的 posts 提供者本来就会输出 lastmod，但这份语种清单是
			 * 主题自建的提供者，不补就没有。有更新时间，爬虫才能判断该优先
			 * 重抓哪一个；页面越多越见效。
			 *
			 * changefreq 与 priority 不写 —— Google 已明确表示两者一律忽略，
			 * 写了不会被读取，只留下日后说不出依据的数字。
			 *
			 * 用 post_modified_gmt 并显式按 GMT 解析。另一个字段 post_modified
			 * 是站点本地时间，两者取错就会输出差一个时区的 lastmod。
			 */
			$post_gmt_ts = function ( $post ) {
				if ( empty( $post->post_modified_gmt ) || '0000-00-00 00:00:00' === $post->post_modified_gmt ) {
					return 0;
				}
				return (int) strtotime( $post->post_modified_gmt . ' GMT' );
			};

			$post_lastmod = function ( $post ) use ( $post_gmt_ts ) {
				$ts = $post_gmt_ts( $post );
				return $ts > 0 ? wp_date( DATE_W3C, $ts ) : '';
			};

			// 各语种首页。它的 lastmod 取该语种下所有页面中最新的更新时间 ——
			// 首页的内容就是这些页面的集合。
			$home_entry = array( 'loc' => sa_url( home_url( '/' ), $locale_key ) );
			$newest     = 0;
			foreach ( $query->posts as $post ) {
				$newest = max( $newest, $post_gmt_ts( $post ) );
			}
			if ( $newest > 0 ) {
				$home_entry['lastmod'] = wp_date( DATE_W3C, $newest );
			}
			$url_list[] = $home_entry;

			foreach ( $query->posts as $post ) {
				$permalink = get_permalink( $post );
				if ( ! $permalink ) {
					continue;
				}
				$entry = array( 'loc' => sa_url( $permalink, $locale_key ) );
				$lm    = $post_lastmod( $post );
				if ( '' !== $lm ) {
					$entry['lastmod'] = $lm;
				}
				$url_list[] = $entry;
			}

			wp_reset_postdata();

			return $url_list;
		}

		/**
		 * 某语种的 sitemap 分页总数。
		 *
		 * @param string $object_subtype 语种前缀。
		 * @return int
		 */
		public function get_max_num_pages( $object_subtype = '' ) {
			if ( '' === $this->resolve_locale_key( $object_subtype ) ) {
				return 0;
			}
			$args                  = $this->build_query_args( 1 );
			$args['fields']        = 'ids';
			$args['no_found_rows'] = false;

			$query = new WP_Query( $args );
			$max   = (int) $query->max_num_pages;

			return $max > 0 ? $max : 1;
		}

		/**
		 * 构造文章查询参数（排除 noindex 页面）。
		 *
		 * @param int $page_num 页码。
		 * @return array<string,mixed>
		 */
		private function build_query_args( $page_num ) {
			$exclude = array();
			foreach ( sa_noindex_slugs() as $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page instanceof WP_Post ) {
					$exclude[] = (int) $page->ID;
				}
			}

			return array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => 'publish',
				'posts_per_page'         => (int) wp_sitemaps_get_max_urls( $this->object_type ),
				'paged'                  => max( 1, (int) $page_num ),
				'post__not_in'           => $exclude,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
		}

		/**
		 * 语种前缀 → 语种 key。
		 *
		 * @param string $prefix 前缀。
		 * @return string 空字符串表示无效。
		 */
		private function resolve_locale_key( $prefix ) {
			$map = sa_locale_prefix_map();
			return isset( $map[ $prefix ] ) ? $map[ $prefix ] : '';
		}
	}

	/**
	 * 院校公开页 sitemap。
	 *
	 * 院校详情页是自定义 rewrite 端点，数据存在 sa_schools 表而非 wp_posts，
	 * 因此 WordPress 核心 sitemap 完全看不到它们 —— 不单独注册的话，
	 * 这些页面只能靠列表页的内链被发现，收录会明显变慢。
	 *
	 * 每个语种一组：同一所院校在三个语种下是三个独立可索引 URL。
	 */
	class SA_Sitemap_Schools_Provider extends WP_Sitemaps_Provider {

		/**
		 * 构造。
		 */
		public function __construct() {
			$this->name        = 'schools';
			$this->object_type = 'school';
		}

		/**
		 * 子类型：每个语种一组（含默认语种）。
		 *
		 * @return array<string,array<string,string>>
		 */
		public function get_object_subtypes() {
			$subtypes = array();
			foreach ( sa_locales() as $key => $loc ) {
				// 默认语种前缀为空，用语种 key 作为分组名。
				$name              = empty( $loc['prefix'] ) ? $key : $loc['prefix'];
				$subtypes[ $name ] = array(
					'name'  => $name,
					'label' => isset( $loc['label'] ) ? $loc['label'] : $key,
				);
			}
			return $subtypes;
		}

		/**
		 * URL 列表。
		 *
		 * @param int    $page_num       页码。
		 * @param string $object_subtype 语种分组名。
		 * @return array<int,array<string,string>>
		 */
		public function get_url_list( $page_num, $object_subtype = '' ) {
			$locale_key = $this->resolve_locale_key( $object_subtype );
			if ( '' === $locale_key || ! class_exists( 'SA_School_Repo' ) ) {
				return array();
			}

			$per_page = (int) wp_sitemaps_get_max_urls( $this->object_type );
			$schools  = SA_School_Repo::get_published_schools(
				array(
					'limit'  => $per_page,
					'offset' => ( max( 1, (int) $page_num ) - 1 ) * $per_page,
				)
			);

			$url_list = array();

			/*
			 * 为什么要输出 lastmod。
			 *
			 * WordPress 核心的自定义 sitemap 提供者只输出 loc。院校页共
			 * 8 所 × 3 语种 = 24 个 URL，各校的更新节奏又不一样（改过学费的
			 * 和没改的混在一起）。没有 lastmod，爬虫无从判断该优先重抓哪一个，
			 * 改过的那几所要更久才会被重新收录。
			 *
			 * 渲染器接受 lastmod / changefreq / priority 三个字段，但
			 * changefreq 与 priority 不写 —— Google 已明确表示两者一律忽略。
			 * 写了也不会被读取，反而留下一堆日后说不出依据的数字。
			 *
			 * 格式为 W3C Datetime（ISO 8601）。
			 *
			 * updated_at 由 SA_DB::now() 写入，值是 current_time('mysql', true)，
			 * 即 GMT。所以这里显式按 GMT 解析：WordPress 会把 PHP 默认时区设为
			 * UTC，不加后缀眼下也能得出同样的结果，但那是巧合 —— 一旦有插件
			 * 调用 date_default_timezone_set()，全部 lastmod 就会整体偏移时差。
			 */
			$to_gmt_ts = function ( $mysql_datetime ) {
				if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
					return 0;
				}
				return (int) strtotime( $mysql_datetime . ' GMT' );
			};

			$lastmod = function ( $row ) use ( $to_gmt_ts ) {
				$ts = $to_gmt_ts( isset( $row['updated_at'] ) ? $row['updated_at'] : '' );
				return $ts > 0 ? wp_date( DATE_W3C, $ts ) : '';
			};

			// 列表页本身也收录（进入详情页的入口）。
			// 它的 lastmod 取已上架院校中最新的更新时间 —— 列表的内容就是这些院校的
			// 集合，任何一所变了，列表页也就变了。
			if ( 1 === (int) $page_num ) {
				$entry  = array( 'loc' => sa_schools_url( $locale_key ) );
				$newest = 0;
				foreach ( $schools as $school ) {
					$newest = max( $newest, $to_gmt_ts( isset( $school['updated_at'] ) ? $school['updated_at'] : '' ) );
				}
				if ( $newest > 0 ) {
					$entry['lastmod'] = wp_date( DATE_W3C, $newest );
				}
				$url_list[] = $entry;
			}

			foreach ( $schools as $school ) {
				if ( empty( $school['slug'] ) ) {
					continue;
				}
				$entry = array( 'loc' => sa_school_url( $school['slug'], $locale_key ) );
				$lm    = $lastmod( $school );
				if ( '' !== $lm ) {
					$entry['lastmod'] = $lm;
				}
				$url_list[] = $entry;
			}

			return $url_list;
		}

		/**
		 * 分页总数。
		 *
		 * @param string $object_subtype 语种分组名。
		 * @return int
		 */
		public function get_max_num_pages( $object_subtype = '' ) {
			if ( '' === $this->resolve_locale_key( $object_subtype ) || ! class_exists( 'SA_School_Repo' ) ) {
				return 0;
			}

			$total = SA_School_Repo::count_published_schools();
			if ( $total < 1 ) {
				// 没有已发布院校时，仍保留 1 页用于收录列表页。
				return 1;
			}

			$per_page = max( 1, (int) wp_sitemaps_get_max_urls( $this->object_type ) );

			return (int) ceil( $total / $per_page );
		}

		/**
		 * 分组名 → 语种 key。
		 *
		 * @param string $subtype 分组名（语种前缀，或默认语种的 key）。
		 * @return string
		 */
		private function resolve_locale_key( $subtype ) {
			if ( '' === $subtype ) {
				return '';
			}

			$map = sa_locale_prefix_map();
			if ( isset( $map[ $subtype ] ) ) {
				return $map[ $subtype ];
			}

			// 默认语种以 key 命名分组。
			$locales = sa_locales();
			return isset( $locales[ $subtype ] ) ? $subtype : '';
		}
	}

	add_action(
		'init',
		function () {
			if ( ! function_exists( 'wp_register_sitemap_provider' ) ) {
				return;
			}

			// 只有存在非默认语种时才注册多语种分组。
			if ( ! empty( sa_locale_prefix_map() ) ) {
				wp_register_sitemap_provider( 'locales', new SA_Sitemap_Locale_Provider() );
			}

			// 院校页 sitemap：有院校库时才注册。
			if ( class_exists( 'SA_School_Repo' ) && function_exists( 'sa_school_url' ) ) {
				wp_register_sitemap_provider( 'schools', new SA_Sitemap_Schools_Provider() );
			}
		},
		20
	);
}
