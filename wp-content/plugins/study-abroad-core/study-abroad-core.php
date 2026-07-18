<?php
/**
 * Plugin Name:       Study Abroad Core
 * Plugin URI:        https://example.com/
 * Description:       日本留学中介平台核心业务插件：落地页留资、院校库、自动匹配、加密资料、后台管理、数据埋点。
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Study Abroad Team
 * License:           GPL-2.0-or-later
 * Text Domain:       sa-core
 * Domain Path:       /languages
 *
 * 面向「市场验证阶段」：落地页 + 意向表单 + 埋点为第一优先级，完整业务流程随后接入。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问
}

// ---------------------------------------------------------------------------
// 常量
// ---------------------------------------------------------------------------
define( 'SA_CORE_VERSION', '0.1.0' );
define( 'SA_CORE_FILE', __FILE__ );
define( 'SA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SA_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'SA_CORE_BASENAME', plugin_basename( __FILE__ ) );

/** 数据库业务表前缀（不含 WP 前缀）。最终表名为 {$wpdb->prefix}sa_xxx */
define( 'SA_TABLE_PREFIX', 'sa_' );

/** 加密附件的受保护存储子目录（位于 uploads 下） */
define( 'SA_SECURE_DIRNAME', 'sa-secure' );

// ---------------------------------------------------------------------------
// 自动加载核心类
// ---------------------------------------------------------------------------
require_once SA_CORE_DIR . 'includes/class-activator.php';
require_once SA_CORE_DIR . 'includes/class-deactivator.php';
require_once SA_CORE_DIR . 'includes/class-plugin.php';

// ---------------------------------------------------------------------------
// 激活 / 停用钩子
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'SA_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SA_Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// 启动插件
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', function () {
	SA_Plugin::instance()->run();
} );
