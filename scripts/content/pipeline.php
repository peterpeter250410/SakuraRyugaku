<?php
/**
 * 文章生成流水线。
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 这个脚本不要在生产服务器上运行。                                 │
 * │                                                                  │
 * │ 它会调用 Anthropic API，因此需要把密钥放到服务器上。按本项目的   │
 * │ 约定，生产机不存任何模型服务的密钥、也不发起这类调用。           │
 * │                                                                  │
 * │ 生产服务器上只跑这三个，全部零 API 调用：                        │
 * │     check-article.php        校验（抓取来源、核对数字、查重）    │
 * │     wp/publish-article.php   发布                                │
 * │     wp/dump-articles.php     导出查重语料                        │
 * │                                                                  │
 * │ 稿件在别处写好（开发机，或 Claude Code 会话里），以 article.json │
 * │ 的形式交付，服务器只负责校验与发布。                             │
 * │                                                                  │
 * │ 保留本脚本是因为在开发机上它仍然有用 —— 要批量跑的时候，         │
 * │ 一条命令比逐篇手写省事。但那是开发机，不是生产机。               │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 用法：
 *   php scripts/content/pipeline.php list
 *   php scripts/content/pipeline.php run <keyword-id> [--dry-run]
 *   php scripts/content/pipeline.php run-all --limit=3
 *   php scripts/content/pipeline.php status
 *   php scripts/content/pipeline.php reset <keyword-id>
 *
 * 环境变量：
 *   ANTHROPIC_API_KEY  必需
 *   SA_LLM_MODEL       可选，默认 claude-opus-5
 *   SA_WP_PATH         WordPress 根目录，默认 /www/wwwroot/studyinjp.com
 *   SA_MAX_PER_DAY     每日发布上限，默认 2
 *
 * ── 阶段 ────────────────────────────────────────────────────────
 *
 *   outline  → draft → humanize → gates → review → publish
 *
 * 每个阶段的产物都落在 state/<keyword-id>.json，中断后重跑会从上次停下的
 * 阶段继续，不会重复烧 token。改了 prompt 想重跑，用 reset。
 *
 * ── 四道闸门 ────────────────────────────────────────────────────
 *
 *   1. 来源闸门   每个 URL 实际抓取；每个数字在来源页上逐个核对
 *   2. 重复度     与站内已发布文章的 Jaccard 相似度
 *   3. 文风       AI 味评分，超标则回到 humanize 再跑一轮
 *   4. 节奏       每日发布数上限
 *
 * 闸门 1、2、4 是客观的，不通过就是不通过。闸门 3 触发的是重写而非拒绝。
 * 模型评审（review）不能单独放行一篇稿子 —— 它只能否决。理由见 prompts/review.md。
 *
 * @package StudyAbroadContent
 */

define( 'SA_CONTENT_DIR', __DIR__ );

require_once SA_CONTENT_DIR . '/lib/class-llm.php';
require_once SA_CONTENT_DIR . '/lib/class-ai-tell.php';
require_once SA_CONTENT_DIR . '/lib/class-source-gate.php';
require_once SA_CONTENT_DIR . '/lib/class-dedup.php';

/** 文风分数超过这个值就重写。 */
const SA_AI_TELL_MAX = 35;

/** 与站内已有文章的相似度上限。 */
const SA_DEDUP_MAX = 0.28;

/** humanize 最多重跑几轮。超过还不达标就交人工，不无限烧 token。 */
const SA_HUMANIZE_ROUNDS = 2;

/* =========================================================================
 * 入口
 * ====================================================================== */

$argv0 = array_shift( $argv );
$cmd   = array_shift( $argv );

$flags = array();
$args  = array();
foreach ( (array) $argv as $a ) {
	if ( 0 === strpos( $a, '--' ) ) {
		$kv = explode( '=', substr( $a, 2 ), 2 );
		$flags[ $kv[0] ] = isset( $kv[1] ) ? $kv[1] : true;
	} else {
		$args[] = $a;
	}
}

