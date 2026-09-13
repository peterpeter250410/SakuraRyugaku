<?php
/**
 * import-schools.php — 从 JSON 导入院校与专业数据。
 *
 * 配合 fetch-school-data.php 使用：
 *   1. php scripts/fetch-school-data.php        采集官网公开信息
 *   2. 人工核实并整理为 scripts/schools.json
 *   3. php scripts/import-schools.php scripts/schools.json
 *
 * 幂等：按 slug 判断，已存在则更新，不存在则新建。
 * 可反复执行，不会产生重复院校。
 *
 * published 的语义：
 *   新建院校时按 JSON 取值（默认 false，即页面 404、不被收录）。
 *   更新已有院校时**不动**这个字段 —— 发布与否是运营状态，不是院校资料：
 *   某校下线后，为了补一句简介重跑导入，不该把它又悄悄发布出去。
 *   日常发布 / 下线请用 scripts/publish-school.sh，它直接改库并验证页面状态，
 *   不需要编辑这个被 git 跟踪的 JSON（在服务器上改它会让工作区变脏，
 *   挡住下一次 git merge --ff-only —— 本项目实际踩过）。
 *
 * 用法：
 *   php scripts/import-schools.php <file.json>
 *   php scripts/import-schools.php <file.json> --dry-run          只校验不写库
 *   php scripts/import-schools.php <file.json> --force-published  用 JSON 覆盖线上发布状态
 *
 * JSON 格式（顶层为数组）：
 * [
 *   {
 *     "slug": "akamonkai",
 *     "name": "赤門会日本語学校",
 *     "name_i18n":        { "ja": "...", "zh": "...", "en": "..." },
 *     "description_i18n": { "ja": "...", "zh": "...", "en": "..." },
 *     "school_type": "language",          language|vocational|university|graduate|junior
 *     "region": "関東",
 *     "city": "東京",
 *     "language_req": "",                 入学时的日语要求，无则留空
 *     "min_education": "high_school",     junior_high|high_school|bachelor
 *     "status": "active",                 是否参与 AI 匹配
 *     "published": false,                 是否生成对外可索引页面
 *     "sort_order": 10,
 *     "programs": [
 *       {
 *         "name": "進学コース",
 *         "name_i18n": { "zh": "升学课程", "en": "University Preparation" },
 *         "major_tags": ["进学", "日语"],
 *         "tuition_min": 780000,          日元/年，未知填 0
 *         "tuition_max": 860000,
 *         "language_req": "N5",
 *         "duration": "1年〜2年"
 *       }
 *     ]
 *   }
 * ]
 *
 * 兼容 PHP 7.4。
 *
 * @package StudyAbroadCore
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 1 );
}

$args    = array_slice( $argv, 1 );
$dry_run         = in_array( '--dry-run', $args, true );
// 默认不用 JSON 的 published 覆盖已有院校的线上发布状态，见下方更新分支的说明。
$force_published = in_array( '--force-published', $args, true );
$files   = array_values( array_filter( $args, function ( $a ) { return 0 !== strpos( $a, '--' ); } ) );

if ( empty( $files ) ) {
	fwrite( STDERR, "用法: php scripts/import-schools.php <file.json> [--dry-run]\n" );
	exit( 1 );
}

$json_file = $files[0];
if ( ! is_readable( $json_file ) ) {
	fwrite( STDERR, "无法读取: {$json_file}\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * 载入 WordPress
 * ---------------------------------------------------------------------- */

$site_root = dirname( __DIR__ );
$wp_load   = $site_root . '/wp-load.php';

if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "找不到 wp-load.php（期望位置: {$wp_load}）\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
require_once $wp_load;

