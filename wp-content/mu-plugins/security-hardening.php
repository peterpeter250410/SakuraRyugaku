<?php
/**
 * Plugin Name: WordPressBase Security Hardening
 * Description: 核心安全加固 - 禁用不安全的默认行为
 * Version: 1.0.0
 * Author: WordPressBase
 *
 * 此文件为 Must-Use 插件，自动加载且无法从后台禁用。
 * 安全加固项：版本隐藏、XML-RPC禁用、用户枚举防护、安全头、登录限制等。
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================
// 1. 移除 WordPress 版本号（防止版本探测）
// ============================================
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

// 从脚本和样式中移除版本号参数
add_filter('style_loader_src', 'wpbase_remove_version_query', 10, 2);
add_filter('script_loader_src', 'wpbase_remove_version_query', 10, 2);

function wpbase_remove_version_query($src, $handle) {
    if (strpos($src, 'ver=')) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}

// ============================================
// 2. 禁用 XML-RPC（防暴力破解和 DDoS 放大攻击）
// ============================================
add_filter('xmlrpc_enabled', '__return_false');

add_filter('wp_headers', function ($headers) {
    unset($headers['X-Pingback']);
    return $headers;
});

// ============================================
// 3. 禁用 REST API 用户枚举（防止用户名泄露）
// ============================================
add_filter('rest_endpoints', function ($endpoints) {
    if (isset($endpoints['/wp/v2/users'])) {
        unset($endpoints['/wp/v2/users']);
    }
    if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }
    return $endpoints;
});

// ============================================
// 4. 阻止通过 ?author=N 进行用户名枚举
// ============================================
add_action('template_redirect', function () {
    if (is_author() && !is_admin()) {
        wp_redirect(home_url(), 301);
        exit;
    }
});

// ============================================
// 5. 禁用后台文件编辑器（双重保障）
// ============================================
if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

// ============================================
// 6. 添加安全 HTTP 头
// ============================================
add_action('send_headers', function () {
    if (!is_admin()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }
});

// ============================================
// 7. 限制登录尝试（5次/15分钟）
// ============================================
add_filter('authenticate', function ($user, $username, $password) {
    if (empty($username) || empty($password)) {
        return $user;
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $transient_key = 'wpbase_login_attempts_' . md5($ip);
    $attempts = get_transient($transient_key);

    if ($attempts !== false && $attempts >= 5) {
        return new WP_Error(
            'too_many_attempts',
            '登录尝试次数过多，请15分钟后再试。'
        );
    }

    return $user;
}, 30, 3);

add_action('wp_login_failed', function ($username) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $transient_key = 'wpbase_login_attempts_' . md5($ip);
    $attempts = get_transient($transient_key);

    if ($attempts === false) {
        set_transient($transient_key, 1, 15 * MINUTE_IN_SECONDS);
    } else {
        set_transient($transient_key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
    }
});

// 登录成功时清除计数
add_action('wp_login', function () {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $transient_key = 'wpbase_login_attempts_' . md5($ip);
    delete_transient($transient_key);
});

// ============================================
// 8. 移除不必要的 wp_head 输出
// ============================================
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'rest_output_link_wp_head');
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);

// ============================================
// 9. 禁用 WordPress Emoji（减少外部请求）
// ============================================
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

    add_filter('tiny_mce_plugins', function ($plugins) {
        return is_array($plugins) ? array_diff($plugins, array('wpemoji')) : $plugins;
    });

    add_filter('wp_resource_hints', function ($urls, $relation_type) {
        if ($relation_type === 'dns-prefetch') {
            $urls = array_filter($urls, function ($url) {
                return strpos($url, 'https://s.w.org/images/core/emoji/') === false;
            });
        }
        return $urls;
    }, 10, 2);
});