try {
	switch ( $cmd ) {
		case 'list':
			cmd_list();
			break;
		case 'status':
			cmd_status();
			break;
		case 'run':
			if ( empty( $args[0] ) ) {
				fail( '用法：pipeline.php run <keyword-id>' );
			}
			cmd_run( $args[0], ! empty( $flags['dry-run'] ) );
			break;
		case 'run-all':
			cmd_run_all( isset( $flags['limit'] ) ? (int) $flags['limit'] : 1, ! empty( $flags['dry-run'] ) );
			break;
		case 'reset':
			if ( empty( $args[0] ) ) {
				fail( '用法：pipeline.php reset <keyword-id>' );
			}
			cmd_reset( $args[0] );
			break;
		default:
			echo usage();
			exit( 1 );
	}
} catch ( Exception $e ) {
	fail( $e->getMessage() );
}

/* =========================================================================
 * 命令
 * ====================================================================== */

/**
 * 用法说明。
 *
 * @return string
 */
function usage() {
	return <<<TXT
文章生成流水线

  list                      列出词库中的选题及其状态
  status                    汇总各阶段的数量与今日发布数
  run <keyword-id>          跑单个选题（可加 --dry-run 只跑到闸门不发布）
  run-all --limit=N         按优先级依次跑 N 个
  reset <keyword-id>        清掉该选题的状态，下次从头跑

环境变量：ANTHROPIC_API_KEY（必需）、SA_LLM_MODEL、SA_WP_PATH、SA_MAX_PER_DAY

TXT;
}

/**
 * 列出选题。
 */
function cmd_list() {
	$kws = load_keywords();
	printf( "%-34s %-10s %-9s %-11s %s\n", 'KEYWORD ID', 'CLUSTER', 'PRIORITY', 'EVIDENCE', 'STATE' );
	echo str_repeat( '-', 100 ), "\n";
	foreach ( $kws as $k ) {
		$st = load_state( $k['id'] );
		printf(
			"%-34s %-10s %-9s %-11s %s\n",
			$k['id'],
			substr( $k['cluster'], 0, 10 ),
			'P' . $k['priority'],
			$k['evidence'],
			isset( $st['stage'] ) ? $st['stage'] : '-'
		);
	}
}

/**
 * 汇总状态。
 */
function cmd_status() {
	$kws    = load_keywords();
	$counts = array();
	foreach ( $kws as $k ) {
		$st = load_state( $k['id'] );
		$s  = isset( $st['stage'] ) ? $st['stage'] : 'pending';
		$counts[ $s ] = isset( $counts[ $s ] ) ? $counts[ $s ] + 1 : 1;
	}
	echo "选题总数：", count( $kws ), "\n";
	foreach ( $counts as $s => $n ) {
		printf( "  %-12s %d\n", $s, $n );
	}
	printf( "\n今日已发布：%d / %d\n", published_today(), max_per_day() );
}

/**
 * 清状态。
 *
 * @param string $id keyword id。
 */
function cmd_reset( $id ) {
	$f = state_file( $id );
	if ( file_exists( $f ) ) {
		unlink( $f );
		echo "已清除 {$id} 的状态。\n";
	} else {
		echo "{$id} 本来就没有状态。\n";
	}
}

/**
 * 批量跑。
 *
 * @param int  $limit   跑几个。
 * @param bool $dry_run 只跑到闸门不发布。
 */