if ( ! class_exists( 'SA_School_Repo' ) ) {
	fwrite( STDERR, "SA_School_Repo 不可用。请确认「Study Abroad Core」插件已启用。\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * 解析与校验
 * ---------------------------------------------------------------------- */

$raw  = file_get_contents( $json_file );
$data = json_decode( $raw, true );

if ( ! is_array( $data ) ) {
	fwrite( STDERR, "JSON 解析失败: " . json_last_error_msg() . "\n" );
	exit( 1 );
}

$valid_types = array( 'language', 'vocational', 'university', 'graduate', 'junior' );
$valid_edu   = array( '', 'junior_high', 'high_school', 'bachelor' );

$errors   = array();
$warnings = array();

foreach ( $data as $i => $row ) {
	$where = "第 " . ( $i + 1 ) . " 条";

	if ( empty( $row['name'] ) ) {
		$errors[] = "{$where}: 缺少 name";
	}

	$label = ! empty( $row['name'] ) ? $row['name'] : $where;

	if ( empty( $row['school_type'] ) ) {
		$errors[] = "{$label}: 缺少 school_type";
	} elseif ( ! in_array( $row['school_type'], $valid_types, true ) ) {
		$errors[] = "{$label}: school_type 非法「{$row['school_type']}」，可选 " . implode( ' / ', $valid_types );
	}

	if ( isset( $row['min_education'] ) && ! in_array( $row['min_education'], $valid_edu, true ) ) {
		$errors[] = "{$label}: min_education 非法「{$row['min_education']}」，可选 " . implode( ' / ', array_filter( $valid_edu ) );
	}

	// 发布校验：对外公开的院校必须具备可展示的最小信息集。
	if ( ! empty( $row['published'] ) ) {
		if ( empty( $row['slug'] ) ) {
			$warnings[] = "{$label}: 标记为 published 但未指定 slug（将按英文名自动生成）";
		}
		if ( empty( $row['description_i18n'] ) ) {
			$warnings[] = "{$label}: 标记为 published 但没有 description_i18n —— 页面会缺少介绍正文，属于薄内容";
		}
		if ( empty( $row['city'] ) && empty( $row['region'] ) ) {
			$warnings[] = "{$label}: 标记为 published 但无所在地 —— 地域词是院校页的主要长尾来源";
		}
		if ( empty( $row['programs'] ) ) {
			$warnings[] = "{$label}: 标记为 published 但没有 programs —— 页面将没有专业与学费表";
		}
		// 页面正文明确写着「最新情报以学校官网为准」，那就必须给得出链接。
		if ( empty( $row['official_url'] ) ) {
			$errors[] = "{$label}: 标记为 published 但缺少 official_url —— "
				. '页面声明「以官方最新信息为准」，不给链接等于让用户自己去搜；'
				. '该字段同时是结构化数据里院校实体的 url';
		}
	}

	// 学费合理性：防止单位写错（例如把 78 万写成 780 而不是 780000）
	if ( ! empty( $row['programs'] ) && is_array( $row['programs'] ) ) {
		foreach ( $row['programs'] as $j => $p ) {
			$pn  = ! empty( $p['name'] ) ? $p['name'] : ( '专业#' . ( $j + 1 ) );
			$min = isset( $p['tuition_min'] ) ? (int) $p['tuition_min'] : 0;
			$max = isset( $p['tuition_max'] ) ? (int) $p['tuition_max'] : 0;

			if ( $min > 0 && $max > 0 && $min > $max ) {
				$errors[] = "{$label} / {$pn}: tuition_min 大于 tuition_max";
			}
			foreach ( array( 'tuition_min' => $min, 'tuition_max' => $max ) as $k => $v ) {
				if ( $v > 0 && $v < 10000 ) {
					$warnings[] = "{$label} / {$pn}: {$k} = {$v}，单位是日元而非万日元，看起来偏小，请确认";
				}
				if ( $v > 30000000 ) {
					$warnings[] = "{$label} / {$pn}: {$k} = {$v}，超过 3000 万日元，请确认";
				}
			}

			/*
			 * 学费口径必须显式且合法。
			 *
			 * 写错这个字段不会报错，只会让页面把「课程总额」显示成「年額」
			 * （或反之），金额可能差出一倍 —— 而页面上冠的是真实院校名。
			 * 因此这里按错误而非警告处理。
			 */
			$basis = isset( $p['tuition_basis'] ) ? $p['tuition_basis'] : 'year';
			if ( ! in_array( $basis, array( 'year', 'total' ), true ) ) {
				$errors[] = "{$label} / {$pn}: tuition_basis 非法「{$basis}」，只能是 year（年额）或 total（课程总额）";
			}
			if ( ( $min > 0 || $max > 0 ) && ! isset( $p['tuition_basis'] ) ) {
				$warnings[] = "{$label} / {$pn}: 填了学费却未写 tuition_basis，将按 year（年额）显示。"
					. '语言学校多按课程总额公布，请确认是否应为 total';
			}

			// 超过 1 年的课程标成年额、金额又明显偏高时，多半是把总额当年额填了。
			$dur = isset( $p['duration'] ) ? (string) $p['duration'] : '';
			if ( 'year' === $basis && $max >= 1200000
				&& preg_match( '/(2年|３年|3年|1年[6９9６]|1年9|21か月|24か月)/u', $dur ) ) {
				$warnings[] = "{$label} / {$pn}: 修业年限「{$dur}」超过 1 年，学费 {$max} 却标为年额。"
					. '请确认不是把课程总额填进了年额字段';
			}
		}
	}
}

echo "============================================================\n";
echo "  院校数据导入\n";
echo "  文件: {$json_file}\n";
echo "  条数: " . count( $data ) . "\n";
echo "  模式: " . ( $dry_run ? '校验（不写库）' : '实际写入' ) . "\n";
echo "============================================================\n\n";

if ( ! empty( $errors ) ) {
	echo "【错误】必须修正后才能导入：\n";
	foreach ( $errors as $e ) {
		echo "  ✗ {$e}\n";
	}
	echo "\n";
	exit( 1 );
}

if ( ! empty( $warnings ) ) {
	echo "【提醒】不阻断导入，但请确认：\n";
	foreach ( $warnings as $w ) {
		echo "  ! {$w}\n";
	}
	echo "\n";
}

// 统计将被公开的院校 —— 公开即意味着以真实院校名义对外展示，必须显眼提示。
$to_publish = array();
foreach ( $data as $row ) {
	if ( ! empty( $row['published'] ) ) {
		$to_publish[] = isset( $row['name'] ) ? $row['name'] : '?';
	}
}

if ( empty( $to_publish ) ) {
	echo "JSON 中的院校全部标记为「不公开」。新建的院校将返回 404，不会被搜索引擎收录。\n";
} else {
	echo "JSON 中以下 " . count( $to_publish ) . " 所院校标记为 published：\n";
	foreach ( $to_publish as $n ) {
		echo "  ● {$n}\n";
	}
}
if ( $force_published ) {
	echo "\n⚠ 已指定 --force-published：JSON 的 published 会覆盖线上状态，\n";
	echo "  包括把此前刻意下线的院校重新公开。请确认这是你要的结果。\n";
} else {
	echo "\n已有院校的发布状态以数据库为准，本次不会被 JSON 改动（新建的院校按 JSON 取值）。\n";
	echo "发布 / 下线请用： bash scripts/publish-school.sh <slug> on|off\n";
}
echo "\n";

if ( $dry_run ) {
	echo "校验通过。去掉 --dry-run 即可实际导入。\n";
	exit( 0 );
}

/* -------------------------------------------------------------------------
 * 导入
 * ---------------------------------------------------------------------- */

global $wpdb;
$schools_table  = SA_DB::table( 'schools' );
$programs_table = SA_DB::table( 'programs' );

$created = 0;
$updated = 0;
$prog_n  = 0;

foreach ( $data as $row ) {
	$slug = isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '';

	$fields = array(
		'name'             => isset( $row['name'] ) ? $row['name'] : '',
		'name_i18n'        => isset( $row['name_i18n'] ) ? $row['name_i18n'] : null,
		'description_i18n' => isset( $row['description_i18n'] ) ? $row['description_i18n'] : null,
		'school_type'      => isset( $row['school_type'] ) ? $row['school_type'] : '',
		'region'           => isset( $row['region'] ) ? $row['region'] : '',
		'city'             => isset( $row['city'] ) ? $row['city'] : '',
		// 官网地址。协议白名单清洗由 SA_School_Repo 统一处理。
		'official_url'     => isset( $row['official_url'] ) ? $row['official_url'] : '',
		'language_req'     => isset( $row['language_req'] ) ? $row['language_req'] : '',
		'min_education'    => isset( $row['min_education'] ) ? $row['min_education'] : '',
		'status'           => isset( $row['status'] ) ? $row['status'] : 'active',
		'published'        => ! empty( $row['published'] ) ? 1 : 0,
		'sort_order'       => isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0,
		'slug'             => $slug,
	);

	if ( isset( $row['required_docs'] ) ) {
		$fields['required_docs'] = $row['required_docs'];
	}

	// 幂等：按 slug 查已有院校。
	$existing_id = 0;
	if ( '' !== $slug ) {
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$schools_table} WHERE slug = %s LIMIT 1", $slug )
		);
	}

	if ( $existing_id > 0 ) {
		/*
		 * 更新已有院校时，默认不动 published。
		 *
		 * 发布与否是运营状态，不是院校资料的一部分：某校核实后被下线，
		 * 下一次为了补一句简介而重跑导入，不应该把它又悄悄发布出去。
		 * 因此让数据库里的开关保持权威，JSON 里的 published 只作为
		 * 新建时的初始值（默认 false）。
		 *
		 * 确实要用 JSON 覆盖线上状态时，显式加 --force-published。
		 * 日常的发布 / 下线请用：bash scripts/publish-school.sh <slug> on|off
		 */
		$cur_pub = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT published FROM {$schools_table} WHERE id = %d", $existing_id )
		);
		if ( ! $force_published ) {
			unset( $fields['published'] );
		}

		SA_School_Repo::update_school( $existing_id, $fields );
		$school_id = $existing_id;
		++$updated;

		$pub_note = '';
		if ( ! $force_published && (int) ( ! empty( $row['published'] ) ) !== $cur_pub ) {
			$pub_note = sprintf(
				'（JSON 写的是 published=%s，但保留线上的 %s —— 如需覆盖请加 --force-published）',
				! empty( $row['published'] ) ? 'true' : 'false',
				$cur_pub ? '已发布' : '未发布'
			);
		}
		printf( "  [更新] #%d %s %s\n", $school_id, $fields['name'], $pub_note );
	} else {
		$school_id = SA_School_Repo::create_school( $fields );
		if ( ! $school_id ) {
			fwrite( STDERR, "  [失败] {$fields['name']} 创建失败\n" );
			continue;
		}
		++$created;
		printf( "  [新建] #%d %s\n", $school_id, $fields['name'] );
	}

	// 专业：整体替换，避免反复导入产生重复行。
	if ( isset( $row['programs'] ) && is_array( $row['programs'] ) ) {
		$wpdb->delete( $programs_table, array( 'school_id' => $school_id ), array( '%d' ) );

		foreach ( $row['programs'] as $p ) {
			$ok = SA_School_Repo::create_program(
				array(
					'school_id'    => $school_id,
					'name'         => isset( $p['name'] ) ? $p['name'] : '',
					'name_i18n'    => isset( $p['name_i18n'] ) ? $p['name_i18n'] : null,
					'major_tags'   => isset( $p['major_tags'] ) ? $p['major_tags'] : null,
					'tuition_min'   => isset( $p['tuition_min'] ) ? (int) $p['tuition_min'] : 0,
					'tuition_max'   => isset( $p['tuition_max'] ) ? (int) $p['tuition_max'] : 0,
					// year=年额 / total=课程总额。决定前台显示「年間」还是「総額」。
					'tuition_basis' => isset( $p['tuition_basis'] ) ? $p['tuition_basis'] : 'year',
					'tuition_note'  => isset( $p['tuition_note'] ) ? $p['tuition_note'] : '',
					'language_req'  => isset( $p['language_req'] ) ? $p['language_req'] : '',
					'duration'      => isset( $p['duration'] ) ? $p['duration'] : '',
					'status'        => 'active',
				)
			);
			if ( $ok ) {
				++$prog_n;
			}
		}
		printf( "         专业 %d 条\n", count( $row['programs'] ) );
	}
}

