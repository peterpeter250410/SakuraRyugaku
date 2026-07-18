<?php
/**
 * 插件激活：建表、创建角色/能力、初始化匹配规则、准备受保护目录。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Activator {

	/**
	 * 激活入口。
	 */
	public static function activate() {
		self::create_tables();
		self::seed_match_rules();
		self::register_roles();
		self::prepare_secure_dir();

		// 记录已安装版本，便于后续迁移。
		update_option( 'sa_core_db_version', SA_CORE_VERSION );

		flush_rewrite_rules();
	}

	/**
	 * 创建全部业务表。使用 dbDelta 以支持后续升级。
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix . SA_TABLE_PREFIX;

		$sql = array();

		// 学生扩展档案
		$sql[] = "CREATE TABLE {$p}student_profiles (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			full_name VARCHAR(191) DEFAULT '',
			phone VARCHAR(32) DEFAULT '',
			nationality VARCHAR(64) DEFAULT '',
			preferred_locale VARCHAR(16) DEFAULT '',
			budget_min INT UNSIGNED DEFAULT 0,
			budget_max INT UNSIGNED DEFAULT 0,
			intended_major VARCHAR(191) DEFAULT '',
			intended_major_tags TEXT NULL,
			education_level VARCHAR(64) DEFAULT '',
			gpa DECIMAL(4,2) NULL,
			jp_level VARCHAR(16) DEFAULT '',
			en_score VARCHAR(32) DEFAULT '',
			target_region VARCHAR(64) DEFAULT '',
			intake_term VARCHAR(32) DEFAULT '',
			consent_privacy TINYINT(1) DEFAULT 0,
			consent_at DATETIME NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY intended_major (intended_major),
			KEY budget_range (budget_min, budget_max)
		) {$charset_collate};";

		// 基础信息提交记录
		$sql[] = "CREATE TABLE {$p}intake_submissions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) DEFAULT 'draft',
			payload LONGTEXT NULL,
			submitted_at DATETIME NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_status (user_id, status)
		) {$charset_collate};";

		// 院校库
		$sql[] = "CREATE TABLE {$p}schools (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED DEFAULT 0,
			name VARCHAR(191) DEFAULT '',
			name_i18n TEXT NULL,
			school_type VARCHAR(32) DEFAULT '',
			region VARCHAR(64) DEFAULT '',
			city VARCHAR(64) DEFAULT '',
			language_req VARCHAR(32) DEFAULT '',
			min_education VARCHAR(64) DEFAULT '',
			description_i18n LONGTEXT NULL,
			status VARCHAR(16) DEFAULT 'active',
			sort_order INT DEFAULT 0,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY region (region),
			KEY school_type (school_type)
		) {$charset_collate};";

		// 院校专业子项
		$sql[] = "CREATE TABLE {$p}programs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			school_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) DEFAULT '',
			name_i18n TEXT NULL,
			major_tags TEXT NULL,
			tuition_min INT UNSIGNED DEFAULT 0,
			tuition_max INT UNSIGNED DEFAULT 0,
			language_req VARCHAR(32) DEFAULT '',
			duration VARCHAR(32) DEFAULT '',
			status VARCHAR(16) DEFAULT 'active',
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY school_status (school_id, status),
			KEY tuition_range (tuition_min, tuition_max)
		) {$charset_collate};";

		// 匹配结果快照
		$sql[] = "CREATE TABLE {$p}match_results (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			batch_id CHAR(36) NOT NULL,
			school_id BIGINT UNSIGNED NOT NULL,
			program_id BIGINT UNSIGNED NOT NULL,
			total_score DECIMAL(6,2) DEFAULT 0,
			score_detail TEXT NULL,
			rank_no INT DEFAULT 0,
			rule_snapshot TEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_batch (user_id, batch_id),
			KEY user_rank (user_id, rank_no)
		) {$charset_collate};";

		// 选校记录
		$sql[] = "CREATE TABLE {$p}selections (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			school_id BIGINT UNSIGNED NOT NULL,
			program_id BIGINT UNSIGNED NOT NULL,
			source_batch_id CHAR(36) DEFAULT '',
			status VARCHAR(16) DEFAULT 'selected',
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_choice (user_id, school_id, program_id),
			KEY user_status (user_id, status)
		) {$charset_collate};";

		// 申请资料（加密）
		$sql[] = "CREATE TABLE {$p}documents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			selection_id BIGINT UNSIGNED DEFAULT 0,
			doc_type VARCHAR(64) DEFAULT '',
			original_name VARCHAR(255) DEFAULT '',
			stored_path VARCHAR(255) DEFAULT '',
			mime_type VARCHAR(128) DEFAULT '',
			file_size BIGINT UNSIGNED DEFAULT 0,
			checksum CHAR(64) DEFAULT '',
			enc_algo VARCHAR(32) DEFAULT '',
			enc_iv VARBINARY(32) NULL,
			key_ref VARCHAR(64) DEFAULT '',
			status VARCHAR(16) DEFAULT 'uploaded',
			review_note TEXT NULL,
			reviewed_by BIGINT UNSIGNED DEFAULT 0,
			version INT DEFAULT 1,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_type (user_id, doc_type),
			KEY selection_id (selection_id),
			KEY status (status)
		) {$charset_collate};";

		// 学生-顾问分配
		$sql[] = "CREATE TABLE {$p}assignments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			student_id BIGINT UNSIGNED NOT NULL,
			advisor_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) DEFAULT 'active',
			assigned_by BIGINT UNSIGNED DEFAULT 0,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY advisor_status (advisor_id, status),
			KEY student_status (student_id, status)
		) {$charset_collate};";

		// 匹配规则/权重配置
		$sql[] = "CREATE TABLE {$p}match_rules (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_key VARCHAR(64) NOT NULL,
			weight DECIMAL(5,2) DEFAULT 0,
			threshold TEXT NULL,
			enabled TINYINT(1) DEFAULT 0,
			updated_by BIGINT UNSIGNED DEFAULT 0,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rule_key (rule_key)
		) {$charset_collate};";

		// 通知
		$sql[] = "CREATE TABLE {$p}notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(64) DEFAULT '',
			title VARCHAR(191) DEFAULT '',
			body TEXT NULL,
			link VARCHAR(255) DEFAULT '',
			is_read TINYINT(1) DEFAULT 0,
			channel VARCHAR(32) DEFAULT 'inbox',
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_read (user_id, is_read)
		) {$charset_collate};";

		// 审计日志
		$sql[] = "CREATE TABLE {$p}audit_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED DEFAULT 0,
			action VARCHAR(64) DEFAULT '',
			object_type VARCHAR(64) DEFAULT '',
			object_id BIGINT UNSIGNED DEFAULT 0,
			ip VARCHAR(45) DEFAULT '',
			meta TEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY actor_id (actor_id),
			KEY object (object_type, object_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		// 分析事件（自建埋点）
		$sql[] = "CREATE TABLE {$p}analytics_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(64) DEFAULT '',
			session_key CHAR(36) DEFAULT '',
			page_url VARCHAR(255) DEFAULT '',
			referrer VARCHAR(255) DEFAULT '',
			utm_source VARCHAR(64) DEFAULT '',
			utm_medium VARCHAR(64) DEFAULT '',
			utm_campaign VARCHAR(64) DEFAULT '',
			device VARCHAR(16) DEFAULT '',
			locale VARCHAR(16) DEFAULT '',
			country VARCHAR(64) DEFAULT '',
			ip_hash CHAR(64) DEFAULT '',
			meta TEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY event_time (event_type, created_at),
			KEY session_key (session_key),
			KEY utm_source (utm_source)
		) {$charset_collate};";

		// 意向线索（核心产出）
		$sql[] = "CREATE TABLE {$p}leads (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key CHAR(36) DEFAULT '',
			name VARCHAR(191) DEFAULT '',
			contact_type VARCHAR(16) DEFAULT '',
			contact_value VARCHAR(191) DEFAULT '',
			budget_min INT UNSIGNED DEFAULT 0,
			budget_max INT UNSIGNED DEFAULT 0,
			intended_major VARCHAR(191) DEFAULT '',
			extra TEXT NULL,
			lp_variant VARCHAR(32) DEFAULT '',
			utm_source VARCHAR(64) DEFAULT '',
			utm_medium VARCHAR(64) DEFAULT '',
			utm_campaign VARCHAR(64) DEFAULT '',
			lead_status VARCHAR(16) DEFAULT 'new',
			quality_note VARCHAR(255) DEFAULT '',
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY lead_status (lead_status),
			KEY utm_source (utm_source),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * 初始化匹配规则（MVP：预算 50% + 专业 50%，扩展维度默认关闭）。
	 */
	private static function seed_match_rules() {
		global $wpdb;
		$table = $wpdb->prefix . SA_TABLE_PREFIX . 'match_rules';
		$now   = current_time( 'mysql', true );

		$defaults = array(
			array( 'budget', 0.50, 1, wp_json_encode( array( 'penaltyFactor' => 1.5, 'hardExclude' => false ) ) ),
			array( 'major', 0.50, 1, wp_json_encode( array( 'mode' => 'coverage' ) ) ),
			array( 'language', 0.00, 0, wp_json_encode( array( 'hardFilter' => false ) ) ),
			array( 'education', 0.00, 0, wp_json_encode( array() ) ),
			array( 'region', 0.00, 0, wp_json_encode( array() ) ),
			array( 'intake_term', 0.00, 0, wp_json_encode( array() ) ),
		);

		foreach ( $defaults as $rule ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE rule_key = %s", $rule[0] )
			);
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'rule_key'   => $rule[0],
					'weight'     => $rule[1],
					'enabled'    => $rule[2],
					'threshold'  => $rule[3],
					'updated_at' => $now,
				),
				array( '%s', '%f', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * 注册自定义角色与能力。
	 */
	private static function register_roles() {
		// 学生
		add_role(
			'sa_student',
			__( '学生', 'sa-core' ),
			array( 'read' => true )
		);

		// 顾问
		add_role(
			'sa_advisor',
			__( '留学顾问', 'sa-core' ),
			array(
				'read'                 => true,
				'sa_view_assigned'     => true,
				'sa_review_documents'  => true,
			)
		);

		// 管理员能力附加到 WP administrator
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'sa_manage_all' );
			$admin->add_cap( 'sa_view_assigned' );
			$admin->add_cap( 'sa_review_documents' );
			$admin->add_cap( 'sa_manage_schools' );
			$admin->add_cap( 'sa_manage_rules' );
			$admin->add_cap( 'sa_view_analytics' );
		}
	}

	/**
	 * 准备加密附件的受保护目录，并写入禁止直连的 .htaccess 与 index.php。
	 */
	private static function prepare_secure_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . SA_SECURE_DIRNAME;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Apache：禁止直接访问
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Deny all direct access to encrypted documents\n"
				. "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules );
		}

		// 空 index.php 防目录列举
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}
}