function cmd_run_all( $limit, $dry_run ) {
	$kws = load_keywords();

	// 按优先级排，同优先级下 GSC 实证的排前面 —— 有真实数据支撑的先做。
	usort(
		$kws,
		function ( $a, $b ) {
			if ( $a['priority'] !== $b['priority'] ) {
				return $a['priority'] - $b['priority'];
			}
			$rank = array( 'gsc' => 0, 'competitor' => 1, 'serp' => 2, 'inferred' => 3 );
			$ra = isset( $rank[ $a['evidence'] ] ) ? $rank[ $a['evidence'] ] : 9;
			$rb = isset( $rank[ $b['evidence'] ] ) ? $rank[ $b['evidence'] ] : 9;
			return $ra - $rb;
		}
	);

	$done = 0;
	foreach ( $kws as $k ) {
		if ( $done >= $limit ) {
			break;
		}
		$st = load_state( $k['id'] );
		if ( isset( $st['stage'] ) && in_array( $st['stage'], array( 'published', 'rejected' ), true ) ) {
			continue;
		}
		echo "\n", str_repeat( '=', 70 ), "\n";
		cmd_run( $k['id'], $dry_run );
		$done++;
	}

	if ( 0 === $done ) {
		echo "没有待处理的选题。\n";
	}
}

/**
 * 跑单个选题。
 *
 * @param string $id      keyword id。
 * @param bool   $dry_run 只跑到闸门不发布。
 */
