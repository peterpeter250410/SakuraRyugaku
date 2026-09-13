<?php
/**
 * 院校与专业的数据访问层。
 *
 * 负责 sa_schools / sa_programs 两张表的读写，
 * 并提供演示数据填充（seed_demo）用于开发/演示环境。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_School_Repo {

	/**
	 * 取出所有 active 院校下的 active 专业（联表），并携带院校信息。
	 *
	 * 返回的每一行既包含专业字段，也带上院校维度的字段（school_* 前缀），
	 * 供匹配引擎在遍历专业时同时读取院校信息（如地区、最低学历）。
	 *
	 * @return array 专业行数组（ARRAY_A），失败或无数据返回空数组。
	 */
	public static function get_active_programs() {
		global $wpdb;

		$programs = SA_DB::table( 'programs' );
		$schools  = SA_DB::table( 'schools' );

		// 联表查询：专业表 p 关联院校表 s，两侧均需 active。
		$sql = "SELECT
					p.id            AS program_id,
					p.school_id     AS school_id,
					p.name          AS program_name,
					p.name_i18n     AS program_name_i18n,
					p.major_tags    AS major_tags,
					p.tuition_min   AS tuition_min,
					p.tuition_max   AS tuition_max,
					p.language_req  AS program_language_req,
					p.duration      AS duration,
					p.status        AS program_status,
					s.name          AS school_name,
					s.name_i18n     AS school_name_i18n,
					s.school_type   AS school_type,
					s.region        AS school_region,
					s.city          AS school_city,
					s.language_req  AS school_language_req,
					s.min_education AS school_min_education,
					s.status        AS school_status
				FROM {$programs} p
				INNER JOIN {$schools} s ON s.id = p.school_id
				WHERE p.status = %s AND s.status = %s
				ORDER BY s.sort_order ASC, s.id ASC, p.id ASC";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, 'active', 'active' ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 按 ID 取单条院校。
	 *
	 * @param int $id 院校 ID。
	 * @return array|null
	 */
	public static function get_school( $id ) {
		global $wpdb;
		$table = SA_DB::table( 'schools' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * 按 ID 取单条专业。
	 *
	 * @param int $id 专业 ID。
	 * @return array|null
	 */
	public static function get_program( $id ) {
		global $wpdb;
		$table = SA_DB::table( 'programs' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * 创建一条院校记录。
	 *
	 * JSON 字段（name_i18n、description_i18n）若传入数组会自动编码为 JSON。
	 *
	 * @param array $data 院校字段。
	 * @return int|false 新院校 ID，失败返回 false。
	 */
	public static function create_school( array $data ) {
		global $wpdb;

		$now = SA_DB::now();

		$row = array(
			'post_id'          => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0,
			'name'             => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'name_i18n'        => self::encode_json_field( isset( $data['name_i18n'] ) ? $data['name_i18n'] : null ),
			'school_type'      => isset( $data['school_type'] ) ? sanitize_text_field( $data['school_type'] ) : '',
			'region'           => isset( $data['region'] ) ? sanitize_text_field( $data['region'] ) : '',
			'city'             => isset( $data['city'] ) ? sanitize_text_field( $data['city'] ) : '',
			'official_url'     => self::sanitize_official_url( isset( $data['official_url'] ) ? $data['official_url'] : '' ),
			'language_req'     => isset( $data['language_req'] ) ? sanitize_text_field( $data['language_req'] ) : '',
			'min_education'    => isset( $data['min_education'] ) ? sanitize_text_field( $data['min_education'] ) : '',
			'description_i18n' => self::encode_json_field( isset( $data['description_i18n'] ) ? $data['description_i18n'] : null ),
			'required_docs'    => self::encode_json_field( isset( $data['required_docs'] ) ? $data['required_docs'] : null ),
			'status'           => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active',
			// published 默认 0：新建院校不会自动对外可见，须核实数据后显式发布。
			'published'        => empty( $data['published'] ) ? 0 : 1,
			'sort_order'       => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		/*
		 * 格式数组按位置对应 $row 的键顺序，不是按键名匹配 ——
		 * 少一个或错一位，后面所有字段都会用错误的类型写入。
		 * $row 顺序：post_id(%d) / name..status 共 11 个 %s /
		 *            published(%d) / sort_order(%d) / created_at(%s) / updated_at(%s)
		 * 合计 16，与 $row 元素个数一致（下方断言兜底）。
		 */
		$formats = array_merge(
			array( '%d' ),
			array_fill( 0, 11, '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( count( $formats ) !== count( $row ) ) {
			// 字段与格式数量不一致说明有人改了 $row 却漏改格式，宁可失败也不要写脏数据。
			return false;
		}

		$ok = $wpdb->insert( SA_DB::table( 'schools' ), $row, $formats );

		if ( ! $ok ) {
			return false;
		}

		$new_id = (int) $wpdb->insert_id;

		/*
		 * slug 在插入后再写入：生成唯一 slug 需要 ID 做兜底（school-{id}），
		 * 而 ID 只有插入后才存在。
		 */
		$slug = isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '';
		if ( '' !== $slug ) {
			$slug = self::unique_slug( $slug, $new_id );
		} else {
			$slug = self::generate_slug( $data, $new_id );
		}

		if ( '' !== $slug ) {
			$wpdb->update(
				SA_DB::table( 'schools' ),
				array( 'slug' => $slug ),
				array( 'id' => $new_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $new_id;
	}

	/**
	 * 清洗学校官网地址。
	 *
	 * 只接受 http / https：这个值会被输出成前台可点击的 <a href> 与
	 * JSON-LD 的 about.url，若放行 javascript: 或 data: 协议，
	 * 一个有院校编辑权限的账号就能借此在所有访客页面上执行脚本。
	 *
	 * 未带协议的输入（editor 常直接粘 "www.example.ac.jp"）补 https://，
	 * 否则浏览器会把它当相对路径解析到本站下。
	 *
	 * @param mixed $url 原始输入。
	 * @return string 合法的绝对 URL，或空字符串。
	 */
	private static function sanitize_official_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean ) {
			return '';
		}

		// esc_url_raw 对协议不在白名单时会剥掉协议而非返回空，需再确认一次。
		$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return substr( $clean, 0, 255 );
	}

	/**
	 * 默认资料清单：当某院校未在后台自定义时的兜底要求。
	 *
	 * 每项：key（doc_type，用于上传校验）、label（前端显示）、
	 * type（image|pdf|office|text）、required（是否必填）。
	 *
	 * @return array
	 */
	public static function default_required_docs() {
		return array(
			array( 'key' => 'passport', 'label' => 'パスポート写し', 'type' => 'image', 'required' => 1 ),
			array( 'key' => 'photo', 'label' => '証明写真', 'type' => 'image', 'required' => 1 ),
			array( 'key' => 'transcript', 'label' => '成績証明書', 'type' => 'pdf', 'required' => 1 ),
			array( 'key' => 'diploma', 'label' => '卒業証明書', 'type' => 'pdf', 'required' => 1 ),
			array( 'key' => 'jlpt', 'label' => '日本語能力証明', 'type' => 'pdf', 'required' => 0 ),
			array( 'key' => 'statement', 'label' => '志望理由（テキスト記入可）', 'type' => 'text', 'required' => 0 ),
		);
	}

	/**
	 * 更新一条院校记录（仅更新传入字段）。
	 *
	 * @param int   $id   院校 ID。
	 * @param array $data 待更新字段（子集）。
	 * @return bool
	 */
	public static function update_school( $id, array $data ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$row     = array();
		$formats = array();

		$text_fields = array( 'name', 'school_type', 'region', 'city', 'language_req', 'min_education' );
		foreach ( $text_fields as $f ) {
			if ( isset( $data[ $f ] ) ) {
				$row[ $f ]   = sanitize_text_field( $data[ $f ] );
				$formats[]   = '%s';
			}
		}

		// 官网地址走独立的 URL 清洗，不能混进 $text_fields ——
		// sanitize_text_field 不会拦截 javascript: 之类的协议。
		if ( array_key_exists( 'official_url', $data ) ) {
			$row['official_url'] = self::sanitize_official_url( $data['official_url'] );
			$formats[]           = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$row['status'] = sanitize_key( $data['status'] );
			$formats[]     = '%s';
		}
		if ( isset( $data['sort_order'] ) ) {
			$row['sort_order'] = (int) $data['sort_order'];
			$formats[]         = '%d';
		}

		// 公开发布开关。默认关闭，须显式置 1 才生成对外页面。
		if ( isset( $data['published'] ) ) {
			$row['published'] = empty( $data['published'] ) ? 0 : 1;
			$formats[]        = '%d';
		}

		// slug：留空则自动生成；存空字符串会与唯一索引冲突，故归一为 NULL。
		if ( array_key_exists( 'slug', $data ) ) {
			$slug = sanitize_title( (string) $data['slug'] );
			if ( '' === $slug ) {
				$existing = self::get_school( $id );
				$slug     = $existing ? self::generate_slug( $existing, $id ) : '';
			} else {
				$slug = self::unique_slug( $slug, $id );
			}
			$row['slug'] = '' === $slug ? null : $slug;
			$formats[]   = '%s';
		}
		foreach ( array( 'name_i18n', 'description_i18n', 'required_docs' ) as $jf ) {
			if ( array_key_exists( $jf, $data ) ) {
				$row[ $jf ] = self::encode_json_field( $data[ $jf ] );
				$formats[]  = '%s';
			}
		}

		if ( empty( $row ) ) {
			return false;
		}

		$row['updated_at'] = SA_DB::now();
		$formats[]         = '%s';

		$ok = $wpdb->update(
			SA_DB::table( 'schools' ),
			$row,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $ok;
	}

	/**
	 * 取某院校的资料清单（解码 required_docs，缺省回落默认清单）。
	 *
	 * @param int $school_id 院校 ID。
	 * @return array 资料项数组（每项含 key/label/type/required）。
	 */
	public static function get_required_docs( $school_id ) {
		$school = self::get_school( $school_id );
		if ( ! $school || empty( $school['required_docs'] ) ) {
			return self::default_required_docs();
		}

		$decoded = json_decode( $school['required_docs'], true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return self::default_required_docs();
		}

		// 规范化每一项，防止后台存入脏数据。
		$clean = array();
		foreach ( $decoded as $item ) {
			if ( empty( $item['key'] ) ) {
				continue;
			}
			$clean[] = array(
				'key'      => sanitize_key( $item['key'] ),
				'label'    => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : $item['key'],
				'type'     => isset( $item['type'] ) && in_array( $item['type'], array( 'image', 'pdf', 'office', 'text' ), true ) ? $item['type'] : 'pdf',
				'required' => ! empty( $item['required'] ) ? 1 : 0,
			);
		}

		return empty( $clean ) ? self::default_required_docs() : $clean;
	}

	/**
	 * 取院校列表（后台管理用）。
	 *
	 * @param string|null $status 按状态过滤（null 为全部）。
	 * @return array
	 */
	public static function all_schools( $status = null ) {
		global $wpdb;
		$table = SA_DB::table( 'schools' );

		if ( null === $status ) {
			$rows = $wpdb->get_results(
				"SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC",
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY sort_order ASC, id ASC",
					sanitize_key( $status )
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 创建一条专业记录。
	 *
	 * JSON 字段（name_i18n、major_tags）若传入数组会自动编码为 JSON。
	 *
	 * @param array $data 专业字段。
	 * @return int|false 新专业 ID，失败返回 false。
	 */
	public static function create_program( array $data ) {
		global $wpdb;

		$now = SA_DB::now();

		$row = array(
			'school_id'    => isset( $data['school_id'] ) ? absint( $data['school_id'] ) : 0,
			'name'         => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'name_i18n'    => self::encode_json_field( isset( $data['name_i18n'] ) ? $data['name_i18n'] : null ),
			'major_tags'   => self::encode_json_field( isset( $data['major_tags'] ) ? $data['major_tags'] : null ),
			'tuition_min'  => isset( $data['tuition_min'] ) ? absint( $data['tuition_min'] ) : 0,
			'tuition_max'  => isset( $data['tuition_max'] ) ? absint( $data['tuition_max'] ) : 0,
			'language_req' => isset( $data['language_req'] ) ? sanitize_text_field( $data['language_req'] ) : '',
			'duration'     => isset( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : '',
			'status'       => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active',
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$ok = $wpdb->insert(
			SA_DB::table( 'programs' ),
			$row,
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/* ---------------------------------------------------------------------
	 * 对外公开页（院校详情页）所需的查询
	 * ------------------------------------------------------------------ */

	/**
	 * 按 slug 取院校。
	 *
	 * @param string $slug              URL slug。
	 * @param bool   $require_published 是否仅返回已发布院校（默认是）。
	 *                                  后台预览未发布院校时传 false。
	 * @return array|null
	 */
	public static function get_school_by_slug( $slug, $require_published = true ) {
		global $wpdb;

		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$table = SA_DB::table( 'schools' );

		if ( $require_published ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE slug = %s AND published = 1 LIMIT 1",
					$slug
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ),
				ARRAY_A
			);
		}

		return $row ? $row : null;
	}

	/**
	 * 取某院校下的 active 专业。
	 *
	 * @param int $school_id 院校 ID。
	 * @return array 专业行数组（ARRAY_A）。
	 */
	public static function get_school_programs( $school_id ) {
		global $wpdb;

		$table = SA_DB::table( 'programs' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE school_id = %d AND status = 'active' ORDER BY id ASC",
				absint( $school_id )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * 取已发布且有 slug 的院校（公开列表页与 sitemap 用）。
	 *
	 * 没有 slug 就无法生成 URL，因此一并排除，避免列表里出现点不开的条目。
	 *
	 * @param array $args 可选：limit、offset、school_type、region。
	 * @return array
	 */
	public static function get_published_schools( array $args = array() ) {
		global $wpdb;

		$table = SA_DB::table( 'schools' );

		$where  = array( 'published = 1', "slug IS NOT NULL", "slug <> ''" );
		$params = array();

		if ( ! empty( $args['school_type'] ) ) {
			$where[]  = 'school_type = %s';
			$params[] = sanitize_text_field( $args['school_type'] );
		}
		if ( ! empty( $args['region'] ) ) {
			$where[]  = 'region = %s';
			$params[] = sanitize_text_field( $args['region'] );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY sort_order ASC, id ASC';

		if ( isset( $args['limit'] ) ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = max( 1, (int) $args['limit'] );
			$params[] = max( 0, isset( $args['offset'] ) ? (int) $args['offset'] : 0 );
		}

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		return $rows ? $rows : array();
	}

	/**
	 * 已发布院校总数（分页用）。
	 *
	 * @return int
	 */
	public static function count_published_schools() {
		global $wpdb;

		$table = SA_DB::table( 'schools' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE published = 1 AND slug IS NOT NULL AND slug <> ''"
		);
	}

	/* ---------------------------------------------------------------------
	 * 多语言字段
	 * ------------------------------------------------------------------ */

	/**
	 * 取 i18n JSON 字段在指定语种下的值。
	 *
	 * 院校名称与简介存在数据库的 *_i18n JSON 列中，不经过 gettext，
	 * 因此需要在读取时按语种解析。
	 *
	 * JSON 里的键历史上用短码（ja / zh / en），而主题的语种 key 是
	 * ja / zh_CN / en_US，故按「完整 key → 短码 → 默认语种 → 基础列」逐级回退。
	 * 任何一级命中即返回，保证永远不会输出空白。
	 *
	 * @param array  $row        数据行。
	 * @param string $json_field JSON 列名，如 name_i18n。
	 * @param string $base_field 兜底的基础列名，如 name。
	 * @param string $locale     语种 key，如 zh_CN；留空取当前语种。
	 * @return string
	 */
	public static function localized_field( array $row, $json_field, $base_field, $locale = '' ) {
		if ( '' === $locale ) {
			$locale = function_exists( 'sa_current_locale' ) ? sa_current_locale() : 'ja';
		}

		$decoded = array();
		if ( ! empty( $row[ $json_field ] ) ) {
			$maybe = json_decode( (string) $row[ $json_field ], true );
			if ( is_array( $maybe ) ) {
				$decoded = $maybe;
			}
		}

		// 完整 key（zh_CN）→ 短码（zh）
		$short = strtolower( substr( $locale, 0, 2 ) );
		foreach ( array( $locale, $short ) as $key ) {
			if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
				return $decoded[ $key ];
			}
		}

		// 默认语种（日语）
		if ( ! empty( $decoded['ja'] ) && is_string( $decoded['ja'] ) ) {
			return $decoded['ja'];
		}

		// 基础列
		return isset( $row[ $base_field ] ) ? (string) $row[ $base_field ] : '';
	}

	/* ---------------------------------------------------------------------
	 * slug
	 * ------------------------------------------------------------------ */

	/**
	 * 生成唯一的 slug。
	 *
	 * 优先用英文名派生：sanitize_title() 对日文/中文会输出百分号编码，
	 * 既不可读也不利于分享与外链锚文本，因此
	 * 「英文名 → 基础名（若为 ASCII）→ school-{id}」逐级回退。
	 * 冲突时追加 -2、-3。
	 *
	 * @param array $row        院校数据（至少含 name，可含 name_i18n）。
	 * @param int   $exclude_id 更新时排除自身 ID。
	 * @return string 可能为空字符串（无法生成时由调用方决定是否放弃）。
	 */
	public static function generate_slug( array $row, $exclude_id = 0 ) {
		$candidates = array();

		// 英文名优先
		$en = self::localized_field( $row, 'name_i18n', 'name', 'en_US' );
		if ( '' !== $en ) {
			$candidates[] = $en;
		}
		if ( ! empty( $row['name'] ) ) {
			$candidates[] = $row['name'];
		}

		$base = '';
		foreach ( $candidates as $c ) {
			$try = sanitize_title( $c );
			// 含百分号说明 sanitize_title 对非 ASCII 做了编码，不适合做 URL。
			if ( '' !== $try && false === strpos( $try, '%' ) ) {
				$base = $try;
				break;
			}
		}

		if ( '' === $base ) {
			$base = $exclude_id > 0 ? 'school-' . $exclude_id : '';
		}
		if ( '' === $base ) {
			return '';
		}

		return self::unique_slug( $base, $exclude_id );
	}

	/**
	 * 确保 slug 在表内唯一。
	 *
	 * @param string $base       基础 slug。
	 * @param int    $exclude_id 排除的院校 ID。
	 * @return string
	 */
	public static function unique_slug( $base, $exclude_id = 0 ) {
		global $wpdb;

		$base = sanitize_title( $base );
		if ( '' === $base ) {
			return '';
		}

		$table = SA_DB::table( 'schools' );
		$slug  = $base;
		$n     = 1;

		while ( true ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1",
					$slug,
					absint( $exclude_id )
				)
			);
			if ( ! $found ) {
				return $slug;
			}
			++$n;
			$slug = $base . '-' . $n;

			if ( $n > 50 ) {
				// 极端情况下放弃递增，用时间戳兜底，避免死循环。
				return $base . '-' . time();
			}
		}
	}

	/**
	 * 将数组/字符串字段规范化为可存储的 JSON 字符串。
	 *
	 * - 数组：wp_json_encode 编码。
	 * - 字符串：原样返回（假定已是合法 JSON）。
	 * - null/空：返回 null（存 NULL）。
	 *
	 * @param mixed $value 待处理值。
	 * @return string|null
	 */
	private static function encode_json_field( $value ) {
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
		return null;
	}

	/**
	 * 填充演示院校与专业数据（幂等）。
	 *
	 * 若 sa_schools 已存在数据则跳过，避免重复插入。
	 * 用于开发/演示环境快速获得可匹配的院校库。
	 *
	 * @return array{schools:int, programs:int} 实际插入的院校数与专业数。
	 */
	public static function seed_demo() {
		global $wpdb;

		$schools_table = SA_DB::table( 'schools' );

		// 幂等：已有院校则不重复填充。
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$schools_table}" );
		if ( $existing > 0 ) {
			return array(
				'schools'  => 0,
				'programs' => 0,
			);
		}

		// 演示院校及其专业定义（学费单位：日元/年）。
		$demo = array(
			array(
				'school'   => array(
					'name'          => '早稲田大学',
					'name_i18n'     => array(
						'ja' => '早稲田大学',
						'zh' => '早稻田大学',
						'en' => 'Waseda University',
					),
					'school_type'   => 'university',
					'region'        => '関東',
					'city'          => '東京',
					'language_req'  => 'JLPT N2',
					'min_education' => 'high_school',
					'status'        => 'active',
					'sort_order'    => 1,
				),
				'programs' => array(
					array(
						'name'        => '経営学部',
						'name_i18n'   => array(
							'zh' => '经营学部',
							'en' => 'School of Commerce',
						),
						'major_tags'  => array( '经营', '商学' ),
						'tuition_min' => 1000000,
						'tuition_max' => 1300000,
						'language_req'=> 'JLPT N2',
						'duration'    => '4年',
					),
					array(
						'name'        => '基幹理工学部',
						'name_i18n'   => array(
							'zh' => '基础理工学部',
							'en' => 'School of Fundamental Science and Engineering',
						),
						'major_tags'  => array( 'IT', '工学' ),
						'tuition_min' => 1500000,
						'tuition_max' => 1800000,
						'language_req'=> 'JLPT N2',
						'duration'    => '4年',
					),
				),
			),
			array(
				'school'   => array(
					'name'          => '東京大学',
					'name_i18n'     => array(
						'ja' => '東京大学',
						'zh' => '东京大学',
						'en' => 'The University of Tokyo',
					),
					'school_type'   => 'university',
					'region'        => '関東',
					'city'          => '東京',
					'language_req'  => 'JLPT N1',
					'min_education' => 'high_school',
					'status'        => 'active',
					'sort_order'    => 2,
				),
				'programs' => array(
					array(
						'name'        => '文学部',
						'name_i18n'   => array(
							'zh' => '文学部',
							'en' => 'Faculty of Letters',
						),
						'major_tags'  => array( '文学', '人文' ),
						'tuition_min' => 535800,
						'tuition_max' => 535800,
						'language_req'=> 'JLPT N1',
						'duration'    => '4年',
					),
					array(
						'name'        => '工学部（情報工学）',
						'name_i18n'   => array(
							'zh' => '工学部（信息工学）',
							'en' => 'Faculty of Engineering (Information)',
						),
						'major_tags'  => array( 'IT', '工学', '计算机' ),
						'tuition_min' => 535800,
						'tuition_max' => 800000,
						'language_req'=> 'JLPT N1',
						'duration'    => '4年',
					),
				),
			),
			array(
				'school'   => array(
					'name'          => '大阪大学',
					'name_i18n'     => array(
						'ja' => '大阪大学',
						'zh' => '大阪大学',
						'en' => 'Osaka University',
					),
					'school_type'   => 'university',
					'region'        => '関西',
					'city'          => '大阪',
					'language_req'  => 'JLPT N2',
					'min_education' => 'high_school',
					'status'        => 'active',
					'sort_order'    => 3,
				),
				'programs' => array(
					array(
						'name'        => '経済学部',
						'name_i18n'   => array(
							'zh' => '经济学部',
							'en' => 'School of Economics',
						),
						'major_tags'  => array( '经营', '经济' ),
						'tuition_min' => 535800,
						'tuition_max' => 700000,
						'language_req'=> 'JLPT N2',
						'duration'    => '4年',
					),
					array(
						'name'        => '情報科学研究科',
						'name_i18n'   => array(
							'zh' => '信息科学研究科',
							'en' => 'Graduate School of Information Science',
						),
						'major_tags'  => array( 'IT', '计算机' ),
						'tuition_min' => 800000,
						'tuition_max' => 1100000,
						'language_req'=> 'JLPT N2',
						'duration'    => '2年',
					),
				),
			),
			array(
				'school'   => array(
					'name'          => '京都大学',
					'name_i18n'     => array(
						'ja' => '京都大学',
						'zh' => '京都大学',
						'en' => 'Kyoto University',
					),
					'school_type'   => 'university',
					'region'        => '関西',
					'city'          => '京都',
					'language_req'  => 'JLPT N1',
					'min_education' => 'high_school',
					'status'        => 'active',
					'sort_order'    => 4,
				),
				'programs' => array(
					array(
						'name'        => '文学研究科',
						'name_i18n'   => array(
							'zh' => '文学研究科',
							'en' => 'Graduate School of Letters',
						),
						'major_tags'  => array( '文学', '人文' ),
						'tuition_min' => 535800,
						'tuition_max' => 600000,
						'language_req'=> 'JLPT N1',
						'duration'    => '2年',
					),
				),
			),
		);

		$school_count  = 0;
		$program_count = 0;

		foreach ( $demo as $entry ) {
			$school_id = self::create_school( $entry['school'] );
			if ( ! $school_id ) {
				continue;
			}
			$school_count++;

			foreach ( $entry['programs'] as $program ) {
				$program['school_id'] = $school_id;
				$program['status']    = 'active';
				if ( self::create_program( $program ) ) {
					$program_count++;
				}
			}
		}

		return array(
			'schools'  => $school_count,
			'programs' => $program_count,
		);
	}
}