/* -------------------------------------------------------------------------
 * 收尾
 * ---------------------------------------------------------------------- */

wp_cache_flush();
flush_rewrite_rules();

echo "\n";
echo "============================================================\n";
printf( "  新建 %d 所，更新 %d 所，专业 %d 条\n", $created, $updated, $prog_n );
echo "============================================================\n\n";

// 列出实际生成的公开页地址，便于逐条打开核对。
$published = SA_School_Repo::get_published_schools();

if ( empty( $published ) ) {
	echo "当前没有已公开的院校，/schools/ 会显示空列表。\n\n";
} else {
	echo "已公开的院校页（请逐个打开核对内容准确性）：\n";
	foreach ( $published as $s ) {
		echo '  ' . sa_school_url( $s['slug'] ) . "\n";
		echo '  ' . sa_school_url( $s['slug'], 'zh_CN' ) . "\n";
		echo '  ' . sa_school_url( $s['slug'], 'en_US' ) . "\n";
	}
	echo "\n";
}

$home = home_url( '/' );

echo "------------------------------------------------------------\n";
echo "SEO 检查链接\n";
echo "------------------------------------------------------------\n";
echo "  院校列表      " . sa_schools_url() . "\n";
echo "  sitemap       " . $home . "wp-sitemap.xml\n";
echo "  院校 sitemap  " . $home . "wp-sitemap-schools-ja-1.xml\n";
echo "\n";
echo "  GSC 索引报告  https://search.google.com/search-console/index?resource_id="
	. rawurlencode( $home ) . "\n";
echo "  GSC 站点地图  https://search.google.com/search-console/sitemaps?resource_id="
	. rawurlencode( $home ) . "\n";
echo "  网址检查      https://search.google.com/search-console/inspect?resource_id="
	. rawurlencode( $home ) . "&id=" . rawurlencode( sa_schools_url() ) . "\n";
echo "\n";
echo "  完整检查清单  bash scripts/seo-links.sh " . rtrim( $home, '/' ) . "\n";
echo "  全量排查      bash scripts/seo-audit.sh " . rtrim( $home, '/' ) . "\n";
echo "\n";
echo "提示：新增院校页后，去 GSC 重新提交 sitemap，并对院校列表页\n";
echo "      执行「请求编入索引」，可加快发现速度。\n\n";
