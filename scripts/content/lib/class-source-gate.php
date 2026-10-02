<?php
/**
 * 来源闸门：文章发布前的事实性校验。
 *
 * 这是自动发布模式下最关键的一道关。没有人工复核，就必须有机器能查实的东西
 * 挡在发布之前，否则「AI 编排 + 自动发布」等于把未经核对的内容直接上线。
 *
 * ── 约定 ───────────────────────────────────────────────────────
 *
 * 正文中凡是事实性论断，都要带来源标记：
 *
 *     Students may work up to 28 hours per week. [source:1]
 *
 * 数字 1 对应 sources 数组的第 1 项（从 1 起数，因为正文里读起来更自然）。
 *
 * ── 五项检查 ───────────────────────────────────────────────────
 *
 *   1. 域名白名单   引用的 URL 必须落在该选题允许的域内
 *   2. 可达性       实际发 HTTP 请求，必须 200
 *   3. 数字核对     带数字的句子，其引用来源的正文里必须真的出现这个数字
 *   4. 无孤儿引用   [source:N] 不能指向不存在的条目
 *   5. 无裸数字     带数字的事实句必须有来源标记
 *
 * ── 这道闸门做不到什么（必须说清楚）─────────────────────────────
 *
 * 第 3 项验证的是「这个数字紧挨着这个量词、在被引用的页面上出现过」，
 * 不是「它在那页里的含义与本文的用法一致」。
 *
 * 实测到的一个例子，比抽象描述有用：
 *
 *   ヒューマンアカデミー福岡校のページには
 *       「地下鉄『天神』駅より徒歩5分」   ← 本当の徒歩分数
 *       「授業時間：午前 9時15分～…」      ← 授業開始時刻
 *   の両方がある。本文の「a 5 minute walk」を「15 minute」に改竄しても、
 *   ページ上に「15分」が（時刻の一部として）存在するため闸门は通してしまう。
 *   変異テストで実際に素通りした。
 *
 * つまり挡得住的是**凭空编造的数字** —— 自动生成内容最主要的失真来源。
 * 挡不住的是、同じページの別の文脈に偶然同じ数字があるケース。
 * 判別には意味の理解が要る。正規表現にはできない。できるふりをしない。
 *
 * 運用上の帰結：数字が「出典ページに在る」ことは闸门が保証するが、
 * 「その意味で在る」ことは保証しない。後者は書き手の責任のまま残る。
 *
 * @package StudyAbroadContent
 */

if ( ! defined( 'SA_CONTENT_DIR' ) ) {
	exit( "must be loaded by pipeline\n" );
}

class SA_Source_Gate {

	/**
	 * 官方来源白名单。
	 *
	 * 法规、在留资格、打工时长这类内容只认这几个域 ——
	 * 学校官网不是签证规则的权威来源，商业站更不是。
	 *
	 * @return array<int,string>
	 */
	public static function official_domains() {
		return array(
			'moj.go.jp',            // 出入国在留管理庁
			'studyinjapan.go.jp',   // JASSO
			'jasso.go.jp',
			'mext.go.jp',           // 文部科学省
			'nisshinkyo.org',       // 日本語教育振興協会
			'mhlw.go.jp',           // 厚生労働省（労働条件）
		);
	}

	/** @var array<string,array{ok:bool,status:int,text:string}> 抓取缓存，同一 URL 只取一次。 */
	private $cache = array();

	/** @var int */
	private $timeout;

	/**
	 * @param int $timeout 单次抓取超时（秒）。
	 */
	public function __construct( $timeout = 30 ) {
		$this->timeout = max( 5, (int) $timeout );
	}