function cmd_run( $id, $dry_run ) {
	$kw = find_keyword( $id );
	if ( ! $kw ) {
		fail( "词库里找不到 keyword id：{$id}" );
	}

	$state = load_state( $id );

	say( "选题：{$kw['title']}" );
	say( "  簇 {$kw['cluster']} / P{$kw['priority']} / 证据档位 {$kw['evidence']}" );

	// LLM 客户端在打印选题之后才构造：缺 API key 时，先让人看见卡在哪一篇。
	$llm = new SA_LLM();

	/* ---- 阶段 1：大纲 ------------------------------------------------ */
	if ( empty( $state['outline'] ) ) {
		say( '【1/6】调研与大纲…' );
		$state['outline'] = $llm->complete_json(
			read_prompt( 'outline' ),
			wp_json_encode_compat(
				array(
					'title'             => $kw['title'],
					'locale'            => $kw['locale'],
					'allowed_domains'   => $kw['sources_required'],
					'cluster_rationale' => $kw['rationale'],
					'internal_links'    => $kw['internal_links'],
				)
			)
		);
		$state['stage'] = 'outlined';
		save_state( $id, $state );
		say( '      ' . count( $state['outline']['sections'] ) . ' 个小节' );
		if ( ! empty( $state['outline']['excluded'] ) ) {
			say( '      主动放弃（查不到出处）：' . count( $state['outline']['excluded'] ) . ' 点' );
		}
	}

	/* ---- 阶段 2：初稿 ------------------------------------------------ */
	if ( empty( $state['draft'] ) ) {
		say( '【2/6】撰写初稿…' );
		$state['draft'] = $llm->complete_json(
			read_prompt( 'draft' ),
			wp_json_encode_compat(
				array(
					'title'           => $kw['title'],
					'locale'          => $kw['locale'],
					'allowed_domains' => $kw['sources_required'],
					'outline'         => $state['outline'],
					'today'           => date( 'Y-m-d' ),
				)
			)
		);
		$state['stage'] = 'drafted';
		save_state( $id, $state );
		say( '      ' . strlen( $state['draft']['body_html'] ) . ' 字节 / '
			. count( $state['draft']['sources'] ) . ' 条来源' );
	}

	$current = $state['draft'];

	/* ---- 阶段 3：文风修订（闸门 3 驱动）------------------------------ */
	$lang = ( 0 === strpos( $kw['locale'], 'en' ) ) ? 'en' : 'cjk';

	for ( $round = 0; $round <= SA_HUMANIZE_ROUNDS; $round++ ) {
		$tell = SA_AI_Tell::analyze( $current['body_html'], $lang );
		say( sprintf( '【3/6】文风评分 %d（%s）阈值 %d', $tell['score'], $tell['verdict'], SA_AI_TELL_MAX ) );

		foreach ( $tell['metrics'] as $name => $m ) {
			if ( $m['points'] > 0 ) {
				say( sprintf( '      %-20s %2d 分  %s', $name, $m['points'], $m['note'] ) );
			}
		}

		if ( $tell['score'] <= SA_AI_TELL_MAX ) {
			break;
		}
		if ( $round >= SA_HUMANIZE_ROUNDS ) {
			say( '      重写 ' . SA_HUMANIZE_ROUNDS . ' 轮后仍超标，交人工处理。' );
			$state['stage']  = 'needs_human';
			$state['reason'] = "文风评分 {$tell['score']} 持续超标";
			save_state( $id, $state );
			return;
		}

		say( '      超标，改写第 ' . ( $round + 1 ) . ' 轮…' );
		$rev = $llm->complete_json(
			read_prompt( 'humanize' ),
			wp_json_encode_compat(
				array(
					'body_html' => $current['body_html'],
					'summary'   => $current['summary'],
					'sources'   => $current['sources'],
					'report'    => $tell,
				)
			)
		);

		$current['body_html'] = $rev['body_html'];
		$current['summary']   = isset( $rev['summary'] ) ? $rev['summary'] : $current['summary'];

		$state['humanized'] = $current;
		$state['stage']     = 'humanized';
		save_state( $id, $state );
	}

	$state['final'] = $current;
	$state['tell']  = SA_AI_Tell::analyze( $current['body_html'], $lang );

	/* ---- 闸门 1：来源 ------------------------------------------------ */
	say( '【4/6】来源闸门（逐个抓取并核对数字）…' );
	$gate   = new SA_Source_Gate();
	$result = $gate->check( $current['body_html'], $current['sources'], $kw['sources_required'] );

	say( "      抓取 {$result['checked']} 个来源" );
	foreach ( $result['warnings'] as $w ) {
		say( '      [警告] ' . $w );
	}

	if ( ! $result['pass'] ) {
		foreach ( $result['errors'] as $e ) {
			say( '      [不通过] ' . $e );
		}
		$state['stage']  = 'rejected';
		$state['reason'] = '来源闸门：' . implode( ' / ', $result['errors'] );
		save_state( $id, $state );
		say( '  → 拒绝发布。' );
		return;
	}
	say( '      通过' );

	/* ---- 闸门 2：重复度 ---------------------------------------------- */
	say( '【5/6】重复度闸门…' );
	$corpus = existing_articles( $kw['locale'] );
	if ( empty( $corpus ) ) {
		say( '      站内还没有同语种文章，跳过' );
	} else {
		$dd = SA_Dedup::compare( $current['body_html'], $corpus, $lang );
		say( sprintf( '      与 %d 篇比对，最高相似度 %.3f（%s）阈值 %.2f',
			count( $corpus ), $dd['max'], $dd['worst'], SA_DEDUP_MAX ) );
		if ( $dd['max'] > SA_DEDUP_MAX ) {
			$state['stage']  = 'rejected';
			$state['reason'] = "与 {$dd['worst']} 相似度 {$dd['max']}，超过阈值";
			save_state( $id, $state );
			say( '  → 拒绝发布：内容与已有文章过于接近。' );
			return;
		}
	}

	/* ---- 阶段 6：模型评审 -------------------------------------------- */
	say( '【6/6】质量评审…' );
	$review = $llm->complete_json(
		read_prompt( 'review' ),
		wp_json_encode_compat(
			array(
				'title'     => $kw['title'],
				'summary'   => $current['summary'],
				'body_html' => $current['body_html'],
				'sources'   => $current['sources'],
			)
		)
	);

	$state['review'] = $review;
	say( sprintf( '      总分 %s → %s', $review['total'], $review['verdict'] ) );
	foreach ( (array) $review['scores'] as $k2 => $v ) {
		say( sprintf( '      %-18s %s', $k2, $v ) );
	}
	foreach ( (array) $review['blocking_issues'] as $b ) {
		say( '      [阻断] ' . $b );
	}

	if ( 'publish' !== $review['verdict'] ) {
		$state['stage']  = 'needs_human';
		$state['reason'] = '评审判定：' . $review['verdict'];
		save_state( $id, $state );
		say( '  → 未达发布标准，交人工。' );
		return;
	}

	/* ---- 闸门 4：节奏 ------------------------------------------------ */
	$today = published_today();
	if ( $today >= max_per_day() ) {
		say( sprintf( '  → 今日已发 %d 篇，达到上限 %d。稿件留在 ready 状态，明天再跑即可发布。',
			$today, max_per_day() ) );
		$state['stage'] = 'ready';
		save_state( $id, $state );
		return;
	}

	if ( $dry_run ) {
		$state['stage'] = 'ready';
		save_state( $id, $state );
		say( '  → dry-run：全部闸门通过，未发布。稿件在 ' . state_file( $id ) );
		return;
	}

	/* ---- 发布 -------------------------------------------------------- */
	say( '发布中…' );
	$url = publish( $id, $kw, $current );
	$state['stage']        = 'published';
	$state['published_at'] = gmdate( 'c' );
	$state['url']          = $url;
	save_state( $id, $state );
	say( '  → 已发布：' . $url );

	$u = $llm->usage_total();
	say( sprintf( '本次用量：%d 次调用 / 输入 %s tok / 输出 %s tok',
		$u['calls'], number_format( $u['input'] ), number_format( $u['output'] ) ) );
}

