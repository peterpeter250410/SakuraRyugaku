<?php
/**
 * Claude Messages API 客户端。
 *
 * 为什么不用官方 PHP SDK（anthropic-ai/sdk）：
 *   该包 require "php": "^8.1"（v0.51.0 实测），本站生产环境是 PHP 7.4，
 *   装不上也跑不了。这是硬约束，不是取舍。
 *
 *   因此这里用 cURL 直连 REST 端点。整个项目里只有这一个文件碰 HTTP，
 *   等哪天生产升到 8.1+，把这个类的内部换成 SDK 调用即可，
 *   调用方（pipeline.php 与各闸门）一行都不用改。
 *
 * 用法：
 *   $llm = new SA_LLM();                       // 从环境变量读 key
 *   $text = $llm->complete( $system, $user );  // 返回纯文本
 *
 * 环境变量：
 *   ANTHROPIC_API_KEY   必需
 *   SA_LLM_MODEL        可选，默认 claude-opus-5
 *
 * @package StudyAbroadContent
 */

if ( ! defined( 'SA_CONTENT_DIR' ) ) {
	exit( "must be loaded by pipeline\n" );
}

class SA_LLM {

	/** API 端点。 */
	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * API 版本头。
	 *
	 * 这是 Messages API 的版本标识，不是模型版本，不要跟着模型一起改。
	 */
	const API_VERSION = '2023-06-01';

	/** @var string */
	private $api_key;

	/** @var string */
	private $model;

	/** @var int 单次请求超时（秒）。思考型模型在长文任务上确实会跑很久。 */
	private $timeout;

	/** @var array<int,array<string,mixed>> 累计用量，供 pipeline 汇报成本。 */
	private $usage_log = array();

	/**
	 * @param string|null $api_key 留空则读 ANTHROPIC_API_KEY。
	 * @param string|null $model   留空则读 SA_LLM_MODEL，再默认 claude-opus-5。
	 * @param int         $timeout 超时秒数。
	 *
	 * @throws RuntimeException 未配置 API key 时。
	 */
	public function __construct( $api_key = null, $model = null, $timeout = 900 ) {
		$key = null !== $api_key ? $api_key : getenv( 'ANTHROPIC_API_KEY' );
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			throw new RuntimeException(
				"未配置 ANTHROPIC_API_KEY。\n"
				. "  export ANTHROPIC_API_KEY='sk-ant-...'\n"
				. '然后重新运行。'
			);
		}

		$this->api_key = trim( $key );

		$m = null !== $model ? $model : getenv( 'SA_LLM_MODEL' );
		// 默认模型集中在这一处。改模型改这里，不要散落在各 prompt 调用点。
		$this->model = ( is_string( $m ) && '' !== trim( $m ) ) ? trim( $m ) : 'claude-opus-5';