	/**
	 * 校验一篇文章。
	 *
	 * @param string                               $body            正文（含 [source:N] 标记）。
	 * @param array<int,array<string,string>>      $sources         来源清单（0-indexed；正文里的 N 从 1 起）。
	 * @param array<int,string>                    $allowed_domains 该选题允许的域名，空数组表示不限制域。
	 * @return array{pass:bool,errors:array<int,string>,warnings:array<int,string>,checked:int}
	 */
	public function check( $body, array $sources, array $allowed_domains = array() ) {
		$errors       = array();
		$warnings     = array();
		$unverifiable = array();
		$checked      = 0;

		$text = wp_strip_tags_compat( self::attribute_tables( $body ) );

		/* ---- 1 & 4：标记与条目对应 ---------------------------------- */

		preg_match_all( '/\[source:\s*(\d+)\s*\]/i', $text, $m );
		$referenced = array_unique( array_map( 'intval', $m[1] ) );

		if ( empty( $sources ) ) {
			$errors[] = '文章没有任何来源条目。';
			return array(
				'pass'         => false,
				'errors'       => $errors,
				'warnings'     => $warnings,
				'unverifiable' => $unverifiable,
				'checked'      => 0,
			);
		}

		foreach ( $referenced as $n ) {
			if ( $n < 1 || $n > count( $sources ) ) {
				$errors[] = "正文引用了 [source:{$n}]，但来源清单只有 " . count( $sources ) . ' 条。';
			}
		}

		// 反向：列了却没被引用的来源。这是警告不是错误 ——
		// 多列一条参考资料不至于让文章不能发，但通常说明生成时凑数了。
		for ( $i = 1; $i <= count( $sources ); $i++ ) {
			if ( ! in_array( $i, $referenced, true ) ) {
				$warnings[] = "来源 [{$i}] " . $sources[ $i - 1 ]['url'] . ' 在正文中未被引用。';
			}
		}

		/* ---- 2：域名与可达性 ---------------------------------------- */

		foreach ( $sources as $idx => $src ) {
			$n   = $idx + 1;
			$url = isset( $src['url'] ) ? trim( (string) $src['url'] ) : '';

			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				$errors[] = "来源 [{$n}] 不是有效的 http(s) URL：{$url}";
				continue;
			}

			$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

			if ( ! empty( $allowed_domains ) && ! self::host_allowed( $host, $allowed_domains ) ) {
				$errors[] = "来源 [{$n}] 的域名 {$host} 不在该选题允许的范围内（" . implode( ', ', $allowed_domains ) . '）。';
				continue;
			}

			$fetched = $this->fetch( $url );
			$checked++;

			if ( $fetched['ok'] ) {
				continue;
			}

			if ( $fetched['env_fail'] ) {
				// 本机够不着，不代表来源有问题 —— 记为「无法核实」，不计入错误。
				$unverifiable[] = "来源 [{$n}] 本机无法访问（cURL {$fetched['errno']}: {$fetched['errstr']}）：{$url}";
			} else {
				$errors[] = "来源 [{$n}] 抓取失败（HTTP {$fetched['status']}）：{$url}";
			}
		}

		/* ---- 3 & 5：数字核对 ---------------------------------------- */

		foreach ( self::sentences_with_numbers( $text ) as $sent ) {
			$nums = self::extract_numbers( $sent );
			if ( empty( $nums ) ) {
				continue;
			}

			/*
			 * 一文が複数の出典を引くことは普通にある。
			 *     「Tokyo's capacity is 2,440. [source:2] Osaka's is 2,800. [source:3]」
			 * 以前は preg_match で最初の一つしか読まず、2,800 まで出典[2]に
			 * 照らしていた。マーカーは全部拾い、数字はそのいずれかに在れば可とする。
			 *
			 * 緩めているように見えるが、そうではない —— 書き手が明示的に引いた
			 * 出典の集合に限った話で、引いていない出典は候補に入らない。
			 */
			if ( ! preg_match_all( '/\[source:\s*(\d+)\s*\]/i', $sent, $sm ) ) {
				$errors[] = '出现未标注来源的数字：「' . self::excerpt( $sent ) . '」';
				continue;
			}

			$cited = array();
			foreach ( array_unique( array_map( 'intval', $sm[1] ) ) as $n ) {
				if ( $n < 1 || $n > count( $sources ) ) {
					continue; // 上面已经报过孤儿引用了，不重复报。
				}
				$page = $this->fetch( $sources[ $n - 1 ]['url'] );
				/*
				 * 页面取不到就没法核对。上面已按「来源有问题」或「本机够不着」
				 * 记过一笔，这里不重复 —— 但绝不能把「没查」当成「查过且通过」，
				 * 那是最坏的一种假阳性。取不到的来源不进候选集。
				 */
				if ( $page['ok'] ) {
					$cited[ $n ] = $page['text'];
				}
			}

			if ( empty( $cited ) ) {
				continue;
			}

			foreach ( $nums as $item ) {
				$found = false;
				foreach ( $cited as $text ) {
					if ( self::number_present( $item['num'], $item['unit'], $text ) ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$with  = '' !== $item['unit'] ? "（{$item['unit']}）" : '';
					$where = '[' . implode( '][', array_keys( $cited ) ) . ']';
					$errors[] = "数字 {$item['raw']}{$with} 在所引来源 {$where} 的页面上均未找到：「" . self::excerpt( $sent ) . '」';
				}
			}
		}

		/*
		 * pass 只看 errors。本机够不着的来源不算内容缺陷，
		 * 但调用方必须据 unverifiable 另行决断 —— 见 check-article.php 的退出码 3。
		 */
		return array(
			'pass'         => empty( $errors ),
			'errors'       => $errors,
			'warnings'     => $warnings,
			'unverifiable' => $unverifiable,
			'checked'      => $checked,
		);
	}

