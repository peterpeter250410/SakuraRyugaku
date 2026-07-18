<?php
/**
 * 能力常量集中定义，便于引用与维护。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Capabilities {
	const MANAGE_ALL       = 'sa_manage_all';
	const VIEW_ASSIGNED    = 'sa_view_assigned';
	const REVIEW_DOCUMENTS = 'sa_review_documents';
	const MANAGE_SCHOOLS   = 'sa_manage_schools';
	const MANAGE_RULES     = 'sa_manage_rules';
	const VIEW_ANALYTICS   = 'sa_view_analytics';
}