/* =========================================================================
 * 词库 / 状态
 * ====================================================================== */

/**
 * 读词库，拍平成一维。
 *
 * @return array<int,array<string,mixed>>
 */
function load_keywords() {
	static $flat = null;
	if ( null !== $flat ) {
		return $flat;
	}

	$dir  = SA_CONTENT_DIR . '/keywords';
	$flat = array();

	foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) || empty( $data['clusters'] ) ) {
			continue;
		}

		$locale = isset( $data['meta']['locale'] ) ? $data['meta']['locale'] : 'en';
		// 词库文件用简写（en），WordPress 那边用 en_US，在这里对齐一次。
		$map    = array( 'en' => 'en_US', 'zh' => 'zh_CN', 'ja' => 'ja' );
		$locale = isset( $map[ $locale ] ) ? $map[ $locale ] : $locale;

		foreach ( $data['clusters'] as $cluster ) {
			$c_domains = isset( $cluster['sources_required'] ) ? (array) $cluster['sources_required'] : array();
			$c_reason  = isset( $cluster['rationale'] ) ? implode( ' ', (array) $cluster['rationale'] ) : '';

			foreach ( (array) $cluster['keywords'] as $k ) {
				// 条目级 sources_required 覆盖簇级；两者都空表示不限域。
				$domains = isset( $k['sources_required'] ) ? (array) $k['sources_required'] : $c_domains;

				// official_school_site 是占位符，不是真域名 —— 去掉它，
				// 否则会被当成域名去比对，把所有学校官网都判为不合规。
				$domains = array_values( array_filter( $domains, function ( $d ) {
					return 'official_school_site' !== $d;
				} ) );

				$flat[] = array(
					'id'               => $k['id'],
					'title'            => $k['title'],
					'slug'             => isset( $k['slug'] ) ? $k['slug'] : $k['id'],
					'cluster'          => $cluster['id'],
					'priority'         => isset( $k['priority'] ) ? (int) $k['priority'] : 3,
					'evidence'         => isset( $k['evidence'] ) ? $k['evidence'] : 'inferred',
					'locale'           => $locale,
					'rationale'        => $c_reason . ' ' . ( isset( $k['evidence_note'] ) ? $k['evidence_note'] : '' ),
					'internal_links'   => isset( $k['internal_links'] ) ? (array) $k['internal_links'] : array(),
					'sources_required' => $domains,
				);
			}
		}
	}

	return $flat;
}