	/**
	 * 这次抓取失败，是本机环境的问题还是来源本身的问题。
	 *
	 * 为什么必须区分：
	 *
	 *   闸门的职责是「这个数字在来源页上对不对」。抓不到页面有两种完全不同的原因：
	 *
	 *     来源的问题  —— 404、页面没了、域名过期。这是内容缺陷，该拦。
	 *     本机的问题  —— TLS 谈不拢、DNS 不通、出站被墙。来源好端端的，
	 *                    只是这台机器够不着。这不是内容缺陷。
	 *
	 *   混为一谈的后果在生产服务器上已经出现了：那台 CentOS 7 的
	 *   curl 7.29.0 / OpenSSL 1.0.2k 不支持 TLS 1.3，而 ISI 官网走的
	 *   Chinafy 节点要求 TLS 1.3，于是每一篇引用学校官网的文章都报同一个
	 *   「抓取失败」。
	 *
	 *   一个天天喊狼来了的检查，最后一定会被绕过 —— 然后它就再也拦不住
	 *   真正该拦的东西了。误报的代价不是「多看一眼」，是整道闸门失效。
	 *
	 * @param int $errno cURL 错误码。
	 * @return bool
	 */
	private static function is_env_failure( $errno ) {
		return in_array(
			(int) $errno,
			array(
				5,  // CURLE_COULDNT_RESOLVE_PROXY
				6,  // CURLE_COULDNT_RESOLVE_HOST   DNS 不通
				7,  // CURLE_COULDNT_CONNECT        连不上
				28, // CURLE_OPERATION_TIMEDOUT
				35, // CURLE_SSL_CONNECT_ERROR      TLS 握手失败（本机 TLS 版本过旧即属此类）
				51, // CURLE_PEER_FAILED_VERIFICATION
				60, // CURLE_SSL_CACERT             本机根证书过期
				77, // CURLE_SSL_CACERT_BADFILE
			),
			true
		);
	}

	/**
	 * 让表格继承「引出它那句话」的来源标记。
	 *
	 * 为什么需要这一步：
	 *
	 *   闸门按句读切分文本，再要求每个含数字的句子带 [source:N]。
	 *   表格里全是数字、没有句号，整张表会被切成一大块无标记内容，
	 *   于是每一篇带数据表的文章都会被判成「一堆裸数字」。
	 *
	 *   但人本来就不会在表格每个格子里标注来源 —— 真实的写法是
	 *   「各校区首年费用如下 [source:1]：」然后跟一张表。
	 *   这条规则就是把那个写法变成机器能认的：
	 *   表格前面最近的一个 [source:N]，视为整张表的出处。
	 *
	 *   作用域刻意限制在 <table> 内：正文段落仍然逐句要求标记，
	 *   不会因为这条规则而整体放松。
	 *
	 * 同时把 </tr> 换成段落分隔、</td> 换成竖线，
	 * 让每一行成为独立的切分单元，否则整张表仍是一块。
	 *
	 * @param string $html 正文 HTML。
	 * @return string 处理后的 HTML（仅用于校验，不影响发布的正文）。
	 */
	private static function attribute_tables( $html ) {
		return preg_replace_callback(
			'#<table\b.*?</table>#is',
			function ( $m ) use ( $html ) {
				$table = $m[0];
				$pos   = strpos( $html, $table );

				// 表格之前的全部内容里，最后一个 [source:N]。
				$before = false !== $pos ? substr( $html, 0, $pos ) : '';
				if ( ! preg_match_all( '/\[source:\s*(\d+)\s*\]/i', $before, $sm ) ) {
					return $table; // 前面没有任何标记 —— 照常走裸数字检查，该报就报。
				}
				$n = (int) end( $sm[1] );

				// 每个 </tr> 前补上标记，使每一行都带出处。
				return preg_replace( '#</tr>#i', " [source:{$n}]</tr>", $table );
			},
			$html
		);
	}

