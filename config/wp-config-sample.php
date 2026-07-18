<?php
/**
 * WordPressBase - wp-config 配置模板
 *
 * 使用方法：复制此文件到项目根目录，重命名为 wp-config.php，填入实际值
 * 认证密钥从 https://api.wordpress.org/secret-key/1.1/salt/ 获取
 *
 * @package WordPressBase
 */

// ============================================
// 数据库配置
// ============================================
define('DB_NAME',     '{{DB_NAME}}');
define('DB_USER',     '{{DB_USER}}');
define('DB_PASSWORD', '{{DB_PASSWORD}}');
define('DB_HOST',     'localhost');
define('DB_CHARSET',  'utf8mb4');
define('DB_COLLATE',  '');

// ============================================
// 数据表前缀（务必修改默认值）
// ============================================
$table_prefix = 'wpbase_';

// ============================================
// 认证密钥和盐值
// 从 https://api.wordpress.org/secret-key/1.1/salt/ 获取
// ============================================
define('AUTH_KEY',         '{{GENERATE_NEW}}');
define('SECURE_AUTH_KEY',  '{{GENERATE_NEW}}');
define('LOGGED_IN_KEY',    '{{GENERATE_NEW}}');
define('NONCE_KEY',        '{{GENERATE_NEW}}');
define('AUTH_SALT',        '{{GENERATE_NEW}}');
define('SECURE_AUTH_SALT', '{{GENERATE_NEW}}');
define('LOGGED_IN_SALT',   '{{GENERATE_NEW}}');
define('NONCE_SALT',       '{{GENERATE_NEW}}');

// ============================================
// 安全加固配置
// ============================================
define('DISALLOW_FILE_EDIT', true);        // 禁止后台编辑主题/插件文件
define('DISALLOW_FILE_MODS', false);       // 允许后台安装插件（生产环境建议改为 true）
define('FORCE_SSL_ADMIN', true);           // 后台强制 HTTPS
define('WP_HTTP_BLOCK_EXTERNAL', false);   // 是否阻止外部 HTTP 请求

// ============================================
// 调试配置（生产环境务必关闭）
// ============================================
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', false);

// ============================================
// 性能与限制
// ============================================
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
define('WP_POST_REVISIONS', 5);
define('AUTOSAVE_INTERVAL', 120);
define('EMPTY_TRASH_DAYS', 15);
define('MEDIA_TRASH', true);

// ============================================
// 自动更新策略
// ============================================
define('AUTOMATIC_UPDATER_DISABLED', false);
define('WP_AUTO_UPDATE_CORE', 'minor');

// ============================================
// 绝对路径（勿修改）
// ============================================
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
