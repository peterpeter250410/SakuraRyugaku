/**
 * Study Abroad Theme — 前端交互。
 *
 * - 落地页意向表单提交（fetch → sa/v1/lead）
 * - 提交成功后展示感谢信息并上报转化埋点（tracker.js 已监听 form_submit，此处再触发一次显式转化）
 * - 移动端菜单
 */
(function () {
	'use strict';

	if (typeof window.SA_LP === 'undefined') {
		return;
	}

	var cfg = window.SA_LP;

	// -------- 落地页表单 --------
	var form = document.querySelector('.sa-lead-form');
	if (form) {
		var msg = form.querySelector('.sa-form-msg');
		var btn = form.querySelector('button[type="submit"]');

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			var name = (form.querySelector('[name="name"]') || {}).value || '';
			var contact = (form.querySelector('[name="contact_value"]') || {}).value || '';
			var consent = form.querySelector('[name="consent"]');
			var honeypot = (form.querySelector('[name="website"]') || {}).value || '';

			// 基础校验
			if (!name.trim() || !contact.trim() || !consent || !consent.checked) {
				showMsg(cfg.i18n.required, 'err');
				return;
			}

			// 预算区间解析
			var budgetMin = 0, budgetMax = 0;
			var br = (form.querySelector('[name="budget_range"]') || {}).value || '';
			if (br.indexOf('-') > -1) {
				var parts = br.split('-');
				budgetMin = parseInt(parts[0], 10) * 10000 || 0;
				budgetMax = parseInt(parts[1], 10) * 10000 || 0;
			}

			var payload = {
				name: name,
				contact_type: (form.querySelector('[name="contact_type"]') || {}).value || 'email',
				contact_value: contact,
				budget_min: budgetMin,
				budget_max: budgetMax,
				intended_major: (form.querySelector('[name="intended_major"]') || {}).value || '',
				consent: consent.checked ? 1 : 0,
				website: honeypot,
				lp_variant: (document.querySelector('[data-sa-lp]') || {}).getAttribute
					? (document.querySelector('[data-sa-lp]').getAttribute('data-sa-lp') || '') : '',
				page_url: window.location.href,
				session_key: (typeof window.saSession === 'function') ? window.saSession() : '',
				utm_source: utm('utm_source'),
				utm_medium: utm('utm_medium'),
				utm_campaign: utm('utm_campaign')
			};

			btn.disabled = true;
			showMsg(cfg.i18n.submitting, '');

			fetch(cfg.leadEndpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(payload)
			})
				.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
				.then(function (res) {
					btn.disabled = false;
					if (res.ok && res.data && res.data.ok) {
						form.reset();
						showMsg(cfg.i18n.success, 'ok');
						// 显式转化埋点（GA4 conversion）
						if (typeof window.saTrack === 'function') {
							window.saTrack('form_submit', { explicit: true, lead_id: res.data.lead_id || 0 });
						}
					} else {
						var m = (res.data && res.data.message) ? res.data.message : cfg.i18n.error;
						showMsg(m, 'err');
					}
				})
				.catch(function () {
					btn.disabled = false;
					showMsg(cfg.i18n.error, 'err');
				});
		});

		function showMsg(text, type) {
			if (!msg) return;
			msg.textContent = text;
			msg.className = 'sa-form-msg' + (type ? ' sa-form-msg--' + type : '');
		}
	}

	function utm(name) {
		try {
			return localStorage.getItem('sa_' + name) || '';
		} catch (e) {
			return '';
		}
	}

	// -------- 移动端菜单 --------
	var menuBtn = document.querySelector('.sa-menu-btn');
	var nav = document.querySelector('.sa-nav');
	if (menuBtn && nav) {
		menuBtn.addEventListener('click', function () {
			if (nav.style.display === 'block') {
				nav.style.display = '';
			} else {
				nav.style.display = 'block';
			}
		});
	}
})();
