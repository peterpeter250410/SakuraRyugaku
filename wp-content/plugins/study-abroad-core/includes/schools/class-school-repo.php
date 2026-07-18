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
			'language_req'     => isset( $data['language_req'] ) ? sanitize_text_field( $data['language_req'] ) : '',
			'min_education'    => isset( $data['min_education'] ) ? sanitize_text_field( $data['min_education'] ) : '',
			'description_i18n' => self::encode_json_field( isset( $data['description_i18n'] ) ? $data['description_i18n'] : null ),
			'status'           => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'active',
			'sort_order'       => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$ok = $wpdb->insert(
			SA_DB::table( 'schools' ),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
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