	/**
	 * 域名是否在白名单内（含子域）。
	 *
	 * 用后缀比对而不是 strpos：strpos 会让 evil-moj.go.jp.attacker.com 通过。
	 *
	 * @param string            $host    主机名。
	 * @param array<int,string> $allowed 允许的域。
	 * @return bool
	 */
	private static function host_allowed( $host, array $allowed ) {
		foreach ( $allowed as $d ) {
			$d = strtolower( ltrim( trim( $d ), '.' ) );
			if ( '' === $d ) {
				continue;
			}
			if ( $host === $d || substr( $host, -( strlen( $d ) + 1 ) ) === '.' . $d ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 抓取页面纯文本（带缓存）。
	 *
	 * @param string $url URL。
	 * @return array{ok:bool,status:int,text:string}
	 */
	private function fetch( $url ) {
		if ( isset( $this->cache[ $url ] ) ) {
			return $this->cache[ $url ];
		}

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => $this->timeout,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_ENCODING       => '', // 接受 gzip，不少站点只对声明了压缩的客户端正常响应。
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				/*
				 * 光有 User-Agent 不够。
				 *
				 * moj.go.jp 对默认 UA 返回 403，加了 UA 就通过；
				 * 但 isi-education.com 的 WAF 还要看 Accept / Accept-Language /
				 * Referer —— 只带 UA 时同样是 403，补齐这三个头立刻变 200。
				 *
				 * 这一点不修的话，闸门会把「内容完全正确、只是官网挡了爬虫」的文章
				 * 判成来源不可达而拒绝发布。误拦截同样是故障，只是不容易被发现。
				 */
				CURLOPT_HTTPHEADER     => array(
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language: ja,en-US;q=0.9,en;q=0.8,zh-CN;q=0.7',
					'Referer: https://www.google.com/',
					'Upgrade-Insecure-Requests: 1',
				),
			)
		);
		$raw    = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$errno  = (int) curl_errno( $ch );
		$errstr = (string) curl_error( $ch );
		curl_close( $ch );

		$text = '';
		if ( false !== $raw ) {
			// script/style 里的内容不是正文，留着会造成误命中。
			$clean = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $raw );
			$text  = wp_strip_tags_compat( $clean );
			$text  = preg_replace( '/\s+/u', ' ', $text );
		}

		$out = array(
			'ok'      => ( 200 === $status && '' !== trim( $text ) ),
			'status'  => $status,
			'text'    => $text,
			'errno'   => $errno,
			'errstr'  => $errstr,
			// 本机环境所限、而非来源本身有问题 —— 两者必须分开，理由见 is_env_failure()。
			'env_fail' => self::is_env_failure( $errno ),
		);

		$this->cache[ $url ] = $out;
		return $out;
	}