/**
 * 按 id 找选题。
 *
 * @param string $id keyword id。
 * @return array<string,mixed>|null
 */
function find_keyword( $id ) {
	foreach ( load_keywords() as $k ) {
		if ( $k['id'] === $id ) {
			return $k;
		}
	}
	return null;
}

/**
 * 状态文件路径。
 *
 * @param string $id keyword id。
 * @return string
 */
function state_file( $id ) {
	$dir = SA_CONTENT_DIR . '/state';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	return $dir . '/' . preg_replace( '/[^a-z0-9_-]/i', '_', $id ) . '.json';
}

/**
 * 读状态。
 *
 * @param string $id keyword id。
 * @return array<string,mixed>
 */
function load_state( $id ) {
	$f = state_file( $id );
	if ( ! file_exists( $f ) ) {
		return array();
	}
	$d = json_decode( (string) file_get_contents( $f ), true );
	return is_array( $d ) ? $d : array();
}

/**
 * 写状态。
 *
 * @param string              $id    keyword id。
 * @param array<string,mixed> $state 状态。
 */
function save_state( $id, array $state ) {
	$state['updated_at'] = gmdate( 'c' );
	file_put_contents(
		state_file( $id ),
		json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
	);
}

/**
 * 读 prompt 文件。
 *
 * @param string $name 不带扩展名。
 * @return string
 *
 * @throws RuntimeException 文件不存在时。
 */
function read_prompt( $name ) {
	$f = SA_CONTENT_DIR . '/prompts/' . $name . '.md';
	if ( ! file_exists( $f ) ) {
		throw new RuntimeException( "找不到 prompt：{$f}" );
	}
	return (string) file_get_contents( $f );
}

/* =========================================================================
 * WordPress 侧
 * ====================================================================== */

/**
 * WordPress 根目录。
 *
 * @return string
 */
function wp_path() {
	$p = getenv( 'SA_WP_PATH' );
	return ( is_string( $p ) && '' !== $p ) ? rtrim( $p, '/' ) : '/www/wwwroot/studyinjp.com';
}

/**
 * 每日发布上限。
 *
 * 默认 2。这个数字不是随便定的：批量灌稿是 Google 明确打击的模式，
 * 而且我们自己也需要时间观察前几篇的收录与排名表现再决定要不要加量。
 *
 * @return int
 */
function max_per_day() {
	$n = (int) getenv( 'SA_MAX_PER_DAY' );
	return $n > 0 ? $n : 2;
}

/**
 * 跑一段 WP-CLI eval-file，返回 stdout。
 *
 * @param string            $script 脚本路径。
 * @param array<int,string> $args   传给脚本的参数。
 * @return string
 *
 * @throws RuntimeException 执行失败时。
 */
function wp_eval( $script, array $args = array() ) {
	$cmd = 'wp eval-file ' . escapeshellarg( $script );
	foreach ( $args as $a ) {
		$cmd .= ' ' . escapeshellarg( $a );
	}
	$cmd .= ' --path=' . escapeshellarg( wp_path() );

	/*
	 * root 下必须加 --allow-root，否则 WP-CLI 直接拒绝执行。
	 *
	 * 仓库里其它脚本早有这个约定（见 scripts/publish-school.sh 的
	 * `[ "$(id -u)" = "0" ] && WP="${WP} --allow-root"`），这里当初漏了，
	 * 结果是前面所有阶段跑完、烧掉 token，最后倒在发布这一步。
	 *
	 * 不无条件加：非 root 时带上它虽然也能跑，但会掩盖「本来就不该用 root」
	 * 这件事 —— 站点文件的属主是谁，wp-cli 就该用谁的身份跑。
	 */
	if ( sa_is_root() ) {
		$cmd .= ' --allow-root';
	}

	$cmd .= ' 2>&1';

	$out  = array();
	$code = 0;
	exec( $cmd, $out, $code );

	$text = implode( "\n", $out );
	if ( 0 !== $code ) {
		throw new RuntimeException( "WP-CLI 失败（exit {$code}）：\n{$text}" );
	}
	return $text;
}

