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

	// -------- 落地页表单：AI 诊断 + 即时结果 + 并行留资 --------
	var form = document.querySelector('.sa-lead-form');
	if (form) {
		var msg = form.querySelector('.sa-form-msg');
		var btn = form.querySelector('button[type="submit"]');
		var resultBox = document.querySelector('[data-sa-diagnose-result]');
		// 缓存最近一次表单数据，供选校时复用。
		var lastPayload = null;

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
				budget_range: br,
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
			lastPayload = payload;

			btn.disabled = true;
			showMsg(cfg.i18n.diagnosing, '');

			// 1) 并行留资：保证填了就有线索，不因诊断失败而丢失。
			fetch(cfg.leadEndpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(payload)
			}).then(function (r) {
				return r.json().then(function (d) { return { ok: r.ok, data: d }; });
			}).then(function (res) {
				if (res.ok && res.data && res.data.ok && typeof window.saTrack === 'function') {
					window.saTrack('form_submit', { explicit: true, lead_id: res.data.lead_id || 0 });
				}
			}).catch(function () { /* 留资失败不阻断诊断展示 */ });

			// 2) 诊断：拿 Top N 同页渲染。
			fetch(cfg.diagnoseEndpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(payload)
			})
				.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
				.then(function (res) {
					btn.disabled = false;
					if (res.ok && res.data && res.data.ok) {
						showMsg('', '');
						if (typeof window.saTrack === 'function') {
							window.saTrack('diagnose', { count: (res.data.results || []).length });
						}
						renderResults(res.data.results || []);
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

		function renderResults(results) {
			if (!resultBox) { return; }
			resultBox.innerHTML = '';
			resultBox.hidden = false;

			if (!results.length) {
				resultBox.innerHTML = '<p class="sa-diag-empty"></p>';
				resultBox.querySelector('.sa-diag-empty').textContent = cfg.i18n.noResult;
				return;
			}

			var title = document.createElement('h3');
			title.className = 'sa-diag-title';
			title.textContent = 'AI診断結果 · TOP' + results.length;
			resultBox.appendChild(title);

			results.forEach(function (item) {
				resultBox.appendChild(buildCard(item));
			});
			resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}

		function buildCard(item) {
			var card = document.createElement('div');
			card.className = 'sa-diag-card';

			var pct = parseInt(item.percent, 10) || 0;

			var head = document.createElement('div');
			head.className = 'sa-diag-card__head';
			var h = document.createElement('div');
			h.className = 'sa-diag-card__name';
			h.textContent = item.school_name + ' · ' + item.program_name;
			var badge = document.createElement('span');
			badge.className = 'sa-diag-card__pct';
			badge.textContent = cfg.i18n.matchLabel + ' ' + pct + '%';
			head.appendChild(h);
			head.appendChild(badge);
			card.appendChild(head);

			var meta = document.createElement('div');
			meta.className = 'sa-diag-card__meta';
			meta.textContent = (item.region || '') + '　' + (item.tuition_label || '');
			card.appendChild(meta);

			// 进度条
			var bar = document.createElement('div');
			bar.className = 'sa-diag-bar';
			var fill = document.createElement('span');
			fill.style.width = pct + '%';
			bar.appendChild(fill);
			card.appendChild(bar);

			var selectBtn = document.createElement('button');
			selectBtn.type = 'button';
			selectBtn.className = 'sa-btn sa-btn--primary sa-diag-card__btn';
			selectBtn.textContent = cfg.i18n.selectSchool;
			selectBtn.setAttribute('data-school-id', item.school_id);
			selectBtn.setAttribute('data-program-id', item.program_id);
			selectBtn.addEventListener('click', function () {
				selectSchool(item.school_id, item.program_id, selectBtn);
			});
			card.appendChild(selectBtn);

			return card;
		}

		function selectSchool(schoolId, programId, buttonEl) {
			if (!lastPayload) { return; }
			buttonEl.disabled = true;
			var original = buttonEl.textContent;
			buttonEl.textContent = cfg.i18n.selecting;

			var body = {};
			Object.keys(lastPayload).forEach(function (k) { body[k] = lastPayload[k]; });
			body.school_id = schoolId;
			body.program_id = programId;

			fetch(cfg.claimEndpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(body)
			})
				.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
				.then(function (res) {
					if (res.ok && res.data && res.data.ok && res.data.redirect) {
						if (typeof window.saTrack === 'function') {
							window.saTrack('select_school', { school_id: schoolId, program_id: programId });
						}
						window.location.assign(res.data.redirect);
					} else {
						buttonEl.disabled = false;
						buttonEl.textContent = original;
						var m = (res.data && res.data.message) ? res.data.message : cfg.i18n.error;
						showMsg(m, 'err');
					}
				})
				.catch(function () {
					buttonEl.disabled = false;
					buttonEl.textContent = original;
					showMsg(cfg.i18n.error, 'err');
				});
		}

		function showMsg(text, type) {
			if (!msg) return;
			msg.textContent = text;
			msg.className = 'sa-form-msg' + (type ? ' sa-form-msg--' + type : '');
		}
	}

	// -------- 资料上传页（page-upload.php）：选文件即自动上传 + 页尾总提交 --------
	initUploadPage();

	function initUploadPage() {
		var uploadForm = document.querySelector('[data-sa-upload-form]');
		if (!uploadForm) { return; }

		// 附件项：选择文件即自动上传（无单项按钮）。
		var fileInputs = uploadForm.querySelectorAll('[data-sa-upload-file]');
		Array.prototype.forEach.call(fileInputs, function (input) {
			input.addEventListener('change', function () {
				var item = input.closest('[data-sa-upload-item]');
				if (!item) { return; }
				if (!input.files || !input.files.length) { return; }
				autoUploadFile(item, input);
			});

			// 拖拽高亮 + 拖入即上传。
			var zone = input.closest('[data-sa-dropzone]');
			if (!zone) { return; }
			['dragenter', 'dragover'].forEach(function (ev) {
				zone.addEventListener(ev, function (e) {
					e.preventDefault();
					if (input.disabled) { return; }
					zone.classList.add('is-dragover');
				});
			});
			['dragleave', 'drop'].forEach(function (ev) {
				zone.addEventListener(ev, function (e) {
					e.preventDefault();
					zone.classList.remove('is-dragover');
				});
			});
			zone.addEventListener('drop', function (e) {
				if (input.disabled) { return; }
				var dtFiles = e.dataTransfer && e.dataTransfer.files;
				if (!dtFiles || !dtFiles.length) { return; }
				input.files = dtFiles;
				var item = input.closest('[data-sa-upload-item]');
				if (item) { autoUploadFile(item, input); }
			});
		});

		// 页尾总提交按钮。
		var finalBtn = uploadForm.querySelector('[data-sa-upload-final-submit]');
		if (finalBtn) {
			finalBtn.addEventListener('click', function () {
				submitFinal(uploadForm, finalBtn);
			});
		}

		// 首次进入（含已回显的已传项）刷新总按钮可用性。
		refreshFinalButton();
	}

	// 附件自动上传：成功后 dropzone 变绿 + 显示文件名，失败原地可重选。
	function autoUploadFile(item, input) {
		var statusEl = item.querySelector('[data-sa-upload-status]');
		var zone = item.querySelector('[data-sa-dropzone]');
		var titleEl = item.querySelector('[data-sa-dropzone-title]');
		var docType = item.getAttribute('data-doc-type');
		var selectionId = item.getAttribute('data-selection-id');
		var userId = item.getAttribute('data-user-id');
		var fileName = input.files[0] ? input.files[0].name : '';

		var fd = new FormData();
		fd.append('doc_type', docType);
		fd.append('selection_id', selectionId);
		fd.append('user_id', userId);
		fd.append('file', input.files[0]);

		input.disabled = true;
		item.classList.remove('is-uploaded');
		if (zone) { zone.classList.remove('is-uploaded'); zone.classList.add('is-loading'); }
		if (titleEl && fileName) { titleEl.textContent = fileName; }
		setStatus(statusEl, cfg.i18n.submitting, '');

		fetch(cfg.uploadEndpoint, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: fd
		})
			.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
			.then(function (res) {
				if (zone) { zone.classList.remove('is-loading'); }
				if (res.ok && res.data && res.data.ok) {
					setStatus(statusEl, cfg.i18n.uploadedLabel || '提出済み', 'ok');
					item.classList.add('is-uploaded');
					if (zone) { zone.classList.add('is-uploaded'); }
					if (titleEl && fileName) { titleEl.textContent = fileName; }
					refreshFinalButton();
				} else {
					// 失败：解禁输入允许重选，dropzone 复位。
					input.disabled = false;
					input.value = '';
					if (titleEl) { titleEl.textContent = cfg.i18n.reselect || 'ファイルを選択'; }
					var m = (res.data && res.data.message) ? res.data.message : cfg.i18n.uploadErr;
					setStatus(statusEl, m, 'err');
				}
			})
			.catch(function () {
				if (zone) { zone.classList.remove('is-loading'); }
				input.disabled = false;
				input.value = '';
				if (titleEl) { titleEl.textContent = cfg.i18n.reselect || 'ファイルを選択'; }
				setStatus(statusEl, cfg.i18n.uploadErr, 'err');
			});
	}

	// 所有必交附件是否均已上传。
	function allRequiredDone() {
		var required = document.querySelectorAll('[data-sa-upload-item][data-required="1"]');
		if (!required.length) { return true; }
		return Array.prototype.every.call(required, function (el) {
			return el.classList.contains('is-uploaded');
		});
	}

	// 按必交项完成情况启用/禁用页尾总提交按钮。
	function refreshFinalButton() {
		var finalBtn = document.querySelector('[data-sa-upload-final-submit]');
		if (!finalBtn) { return; }
		finalBtn.disabled = !allRequiredDone();
	}

	// 点击总提交：若有文本则先提交文本，再跳转成功页。
	function submitFinal(uploadForm, finalBtn) {
		if (finalBtn.disabled) { return; }
		if (!allRequiredDone()) { refreshFinalButton(); return; }

		finalBtn.disabled = true;

		var finalWrap = uploadForm.querySelector('[data-sa-upload-final]');
		var textEl = finalWrap ? finalWrap.querySelector('[data-sa-upload-text]') : null;
		var statusEl = uploadForm.querySelector('[data-sa-upload-final-status]');
		var text = textEl ? textEl.value.trim() : '';

		var go = function () {
			window.location.href = cfg.thanksUrl || '/thanks/';
		};

		// 无文本项或未填写：直接跳转。
		if (!textEl || !text || !finalWrap) { go(); return; }

		var fd = new FormData();
		fd.append('doc_type', finalWrap.getAttribute('data-doc-type'));
		fd.append('selection_id', finalWrap.getAttribute('data-selection-id'));
		fd.append('user_id', finalWrap.getAttribute('data-user-id'));
		fd.append('text', text);

		setStatus(statusEl, cfg.i18n.submitting, '');

		fetch(cfg.uploadEndpoint, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: fd
		})
			.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
			.then(function (res) {
				// 文本为可选补充，成功失败都跳转（失败不阻断，附件已完成）。
				go();
			})
			.catch(function () { go(); });
	}

	function setStatus(el, text, type) {
		if (!el) { return; }
		el.textContent = text;
		el.className = 'sa-upload-status' + (type ? ' sa-upload-status--' + type : '');
	}

	// -------- 首页轮播（原生，无依赖） --------
	initCarousel();

	function initCarousel() {
		var root = document.querySelector('[data-sa-carousel]');
		if (!root) { return; }
		var track = root.querySelector('.sa-carousel__track');
		var slides = root.querySelectorAll('.sa-carousel__slide');
		var prev = root.querySelector('.sa-carousel__prev');
		var next = root.querySelector('.sa-carousel__next');
		var dotsWrap = root.querySelector('.sa-carousel__dots');
		if (!track || slides.length === 0) { return; }

		var index = 0;
		var timer = null;

		var dots = [];
		if (dotsWrap) {
			Array.prototype.forEach.call(slides, function (s, i) {
				var dot = document.createElement('button');
				dot.type = 'button';
				dot.className = 'sa-carousel__dot';
				dot.setAttribute('aria-label', 'slide ' + (i + 1));
				dot.addEventListener('click', function () { go(i); restart(); });
				dotsWrap.appendChild(dot);
				dots.push(dot);
			});
		}

		function go(i) {
			index = (i + slides.length) % slides.length;
			track.style.transform = 'translateX(' + (-index * 100) + '%)';
			dots.forEach(function (d, di) {
				d.className = 'sa-carousel__dot' + (di === index ? ' is-active' : '');
			});
		}
		function nextSlide() { go(index + 1); }
		function prevSlide() { go(index - 1); }
		function start() { timer = window.setInterval(nextSlide, 5000); }
		function restart() { if (timer) { window.clearInterval(timer); } start(); }

		if (next) { next.addEventListener('click', function () { nextSlide(); restart(); }); }
		if (prev) { prev.addEventListener('click', function () { prevSlide(); restart(); }); }

		go(0);
		start();
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