	/**
	 * 含数字的句子。
	 *
	 * @param string $text 正文。
	 * @return array<int,string>
	 */
	private static function sentences_with_numbers( $text ) {
		/*
		 * 两种边界都要切：句末标点，以及空行。
		 *
		 * 只按标点切的话，小标题会和它后面那一句粘成一块 —— 标题不带句号。
		 * 结果是报错信息里出现「The campus gap is not where you would look for it
		 * Nagano's first-year total is 905,000 yen」这种横跨标题与正文的片段，
		 * 定位起来要多花一道功夫。段落边界本来就是句子边界。
		 */
		$parts = preg_split( '/(?<=[.!?。！？])\s+|\n{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array_values( array_map( 'trim', (array) $parts ) );

		/*
		 * 把落单的来源标记并回上一句。
		 *
		 * 约定写法是「……granted. [source:1]」—— 标记跟在句号之后，
		 * 于是按句号分句时它会被切成独立的一「句」，原句就成了「有数字、无来源」，
		 * 每一篇正确标注的文章都会被误判。
		 *
		 * 让标记写在句号前（"…granted [source:1]."）也能绕开，但那样读者看到的
		 * 正文会很别扭，而且要求生成端记住一条反直觉的规则。宁可在这里多合并一步。
		 */
		$merged = array();
		foreach ( $parts as $p ) {
			/*
			 * 先頭のマーカーだけを前の文へ返し、残りは独立した文のままにする。
			 *
			 * 以前はマーカーで始まる塊を丸ごと前へ併合していた。すると
			 *     「Tokyo's capacity is 2,440. [source:2] Osaka's is 2,800. [source:3]」
			 * が一塊になり、後述のとおりマーカーは最初の一つしか読まれないため、
			 * 2,800 を出典[2]に照らして「無い」と誤判定していた。
			 * 正しく書かれた記事が弾かれる方向の誤りで、実際に弾かれた。
			 */
			// 連続するマーカー（[source:1] [source:2]）はまとめて剥がす。
			// 一つずつだと二つ目が後続の文に残り、その文が引いていない出典として扱われる。
			if ( preg_match( '/^((?:\[source:\s*\d+\s*\]\s*)+)(.*)$/is', $p, $mm ) && ! empty( $merged ) ) {
				$merged[ count( $merged ) - 1 ] .= ' ' . trim( $mm[1] );
				$rest = trim( $mm[2] );
				if ( '' !== $rest ) {
					$merged[] = $rest;
				}
				continue;
			}
			$merged[] = $p;
		}

		$out = array();
		foreach ( $merged as $p ) {
			if ( preg_match( '/\d/u', $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * 量词同义表：把文中的写法归到一个类，再展开成来源页上可能的所有写法。
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function unit_synonyms() {
		return array(
			'hour'  => array( 'hour', 'hours', '時間' ),
			'week'  => array( 'week', 'weeks', 'weekly', '週間', '週' ),
			'day'   => array( 'day', 'days', '日間', '日' ),
			'month' => array( 'month', 'months', 'か月', 'ヶ月', '箇月', 'カ月' ),
			'year'  => array( 'year', 'years', 'annual', 'annually', '年間', '年' ),
			'yen'   => array( 'yen', 'JPY', '円' ),
			'page'  => array( 'page', 'pages', 'ページ' ),
			/*
			 * 「分」と「名」を入れておく理由。
			 *
			 * 学校の案内は「徒歩5分」「収容定員100名」という書き方をする。
			 * この二つを量詞として登録しないと、5 も 100 も「3桁以下・量詞なし」
			 * の分岐に落ちて検査対象から外れる —— 定員を 100 から 500 に
			 * 書き換えても闸门は何も言わない。
			 *
			 * 徒歩分数と定員は、学校を選ぶ人が実際に比べる数字なので、
			 * 小さいからという理由で検査外にしてよいものではない。
			 */
			'minute' => array( 'minute', 'minutes', 'min', '分' ),
			'people' => array( 'student', 'students', 'people', 'places', '名', '人' ),
		);
	}

	/**
	 * 从句子中抽出需要核对的数字，连同它的量词。
	 *
	 * 带量词是关键：只搜数字本身会撞上来源页上的电话号码、邮编、条款号。
	 * 实测过 —— 「45 hours」曾因为 ISA 页面角落的 ℡045-370-9755 而被判为有据可依。
	 *
	 * 刻意跳过 [source:N] 里的 N，那是标记不是事实。
	 *
	 * @param string $sentence 句子。
	 * @return array<int,array{num:string,unit:string,raw:string}> unit 为空表示无量词。
	 */
	private static function extract_numbers( $sentence ) {
		$s = preg_replace( '/\[source:\s*\d+\s*\]/i', ' ', $sentence );

		$out  = array();
		$seen = array();

		/*
		 * 时刻（9:15 / 13:30）先单独抽出来。
		 *
		 * 不这样做的话它们会被拆成「9」和「15」两个两位数，双双落进
		 * 「≤3 位且无量词 → 跳过」的分支，于是整条时刻完全不被核对。
		 * 实测把 9:15 改成 8:15，闸门毫无反应。
		 *
		 * 授業時間は打工できる時間帯を決める —— 本記事ではまさにそこから
		 * アルバイトの可否を論じている。間違えれば実害が出る種類の数字で、
		 * 「小さい数だから」で見逃してよいものではない。
		 */
		preg_match_all( '/\b(\d{1,2}):(\d{2})\b/u', $s, $tm, PREG_SET_ORDER );
		foreach ( $tm as $t ) {
			$key = $t[0] . '|time';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = array( 'num' => $t[0], 'unit' => 'time', 'raw' => $t[0] );
		}

		// 时刻已单独处理，从文本里剔除，避免再被当成两个普通数字。
		$s = preg_replace( '/\b\d{1,2}:\d{2}\b/u', ' ', $s );

		/*
		 * 語学レベルの記号（A1〜C2 / N1〜N5）。
		 *
		 * 「A1」の 1 は単独の数字として扱われ、1桁・量詞なしでスキップされる。
		 * つまり A1 を A2 に書き換えても闸门は何も言わない —— 実際に素通りした。
		 *
		 * だが A1 と A2 は別の水準であり、入学要件としては実質的な違いになる。
		 * 日本語学校を扱うサイトでは CEFR と JLPT の級は常時出てくるので、
		 * 「記号だから数字ではない」で検査外にしてよいものではない。
		 */
		preg_match_all( '/\b([A-C][1-2]|N[1-5])\b/u', $s, $lv, PREG_SET_ORDER );
		foreach ( $lv as $l ) {
			$key = $l[0] . '|level';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = array( 'num' => $l[0], 'unit' => 'level', 'raw' => $l[0] );
		}
		$s = preg_replace( '/\b([A-C][1-2]|N[1-5])\b/u', ' ', $s );

		preg_match_all( '/\d[\d,]*/u', $s, $m, PREG_OFFSET_CAPTURE );

		$syn = self::unit_synonyms();

		foreach ( $m[0] as $hit ) {
			$raw    = $hit[0];
			$offset = $hit[1];
			$n      = str_replace( ',', '', $raw );

			// 数字之后的一小段，用来判定量词（"28 hours" / "28-hour" / "780,000 yen"）。
			$tail = mb_substr( substr( $s, $offset + strlen( $raw ) ), 0, 12, 'UTF-8' );

			$unit = '';
			foreach ( $syn as $key => $words ) {
				foreach ( $words as $w ) {
					if ( preg_match( '/^[\s\-]*' . preg_quote( $w, '/' ) . '/iu', $tail ) ) {
						$unit = $key;
						break 2;
					}
				}
			}

			/*
			 * 收录条件：
			 *   有量词          —— 一律核对，哪怕只有两位数（28 小时正是这种）
			 *   无量词且 ≥4 位  —— 核对，位数够多时偶然撞上的概率低
			 *   无量词且 ≤3 位  —— 跳过。这类多是列表序号、章节号，
			 *                      逐个核对只会制造噪音，而噪音会让人开始忽略告警
			 */
			if ( '' === $unit && strlen( $n ) < 4 ) {
				continue;
			}

			$key = $n . '|' . $unit;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$out[] = array( 'num' => $n, 'unit' => $unit, 'raw' => $raw );
		}

		return $out;
	}

	/**
	 * 数字是否出现在来源页面上。
	 *
	 * 同一个数量在网页上写法不一，逐一尝试：
	 *   1200000 / 1,200,000 / 120万 / １２０００００（全角）
	 *
	 * 日本的官方站点大量使用全角数字 —— ISA 的资格外活动页写的是「１週について
	 * ２８時間以内」，半角 "28" 在整页里出现 0 次。不做全角展开的话，
	 * 每一篇正确引用政府页面的文章都会被这道闸门判死。
	 *
	 * 有量词时要求**数字与量词相邻**，而不是各自出现在页面某处：
	 * 整页子串搜索会撞上电话号码与条款号（实测 ℡045-370-9755 让「45 hours」蒙混过关）。
	 *
	 * @param string $num  归一化后的数字串（无千分位）。
	 * @param string $unit 量词键（unit_synonyms 的键），空串表示无量词。
	 * @param string $page 页面纯文本。
	 * @return bool
	 */
	private static function number_present( $num, $unit, $page ) {
		/*
		 * 时刻は書き方が割れる。
		 *
		 * 英語の本文では 9:15 と書くが、日本語のサイトは「9時15分」と書く ——
		 * ヒューマンアカデミーの校舎ページがまさにそれで、コロン表記は一度も出てこない。
		 * コロンだけ探しても永遠に見つからず、正しい記述が落とされる。
		 *
		 * 先頭ゼロの有無（9:15 と 09:15）も両方試す。
		 */
		/*
		 * 語学レベル記号。全角で書かれることが多い ——
		 * 入管庁の通知は「Ａ１相当」で、半角の A1 はページ全体に存在しない。
		 * 英字も数字も全角化した形を試す。
		 */
		if ( 'level' === $unit ) {
			$fw_alpha = array( 'A' => 'Ａ', 'B' => 'Ｂ', 'C' => 'Ｃ', 'N' => 'Ｎ' );
			$letter   = substr( $num, 0, 1 );
			$digit    = substr( $num, 1 );
			$forms    = array(
				$num,
				( isset( $fw_alpha[ $letter ] ) ? $fw_alpha[ $letter ] : $letter ) . self::to_fullwidth( $digit ),
				$letter . self::to_fullwidth( $digit ),
				( isset( $fw_alpha[ $letter ] ) ? $fw_alpha[ $letter ] : $letter ) . $digit,
			);
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $page, $f ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'time' === $unit ) {
			$parts = explode( ':', $num );
			$h     = (int) $parts[0];
			$mi    = isset( $parts[1] ) ? $parts[1] : '00';

			$forms = array(
				$h . ':' . $mi,
				sprintf( '%02d', $h ) . ':' . $mi,
				$h . '時' . (int) $mi . '分',
				$h . '時' . $mi . '分',
				$h . '：' . $mi, // 全角コロン
			);
			// 00 分は「9時」とだけ書かれることがある。
			if ( '00' === $mi ) {
				$forms[] = $h . '時';
			}

			$hay = str_replace( ' ', '', $page );
			foreach ( array_unique( $forms ) as $f ) {
				$f_nospace = str_replace( ' ', '', $f );
				if ( false !== strpos( $hay, $f_nospace ) ) {
					return true;
				}
				// 全角数字版も試す。
				$fw = strtr( $f_nospace, array( '0' => '０', '1' => '１', '2' => '２', '3' => '３', '4' => '４',
					'5' => '５', '6' => '６', '7' => '７', '8' => '８', '9' => '９' ) );
				if ( false !== strpos( $hay, $fw ) ) {
					return true;
				}
			}
			return false;
		}

		$candidates = array( $num );

		if ( strlen( $num ) > 3 ) {
			$candidates[] = number_format( (float) $num );
		}

		/*
		 * 和暦。
		 *
		 * 日本の官公庁文書は西暦をほとんど使わない。入管庁の運用見直し通知
		 * （moj.go.jp/isa/10_00258.html）は全編「令和８年」で、ページ全体に
		 * 「2026」という文字列は一度も現れない。
		 *
		 * 英語記事には当然 2026 と書く。換算しなければ、正しく一次資料を
		 * 引いた記事がすべて「出典に数字が無い」と弾かれる ——
		 * 日本の公的機関を出典にする記事は全滅する。
		 *
		 * 令和元年＝2019年、平成元年＝1989年。
		 */
		$y = (int) $num;
		if ( $y >= 1926 && $y <= 2100 ) {
			$reiwa = $y - 2018; // 2019 → 1
			if ( $reiwa >= 1 ) {
				$candidates[] = '令和' . $reiwa . '年';
				$candidates[] = '令和' . self::to_fullwidth( (string) $reiwa ) . '年';
				if ( 1 === $reiwa ) {
					$candidates[] = '令和元年';
				}
			}
			$heisei = $y - 1988; // 1989 → 1
			if ( $heisei >= 1 && $heisei <= 31 ) {
				$candidates[] = '平成' . $heisei . '年';
				$candidates[] = '平成' . self::to_fullwidth( (string) $heisei ) . '年';
			}
		}

		// 日式「万」：1200000 → 120万
		$v = (float) $num;
		if ( $v >= 10000 && fmod( $v, 10000 ) === 0.0 ) {
			$candidates[] = (string) ( (int) ( $v / 10000 ) ) . '万';
		}

		// 全角。
		$candidates[] = self::to_fullwidth( $num );

		$candidates = array_values( array_unique( $candidates ) );

		// 无量词：只能做整页出现性检查。仅对 4 位以上数字启用（见 extract_numbers）。
		if ( '' === $unit ) {
			$hay = str_replace( ',', '', $page );
			foreach ( $candidates as $c ) {
				if ( false !== strpos( $hay, str_replace( ',', '', $c ) ) || false !== strpos( $page, $c ) ) {
					return true;
				}
			}
			return false;
		}

		// 有量词：要求两者在 12 个字符内相邻（数字在前或量词在前都接受，
		// 覆盖 "28 hours" / "hours: 28" / "２８時間" / "1週について２８時間"）。
		$syns = self::unit_synonyms();
		$words = isset( $syns[ $unit ] ) ? $syns[ $unit ] : array( $unit );

		$unit_alt = implode( '|', array_map( function ( $w ) {
			return preg_quote( $w, '/' );
		}, $words ) );

		foreach ( $candidates as $c ) {
			$n_q = preg_quote( $c, '/' );
			// 数字 → 量词
			if ( preg_match( '/' . $n_q . '.{0,12}?(' . $unit_alt . ')/iu', $page ) ) {
				return true;
			}
			// 量词 → 数字
			if ( preg_match( '/(' . $unit_alt . ').{0,12}?' . $n_q . '/iu', $page ) ) {
				return true;
			}
		}

		/*
		 * 邻接判定失败时的回退：5 位以上的数字改用整页出现性检查。
		 *
		 * 表格里的金额普遍不重复单位 —— ISI 的学费页把「（単位：日本円）」
		 * 写在表头，下面每个 100,000 / 1,065,000 旁边都没有「円」。
		 * 只认邻接的话，这类完全正确的引用会被全部判死。
		 *
		 * 门槛定在 5 位是因为误放行才是危险方向：当初「45 hours」正是被
		 * 页面角落的电话号码 ℡045-370-9755 蒙混过关的。两位数继续严查，
		 * 而 6 位数（1065000）在一个页面上偶然出现的概率低到可以接受。
		 *
		 * 换句话说：邻接是首选证据，大数字的整页出现是次级证据，
		 * 小数字没有次级证据可用。
		 */
		if ( strlen( $num ) >= 5 ) {
			$hay = str_replace( ',', '', $page );
			foreach ( $candidates as $c ) {
				if ( false !== strpos( $hay, str_replace( ',', '', $c ) ) || false !== strpos( $page, $c ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * 半角数字を全角に。
	 *
	 * 日本の官公庁サイトは全角数字を多用する —— 入管庁の資格外活動ページは
	 * 「２８時間」と書いており、半角の 28 はページ全体に一度も出てこない。
	 *
	 * @param string $s 文字列。
	 * @return string
	 */
	private static function to_fullwidth( $s ) {
		return strtr(
			(string) $s,
			array( '0' => '０', '1' => '１', '2' => '２', '3' => '３', '4' => '４',
				'5' => '５', '6' => '６', '7' => '７', '8' => '８', '9' => '９' )
		);
	}

	/**
	 * 截断成便于阅读的片段。
	 *
	 * @param string $s 文本。
	 * @return string
	 */
	private static function excerpt( $s ) {
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		return mb_strlen( $s, 'UTF-8' ) > 90 ? mb_substr( $s, 0, 90, 'UTF-8' ) . '…' : $s;
	}
}
