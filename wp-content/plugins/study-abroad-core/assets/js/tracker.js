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

	// -------- 自动事件的发送时机 --------
	/*
	 * 页面加载期间的埋点一律推迟到「load 之后 + 浏览器空闲」再发。
	 *
	 * 起因是 PageSpeed 手机端的实测：本脚本在解析时立即发出 3 个
	 * POST /sa/v1/track（pageview + lp_view + form_impression），
	 * 每个都要走完整的 WordPress REST 引导。关键请求链因此被拉到
	 * 1,925 毫秒，而同一份报告里 LCP 的「元素渲染延迟」是 1,940 毫秒 ——
	 * 两个数字对得上：首屏就卡在这几个埋点请求后面。
	 *
	 * 埋点是旁路数据，永远不该跟首屏渲染抢带宽和主线程。推迟之后
	 * 数据一条不少，只是晚几百毫秒入库。
	 *
	 * 用户主动触发的事件（点击、表单输入）不走这里 —— 那些发生在
	 * 加载完成之后，本来就不在关键路径上。
	 */
	function whenIdle(fn) {
		var run = function () {
			if (typeof window.requestIdleCallback === 'function') {
				// timeout 兜底：页面长期繁忙时也不会一直不发。
				window.requestIdleCallback(fn, { timeout: 3000 });
			} else {
				setTimeout(fn, 1);
			}
		};
		if (document.readyState === 'complete') {
			run();
		} else {
			window.addEventListener('load', run);
		}
	}

	// -------- auto events --------
	/*
	 * thanks_view 不推迟。
	 *
	 * 它是转化确认事件，业务上比性能重要：万一用户在 load 后立刻关掉
	 * 标签页，推迟发送就会丢掉一条转化记录（fetch 的 keepalive 只能保住
	 * 「已发出」的请求，还没发出的救不回来）。
	 * 而感谢页是转化完成后的页面，且本身 noindex，不是要优化的对象。
	 */
	var thanks = document.querySelector('[data-sa-thanks]');
	if (thanks) {
		send('thanks_view', {});
		if (cfg.ga4 && typeof window.gtag === 'function') {
			window.gtag('event', 'conversion', { send_to: 'lead_form' });
		}
	}

	whenIdle(function () {
		// pageview
		send('pageview');

		// 落地页视图（页面含 [data-sa-lp] 时）
		var lp = document.querySelector('[data-sa-lp]');
		if (lp) {
			send('lp_view', { lp_variant: lp.getAttribute('data-sa-lp') || '' });
		}
	});

	// 表单曝光（IntersectionObserver）
	var form = document.querySelector('[data-sa-form]');
	if (form && 'IntersectionObserver' in window) {
		/*
		 * 观察器本身也放到空闲时再挂。
		 *
		 * 表单若落在首屏内，观察器一挂上就会立刻判定为「已曝光」并发请求 ——
		 * 那又回到关键路径上了（首页的 data-sa-form 就在 hero 里，正是这种情况）。
		 * 元素位置不会因为晚挂几百毫秒而改变，曝光判定结果完全一致。
		 */
		whenIdle(function () {
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
		});

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

	/*
	 * 滚动深度。
	 *
	 * 这里原本在每次 scroll 事件里读 document.documentElement.scrollHeight。
	 * 那是一次强制同步布局（forced reflow）—— 浏览器必须立刻算完布局才能
	 * 返回这个值，而滚动事件的触发频率极高。PageSpeed 的「强制自动重排」
	 * 一项因此涨到 256 毫秒（它会滚动页面来采集截图，把这条路径踩满）。
	 *
	 * 改法两条：
	 *   1. 高度缓存下来，只在初次、resize、load 之后重新测量。
	 *      页面高度在滚动过程中不会变，没有理由每次都问一遍。
	 *      load 之后要补测一次：图片加载完会改变文档高度。
	 *   2. 滚动处理放进 requestAnimationFrame 并去重，每帧至多算一次。
	 *      浏览器一帧内只需要一个答案，算多了也用不上。
	 */
	var depths = [25, 50, 75, 100];
	var fired = {};
	var docH = -1;

	function measureDocH() {
		docH = document.documentElement.scrollHeight - window.innerHeight;
	}

	window.addEventListener('resize', function () { docH = -1; }, { passive: true });
	window.addEventListener('load', function () { docH = -1; });

	var ticking = false;
	window.addEventListener('scroll', function () {
		if (ticking) { return; }
		ticking = true;
		window.requestAnimationFrame(function () {
			ticking = false;
			if (docH < 0) { measureDocH(); }
			if (docH <= 0) { return; }
			var pct = Math.round((window.scrollY / docH) * 100);
			depths.forEach(function (d) {
				if (pct >= d && !fired[d]) {
					fired[d] = true;
					send('scroll_depth', { percent: d });
				}
			});
		});
	}, { passive: true });
})();
