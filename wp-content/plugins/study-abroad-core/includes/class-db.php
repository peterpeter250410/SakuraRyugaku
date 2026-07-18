<?php
/**
 * 数据库辅助：统一业务表名，集中 $wpdb 访问入口。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_DB {

	/**
	 * 取业务表全名。
	 *
	 * @param string $name 逻辑表名（不含前缀），如 'leads'。
	 * @return string 完整表名。
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . SA_TABLE_PREFIX . $name;
	}

	/**
	 * 当前 UTC 时间（mysql 格式）。
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}
}
