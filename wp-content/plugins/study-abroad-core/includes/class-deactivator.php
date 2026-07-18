<?php
/**
 * 插件停用：清理重写规则。保留数据表与数据（避免误删业务数据）。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Deactivator {

	/**
	 * 停用入口。
	 *
	 * 注意：停用不删表、不删角色，避免误操作丢失数据。
	 * 彻底卸载（删数据）应通过 uninstall.php 由用户显式触发。
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
