/**
 * Study Abroad 自建埋点 + GA4 桥接。
 *
 * - 生成/复用匿名 session_key（localStorage）
 * - 采集 UTM 与设备类型
 * - 上报 pageview / lp_view / form_impression / form_start / form_submit / cta_click / scroll_depth
 * - 若配置 GA4，则同步 gtag 事件
 */
(function () {
	'use strict';

	if (typeof window.SA_TRACK === 'undefined') {
		return;
	}

	var cfg = window.SA_TRACK;

	// -------- session key --------
	function uuid() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			var v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	function sessionKey() {
		var k = 'sa_session_key';
		try {
			var v = localStorage.getItem(k);
			if (!v) {
				v = uuid();
				localStorage.setItem(k, v);
			}
			return v;
		} catch (e) {
			return uuid();
		}
	}

	// -------- utm & device --------
	function getParam(name) {
		var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
		return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
	}

	function persistUtm() {
		var keys = ['utm_source', 'utm_medium', 'utm_campaign'];
		var stored = {};
		keys.forEach(function (key) {
			var val = getParam(key);
			if (val) {
				try { localStorage.setItem('sa_' + key, val); } catch (e) {}
			}
			try { stored[key] = localStorage.getItem('sa_' + key) || ''; } catch (e) { stored[key] = ''; }
		});
		return stored;
	}

	function device() {
		return window.matchMedia && window.matchMedia('(max-width: 768px)').matches ? 'h5' : 'pc';
	}

	var SK = sessionKey();
	var UTM = persistUtm();
	var DEVICE = device();
	var LOCALE = document.documentElement.getAttribute('lang') || '';

	// -------- send --------
	function send(eventType, meta) {
		var payload = {
			event_type: eventType,
			session_key: SK,
			page_url: window.location.href,
			referrer: document.referrer,
			utm_source: UTM.utm_source,
			utm_medium: UTM.utm_medium,
			utm_campaign: UTM.utm_campaign,
			device: DEVICE,
			locale: LOCALE,
			meta: meta || {}
		};

		try {
			fetch(cfg.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(payload),
				keepalive: true
			});
		} catch (e) {}

		// GA4 桥接
		if (cfg.ga4 && typeof window.gtag === 'function') {
			window.gtag('event', eventType, {
				utm_source: UTM.utm_source,
				device: DEVICE
			});
		}
	}

	// 暴露给表单脚本使用
	window.saTrack = send;
	window.saSession = function () { return SK; };
	window.saUtm = function () { return UTM; };

	// -------- auto events --------
	// pageview
	send('pageview');

	// 落地页视图（页面含 [data-sa-lp] 时）
	var lp = document.querySelector('[data-sa-lp]');
	if (lp) {
		send('lp_view', { lp_variant: lp.getAttribute('data-sa-lp') || '' });
	}

	// 表单曝光（IntersectionObserver）
	var form = document.querySelector('[data-sa-form]');
	if (form && 'IntersectionObserver' in window) {
		var seen = false;
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting && !seen) {
					seen = true;
					send('form_impression', { form_id: form.getAttribute('data-sa-form') });
					io.disconnect();
				}
			});
		});
		io.observe(form);

		// 首次输入 -> form_start
		var started = false;
		form.addEventListener('input', function () {
			if (!started) {
				started = true;
				send('form_start', { form_id: form.getAttribute('data-sa-form') });
			}
		});
	}

	// CTA 点击
	document.querySelectorAll('[data-sa-cta]').forEach(function (el) {
		el.addEventListener('click', function () {
			send('cta_click', { cta_position: el.getAttribute('data-sa-cta') });
		});
	});

	// 滚动深度
	var depths = [25, 50, 75, 100];
	var fired = {};
	window.addEventListener('scroll', function () {
		var h = document.documentElement.scrollHeight - window.innerHeight;
		if (h <= 0) return;
		var pct = Math.round((window.scrollY / h) * 100);
		depths.forEach(function (d) {
			if (pct >= d && !fired[d]) {
				fired[d] = true;
				send('scroll_depth', { percent: d });
			}
		});
	}, { passive: true });
})();