		$this->timeout = max( 60, (int) $timeout );
	}

	/**
	 * 发一轮对话，返回拼接后的文本。
	 *
	 * @param string $system     system prompt。
	 * @param string $user       user 消息。
	 * @param int    $max_tokens 输出上限。
	 * @return string 所有 text block 拼接的结果。
	 *
	 * @throws RuntimeException 请求失败、被拒绝、或输出被截断时。
	 */
	public function complete( $system, $user, $max_tokens = 16000 ) {
		$payload = array(
			'model'      => $this->model,
			'max_tokens' => (int) $max_tokens,
			'system'     => (string) $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => (string) $user ),
			),
			/*
			 * adaptive thinking：由模型自行决定思考深度。
			 *
			 * 不写 budget_tokens —— 该参数在 Opus 5 上已移除，传了会 400。
			 * display 保持默认（omitted）：我们不需要读思考过程，
			 * 拿回来只是白白占用解析与日志空间。
			 */
			'thinking'   => array( 'type' => 'adaptive' ),
		);

		$resp = $this->request( $payload );

		/*
		 * stop_reason 必须在读 content 之前检查。
		 *
		 * refusal 是 HTTP 200 —— 不检查的话会把一段空内容或拒绝说明
		 * 当成正常稿件继续往下走，最后发布出去。
		 */
		$stop = isset( $resp['stop_reason'] ) ? $resp['stop_reason'] : '';

		if ( 'refusal' === $stop ) {
			$cat = isset( $resp['stop_details']['category'] ) ? $resp['stop_details']['category'] : 'unknown';
			throw new RuntimeException( "模型拒绝了这次请求（category: {$cat}）。" );
		}

		if ( 'max_tokens' === $stop ) {
			// 截断的稿子是半句话，绝不能当成成品。
			throw new RuntimeException(
				"输出在 max_tokens={$max_tokens} 处被截断，内容不完整。请调高上限或拆分任务。"
			);
		}

		$text = '';
		if ( isset( $resp['content'] ) && is_array( $resp['content'] ) ) {
			foreach ( $resp['content'] as $block ) {
				// 只取 text；thinking block 在 display=omitted 下本就是空的。
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			throw new RuntimeException( '模型返回了空内容。' );
		}

		return $text;
	}

	/**
	 * 要求模型返回 JSON，并解析。
	 *
	 * 不用 output_config.format：那需要预先定义 schema，而这里各 prompt
	 * 返回的结构差异较大，逐个写 schema 收益不抵成本。改为在 prompt 里
	 * 明确要求纯 JSON，并在此处做容错解析（剥掉可能的 ``` 包裹）。
	 *
	 * @param string $system     system prompt。
	 * @param string $user       user 消息。
	 * @param int    $max_tokens 输出上限。
	 * @return array 解析后的数组。
	 *
	 * @throws RuntimeException 解析失败时（附带原始返回，便于排查）。
	 */
	public function complete_json( $system, $user, $max_tokens = 16000 ) {
		$raw = $this->complete( $system, $user, $max_tokens );

		$s = trim( $raw );

		// 剥掉 ```json ... ``` 包裹：即使 prompt 说了只要 JSON，也偶尔会带。
		if ( 0 === strpos( $s, '```' ) ) {
			$s = preg_replace( '/^```[a-zA-Z]*\s*/', '', $s );
			$s = preg_replace( '/\s*```\s*$/', '', $s );
		}

		$data = json_decode( $s, true );

		if ( ! is_array( $data ) ) {
			throw new RuntimeException(
				'期望 JSON，实际拿到：' . PHP_EOL . substr( $raw, 0, 800 )
			);
		}

		return $data;
	}

	/**
	 * 本次进程累计用量。
	 *
	 * @return array{input:int,output:int,calls:int}
	 */
	public function usage_total() {
		$in  = 0;
		$out = 0;
		foreach ( $this->usage_log as $u ) {
			$in  += $u['input'];
			$out += $u['output'];
		}
		return array( 'input' => $in, 'output' => $out, 'calls' => count( $this->usage_log ) );
	}

	/**
	 * 发送请求，带针对可重试错误的退避重试。
	 *
	 * @param array $payload 请求体。
	 * @return array 解析后的响应。
	 *
	 * @throws RuntimeException 不可重试、或重试耗尽时。
	 */
	private function request( array $payload ) {
		$body = wp_json_encode_compat( $payload );

		$attempt  = 0;
		$max_try  = 4;
		$last_err = '';

		while ( $attempt < $max_try ) {
			$attempt++;

			$ch = curl_init( self::ENDPOINT );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => $this->timeout,
					CURLOPT_CONNECTTIMEOUT => 30,
					CURLOPT_HTTPHEADER     => array(
						'content-type: application/json',
						'x-api-key: ' . $this->api_key,
						'anthropic-version: ' . self::API_VERSION,
					),
				)
			);

			$raw    = curl_exec( $ch );
			$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$cerr   = curl_error( $ch );
			curl_close( $ch );

			// 连接层失败：可重试。
			if ( false === $raw ) {
				$last_err = "cURL: {$cerr}";
				$this->backoff( $attempt );
				continue;
			}

			$data = json_decode( $raw, true );

			if ( 200 === $status && is_array( $data ) ) {
				if ( isset( $data['usage'] ) ) {
					$this->usage_log[] = array(
						'input'  => isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : 0,
						'output' => isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : 0,
					);
				}
				return $data;
			}

			$etype = isset( $data['error']['type'] ) ? $data['error']['type'] : '';
			$emsg  = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( (string) $raw, 0, 300 );
			$last_err = "HTTP {$status} {$etype}: {$emsg}";

			/*
			 * 只重试确实可能自愈的：限流、过载、服务端错误、请求超时/冲突。
			 * 400（参数错）和 401（key 错）重试多少次都是同样的结果，
			 * 立刻抛出比让用户等四轮退避有用。
			 */
			$retryable = in_array( $status, array( 408, 409, 429, 500, 502, 503, 504, 529 ), true );
			if ( ! $retryable ) {
				throw new RuntimeException( $last_err );
			}

			$this->backoff( $attempt );
		}

		throw new RuntimeException( "重试 {$max_try} 次后仍失败。最后一次：{$last_err}" );
	}

	/**
	 * 指数退避。
	 *
	 * @param int $attempt 第几次尝试（从 1 起）。
	 */
	private function backoff( $attempt ) {
		// 2, 4, 8, 16 秒。
		sleep( (int) pow( 2, $attempt ) );
	}
}

/**
 * JSON 编码（不转义斜杠与 Unicode）。
 *
 * 独立成函数是因为这些脚本在 WordPress 之外也要能跑（纯 CLI），
 * 不能依赖 wp_json_encode()。
 *
 * @param mixed $data 数据。
 * @return string
 */
function wp_json_encode_compat( $data ) {
	return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
