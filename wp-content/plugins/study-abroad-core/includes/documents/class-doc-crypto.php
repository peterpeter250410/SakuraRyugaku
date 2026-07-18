<?php
/**
 * 附件加密工具：AES-256-GCM（AEAD）加解密。
 *
 * 密钥管理：
 *  - 优先读取 wp-config 常量 SA_DOC_ENC_KEY（推荐做法，密钥独立于数据库）。
 *  - 若未定义则回退用 wp_salt('secure_auth') 经 hash('sha256', ...) 派生 32 字节密钥。
 *  - 数据库只存 key_ref（密钥版本标识，如 'v1'），不落明文密钥，支持后续轮换。
 *
 * GCM tag 处理方案：
 *  - GCM 模式除密文与 IV 外，还产生 16 字节认证标签（tag），用于防篡改。
 *  - 本模块采用「tag 前置拼接」方案：将 16 字节 tag 拼在密文前面一起写入受保护文件，
 *    即落盘内容 = tag(16 bytes) . cipher。解密时先切出前 16 字节作为 tag，其余为密文。
 *  - 该方案无需为 tag 额外增加数据库字段，enc_iv 字段仅存 IV，语义清晰。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Doc_Crypto {

	/**
	 * 加密算法标识（openssl 使用）。
	 */
	const ALGO = 'aes-256-gcm';

	/**
	 * GCM 认证标签长度（字节）。
	 */
	const TAG_LEN = 16;

	/**
	 * 当前密钥版本标识。
	 */
	const KEY_VERSION = 'v1';

	/**
	 * 取 32 字节（256 位）加密密钥。
	 *
	 * 优先使用 wp-config 常量 SA_DOC_ENC_KEY；未定义时用 salt 派生。
	 *
	 * @return string 二进制 32 字节密钥。
	 */
	public static function get_key() {
		if ( defined( 'SA_DOC_ENC_KEY' ) && SA_DOC_ENC_KEY ) {
			$raw = (string) SA_DOC_ENC_KEY;
			// 若常量本身恰为 32 字节则直接使用，否则统一经 sha256 归一到 32 字节。
			if ( 32 === strlen( $raw ) ) {
				return $raw;
			}
			return hash( 'sha256', $raw, true );
		}

		// 回退：用 secure_auth salt 派生（true 表示返回原始二进制 32 字节）。
		return hash( 'sha256', wp_salt( 'secure_auth' ), true );
	}

	/**
	 * 返回当前密钥版本标识（写入数据库 key_ref）。
	 *
	 * @return string
	 */
	public static function key_ref() {
		return self::KEY_VERSION;
	}

	/**
	 * 加密明文。
	 *
	 * @param string $plaintext 明文（文件二进制内容）。
	 * @return array|false 成功返回 ['cipher'=>密文, 'iv'=>随机IV, 'algo'=>'aes-256-gcm', 'tag'=>认证标签]；失败 false。
	 */
	public static function encrypt( $plaintext ) {
		$key    = self::get_key();
		$iv_len = openssl_cipher_iv_length( self::ALGO );
		if ( false === $iv_len || $iv_len <= 0 ) {
			return false;
		}

		// 每文件唯一 IV（密码学安全随机）。
		$iv  = random_bytes( $iv_len );
		$tag = '';

		$cipher = openssl_encrypt(
			$plaintext,
			self::ALGO,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LEN
		);

		if ( false === $cipher ) {
			return false;
		}

		return array(
			'cipher' => $cipher,
			'iv'     => $iv,
			'algo'   => self::ALGO,
			'tag'    => $tag,
		);
	}

	/**
	 * 解密密文。
	 *
	 * @param string $cipher 密文（不含 tag 的纯密文部分）。
	 * @param string $iv     加密时使用的 IV。
	 * @param string $tag    GCM 认证标签。
	 * @return string|false 成功返回明文，认证失败或错误返回 false。
	 */
	public static function decrypt( $cipher, $iv, $tag ) {
		$key = self::get_key();

		$plaintext = openssl_decrypt(
			$cipher,
			self::ALGO,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		// GCM 认证失败时 openssl_decrypt 返回 false，此时说明密文被篡改或密钥不匹配。
		return $plaintext;
	}

	/**
	 * 将 tag 前置拼接到密文，得到落盘内容。
	 *
	 * @param string $cipher 纯密文。
	 * @param string $tag    认证标签（16 字节）。
	 * @return string tag . cipher
	 */
	public static function pack_stored( $cipher, $tag ) {
		return $tag . $cipher;
	}

	/**
	 * 从落盘内容中切分出 tag 与密文。
	 *
	 * @param string $stored 落盘内容（tag . cipher）。
	 * @return array|false ['tag'=>.., 'cipher'=>..]，长度不足返回 false。
	 */
	public static function unpack_stored( $stored ) {
		if ( strlen( $stored ) <= self::TAG_LEN ) {
			return false;
		}

		return array(
			'tag'    => substr( $stored, 0, self::TAG_LEN ),
			'cipher' => substr( $stored, self::TAG_LEN ),
		);
	}
}