/**
 * 站内已发布的同语种文章（用于重复度比对）。
 *
 * @param string $locale 语种键。
 * @return array<string,string> slug => 正文。
 */
function existing_articles( $locale ) {
	try {
		$json = wp_eval( SA_CONTENT_DIR . '/wp/dump-articles.php', array( $locale ) );
	} catch ( Exception $e ) {
		say( '      [警告] 取站内文章失败，跳过重复度检查：' . $e->getMessage() );
		return array();
	}

	// WP-CLI 可能在 JSON 前后夹带警告行，只取第一个 { 到最后一个 }。
	$s = substr( $json, (int) strpos( $json, '{' ) );
	$s = substr( $s, 0, (int) strrpos( $s, '}' ) + 1 );

	$d = json_decode( $s, true );
	return is_array( $d ) ? $d : array();
}

/**
 * 今日已发布篇数。
 *
 * @return int
 */
function published_today() {
	$n = 0;
	foreach ( (array) glob( SA_CONTENT_DIR . '/state/*.json' ) as $f ) {
		$d = json_decode( (string) file_get_contents( $f ), true );
		if ( ! is_array( $d ) || empty( $d['published_at'] ) ) {
			continue;
		}
		if ( substr( $d['published_at'], 0, 10 ) === gmdate( 'Y-m-d' ) ) {
			$n++;
		}
	}
	return $n;
}

/**
 * 发布到 WordPress。
 *
 * @param string              $id      keyword id。
 * @param array<string,mixed> $kw      选题。
 * @param array<string,mixed> $article 稿件。
 * @return string 文章 URL。
 *
 * @throws RuntimeException 发布失败时。
 */
function publish( $id, array $kw, array $article ) {
	$payload = array(
		'title'      => $kw['title'],
		'slug'       => $kw['slug'],
		'locale'     => $kw['locale'],
		'group'      => $id,
		'summary'    => $article['summary'],
		'body_html'  => $article['body_html'],
		'sources'    => $article['sources'],
		'keyword_id' => $id,
	);

	$tmp = sys_get_temp_dir() . '/sa-article-' . $id . '.json';
	file_put_contents( $tmp, wp_json_encode_compat( $payload ) );

	$out = wp_eval( SA_CONTENT_DIR . '/wp/publish-article.php', array( $tmp ) );
	unlink( $tmp );

	if ( ! preg_match( '#(https?://\S+)#', $out, $m ) ) {
		throw new RuntimeException( "发布脚本没有返回 URL：\n{$out}" );
	}
	return $m[1];
}

/* =========================================================================
 * 杂项
 * ====================================================================== */

/**
 * 当前是否以 root 运行。
 *
 * posix 扩展不是必装的（很多面板环境把它禁了），所以留一条 `id -u` 的退路。
 * 两条都拿不到时返回 false —— 宁可让 WP-CLI 自己报那句明确的 root 警告，
 * 也不要凭猜测替用户加上 --allow-root。
 *
 * @return bool
 */
function sa_is_root() {
	if ( function_exists( 'posix_geteuid' ) ) {
		return 0 === posix_geteuid();
	}
	$uid = trim( (string) @shell_exec( 'id -u' ) );
	return '0' === $uid;
}

/**
 * 输出一行。
 *
 * @param string $msg 消息。
 */
function say( $msg ) {
	echo $msg, "\n";
}

/**
 * 报错退出。
 *
 * @param string $msg 消息。
 */
function fail( $msg ) {
	fwrite( STDERR, "错误：{$msg}\n" );
	exit( 1 );
}
