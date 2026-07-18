<?php
/**
 * 匹配维度规则接口。
 *
 * 每个匹配维度（预算、专业、语言、学历等）实现本接口即可被评分器插拔式加载。
 * 评分方法为纯函数式：输入学生 + 专业 + 阈值配置，输出 [0,1] 的归一化得分，
 * 便于单元测试与跨维度加权。
 *
 * @package StudyAbroadCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SA_Rule_Interface {

	/**
	 * 规则唯一键，需与 sa_match_rules.rule_key 对应（如 'budget'、'major'）。
	 *
	 * @return string
	 */
	public function key();

	/**
	 * 计算某学生对某专业在本维度上的得分。
	 *
	 * @param array $student   学生 profile（关联数组）。
	 * @param array $program   专业行（含院校字段，关联数组）。
	 * @param array $threshold 本规则的阈值配置（来自 sa_match_rules.threshold 解码）。
	 * @return float 归一化得分，范围 [0,1]。
	 */
	public function score( array $student, array $program, array $threshold );
}
