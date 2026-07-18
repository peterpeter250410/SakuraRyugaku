<?php
/**
 * 插件主容器：加载依赖、注册钩子、初始化各模块。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SA_Plugin {

	/** @var SA_Plugin */
	private static $instance = null;

	/** @var bool 防止重复 run() */
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
	}

	/**
	 * 载入各模块类文件。
	 */
	private function load_dependencies() {
		$dir = SA_CORE_DIR . 'includes/';

		// 基础设施
		require_once $dir . 'class-db.php';
		require_once $dir . 'security/class-audit-log.php';
		require_once $dir . 'security/class-access-guard.php';
		require_once $dir . 'security/class-capabilities.php';

		// 线索 / 埋点（市场验证第一优先级）
		require_once $dir . 'leads/class-lead-repo.php';
		require_once $dir . 'analytics/class-analytics-repo.php';
		require_once $dir . 'analytics/class-tracker.php';

		// 院校 / 匹配
		require_once $dir . 'schools/class-school-repo.php';
		require_once $dir . 'matching/rules/interface-rule.php';
		require_once $dir . 'matching/rules/class-budget-rule.php';
		require_once $dir . 'matching/rules/class-major-rule.php';
		require_once $dir . 'matching/class-scoring.php';
		require_once $dir . 'matching/class-matcher.php';

		// 资料加密
		require_once $dir . 'documents/class-doc-crypto.php';
		require_once $dir . 'documents/class-doc-repo.php';
		require_once $dir . 'documents/class-doc-access.php';

		// 通知
		require_once $dir . 'notify/class-notifier.php';

		// REST API
		require_once $dir . 'rest/class-rest-leads.php';
		require_once $dir . 'rest/class-rest-analytics.php';

		// 后台
		if ( is_admin() ) {
			require_once $dir . 'admin/class-admin-menu.php';
		}
	}

	/**
	 * 注册所有运行时钩子。
	 */
	public function run() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// REST 路由
		add_action( 'rest_api_init', array( 'SA_Rest_Leads', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'SA_Rest_Analytics', 'register_routes' ) );

		// 受控文件下载端点（鉴权后解密输出）
		add_action( 'init', array( 'SA_Doc_Access', 'maybe_handle_download' ) );

		// 前端埋点脚本注入
		add_action( 'wp_enqueue_scripts', array( 'SA_Tracker', 'enqueue' ) );

		// 后台菜单
		if ( is_admin() ) {
			add_action( 'admin_menu', array( 'SA_Admin_Menu', 'register' ) );
		}
	}

	/**
	 * 加载翻译。
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'sa-core', false, dirname( SA_CORE_BASENAME ) . '/languages' );
	}
}
