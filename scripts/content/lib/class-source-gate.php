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
 * この限界が最も露わになるのは、行の多い表を出典にしたときである。
 *
 *   厚労省の地域別最低賃金一覧は 47 行あり、発効日の欄には
 *   令和8年10月1日・10月2日・10月3日・…・11月1日・12月1日・12月2日 と
 *   ほとんどの日付が揃っている。
 *   そのため「東京は10月1日発効」を「10月2日」に改竄しても、
 *   ページ上に令和8年10月2日が（別の県の行として）存在するので通る。
 *   変異テストで実際に素通りした。
 *   逆に、どの行にも無い日付（令和8年12月5日）に改竄すれば捕まる。
 *
 *   つまり表が密であるほど、闸门は「存在するか」しか言えなくなる。
 *   県名と日付の対応は、書き手が表を読んで確かめる以外にない。
 *
 * ── 干し草の山の大きさが、この限界を支配する ───────────────────
 *
 * 同じ限界は、出典が大きくなるほど急速に悪化する。実測した二つの例：
 *
 *   【ページ範囲で解けた例】
 *   生活・就労ガイドブックは157ページ・約14万字ある。第9章（交通）全体を
 *   一つの出典として引くと、自動車の罰則表と自転車の罰則表が同じ山に入る。
 *   そのため自転車の「30万円以下」を「10万円以下」に改竄しても、
 *   10万円が（自動車の表の別の行として）存在するので通った。
 *
 *   出典に pages を書いて節単位（115ページ）に切ると、
 *   同じ記事の15個の数字すべてが変異テストで捕まるようになった。
 *   PDF を出典にする記事では pages を必ず書く。これは任意項目ではない。
 *
 *   【ページ範囲では解けない例】
 *   赤門会の学生寮ページには「40,000円〜60,000円」（家賃）と
 *   「5,000円〜10,000円」（光熱費の目安）が並んでいる。
 *   光熱費の 10,000 を 40,000 に改竄しても、40,000 は家賃として
 *   同じページに在るので通る。ページは既に小さく、切り分ける余地がない。
 *
 *   この違いは重要である。山を小さくすれば解ける問題と、
 *   同一文脈内の取り違えという解けない問題は、別の種類の限界である。
 *   前者は出典の書き方で対処する。後者は書き手が読んで確かめる以外にない。
 *
 * ── 号番号について特に ─────────────────────────────────────────
 *
 * 条文の号番号（ground 5 / (6) / 第5号）は照合対象にしてあるが、
 * 出典ページが全部の号を列挙している場合、この照合はほぼ無力である。
 *
 *   在留資格の取消事由のページは (1) から (10) までを列挙している。
 *   したがって「ground 5」を「ground 8」に改竄しても、8 はページ上に在る。
 *   変異テストで素通りした。
 *
 * 号番号の照合が効くのは、出典が少数の号しか挙げていないときだけである。
 * 全列挙型のページでは、号と内容の対応は書き手が読んで確かめる。
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

			$fetched = $this->fetch( $url, isset( $src['pages'] ) ? trim( (string) $src['pages'] ) : '' );
			$checked++;

			if ( $fetched['ok'] ) {
				/*
				 * 干し草の山が大きすぎるときは、そう言う。
				 *
				 * この闸门の次級証拠は「その数字がページ上に在るか」である。
				 * 157ページの冊子（生活・就労ガイドブックは約57万字）を出典に
				 * すると、ページ番号だけで1〜157が全部「在る」ことになり、
				 * 2〜3桁の数字はどれに書き換えても素通りする ——
				 * 変異テストで 119→118、110→112、49→59、5→7 がすべて通った。
				 *
				 * 闸门が効いていないことを、闸门自身が毎回言うべきである。
				 * 書き手の手元のメモに書いておくだけでは、次に誰かが
				 * 「退出码 0 だから核対済み」と読む。
				 *
				 * 閾値は目安。小さな告示ページ（数千字）と冊子（数十万字）を
				 * 分ける位置に置いてある。
				 */
				$hay_len = mb_strlen( $fetched['text'], 'UTF-8' );
				if ( $hay_len > 50000 ) {
					$warnings[] = "来源 [{$n}] の本文が " . number_format( $hay_len )
						. ' 字あります。この規模では「数字がページ上に在る」という次級証拠は'
						. 'ほぼ無意味です（ページ番号だけで小さい数字が揃ってしまう）。'
						. "2〜3桁の数字は闸门を通っても核対されたとみなせません：{$url}";
				}
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

		$unchecked = array();

		foreach ( self::sentences_with_numbers( $text ) as $sent ) {
			$skipped = array();
			$nums    = self::extract_numbers( $sent, $skipped );

			/*
			 * 検査外に落ちた数字の告知。
			 *
			 * 出典を引いている文に限る —— 見出しの「1.」や箇条書きの番号まで
			 * 拾えば告警が噪音になり、噪音は無視される習慣を作る。
			 * 出典マーカーのある文に書かれた数字は、書き手が事実の主張として
			 * 出していて、かつ核対されていない。そこだけ知らせる価値がある。
			 */
			if ( ! empty( $skipped ) && preg_match( '/\[source:\s*\d+\s*\]/i', $sent ) ) {
				foreach ( array_keys( $skipped ) as $raw ) {
					$unchecked[ $raw ] = self::excerpt( $sent );
				}
			}

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
				/*
				 * 号番号だけの文は、出典マーカーがなくても誤りとしない。
				 *
				 * 「Item 3: were you doing what your status is for」は見出しであり、
				 * 「that is item 7」は自記事内の節への参照である。どちらも
				 * 文書の構造を指す符号で、世界について量を主張していない。
				 * 見出しに [source:N] を書くことはできないので、ここを誤りに
				 * すると構造的に直せない告警が残り続ける。
				 *
				 * 核対能力は失っていない —— 出典を引いている文に現れた号番号は、
				 * 下の照合でページ上の「（５）」「第5号」に照らされる。
				 * 量を表す数字はこの例外に入らない。
				 */
				$quantities = array_filter(
					$nums,
					static function ( $x ) {
						/*
						 * 月名も号番号と同じ扱いにする。
						 *
						 * 「4月」「10月」は、記事の中では上で出典付きで述べた
						 * 事実への参照として繰り返し現れる ——
						 * 「4月を狙うなら長いほうで見ておく」のような助言文や、
						 * 内链のアンカーテキストがそれである。
						 * 新しい量の主張ではないので、ここで誤りにすると
						 * 構造的に直せない告警になる（中国語版は入学期を
						 * 本文中で何度も指すので特に顕著だった）。
						 *
						 * 核対能力は失っていない：出典を引いている文に現れた
						 * 月名は、下の照合でページ上の「4月」に照らされる。
						 */
						return 'item' !== $x['unit'] && 'monthname' !== $x['unit'];
					}
				);
				if ( empty( $quantities ) ) {
					continue;
				}
				$errors[] = '出现未标注来源的数字：「' . self::excerpt( $sent ) . '」';
				continue;
			}

			$cited   = array();
			$missing = array();
			foreach ( array_unique( array_map( 'intval', $sm[1] ) ) as $n ) {
				if ( $n < 1 || $n > count( $sources ) ) {
					continue; // 上面已经报过孤儿引用了，不重复报。
				}
				$page = $this->fetch(
					$sources[ $n - 1 ]['url'],
					isset( $sources[ $n - 1 ]['pages'] ) ? trim( (string) $sources[ $n - 1 ]['pages'] ) : ''
				);
				/*
				 * 页面取不到就没法核对。上面已按「来源有问题」或「本机够不着」
				 * 记过一笔，这里不重复 —— 但绝不能把「没查」当成「查过且通过」，
				 * 那是最坏的一种假阳性。取不到的来源不进候选集。
				 */
				if ( $page['ok'] ) {
					$cited[ $n ] = $page['text'];
				} else {
					$missing[ $n ] = true;
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
				if ( $found ) {
					continue;
				}

				$with  = '' !== $item['unit'] ? "（{$item['unit']}）" : '';
				$where = '[' . implode( '][', array_keys( $cited ) ) . ']';

				/*
				 * この文が引いている出典のうち一つでも取れていないなら、
				 * 「見つからない」を内容の誤りとして報告してはならない。
				 * 探せなかったページにこそ在るかもしれない。
				 *
				 * 実際に起きた：千駄ヶ谷の3校を比べる文が [3][1][2] を引いており、
				 * 定員100名は [3]（就職課程）のページにしかない。
				 * その回だけ [3] の取得が間欠的に失敗し、闸门は
				 * 「100 は [1][2] に無い」= 内容の誤りと報告した。
				 * 直後の再実行では通る。正しい記事を誤りに見せる方向の嘘で、
				 * 終了コード3（本机够不着）を設けた理由そのものに反する。
				 *
				 * 「狼が来た」と言い続ける検査は最後に必ず迂回される。
				 * 迂回された時点で、本当に止めるべきものも止まらなくなる。
				 */
				if ( ! empty( $missing ) ) {
					$lack          = '[' . implode( '][', array_keys( $missing ) ) . ']';
					$unverifiable[] = "数字 {$item['raw']}{$with} は取得できた来源 {$where} には無かったが、"
						. "同じ文が引く来源 {$lack} を取得できていないため判定を保留する：「"
						. self::excerpt( $sent ) . '」';
					continue;
				}

				$errors[] = "数字 {$item['raw']}{$with} 在所引来源 {$where} 的页面上均未找到：「" . self::excerpt( $sent ) . '」';
			}
		}

		/*
		 * 検査外に落ちた数字をまとめて告知する。
		 *
		 * 警告であって誤りではない。闸门が「この数字は見ていない」と
		 * 自分の保証範囲を申告しているだけで、書き手が判断する。
		 */
		foreach ( $unchecked as $raw => $where ) {
			$warnings[] = "数字 {$raw} は量詞を伴わない3桁以下のため**核対していない**。"
				. '闸门を通ったことは、この数字が来源页に在ることを意味しない：「'
				. $where . '」'
				. '（核対させるには量詞を書く —— 「a capacity of 900」のように）';
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

				/*
				 * 転送が始まってから切れる系。当初はこの一群が抜けていた。
				 *
				 * 抜けていると何が起きるか —— 接続が途中で切れた回は
				 * 「本文が空」になり、env_fail でないので
				 * 「数字がページ上に無い」= 内容の誤りとして報告される。
				 * これは終了コード 3 を作った理由そのものに反する嘘で、
				 * しかも正しい記事を誤りに見せる方向の嘘。
				 *
				 * 実際に起きた：isi-education.com が
				 * 「Recv failure: Connection reset by peer」（56）を返した回と、
				 * group.jp-sji.org が HTTP 000・0 バイトを返した回。
				 * 同じ記事を直後に再実行すると通るので、内容は正しかった。
				 * 間欠的なので、気づかないまま「この数字は裏が取れない」と
				 * 判断してしまう危険がある種類の故障。
				 */
				16, // CURLE_HTTP2
				18, // CURLE_PARTIAL_FILE           途中で切れた
				52, // CURLE_GOT_NOTHING            200 なのに空
				55, // CURLE_SEND_ERROR
				56, // CURLE_RECV_ERROR             Connection reset by peer
				92, // CURLE_HTTP2_STREAM
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
	private function fetch( $url, $pages = '' ) {
		/*
		 * キャッシュキーにページ範囲を含める。同じ PDF を別の範囲で
		 * 引く記事があるため、URL だけだと最初に読んだ範囲が使い回される。
		 */
		$ck = '' === $pages ? $url : $url . '#p=' . $pages;
		if ( isset( $this->cache[ $ck ] ) ) {
			return $this->cache[ $ck ];
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
		$ctype  = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
		$errno  = (int) curl_errno( $ch );
		$errstr = (string) curl_error( $ch );
		curl_close( $ch );

		$text     = '';
		$env_fail = self::is_env_failure( $errno );

		if ( false !== $raw ) {
			if ( self::looks_like_pdf( $raw, $ctype ) ) {
				/*
				 * PDF も一次資料として扱う。
				 *
				 * 日本の官公庁は最も硬い数字を PDF でしか出さない ——
				 * 在留審査処理期間の月次平均日数（留学の在留資格認定証明書なら
				 * 41.0 日）は PDF の表の中だけにあり、HTML ページには
				 * 「平均日数を公表しています」という説明しか載っていない。
				 *
				 * PDF を読めないままにすると、闸门は構造的に
				 * 「最も権威のある出典ほど核対できない」状態になる。
				 * それは一次資料に当たるという方針そのものを無効にする。
				 */
				$pdf = self::pdf_to_text( $raw, $pages );
				if ( null === $pdf ) {
					/*
					 * pdftotext が無い環境では「PDF だから中身を見ていない」と
					 * 言い切る。内容が誤りだから落とすのではないので、
					 * HTTP 不達と同じ env_fail に寄せる（終了コード 3 側）。
					 *
					 * ここで「通す」のは嘘、「落とす」のも嘘。
					 * 「この機械では確かめていない」が唯一正しい報告。
					 */
					$env_fail = true;
					$errstr   = '' !== $errstr
						? $errstr
						: 'PDF 来源：本机没有 pdftotext（poppler-utils），未能提取文本核对';
				} else {
					$text = preg_replace( '/\s+/u', ' ', $pdf );
				}
			} else {
				// script/style 里的内容不是正文，留着会造成误命中。
				$clean = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $raw );
				$text  = wp_strip_tags_compat( $clean );
				$text  = preg_replace( '/\s+/u', ' ', $text );
			}
		}

		$out = array(
			'ok'      => ( 200 === $status && '' !== trim( $text ) ),
			'status'  => $status,
			'text'    => $text,
			'errno'   => $errno,
			'errstr'  => $errstr,
			// 本机环境所限、而非来源本身有问题 —— 两者必须分开，理由见 is_env_failure()。
			'env_fail' => $env_fail,
		);

		$this->cache[ $ck ] = $out;
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
			'hour'  => array( 'hour', 'hours', 'hourly', '時間', '小时', '小時', '時' ),  // 小时：中文
			'week'  => array( 'week', 'weeks', 'weekly', '週間', '週', '周' ),  // 周：中文
			/*
			 * 中文の量詞を入れる。
			 *
			 * 三語展開の検証で分かったこと：中国語版の記事は「41.0天」「3个月」と
			 * 書くが、天 も 个月 も表に無かったため、数字がまるごと検査外に落ちていた。
			 * 変異テストで 41.0天→51.0天、1个月到3个月→5个月 がどちらも素通りした。
			 *
			 * 日本語の出典に対して中国語で書く以上、本文側の量詞は中国語になる。
			 * 照合先（出典ページ）は日本語なので、日本語の量詞と並べて持っておけば
			 * 「中文の量詞で抽出し、日本語の表記で照合する」が成立する。
			 */
			'day'   => array( 'day', 'days', '日間', '日', '天' ),
			/*
			 * 「ヵ月」（U+30F5 小書きカ）は「ヶ月」（U+30F6）と別の文字。
			 * 赤門会のコース一覧は「1年6ヵ月」と書いており、ヶ だけでは当たらない。
			 * 見た目がほぼ同じぶん、抜けていても気づきにくい。
			 */
			'month' => array( 'month', 'months', 'か月', 'ヶ月', 'ヵ月', '箇月', 'カ月', 'ケ月', '个月', '個月' ),
			/*
			 * 年齢。「年」ではなく「歳」で書かれるので、year とは別の量詞にする。
			 *
			 * 在留カードの写真提出は「１歳以上」が要件で、以前は「１６歳未満」が
			 * 免除だった —— ここを取り違えると、子を連れて来る人が
			 * 必要な書類を持たずに窓口へ行く。
			 *
			 * year より先に置く。「years of age」は year 側の 'years' にも
			 * 前方一致するため、順序が逆だと年齢が「年」として照合される。
			 */
			'age'   => array( 'years of age', 'year of age', 'years old', 'year old', '歳', '才' ),
			'year'  => array( 'year', 'years', 'annual', 'annually', '年間', '年' ),
			'yen'   => array( 'yen', 'JPY', '円', '日元', '日圓', '日圆' ),
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
			'minute' => array( 'minute', 'minutes', 'min', 'mins', '分' ),
			'people' => array( 'student', 'students', 'people', 'places', '名', '人' ),
			/*
			 * 学校の「校」。
			 *
			 * 「文部科学省認定の準備教育課程がある日本語学校は約30校」のような
			 * 数字は、学校選びの前提そのものを形づくる —— 30 を 300 と書けば
			 * 「珍しい課程」が「ありふれた課程」に変わってしまう。
			 * 量詞として登録しないと2桁・量詞なしで検査外に落ちる。
			 */
			/*
			 * 学校の数え方。「機関」と institution を入れておく。
			 *
			 * 文科省の認定結果は「申請機関総数 100機関」「認定とした日本語教育機関
			 * 32機関」と数える —— 校 ではなく 機関。英文も institutions と書く。
			 * 入れていなかったため、認定ラウンドの件数（100／32／53／58）が
			 * すべて「量詞なしの2〜3桁」として検査外に落ち、変異テストで
			 * どれを書き換えても闸门が黙った。選校の判断を左右する数字が
			 * まるごと無検査だった。
			 */
			/*
			 * 学校・機関の数。
			 *
			 * 中文の量詞（所・家・個）も入れる。中国語版の記事は
			 * 「100 所机构」と書くので、これが無いと量詞なしの3桁として
			 * 検査外に落ちる —— 認定結果の数字は中国語圏の読者にとって
			 * 最も重い判断材料のひとつで、落としてよいものではない。
			 *
			 * 照合側は出典（日本語）の「機関」「件」に当たればよい。
			 * 量詞キーは記事側の表記から決まり、照合には同じキーの
			 * 全同義語を使うので、言語をまたいでも成立する。
			 */
			'school' => array( 'school', 'schools', 'institution', 'institutions', '校', '機関', '件', '所机构', '所の機関', '所', '家' ),
			/*
			 * 建物の「棟」。
			 *
			 * 「校舎から5～40分の距離に約30棟の寮があります」のような数字は、
			 * 寮の選択肢がどれだけあるかを表す —— 30 棟と 3 棟では
			 * 「希望を出せる」の意味が変わる。量詞に入れないと検査外に落ちる。
			 */
			'building' => array( 'building', 'buildings', 'block', 'blocks', '棟' ),
			/*
			 * パーセント。
			 *
			 * 変異テストで「more than 90 percent」を 70 に書き換えても
			 * 闸门が何も言わないことを確認した —— percent が量詞表に無く、
			 * 2桁・量詞なしで検査外に落ちていた。
			 *
			 * 学校が自ら掲げる就職率・進学率は、読者が школы を比べるときに
			 * 最も重く見る数字で、しかも書き換えが一文字で済む。
			 * 検査外にしておく理由がない。
			 */
			'percent' => array( 'percent', 'percentage', '%', '％', 'パーセント', '割' ),
			/*
			 * 寮。「２つの女子寮」のような数え方を拾うため。
			 * 寮の棟数・女子寮の数は住む場所の選択肢そのもの。
			 */
			'dormitory' => array( 'dormitory', 'dormitories', 'dorm', 'dorms', '寮' ),
			/*
			 * 言語数。入管庁の資料は「19言語」「１９か国語」と書き方が割れる。
			 * ガイドブックが何言語で出ているかは、読者が自分の言語版を
			 * 探すかどうかを決める数字なので検査対象にする。
			 */
			'language' => array( 'language', 'languages', '言語', 'か国語', 'ヵ国語', 'カ国語', '箇国語' ),
			/*
			 * 章番号と版数。冊子を出典にする記事では、読者に「第9章を見ろ」と
			 * 言うこと自体が案内の中身になる。章を一つずらすと読者は
			 * 157ページの冊子の違う場所を開く。
			 *
			 * 版数は、読者が見ている冊子が最新かどうかを判断する唯一の手がかり。
			 */
			/*
			 * 速度・濃度・容量。いずれも法令上の閾値として現れる。
			 *
			 * 歩道を通行できる特定小型原動機付自転車の上限は時速6キロ、
			 * 酒気帯び運転は呼気1ℓ当たり0.15㎎以上。閾値を書き間違えると
			 * 読者は「自分は該当しない」と読む。検査外にしてよい数字ではない。
			 *
			 * 「l」単独は量詞に入れない —— l で始まる語すべてに前方一致する。
			 */
			'speed' => array( 'km/h', 'kph', 'kms', 'km per hour', 'kilometres per hour', 'kilometers per hour', 'キロ', 'km' ),
			'milligram' => array( 'mg', '㎎', 'ミリグラム', 'milligram', 'milligrams' ),
			/*
			 * 'L' は入れない。照合は大小文字を無視するので、「2027 list」の
			 * list に前方一致して「2027リットル」になる —— 実際にそうなり、
			 * 正しい記述が2件とも誤りとして報告された。
			 * 一文字の量詞は、この表に入れてよいものがほとんど無い。
			 */
			'litre' => array( 'litre', 'litres', 'liter', 'liters', 'ℓ', 'リットル' ),
			/*
			 * 「自転車安全利用五則」の則番号と、国・地域の数。
			 * どちらも出典が番号で構造化している記述で、取り違えると
			 * 読者は別の規則・別の国の話を読む。
			 */
			'rule' => array( '則' ),
			'country' => array( 'country', 'countries', 'か国', 'ヵ国', 'カ国', '箇国' ),
			'chapter' => array( 'chapter', 'chapters', '章' ),
			'edition' => array( 'edition', 'editions', '版' ),
		);
	}

	/**
	 * 量詞の候補を「長い順」に平らに並べたもの。
	 *
	 * なぜ必要か：量詞の照合は前方一致なので、キーの宣言順に試すと
	 * 短い量詞が長い量詞の頭に当たって先に勝ってしまう。
	 * 中国語版で「6,000日元」が day の「日」に当たり、yen の「日元」が
	 * 表に在るのに出番が無かった —— 1文字の CJK 量詞には
	 * 語尾境界という手が使えないので、順序で解く。
	 *
	 * 同じ長さのときは宣言順を保つ（usort は安定ではないので
	 * 添字を第二キーに使う）。
	 *
	 * @return array<int,array{key:string,word:string}>
	 */
	private static function unit_candidates_by_length() {
		static $flat = null;
		if ( null !== $flat ) {
			return $flat;
		}

		$flat = array();
		$i    = 0;
		foreach ( self::unit_synonyms() as $key => $words ) {
			foreach ( $words as $w ) {
				$flat[] = array( 'key' => $key, 'word' => $w, 'len' => mb_strlen( $w, 'UTF-8' ), 'i' => $i++ );
			}
		}

		usort(
			$flat,
			static function ( $a, $b ) {
				if ( $a['len'] !== $b['len'] ) {
					return $b['len'] - $a['len'];
				}
				return $a['i'] - $b['i'];
			}
		);

		return $flat;
	}

	/**
	 * 从句子中抽出需要核对的数字，连同它的量词。
	 *
	 * 带量词是关键：只搜数字本身会撞上来源页上的电话号码、邮编、条款号。
	 * 实测过 —— 「45 hours」曾因为 ISA 页面角落的 ℡045-370-9755 而被判为有据可依。
	 *
	 * 刻意跳过 [source:N] 里的 N，那是标记不是事实。
	 *
	 * @param string                   $sentence 句子。
	 * @param array<string,bool>|null &$skipped  传入数组时，把「2〜3桁・量詞なし」で
	 *                                           検査対象から外した数字をここに記録する。
	 *                                           呼び出し側が告知に使う（下の注記参照）。
	 * @return array<int,array{num:string,unit:string,raw:string}> unit 为空表示无量词。
	 */
	private static function extract_numbers( $sentence, &$skipped = null ) {
		$s = preg_replace( '/\[source:\s*\d+\s*\]/i', ' ', $sentence );

		$out  = array();
		$seen = array();

		/*
		 * 条文番号（Article 22-4 / paragraph 1）を先に取り出す。
		 *
		 * 放っておくと「22」「4」「1」に割れ、どれも「3桁以下・量詞なし」の
		 * 分岐で捨てられる —— 変異テストで Article 22-4 を 22-7 に書き換えても
		 * 闸门が何も言わないことを確認した。
		 *
		 * 法令の条番号を間違えると、読者は違う条文を読みに行く。
		 * 在留資格の取消しの根拠を一つずれた条文で示すのは、
		 * 数字を書き間違えるのと同じ種類の実害であって、
		 * 「小さい数だから」で検査外にしてよいものではない。
		 *
		 * 日本語の出典では第２２条の４・第１９条第２項と書かれるので、
		 * 照合は number_present() 側で和文の形に組み立てる。
		 */
		preg_match_all( '/\bArticles?\s+(\d{1,3})(?:\s*-\s*(\d{1,2}))?/iu', $s, $am, PREG_SET_ORDER );
		foreach ( $am as $a ) {
			$num = isset( $a[2] ) && '' !== $a[2] ? $a[1] . '-' . $a[2] : $a[1];
			$key = $num . '|article';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num, 'unit' => 'article', 'raw' => $a[0] );
		}
		$s = preg_replace( '/\bArticles?\s+\d{1,3}(?:\s*-\s*\d{1,2})?/iu', ' ', $s );

		/*
		 * 条文番号の CJK 表記。「第19条之7」（中文）「第19条の7」（日文）。
		 *
		 * 英語の「Article 19-7」しか拾っていなかったため、中国語版・日本語版の
		 * 記事では条番号が「19」「7」に割れ、どちらも検査外に落ちていた。
		 * 条番号の取り違えは読者を別の条文へ行かせる。
		 */
		$cjk_art = '/第\s*([0-9０-９]{1,3})\s*条(?:\s*[之の]\s*([0-9０-９]{1,2}))?/u';
		preg_match_all( $cjk_art, $s, $cam, PREG_SET_ORDER );
		foreach ( $cam as $ca ) {
			$main = strtr( $ca[1], array( '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9' ) );
			$sub  = isset( $ca[2] ) && '' !== $ca[2]
				? strtr( $ca[2], array( '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9' ) )
				: '';
			$num  = '' !== $sub ? $main . '-' . $sub : $main;
			$key  = $num . '|article';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num, 'unit' => 'article', 'raw' => $ca[0] );
		}
		$s = preg_replace( $cjk_art, ' ', $s );

		/*
		 * 条番号の範囲の後端。「Articles 19-7 to 19-13」。
		 *
		 * 上の正規表現は Articles の直後だけを見るので、範囲の後端（19-13）は
		 * 「Article」を伴わず残り、一般の数字抽出で 19 と 13 に割れて
		 * どちらも検査外に落ちていた。実際に3篇の記事でそうなっていた。
		 *
		 * 文中に Article(s) が現れている場合に限って、裸の「N-M」を条番号として
		 * 扱う。この限定がないと、電話番号や年月日の区切りを条番号と読む。
		 */
		if ( preg_match( '/\bArticles?\b/iu', $sentence ) ) {
			preg_match_all( '/\b(\d{1,3})\s*-\s*(\d{1,2})\b/u', $s, $arm, PREG_SET_ORDER );
			foreach ( $arm as $ar ) {
				$num = $ar[1] . '-' . $ar[2];
				$key = $num . '|article';
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array( 'num' => $num, 'unit' => 'article', 'raw' => $ar[0] );
			}
			$s = preg_replace( '/\b\d{1,3}\s*-\s*\d{1,2}\b/u', ' ', $s );
		}

		/*
		 * 日付を一つのトークンとして拾う。
		 *
		 * 「1 October 2026」と書くと、2026 は和暦換算で照合されるが
		 * 月と日はどちらも量詞なしの1〜2桁なので検査外に落ちる ——
		 * 変異テストで「on 2 December」を「on 5 December」に書き換えても
		 * 闸门が黙ることを確認した。
		 *
		 * 規制の文章では発効日・改正日そのものが論点になる。
		 * 最低賃金の記事は「同じ年度でも都道府県ごとに発効日が違う」ことが
		 * 主題で、日付を一日ずらせば記事の主張が崩れる。
		 * 時刻（9:15）や条番号（第22条の4）を個別トークンにしたのと同じ理由。
		 *
		 * 照合は和暦の形（令和８年１０月１日）に組み立てる。
		 * 日本の官公庁文書は西暦でも月日を漢数字混じりで書くため、
		 * 半角・全角の両方を試す。
		 */
		$months = array(
			'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
			'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
			'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
		);
		$mon_alt = implode( '|', array_keys( $months ) );

		// 「1 October 2026」と「October 1, 2026」の両方。
		$date_res = array(
			'/\b(\d{1,2})\s+(' . $mon_alt . ')\s+(\d{4})\b/iu',
			'/\b(' . $mon_alt . ')\s+(\d{1,2}),?\s+(\d{4})\b/iu',
		);
		foreach ( $date_res as $idx => $re ) {
			preg_match_all( $re, $s, $dm, PREG_SET_ORDER );
			foreach ( $dm as $dd ) {
				if ( 0 === $idx ) {
					$day = (int) $dd[1];
					$mon = $months[ strtolower( $dd[2] ) ];
					$yr  = (int) $dd[3];
				} else {
					$mon = $months[ strtolower( $dd[1] ) ];
					$day = (int) $dd[2];
					$yr  = (int) $dd[3];
				}
				$num = $yr . '-' . $mon . '-' . $day;
				$key = $num . '|date';
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array( 'num' => $num, 'unit' => 'date', 'raw' => $dd[0] );
			}
			$s = preg_replace( $re, ' ', $s );
		}

		/*
		 * CJK 表記の日付も一つのトークンにする。
		 *
		 * 三語展開の検証で、日本語・中国語の記事が「令和5年3月17日」と
		 * 書いたときに月の数字が検査外に落ちることが分かった ——
		 * 「3」の後ろは「月17日」で、裸の「月」は量詞表に無い（三月と衝突するため
		 * 意図的に入れていない）。結果、3月を5月に書き換えても闸门が黙った。
		 *
		 * 日付は規制の文章では論点そのものなので、英文（1 October 2026）と
		 * 同じく一つのトークンとして扱う。和暦（令和・平成）と西暦の両方を拾い、
		 * 西暦に正規化してから number_present に渡す —— 照合側は既に
		 * 和暦・西暦の両方の形を組み立てる。
		 */
		$era_base = array( '令和' => 2018, '平成' => 1988, '昭和' => 1925 );
		$cjk_date = '/(?:(令和|平成|昭和)\s*(\d{1,2}|元)|(\d{4}))\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/u';
		preg_match_all( $cjk_date, $s, $cm, PREG_SET_ORDER );
		foreach ( $cm as $c ) {
			if ( '' !== $c[1] ) {
				$n  = ( '元' === $c[2] ) ? 1 : (int) $c[2];
				$yr = $era_base[ $c[1] ] + $n;
			} else {
				$yr = (int) $c[3];
			}
			$num = $yr . '-' . (int) $c[4] . '-' . (int) $c[5];
			$key = $num . '|date';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num, 'unit' => 'date', 'raw' => $c[0] );
		}
		$s = preg_replace( $cjk_date, ' ', $s );

		/*
		 * 日を伴わない「年月」も拾う。
		 *
		 * 規制の文章は「令和６年１０月許可分から」「令和８年６月改正」のように
		 * 月までで切ることが多い。年月日の形だけを見ていると、
		 * この種の記述の月がまるごと検査外に落ちる ——
		 * 検証で 令和6年10月 を 令和6年11月 に書き換えても素通りした。
		 * 制度の施行時期や改正時期は、日付と同じく論点そのものである。
		 *
		 * 年月日の抽出を先に済ませてあるので、ここに残るのは日の無いものだけ。
		 */
		$cjk_ym = '/(?:(令和|平成|昭和)\s*(\d{1,2}|元)|(\d{4}))\s*年\s*(\d{1,2})\s*月(?!\s*\d)/u';
		preg_match_all( $cjk_ym, $s, $ym, PREG_SET_ORDER );
		foreach ( $ym as $c ) {
			if ( '' !== $c[1] ) {
				$n  = ( '元' === $c[2] ) ? 1 : (int) $c[2];
				$yr = $era_base[ $c[1] ] + $n;
			} else {
				$yr = (int) $c[3];
			}
			$num = $yr . '-' . (int) $c[4];
			$key = $num . '|yearmonth';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num, 'unit' => 'yearmonth', 'raw' => $c[0] );
		}
		$s = preg_replace( $cjk_ym, ' ', $s );

		/*
		 * 裸の「N月」を月名として拾う。
		 *
		 * 中国語版・日本語版の記事は入学期を「4月」「10月」と書く。
		 * 上の年月（2026年7月）は直前で消してあるので、ここに残るのは
		 * 年を伴わない月名である。出典側も「（4月、10月）」と書くので、
		 * 文字列そのものを照合すれば足りる。
		 *
		 * これが無いと量詞なしの1〜2桁として検査外に落ちる ——
		 * 入学期は記事の主題そのもので、取り違えたら記事の意味が変わる。
		 *
		 * 「1個月」「1か月」のような期間は 月 の直前に個/か が入るので
		 * この正規表現には当たらない（期間は month の量詞が拾う）。
		 */
		$month_re = '/(?<![0-9０-９年個か箇ヶヵカケ个個])([0-9]{1,2}|[０-９]{1,2})\s*月(?![0-9０-９])/u';
		preg_match_all( $month_re, $s, $mnm, PREG_SET_ORDER );
		foreach ( $mnm as $mn ) {
			$num_mn = strtr( $mn[1], array( '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9' ) );
			/*
			 * 1〜12 の範囲外も捨てない。
			 *
			 * 捨てると「40月」のような書き間違いが、月名でもなく
			 * 量詞つきの数字でもなくなり、検査の対象から消える ——
			 * 変異テストで「10月」を「40月」に書き換えても闸门が黙り、
			 * 原因はこの continue だった。範囲外はそのまま照合に回せば
			 * ページに無いので誤りとして出る。それが正しい挙動である。
			 */
			$key = $num_mn . '|monthname';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num_mn, 'unit' => 'monthname', 'raw' => $mn[0] );
		}
		$s = preg_replace( $month_re, ' ', $s );

		preg_match_all( '/\bparagraphs?\s+(\d{1,2})\b/iu', $s, $pm, PREG_SET_ORDER );
		foreach ( $pm as $p ) {
			$key = $p[1] . '|paragraph';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $p[1], 'unit' => 'paragraph', 'raw' => $p[0] );
		}
		$s = preg_replace( '/\bparagraphs?\s+\d{1,2}\b/iu', ' ', $s );

		/*
		 * 条文の号番号。英文では「ground (6)」「grounds (3) through (10)」と
		 * 丸括弧で書くのが自然で、出典側は「（６）」「（10）」と全角括弧で書く。
		 *
		 * 括弧つき数字を量詞なしの小さい数として扱うと検査外に落ちる。
		 * 号番号を一つずらすと読者は違う号を読みに行く —— 在留資格の取消事由は
		 * 号ごとに結果が別で、(5) は逃亡のおそれがあれば直ちに退去強制、
		 * (6) は3か月の経過を要する。取り違えは条番号の誤りと同じ重さを持つ。
		 *
		 * 「(1)」が見出しの箇条番号であることもあるが、その場合も出典页に
		 * 同じ号が在るかを見るだけなので、誤検知は「出典に在る」側に倒れる。
		 */
		/*
		 * 複合期間。「1 year 9 months」「2 years and 6 months」。
		 *
		 * 部品ごとに検査すると意味がなくなる —— 「1」は year として「1年」を、
		 * 「9」は month として「9か月」を探すが、千駄ヶ谷の3つの課程ページには
		 * 1年6か月・1年9か月・1年3か月・2年が並んでいるので、
		 * どの部品もどこかに在る。結果、「1年9か月」を「1年6か月」に
		 * 書き換えても闸门は黙る。変異テストで intake-comparison の
		 * 5個の数字すべてがこの理由で素通りしていた。
		 *
		 * 期間はコース選択そのものを決める数字で、入学期ごとに違う。
		 * 1文字の違いが「卒業時に受験に間に合うか」を変える。
		 * 丸ごと一つのトークンとして照合する。
		 */
		/*
		 * 複合期間の CJK 表記。「1年9个月」（中文）「1年9か月」（日文）。
		 *
		 * 英語形だけだと中国語版・日本語版では「1」と「9」に割れ、
		 * 千駄ヶ谷の三課程に 1年6か月・1年9か月・1年3か月 が並んでいるため
		 * どの部品もどこかに在り、全部素通りする。
		 * 変異テストで中国語版の課程年限 4 個すべてがこの理由で漏れていた。
		 */
		$cjk_dur = '/([0-9]{1,2}|[０-９]{1,2})\s*年\s*([0-9]{1,2}|[０-９]{1,2})\s*(?:か月|ヶ月|ヵ月|カ月|ケ月|箇月|个月|個月)/u';
		preg_match_all( $cjk_dur, $s, $cdm, PREG_SET_ORDER );
		foreach ( $cdm as $cd ) {
			$z   = array( '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9' );
			$num = strtr( $cd[1], $z ) . '-' . strtr( $cd[2], $z );
			$key = $num . '|duration';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num, 'unit' => 'duration', 'raw' => $cd[0] );
		}
		$s = preg_replace( $cjk_dur, ' ', $s );

		$dur_re = '/\b(\d{1,2})\s*years?\s*(?:and\s+)?(\d{1,2})\s*months?\b/iu';
		preg_match_all( $dur_re, $s, $dm2, PREG_SET_ORDER );
		foreach ( $dm2 as $dd ) {
			$num = $dd[1] . '-' . $dd[2];
			$key = $num . '|duration';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num, 'unit' => 'duration', 'raw' => $dd[0] );
		}
		$s = preg_replace( $dur_re, ' ', $s );

		/*
		 * 号番号の CJK 表記。「第5项」（中文）「第5項」「第5号」。
		 *
		 * 丸括弧と英語の item しか拾っていなかったため、中国語版では
		 * 取消事由の号番号が全部検査外に落ちていた。号を取り違えると
		 * 読者は自分の状況とは別の帰結を読む（第5項は逃亡のおそれで
		 * 直ちに退去強制、第6項は3か月の経過を要する）。
		 */
		$cjk_item = '/第\s*([0-9０-９]{1,2})\s*[项項号]/u';
		preg_match_all( $cjk_item, $s, $cim, PREG_SET_ORDER );
		foreach ( $cim as $ci ) {
			$num_ci = strtr( $ci[1], array( '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9' ) );
			$key    = $num_ci . '|item';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num_ci, 'unit' => 'item', 'raw' => $ci[0] );
		}
		$s = preg_replace( $cjk_item, ' ', $s );

		$item_re = '/(?:\(\s*(\d{1,2})\s*\)|\bitems?\s+(\d{1,2})\b)/iu';
		preg_match_all( $item_re, $s, $im, PREG_SET_ORDER );
		foreach ( $im as $i ) {
			$num_i = '' !== $i[1] ? $i[1] : ( isset( $i[2] ) ? $i[2] : '' );
			if ( '' === $num_i ) {
				continue;
			}
			$key = $num_i . '|item';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $num_i, 'unit' => 'item', 'raw' => $i[0] );
		}
		$s = preg_replace( $item_re, ' ', $s );

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

		/*
		 * 郵便番号・地番。「810-0001」「4-4-11」。
		 *
		 * 一般の数字抽出に任せると 810 と 0001 に割れ、どちらも
		 * 「3桁以下・量詞なし」または桁数不足で検査外に落ちる ——
		 * 住所は丸ごと検査されないまま通っていた。
		 *
		 * 住所の誤りは読者を別の場所へ行かせる。ハイフンを含む
		 * 7〜9文字のトークンは偶然一致する確率が低く、
		 * 文字列そのものを照合するのが最も確実である。
		 *
		 * 条番号（19-16）と形が衝突するので、条文の抽出より後に置く。
		 */
		$code_re = '/\b(\d{3}\s*-\s*\d{4}|\d{1,4}-\d{1,4}-\d{1,4})\b/u';
		preg_match_all( $code_re, $s, $cm, PREG_SET_ORDER );
		foreach ( $cm as $c ) {
			$norm = preg_replace( '/\s+/', '', $c[1] );
			$key  = $norm . '|code';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $norm, 'unit' => 'code', 'raw' => $c[0] );
		}
		$s = preg_replace( $code_re, ' ', $s );

		/*
		 * 号番号を括弧なしで書く形。「ground 5」「grounds 3 through 10」。
		 *
		 * 在留資格の取消事由は号ごとに帰結が違う —— (5) は逃亡のおそれがあれば
		 * 直ちに退去強制、(6) は3か月の経過を要し、(3)〜(10) は30日以内の出国。
		 * 号を一つ取り違えた記述は、読者に自分の状況とは別の帰結を読ませる。
		 *
		 * 既発表の記事で ground 5 / 6 / 8 / 9 / 3 / 10 が
		 * すべて検査外に落ちていたことが、この告知機構で判明した。
		 */
		$ground_re = '/\bgrounds?\s+(\d{1,2})(?:\s*(?:through|to|and|or|[-–—])\s*(\d{1,2}))?/iu';
		preg_match_all( $ground_re, $s, $gm, PREG_SET_ORDER );
		foreach ( $gm as $g ) {
			foreach ( array( $g[1], isset( $g[2] ) ? $g[2] : '' ) as $gn ) {
				if ( '' === $gn ) {
					continue;
				}
				$key = $gn . '|item';
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array( 'num' => $gn, 'unit' => 'item', 'raw' => 'ground ' . $gn );
			}
		}
		$s = preg_replace( $ground_re, ' ', $s );

		/*
		 * 階数の序数。「the 2nd floor or higher」。
		 * 出典は「2階以上」と書く。避難の判断に関わる記述で検査外にしたくない。
		 */
		/*
		 * 版数の序数形。「the 8th edition」。出典は「第8版」と書く。
		 */
		$ed_re = '/\b(\d{1,2})(?:st|nd|rd|th)\s+edition\b/iu';
		preg_match_all( $ed_re, $s, $em, PREG_SET_ORDER );
		foreach ( $em as $e ) {
			$key = $e[1] . '|edition';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $e[1], 'unit' => 'edition', 'raw' => $e[0] );
		}
		$s = preg_replace( $ed_re, ' ', $s );

		$floor_re = '/\b(\d{1,2})(?:st|nd|rd|th)?\s+floor\b/iu';
		preg_match_all( $floor_re, $s, $fm, PREG_SET_ORDER );
		foreach ( $fm as $f ) {
			$key = $f[1] . '|floor';
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'num' => $f[1], 'unit' => 'floor', 'raw' => $f[0] );
		}
		$s = preg_replace( $floor_re, ' ', $s );

		/*
		 * 小数点を含めて一つのトークンとして拾う。
		 *
		 * 入管庁の在留審査処理期間は「41.0」のように小数第一位まで公表される。
		 * 小数点を含めないと「41」と「0」に割れ、「0」は桁数不足で捨てられ、
		 * 「41」はページ上の「41.0」と隣接判定が合わず——正しい引用が落ちる。
		 *
		 * 平均日数を「41 日」と丸めて書くのも誤りではないが、出典が
		 * 小数第一位まで出しているなら本文もそう書くほうが正確で、
		 * 闸门も通る。丸めを許すと「41.4 を 41 と書く」と
		 * 「41.0 を 41 と書く」が区別できなくなる。
		 */
		preg_match_all( '/\d[\d,]*(?:\.\d+)?/u', $s, $m, PREG_OFFSET_CAPTURE );

		$syn = self::unit_synonyms();

		foreach ( $m[0] as $hit ) {
			$raw    = $hit[0];
			$offset = $hit[1];

			/*
			 * 末尾のカンマは数字の一部ではなく句読点。
			 *
			 * \d[\d,]* は「2026,」まで一つのトークンとして飲む。すると直後の
			 * 文字列が " schools that enrol…" になり、量詞判定が
			 * 「2026 所の学校」と読んでしまう —— 実際に
			 * 「From 2026, schools that enrol international students…」が
			 * 公開済みの記事でこの誤判定を起こし、令和８年として整页照合で
			 * 通っていた数字が落ちた。
			 *
			 * カンマは名詞句を切る。数字と量詞の間に句読点があれば、
			 * その語は量詞ではない。1,728 のような内部のカンマは残す。
			 */
			$raw = rtrim( $raw, ',' );
			if ( '' === $raw ) {
				continue;
			}

			$n = str_replace( ',', '', $raw );

			/*
			 * 数字之后的一小段，用来判定量词（"28 hours" / "28-hour" / "780,000 yen"）。
			 *
			 * 窗口は16文字。当初12文字だったが、「 years of age」が13文字あり
			 * 窓からはみ出していた —— 結果、年齢が「年」の量詞として照合され、
			 * 「under 16 years of age」が「16年」を探しに行って
			 * 正しい記述を誤りと報告した。出典は「１６歳」と書いている。
			 *
			 * 照合は先頭アンカーなので、窓を広げても短い量詞の判定は変わらない。
			 */
			$tail = mb_substr( substr( $s, $offset + strlen( $raw ) ), 0, 16, 'UTF-8' );

			/*
			 * 量詞の候補は長い順に試す。
			 *
			 * キーの宣言順に試すと、短い量詞が長い量詞の頭に当たって先に勝つ。
			 * 実際に起きた：中国語版の「6,000日元」が、day の「日」に当たって
			 * 「6000 日」として核対され、出典に無いと報告された。
			 * yen の「日元」は表に在るのに、day がキー順で先だったために
			 * 出番が無かった。
			 *
			 * 1文字の CJK 量詞には語尾境界という手が無い（単語の区切りが無い）。
			 * だから順序で解く —— 長い一致を優先すれば「日元」が「日」に勝つ。
			 */
			$unit = '';
			foreach ( self::unit_candidates_by_length() as $cand ) {
				$key   = $cand['key'];
				$words = array( $cand['word'] );
				foreach ( $words as $w ) {
					/*
					 * 3文字以下の英字の量詞には語尾境界を要求する。
					 *
					 * 照合は大小文字を無視する前方一致なので、短い英字は
					 * 無関係な語の頭に当たる。実際に起きた：升の量詞に 'L' を
					 * 入れたところ、「the 2027 list adds …」の list に当たり、
					 * 「2027リットル」として核対しようとして、正しい記述が
					 * 2件とも誤りと報告された。
					 *
					 * 対象を短い語に限るのは、長い語の前方一致は有用だから ——
					 * 'year' は「years」に当たってほしい。短い語については
					 * 複数形を表に明示してある（day/days, min/mins）。
					 */
					$boundary = preg_match( '/^[A-Za-z]{1,3}$/', $w ) ? '\b' : '';
					if ( preg_match( '/^[\s\-]*' . preg_quote( $w, '/' ) . $boundary . '/iu', $tail ) ) {
						$unit = $key;
						break 2;
					}
				}
			}

			/*
			 * 量詞が数字の前に来る書き方も拾う。
			 *
			 * 英語は「a capacity of 900」「rent of 40,000 yen」のように
			 * 数える対象を先に言う。後ろだけ見ていると 900 は量詞なしの3桁として
			 * 検査外に落ちる —— 変異テストで 900 を 800 に書き換えても
			 * 闸门が黙っていることを確認した。
			 *
			 * 定員は学校を比べるときに最も見られる数字のひとつで、
			 * 本体の量詞表に「名」「人」を入れたのと同じ理由から、
			 * 英語側の言い方も拾わないと意味がない。
			 *
			 * 先行句は限定列挙にする。量詞表を丸ごと前方にも適用すると、
			 * 「within 40 minutes of 30 buildings」のような文で
			 * 誤った単位が付く方向に倒れる。
			 */
			/*
			 * 区間の下限は、上限の量詞を引き継ぐ。
			 *
			 * 「1 to 3 months」「5 to 40 minutes」「3 to under 5 years」のように、
			 * 英語では量詞を上限の側に一度だけ書く。後ろ12文字しか見ないと
			 * 下限の「1」「5」「3」は量詞なしの1桁として検査外に落ちる。
			 *
			 * これは机上の懸念ではない。「標準処理期間は1か月から3か月」は
			 * 複数の記事の中心的な数字で、告知機構を入れた時点で
			 * 7篇の記事でこの形の下限が一度も核対されていないことが判明した。
			 * 「2 to 3 months」と書き換えても闸门は黙る状態だった。
			 *
			 * 下限と上限で量詞が違う書き方（「from 3 days to 2 weeks」）は
			 * 上限側に量詞が隣接しないので、この規則は発火しない。
			 */
			if ( '' === $unit ) {
				$long_tail = mb_substr( substr( $s, $offset + strlen( $raw ) ), 0, 32, 'UTF-8' );
				if ( preg_match(
					'/^\s*(?:to|or|through|and|[-–—~〜]|から)\s*(?:up\s+to|no\s+more\s+than|at\s+least|less\s+than|fewer\s+than|more\s+than|under|over|about|around|approximately)?\s*'
					. '\d[\d,]*(?:\.\d+)?\s*([^\s\d]{1,8})/u',
					$long_tail,
					$rm
				) ) {
					// 区間の上限側の量詞判定も長い順に試す（上の理由と同じ）。
					foreach ( self::unit_candidates_by_length() as $cand2 ) {
						if ( preg_match( '/^' . preg_quote( $cand2['word'], '/' ) . '/iu', $rm[1] ) ) {
							$unit = $cand2['key'];
							break;
						}
					}
				}
			}

			if ( '' === $unit ) {
				$head = mb_substr( substr( $s, max( 0, $offset - 24 ), min( 24, $offset ) ), -24, null, 'UTF-8' );
				$lead = array(
					'people'  => '(?:capacity|capacities|places|enrolment|enrollment)\s+(?:of|for|:)?\s*$',
					'yen'     => '(?:rent|fee|fees|tuition|cost|price)\s+(?:of|from|:)?\s*$',
					'percent' => '(?:rate|ratio)\s+(?:of|:)?\s*$',
					/*
					 * 「aged 1 and over」「aged 16 or older」。年齢は語の前に置く
					 * 言い方が英語では自然で、後ろだけ見ると量詞なしの小さい数に落ちる。
					 */
					'age'     => '(?:aged|age\s+of)\s+$',
					'chapter' => '(?:chapters?)\s+$',
					'rule'    => '(?:rules?)\s+$',
					/*
					 * under / over は入れない。「over 3 and up to 6 months」の 3 を
					 * 年齢と読んで「3歳」を探しに行き、正しい記述を誤りと報告する。
					 * 年齢であることが語そのもので分かる形だけに限る。
					 */
					/*
					 * 電話番号。「dial 119」「call 110」の形で拾う。
					 *
					 * 119 は3桁・量詞なしなので、入れなければ検査外に落ちる。
					 * ところが緊急通報の番号を一桁書き間違えることは、
					 * この闸门が防ぎうる誤りのうち最も害が大きい ——
					 * 読者がその番号にかけるのは、かけ直す余裕がない場面である。
					 * 「小さい数だから検査しない」の例外として扱う。
					 */
					/*
					 * 「save 119 and 110 in your phone」のような言い方も拾う。
					 * dial / call だけに限っていたため、保存を促す文で
					 * 緊急通報番号が検査外に落ちていた（告知機構で判明）。
					 */
					'phone'   => '(?:dial|dialling|dialing|call|calls|calling|ring|save|saving|store|memorise|memorize)\s+(?:\d{2,4}\s*(?:and|or|,|、)\s*)*$',
				);
				foreach ( $lead as $key => $re ) {
					if ( preg_match( '/' . $re . '/iu', $head ) ) {
						$unit = $key;
						break;
					}
				}
			}

			/*
			 * 收录条件：
			 *   有量词          —— 一律核对，哪怕只有两位数（28 小时正是这种）
			 *   无量词且 ≥4 位  —— 核对，位数够多时偶然撞上的概率低
			 *   无量词且 ≤3 位  —— 跳过。这类多是列表序号、章节号，
			 *                      逐个核对只会制造噪音，而噪音会让人开始忽略告警
			 *
			 * ただし、この「跳过」を黙って行わない。
			 *
			 * 「the graduate-school course 900」と書くと、900 は量詞を伴わない3桁
			 * なのでここで落ち、一度も核対されないまま闸门を通る —— 変異テストで
			 * 940 に書き換えても何も起きないことを確認した。書き手の側からは
			 * 「核対されて通った」と「検査されずに通った」が同じ沈黙に見える。
			 *
			 * 落とした数字は呼び出し側に渡し、出典を引いている文に限って告知する。
			 * 闸门がどこまで保証しているかを書き手に見せるためで、通過は妨げない。
			 * 直し方は量詞を書くこと（「a capacity of 900」）——
			 * そのほうが読者にとっても読みやすい。
			 */
			if ( '' === $unit && strlen( $n ) < 4 ) {
				if ( is_array( $skipped ) ) {
					$skipped[ $raw ] = true;
				}
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

		/*
		 * 条文番号。和文の出典は「第２２条の４」「第１９条第２項」と書く。
		 * 半角・全角の両方を試す（官公庁サイトは全角が主）。
		 */
		/*
		 * 号番号。出典側の書き方は割れる ——
		 *   （５）  全角括弧＋全角数字（官公庁サイトの既定）
		 *   （10）  全角括弧＋半角数字（2桁になると混在する。取消事由の页が実際そう）
		 *   (5)     半角括弧
		 *   第五号 / 第5号  条文を引用する文脈
		 */
		/*
		 * 郵便番号・地番。半角・全角の両方と、全角ハイフン（－）を試す。
		 * 官公庁・学校サイトは「１６９－００７５」と全角で書くことがある。
		 */
		if ( 'code' === $unit ) {
			$hay   = str_replace( array( ' ', '　' ), '', $page );
			$forms = array(
				$num,
				self::to_fullwidth( $num ),
				str_replace( '-', '－', $num ),
				str_replace( '-', '－', self::to_fullwidth( $num ) ),
			);
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
			}
			return false;
		}

		/*
		 * 階数。出典は「2階」。「以上」が続くかどうかは問わない。
		 */
		/*
		 * 則番号。出典は「第２則」と全角で書く。量詞表の「則」だけでは
		 * 「2」と「則」が隣接しないので（間に「第」が無く、順序も逆）、
		 * 条番号と同じく形を組み立てて照合する。
		 */
		/*
		 * 複合期間。出典は「1年9か月」と書く。か月の表記は割れるので全部試す。
		 */
		if ( 'duration' === $unit ) {
			list( $yy, $mm2 ) = explode( '-', $num, 2 );
			$hay   = str_replace( array( ' ', "\u{3000}" ), '', $page );
			$forms = array();
			foreach ( array( 'か月', 'ヶ月', 'ヵ月', '箇月', 'カ月', 'ケ月', '月' ) as $mu ) {
				foreach ( array( array( $yy, $mm2 ), array( self::to_fullwidth( $yy ), self::to_fullwidth( $mm2 ) ) ) as $pair ) {
					$forms[] = $pair[0] . '年' . $pair[1] . $mu;
				}
			}
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
			}
			return false;
		}

		/*
		 * 月名。出典も「4月」「１０月」と書くので素の一致でよい。
		 */
		if ( 'monthname' === $unit ) {
			$hay = str_replace( array( ' ', "\u{3000}" ), '', $page );
			foreach ( array( $num . '月', self::to_fullwidth( $num ) . '月' ) as $f ) {
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'rule' === $unit ) {
			$hay = str_replace( array( ' ', '\u{3000}' ), '', $page );
			foreach ( array( '第' . $num . '則', '第' . self::to_fullwidth( $num ) . '則' ) as $f ) {
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'floor' === $unit ) {
			$hay = str_replace( array( ' ', '　' ), '', $page );
			foreach ( array( $num . '階', self::to_fullwidth( $num ) . '階' ) as $f ) {
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'item' === $unit ) {
			$fw    = self::to_fullwidth( $num );
			$forms = array(
				'（' . $fw . '）',
				'（' . $num . '）',
				'(' . $num . ')',
				'(' . $fw . ')',
				'第' . $num . '号',
				'第' . $fw . '号',
			);

			$hay = str_replace( ' ', '', $page );
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'article' === $unit || 'paragraph' === $unit ) {
			$forms = array();
			if ( 'paragraph' === $unit ) {
				$forms[] = '第' . $num . '項';
				$forms[] = '第' . self::to_fullwidth( $num ) . '項';
			} elseif ( false !== strpos( $num, '-' ) ) {
				list( $main, $sub ) = explode( '-', $num, 2 );
				$forms[] = '第' . $main . '条の' . $sub;
				$forms[] = '第' . self::to_fullwidth( $main ) . '条の' . self::to_fullwidth( $sub );
				$forms[] = '第' . $main . '条の' . self::to_fullwidth( $sub );
				$forms[] = '第' . self::to_fullwidth( $main ) . '条の' . $sub;
			} else {
				$forms[] = '第' . $num . '条';
				$forms[] = '第' . self::to_fullwidth( $num ) . '条';
			}

			$hay = str_replace( ' ', '', $page );
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $hay, str_replace( ' ', '', $f ) ) ) {
					return true;
				}
			}
			return false;
		}

		/*
		 * 日付。和暦の形に組み立てて照合する。
		 * 西暦で書かれている場合もあるので、そちらも試す。
		 */
		/*
		 * 年月（日を伴わない）。和暦・西暦の両方の形を試す。
		 * 日付と違い、月までしか無いので回退も無い ——
		 * 「◯月」だけでの照合は年を捨てることになり、年こそが論点だから。
		 */
		if ( 'yearmonth' === $unit ) {
			list( $yr, $mon ) = array_map( 'intval', explode( '-', $num ) );

			$forms = array();
			$reiwa = $yr - 2018;
			if ( $reiwa >= 1 ) {
				$forms[] = '令和' . $reiwa . '年' . $mon . '月';
				if ( 1 === $reiwa ) {
					$forms[] = '令和元年' . $mon . '月';
				}
			}
			$heisei = $yr - 1988;
			if ( $heisei >= 1 && $heisei <= 31 ) {
				$forms[] = '平成' . $heisei . '年' . $mon . '月';
			}
			$forms[] = $yr . '年' . $mon . '月';

			$hay = str_replace( ' ', '', $page );
			foreach ( array_unique( $forms ) as $f ) {
				$f = str_replace( ' ', '', $f );
				if ( false !== strpos( $hay, $f ) || false !== strpos( $hay, self::to_fullwidth( $f ) ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'date' === $unit ) {
			list( $yr, $mon, $day ) = array_map( 'intval', explode( '-', $num ) );

			$forms = array();
			$eras  = array();
			$reiwa = $yr - 2018;
			if ( $reiwa >= 1 ) {
				$eras[] = '令和' . $reiwa;
				if ( 1 === $reiwa ) {
					$eras[] = '令和元';
				}
			}
			$heisei = $yr - 1988;
			if ( $heisei >= 1 && $heisei <= 31 ) {
				$eras[] = '平成' . $heisei;
			}
			$eras[] = (string) $yr;

			foreach ( $eras as $e ) {
				$forms[] = $e . '年' . $mon . '月' . $day . '日';
			}

			$hay = str_replace( ' ', '', $page );
			foreach ( array_unique( $forms ) as $f ) {
				$f = str_replace( ' ', '', $f );
				if ( false !== strpos( $hay, $f ) ) {
					return true;
				}
				if ( false !== strpos( $hay, self::to_fullwidth( $f ) ) ) {
					return true;
				}
			}

			/*
			 * 年を伴わない「月日」だけの形も次級証拠として認める。ただし条件付き。
			 *
			 * 日本語の文章は、文脈で年が分かるときに年を省く ——
			 * 手数料改定の注記は「２０２６年９月３０日までに受付した申請については、
			 * 当該申請に係る許可が１０月１日以降となっても」と書き、
			 * 二つ目の日付に年が付かない。
			 * 年月日が揃った形だけを探すと、同じ一文の中の片方しか照合できない。
			 *
			 * ところがこの回退を無条件にすると、年を間違えても通る。
			 * 変異テストで実際に起きた：出典（入管庁の通知）は
			 * 「令和１１年３月３１日」と年込みで書いているのに、
			 * 本文を「31 March 2030」に改竄しても、ページ上の
			 * 「３月３１日」の部分が当たって素通りした。
			 * 年が違えば別の日付であって、しかも制度の期限のような数字では
			 * 年こそが論点になる。
			 *
			 * そこで、その月日が「年付きで」ページに現れているなら、
			 * 出典は年を明示しているということなので回退を認めない。
			 * 年を省いた出現しか無いときだけ、弱い証拠として受ける。
			 */
			$md    = $mon . '月' . $day . '日';
			$md_fw = self::to_fullwidth( $md );

			/*
			 * 年の桁数は1〜4。和暦は一桁が普通である（令和６年、令和8年）。
			 *
			 * 当初ここを {2,4} と書いていた。西暦四桁しか頭に無かったためで、
			 * そのせいで「令和６年１２月２日」が年付きと判定されず、
			 * 回退が走って 2022年12月2日 という誤った年が素通りした
			 * （変異テストで確認）。年を見張るための判定が、
			 * 日本の年表記を見落としていた。
			 *
			 * 「令和元年」のように数字を使わない表記も年付きとして扱う。
			 */
			$year_qualified = preg_match(
				'/(?:\d{1,4}|[０-９]{1,4}|元)\s*年\s*(?:' . preg_quote( $md, '/' ) . '|' . preg_quote( $md_fw, '/' ) . ')/u',
				$hay
			);
			if ( $year_qualified ) {
				return false;
			}

			if ( false !== strpos( $hay, $md ) || false !== strpos( $hay, $md_fw ) ) {
				return true;
			}

			return false;
		}

		/*
		 * 電話番号。日本語の資料は「１１９番」と書く（全角・「番」付き）。
		 * 番 を伴う形を先に探し、無ければ素の数字列でも認める
		 * （英語併記のページは 119 とだけ書くこともある）。
		 */
		if ( 'phone' === $unit ) {
			/*
			 * 「番」を必須にする。素の数字列は認めない。
			 *
			 * 当初は英語併記ページ向けに素の数字も候補に入れていたが、
			 * それは 157 ページの冊子を出典にした途端に無意味になる ——
			 * ページ番号だけで1〜157が全部「ページ上に存在する」ことになり、
			 * 変異テストで 119→118、110→112、118→117 がすべて素通りした。
			 *
			 * 緊急通報の番号は、この闸门が防ぎうる誤りの中で最も害が大きい。
			 * 日本語の官公庁資料は必ず「１１９番」と書くので、
			 * 「番」を要求すれば干し草の山の大きさに関係なく効く。
			 * 英語のみのページを出典にしたい場合は、そのページを
			 * 個別に見て判断する —— 緩い照合で通すより落ちるほうがよい。
			 */
			$hay   = str_replace( array( ' ', '-' ), '', $page );
			$forms = array(
				$num . '番',
				self::to_fullwidth( $num ) . '番',
			);
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $hay, str_replace( ' ', '', $f ) ) ) {
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

		/*
		 * 千位区切りを打った形も候補にする（1200000 → 1,200,000）。
		 *
		 * ctype_digit で整数に限る。これが無いと number_format が小数を
		 * 四捨五入し、別の数量を候補に加えてしまう ——
		 *     number_format( 0.25 ) === '0'
		 *     number_format( 1.5 )  === '2'
		 * 候補「0」は量詞の12文字以内にある任意の 0 と隣接するので、
		 * ページに存在しない小数が核対を通る。実測で確認した：
		 * 呼気アルコール濃度を 0.15 から 0.25 に書き換えても闸门が黙り、
		 * 原因は候補リストに混入した「0」だった。
		 *
		 * 放行の方向に倒れる誤りなので、小数は小数のまま扱う。
		 * 小数側も、整数部が4桁以上なら区切り形を作る（12345.6 → 12,345.6）。
		 */
		if ( strlen( $num ) > 3 ) {
			if ( ctype_digit( $num ) ) {
				$candidates[] = number_format( (float) $num );
			} elseif ( preg_match( '/^(\d+)\.(\d+)$/', $num, $dm ) && strlen( $dm[1] ) > 3 ) {
				$candidates[] = number_format( (float) $num, strlen( $dm[2] ) );
			}
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
			$man = (string) ( (int) ( $v / 10000 ) );
			$candidates[] = $man . '万';
			/*
			 * 全角版も作る。官公庁の罰則表は「５万円以下」と全角で書く ——
			 * 半角の「5万」しか候補に無かったため、ガイドブックに
			 * そのまま載っている額が「見つからない」と報告された。
			 */
			$candidates[] = self::to_fullwidth( $man ) . '万';
		}

		// 全角。
		$candidates[] = self::to_fullwidth( $num );

		/*
		 * 全角の千位区切り形。
		 *
		 * 入管法の手数料表は「１０，０００円」と書く —— 数字もカンマも全角。
		 * 既存の候補は「10,000」（半角カンマ）と「１００００」（区切り無し全角）
		 * しか作らないので、どちらにも当たらない。
		 * 手数料のような、読者が実際に財布から出す金額が核対外に落ちていた。
		 */
		if ( ctype_digit( $num ) && strlen( $num ) > 3 ) {
			$candidates[] = strtr(
				number_format( (float) $num ),
				array(
					'0' => '０', '1' => '１', '2' => '２', '3' => '３', '4' => '４',
					'5' => '５', '6' => '６', '7' => '７', '8' => '８', '9' => '９',
					',' => '，',
				)
			);
		}

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

		/*
		 * 横方向の空白を除いた版も試す。
		 *
		 * PDF を pdftotext -layout で抜くと、組版上の位置合わせがそのまま
		 * 空白として残る。ガイドブックの罰則表は「100 万円以下」と
		 * 数字と万の間に空白が入っており、候補「100万」が一致しなかった ——
		 * 「1,000,000 yen」と正しく書いた記述が、出典にその額が載っている
		 * のに誤りとして報告された。
		 *
		 * 削るのは「数字の直後の空白」だけにする。全部削ってはいけない。
		 *
		 * 当初は横方向の空白を一律に削っていた。「改行は残すから行をまたがない」
		 * と書いたが、それは誤りだった —— fetch() の時点で \s+ は単一空白に
		 * 畳まれており、改行はもう残っていない。つまり一律に削ると
		 * ページ全体が一本の文字列になり、12文字の窓が、本来は空白で
		 * 隔てられていた無関係な箇所をまたいでしまう。
		 *
		 * 実測で露出した：千駄ヶ谷の課程ページで「2年」を「5年」に
		 * 書き換えても闸门が黙る。ページ上の「5年」は「2025年」の一部だけで、
		 * 数字の直前が数字なので後読みが弾くはずだった。
		 * 同じ文面なのに sji では false、sjs と sls では TRUE になり、
		 * 差が出たこと自体がこの経路の存在を示していた。
		 *
		 * 数字の直後の空白だけを削れば、狙っていた組版の崩れ
		 * （「100 万円」「0.15 ㎎」）は拾え、離れた箇所の連結は起きない。
		 */
		$page_tight = preg_replace( '/([0-9０-９,，])[ \t\x{3000}]+(?=[^\x00-\x7F])/u', '$1', $page );

		/*
		 * 隣接の判定には二つの条件を課す。
		 *
		 * 一つ目：数字の直前に別の数字があってはならない（後読み）。
		 * これが無いと、本文の「5 years」がページ上の「2025年」に当たる ——
		 * 「2025年」は部分文字列として「5年」を含むからである。
		 * 実測した：千駄ヶ谷の課程ページで「2 years」を「5 years」に
		 * 書き換えても闸门が黙り、原因は各ページに一度だけ現れる
		 * 「2025年」だった。年号・西暦は官公庁と学校のページに必ず在るので、
		 * この穴は1桁の年数すべてに開いていた。
		 *
		 * 二つ目：数字と量詞の間に数字を挟んではならない。
		 * 「.{0,12}」のままだと「52年」を探すときに
		 * 「5」+「2」+「年」で当たってしまう。間に入れるのは
		 * 助詞・記号・空白までとする（「1週について28時間」は通る）。
		 */
		$gap = '[^\d０-９]{0,12}?';

		/*
		 * 区間の下限に対する例外。
		 *
		 * 出典側も量詞を上限にしか書かないことがある ——
		 * 赤門会の学生寮ページは「校舎から5～40分の距離に」と書く。
		 * 下限の 5 と「分」の間には上限の「40」が挟まっており、
		 * 数字を挟めないという規則だけでは、正しい記述が落ちる。
		 * 実測で落ちた（この例外を入れる前の版が dorm-vs-apartment を誤判定した）。
		 *
		 * 許すのは「数字・区間を表す記号・数字・量詞」という形だけ。
		 * 区切りを任意の非数字にすると穴になる —— 実測で、任意文字を許した版は
		 * 「2 years」を「5 years」に書き換えても通してしまった。
		 * 区間記号に限定すれば、下限の読み方としてしか成立しない。
		 */
		$range_sep = '(?:\s*(?:[~〜～\-–—]|から|to|・)\s*)';
		$range_gap = $range_sep . '[\d０-９][\d,０-９]*' . $gap;

		/*
		 * 量詞を内包した候補は、それ自体が完結した表記なので
		 * 隣接をさらに求めてはいけない。
		 *
		 * 和暦の候補がまさにそれ。「2026年」と書くと量詞は year になり、
		 * 候補には「令和8年」が入る。ところが隣接判定は
		 * 「令和8年」のあとにもう一つ『年』を探しに行くので、
		 * 出典に令和8年とそのまま載っていても見つからない。
		 *
		 * 英語版は「1 October 2026」のように日付トークンの経路を通るため
		 * この穴に当たらず、中国語版で「2026年」と書いて初めて露出した。
		 * 令和8年という文字列は単独で一意なので、素の一致で足りる。
		 */
		foreach ( $candidates as $c ) {
			foreach ( $words as $w ) {
				if ( false === mb_strpos( $c, $w ) ) {
					continue;
				}
				foreach ( array( $page, $page_tight ) as $hay_v ) {
					if ( false !== mb_strpos( $hay_v, $c ) ) {
						return true;
					}
				}
			}
		}

		foreach ( $candidates as $c ) {
			$n_q = '(?<![\d０-９])' . preg_quote( $c, '/' );
			foreach ( array( $page, $page_tight ) as $hay_v ) {
				// 数字 → 量词
				if ( preg_match( '/' . $n_q . $gap . '(' . $unit_alt . ')/iu', $hay_v ) ) {
					return true;
				}
				/*
				 * 量词 → 数字。ただし「ラベル：値」の形だけに限る。
				 *
				 * この向きは「hours: 28」「定員：900」のような、量詞を先に
				 * 書く表記のために入れてある。ところが区切りを問わないと、
				 * CJK では日付が引っかかる —— 「年」の直後の数字は、
				 * 日本語ではほぼ常に日付の別のフィールド（月）である。
				 *
				 * 実測で露出した：千駄ヶ谷の課程ページで「2年」を「5年」に
				 * 書き換えても通る。当たっていたのは
				 *     （2026年5月1日現在）
				 * の「年」＋「5」だった。年数の検査が、日付の月の数字で
				 * 満たされていたことになる。
				 *
				 * 区切り（：: ＝=）を要求すれば、意図した表記は拾えて
				 * 日付は拾わない。
				 */
				if ( preg_match( '/(' . $unit_alt . ')\s*[:：=＝]\s*' . $n_q . '/iu', $hay_v ) ) {
					return true;
				}
				// 数字 → 上限の数字 → 量词（区間の下限）
				if ( preg_match( '/' . $n_q . $range_gap . '(' . $unit_alt . ')/iu', $hay_v ) ) {
					return true;
				}
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
		/*
		 * 在留期間の「３月」。
		 *
		 * 日本の法令文は期間を「か月」ではなく「月」と書く ——
		 * 資格外活動のページは「「３月」の在留期間が決定された場合を除く」。
		 * 量詞表に裸の「月」を足せば拾えるが、それはやってはいけない：
		 * 本文の「3 months」がページ上の「3月」（三月＝March）に当たってしまい、
		 * 誤放行の方向に倒れる。1〜12 はすべて月名と衝突する。
		 *
		 * 代わりに、法令文がこの用法で必ず使うかぎ括弧付きの形だけを認める。
		 * 誤放行には「ページがかぎ括弧付きで『N月』と書いており、かつ
		 * それが月名の意味である」ことが必要で、その組み合わせは実際には起きにくい。
		 * 全角数字と全角かぎ括弧の両方を試す。
		 */
		if ( 'month' === $unit ) {
			$hay   = str_replace( ' ', '', $page );
			$fw    = self::to_fullwidth( $num );
			$forms = array(
				'「' . $num . '月」',
				'「' . $fw . '月」',
				$num . '月の在留期間',
				$fw . '月の在留期間',
			);

			/*
			 * 「３月以下」「３月超」「６月以上」のような、
			 * 比較語を伴う形も期間として認める。
			 *
			 * 手数料表は許可期間を「３月以下／３月超６月以下／６月超１年未満」と
			 * 刻む。ここでの「月」は月名ではなく期間 —— 月名なら
			 * 「３月以下」ではなく「３月末まで」と書く。
			 * 以下・超・以上・未満が後続する形は月名と衝突しないので、
			 * かぎ括弧付きと同じ扱いで認めてよい。
			 */
			foreach ( array( '以下', '超', '以上', '未満' ) as $cmp ) {
				$forms[] = $num . '月' . $cmp;
				$forms[] = $fw . '月' . $cmp;
			}
			foreach ( array_unique( $forms ) as $f ) {
				if ( false !== strpos( $hay, str_replace( ' ', '', $f ) ) ) {
					return true;
				}
			}
		}

		if ( strlen( $num ) >= 5 ) {
			$hay = str_replace( ',', '', $page );
			foreach ( $candidates as $c ) {
				if ( false !== strpos( $hay, str_replace( ',', '', $c ) ) || false !== strpos( $page, $c ) ) {
					return true;
				}
			}
		}

		/*
		 * 4桁も、千位区切りのカンマ付きの形でページに在れば次級証拠と認める。
		 *
		 * 厚労省の地域別最低賃金一覧は単位を表頭（【円】）に一度だけ書き、
		 * 各行は「東京 1,280 （ 1,226 ) 54 4.4% 令和8年10月1日」で、
		 * 数字の隣に「円」が無い。ISI の学費表（単位：日本円）と同じ構造で、
		 * 隣接だけを見ると一次資料そのままの数字が全部落ちる。
		 *
		 * 5桁という既存の閾値を下げるのではなく、カンマ付きという形を要求する。
		 * 「1,280」という文字列は千位区切りが打たれた数量であって、
		 * 電話番号や郵便番号や条番号には現れない —— 当初の誤放行（℡045-370-9755 が
		 * 「45 hours」を通した）は区切りなしの数字列だったから起きた。
		 * カンマを落として突き合わせる既存ルールと違い、ここでは落とさない。
		 * 形が証拠なので、形を崩したら意味がない。
		 *
		 * 3桁以下は対象外。1,280 は千位区切りがあり得るが 280 には無く、
		 * 小さい数字に次級証拠を与えると誤放行の方向に倒れる。
		 */
		if ( strlen( $num ) >= 4 && false === strpos( $num, '.' ) && ctype_digit( $num ) ) {
			$grouped = number_format( (float) $num );
			if ( false !== strpos( $grouped, ',' ) && false !== strpos( $page, $grouped ) ) {
				return true;
			}
		}

		/*
		 * 小数も整页出現性を次級証拠として認める（有効数字 3 桁以上）。
		 *
		 * 理由は 5 桁整数と同じで、形が特徴的だから。入管庁の処理期間 PDF は
		 *     留学  41.0  45.4  32.4  57.9  46.1
		 * という行で、「日数」は何行も上の見出しにしかない。
		 * 隣接だけを見ると、一次資料にそのまま載っている数字が落ちる。
		 *
		 * ただし有効数字 2 桁（1.5 など）は除く —— 「1.5年コース」のような
		 * 無関係な小数はページ上にいくらでもあり、誤放行の方向に倒れる。
		 * 3 桁（41.0）なら偶然の衝突は受け入れられる水準。
		 */
		if ( false !== strpos( $num, '.' ) ) {
			$digits = preg_replace( '/\D/', '', $num );
			if ( strlen( $digits ) >= 3 && false !== strpos( str_replace( ',', '', $page ), $num ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 取得したバイト列が PDF かどうか。
	 *
	 * Content-Type だけで判断しない。moj.go.jp の PDF は
	 * application/pdf を返すが、サーバの設定次第で
	 * application/octet-stream になることもあるので、
	 * 先頭の %PDF- マジックも見る。
	 *
	 * @param string $raw   レスポンス本体。
	 * @param string $ctype Content-Type ヘッダ。
	 * @return bool
	 */
	private static function looks_like_pdf( $raw, $ctype ) {
		if ( false !== stripos( (string) $ctype, 'application/pdf' ) ) {
			return true;
		}
		return 0 === strncmp( (string) $raw, '%PDF-', 5 );
	}

	/**
	 * PDF からテキストを取り出す。pdftotext が無ければ null。
	 *
	 * 自前で PDF をパースはしない。本文は FlateDecode で圧縮されていて、
	 * 正しく解くには結局 PDF の構文解析が必要になる。闸门にその責任を
	 * 持たせると、闸门自身がバグの出どころになる —— 核対する側が
	 * 信用できないなら核対の意味がない。
	 *
	 * @param string $raw PDF のバイト列。
	 * @return string|null テキスト、または pdftotext が使えないとき null。
	 */
	private static function pdf_to_text( $raw, $pages = '' ) {
		static $available = null;

		if ( null === $available ) {
			$probe     = array();
			$rc        = 0;
			@exec( 'command -v pdftotext 2>/dev/null', $probe, $rc );
			$available = ( 0 === $rc && ! empty( $probe ) );
		}

		if ( ! $available ) {
			return null;
		}

		$tmp = tempnam( sys_get_temp_dir(), 'sa-pdf-' );
		if ( false === $tmp || false === file_put_contents( $tmp, $raw ) ) {
			if ( false !== $tmp ) {
				@unlink( $tmp );
			}
			return null;
		}

		$out = array();
		$rc  = 0;

		/*
		 * ページ範囲の指定があれば、その範囲だけを抜く。
		 *
		 * なぜ必要か —— 生活・就労ガイドブックは157ページ・約14万字あり、
		 * この規模では「数字がページ上に在る」という証拠がほぼ無意味になる。
		 * 実測した：自転車の罰則を「30万円」から「10万円」に書き換えても
		 * 闸门が黙る。どちらの額も同じ表の別の行に載っているからである。
		 *
		 * 章を指定して抜けば、干し草の山が数千字に縮み、隣接判定が
		 * ふたたび意味を持つ。冊子を出典にする記事では pages を必ず書く。
		 */
		$range = '';
		if ( '' !== $pages ) {
			if ( preg_match( '/^(\d{1,4})\s*-\s*(\d{1,4})$/', $pages, $pm ) ) {
				$range = '-f ' . (int) $pm[1] . ' -l ' . (int) $pm[2] . ' ';
			} elseif ( preg_match( '/^(\d{1,4})$/', $pages, $pm ) ) {
				$range = '-f ' . (int) $pm[1] . ' -l ' . (int) $pm[1] . ' ';
			}
		}

		// -layout は表の列並びを保つ。崩すと行と数字の対応が読めなくなる。
		@exec( 'pdftotext ' . $range . '-layout -enc UTF-8 ' . escapeshellarg( $tmp ) . ' - 2>/dev/null', $out, $rc );
		@unlink( $tmp );

		if ( 0 !== $rc ) {
			return null;
		}

		return implode( "\n", $out );
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
