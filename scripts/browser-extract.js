/**
 * browser-extract.js — 在浏览器里提取院校官网的学费与募集信息。
 *
 * ====================================================================
 * 为什么需要在浏览器里跑，而不是继续用服务器脚本
 * ====================================================================
 *
 * scripts/fetch-school-data.php 在生产服务器上遇到三类障碍，
 * 其中两类在服务器端无解：
 *
 *   akamonkai / kla  TCP 层就连不上 —— 对方按 IP 段拦了服务器出口。
 *                    换 TLS 栈、改 UA 都没用。用你自己的网络就能通。
 *   isi              TLS 握手失败 —— CentOS 7 的 OpenSSL 1.0.2 太旧。
 *                    你电脑上的浏览器是现代 TLS，没有这个问题。
 *   jp-sji / tokyoia 学费由 JavaScript 动态渲染 —— 静态 HTML 里
 *                    「授業料：」冒号后面是空的。这一类任何
 *                    curl / PHP 抓取器都拿不到，因为它们不执行 JS。
 *
 * 浏览器读的是渲染完成后的 DOM，三类障碍一并解决，且无需安装任何东西。
 *
 * ====================================================================
 * 为什么必须保留表格结构
 * ====================================================================
 *
 * 服务器那版脚本把 <table> 拍平成了纯文本行，列标题随之丢失。
 * 结果是：ヒューマンアカデミー的五个金额抓到了，但「哪个金额对应
 * 哪个课程时长」无从判断 —— 只能靠算术一致性反推，再请人对照官网核对。
 *
 * 学费表几乎全是二维结构（行=课程/期间，列=费用项目），丢掉任何一维
 * 都会让数据失去意义。本脚本按行列还原表格，并标出表头。
 *
 * ====================================================================
 * 用法
 * ====================================================================
 *
 * 对每一所院校的「学費」页面各做一次：
 *
 *   1. 在浏览器里打开该页面，等页面完全加载（有些学费是延迟渲染的）
 *   2. 按 F12 打开开发者工具，切到 Console（控制台）标签
 *   3. 如果是第一次用 Chrome 的控制台，它会要求你手动输入 allow pasting
 *      后才允许粘贴 —— 照做即可
 *   4. 把本文件全部内容粘贴进去，回车
 *   5. 结果会自动复制到剪贴板（同时也打印在控制台里）
 *   6. 粘贴给我
 *
 * 需要抓的页面：
 *   https://www.isi-education.com/ja/        学費 / 募集要項
 *   https://akamonkai.ac.jp/                 学費 / 募集要項
 *   https://www.kla.ac/ja/                   学費 / 募集要項
 *   https://group.jp-sji.org/sjs/            学費
 *   https://group.jp-sji.org/sji/            学費
 *   https://group.jp-sji.org/sls/            学費
 *   https://www.tokyoia.com/                 学費
 *
 * 找不到「学費」页时，把「募集要項」或「よくある質問」里提到金额的部分
 * 一起抓下来也可以 —— 原文照抄即可，不必整理。
 */

(function () {
	'use strict';

	/* 学费与募集信息相关的关键词。命中任一即认为该段文本值得保留。 */
	var KEYWORDS = [
		'学費', '学费', '授業料', '入学金', '選考料', '選考費', '教材費', '施設費',
		'維持費', '合計', '納入', '費用', '円', '万円',
		'コース', '課程', '期間', '修業', 'ヶ月', 'か月', '年間',
		'定員', '募集', '入学時期', '出願',
		'日本語能力', 'JLPT', 'N1', 'N2', 'N3', 'N4', 'N5', 'EJU',
		'tuition', 'fee', 'admission', 'JPY'
	];

	function hasKeyword(s) {
		for (var i = 0; i < KEYWORDS.length; i++) {
			if (s.indexOf(KEYWORDS[i]) !== -1) { return true; }
		}
		return false;
	}

	function clean(s) {
		return (s || '')
			.replace(/ /g, ' ')      // 不换行空格，日文站点里很常见
			.replace(/[\t\r\n]+/g, ' ')
			.replace(/\s{2,}/g, ' ')
			.trim();
	}

	/* 元素是否实际可见。隐藏的 tab 面板里常有重复的旧价格表，会造成干扰。 */
	function visible(el) {
		if (!el || !el.getBoundingClientRect) { return false; }
		var r = el.getBoundingClientRect();
		if (r.width === 0 && r.height === 0) { return false; }
		var st = window.getComputedStyle(el);
		return st.display !== 'none' && st.visibility !== 'hidden' && st.opacity !== '0';
	}

	var out = [];
	function push(s) { out.push(s); }

	push('==================================================================');
	push('院校信息提取结果');
	push('页面: ' + document.title);
	push('URL : ' + location.href);
	push('时间: ' + new Date().toISOString());
	push('==================================================================');
	push('');

	/* ---------------- 表格：按行列还原，保留表头 ---------------- */
	var tables = document.querySelectorAll('table');
	var tableCount = 0;

	for (var t = 0; t < tables.length; t++) {
		var tbl = tables[t];
		if (!visible(tbl)) { continue; }

		var rows = tbl.rows;
		if (!rows || rows.length === 0) { continue; }

		/* 先看整张表值不值得保留 —— 否则会把导航、页脚里的布局表格也抓进来 */
		if (!hasKeyword(clean(tbl.innerText || tbl.textContent))) { continue; }

		var matrix = [];
		for (var r = 0; r < rows.length; r++) {
			var cells = rows[r].cells;
			var line = [];
			for (var c = 0; c < cells.length; c++) {
				var cell = cells[c];
				var txt = clean(cell.innerText || cell.textContent);
				/*
				 * colspan / rowspan 会让各行列数不一致。
				 * 这里如实标注而不是猜测填充 —— 补错了位置，
				 * 金额就会被挂到错误的课程上。
				 */
				var span = '';
				if (cell.colSpan > 1) { span += '[跨' + cell.colSpan + '列]'; }
				if (cell.rowSpan > 1) { span += '[跨' + cell.rowSpan + '行]'; }
				line.push(span + txt);
			}
			if (line.join('').trim() !== '') { matrix.push(line); }
		}

		if (matrix.length === 0) { continue; }

		tableCount++;
		push('------------------------------------------------------------------');
		push('【表格 ' + tableCount + '】');

		/* 表头：优先用 <th>，没有就把首行当表头并注明是推断的 */
		var headerCells = rows[0].querySelectorAll ? rows[0].querySelectorAll('th') : [];
		if (headerCells && headerCells.length > 0) {
			push('（首行为 <th>，确定是表头）');
		} else {
			push('（该表无 <th>，以下首行按位置推断为表头，请核对）');
		}

		for (var m = 0; m < matrix.length; m++) {
			push((m === 0 ? '表头 | ' : '     | ') + matrix[m].join(' | '));
		}
		push('');
	}

	/* ---------------- 定义列表：部分站点用 dl/dt/dd 排版费用 ---------------- */
	var dls = document.querySelectorAll('dl');
	var dlCount = 0;
	for (var d = 0; d < dls.length; d++) {
		var dl = dls[d];
		if (!visible(dl)) { continue; }
		if (!hasKeyword(clean(dl.innerText || dl.textContent))) { continue; }

		var pairs = [];
		var kids = dl.children;
		var curKey = null;
		for (var k = 0; k < kids.length; k++) {
			var tag = kids[k].tagName;
			var val = clean(kids[k].innerText || kids[k].textContent);
			if (tag === 'DT') { curKey = val; }
			else if (tag === 'DD' && curKey !== null) { pairs.push(curKey + ' : ' + val); }
		}
		if (pairs.length === 0) { continue; }

		dlCount++;
		push('------------------------------------------------------------------');
		push('【定义列表 ' + dlCount + '】');
		for (var p = 0; p < pairs.length; p++) { push('  ' + pairs[p]); }
		push('');
	}

	/* ---------------- 其余含关键词的文本行 ---------------- */
	push('------------------------------------------------------------------');
	push('【其余关键信息行】（表格与定义列表之外的内容）');

	var seen = {};
	var lineCount = 0;
	var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
	var node;
	while ((node = walker.nextNode())) {
		var parent = node.parentElement;
		if (!parent) { continue; }

		var tag = parent.tagName;
		if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') { continue; }
		/* 表格与 dl 已单独处理，跳过避免重复 */
		if (parent.closest && (parent.closest('table') || parent.closest('dl'))) { continue; }
		if (!visible(parent)) { continue; }

		var text = clean(node.nodeValue);
		if (text.length < 3 || text.length > 300) { continue; }
		if (!hasKeyword(text)) { continue; }
		if (seen[text]) { continue; }

		seen[text] = true;
		lineCount++;
		push('  ' + text);
		if (lineCount >= 200) {
			push('  …（已达 200 行上限，其余省略）');
			break;
		}
	}

	/* ---------------- 候选链接：找出本站的学费 / 募集要項页面 ---------------- */
	/*
	 * 为什么需要这一段：
	 *
	 * 各校官网的学费页路径毫无规律 —— /tuition/、/fee/、/admission/cost/、
	 * /japanese/fees.html 都见过。凭猜测给出深层 URL 只会点出一堆 404。
	 * 所以在首页跑一次本脚本，让它把像学费页的链接列出来，照着点即可。
	 */
	var LINK_WORDS = [
		'学費', '学费', '費用', '授業料', '料金', '納入',
		'募集', '出願', '入学', '願書', 'コース', '課程',
		'tuition', 'fee', 'cost', 'admission', 'apply', 'course', 'program'
	];

	var links = document.querySelectorAll('a[href]');
	var cands = [];
	var seenHref = {};

	for (var li = 0; li < links.length; li++) {
		var el   = links[li];
		var href = el.href; // 用 .href 而不是 getAttribute，浏览器会自动补成绝对地址
		if (!href || href.indexOf('http') !== 0) { continue; }
		if (seenHref[href]) { continue; }

		/*
		 * 判断是否同站要真正解析域名。
		 *
		 * 原先写的是 href.indexOf(location.hostname) !== -1 —— 子串匹配，
		 * 形如 https://別サイト/?ref=本站域名 的链接会被误判为同站。
		 *
		 * 外部链接不丢弃、只标注：部分学校把学费页放在集团站或另一个域名下
		 * （千駄ヶ谷就是 group.jp-sji.org 下分三个子路径），直接过滤会漏掉线索。
		 */
		var isExternal = false;
		try {
			isExternal = ( new URL(href).hostname !== location.hostname );
		} catch (e) { /* URL 解析失败时按同站处理，宁可多列不要漏 */ }

		var label = clean(el.innerText || el.textContent);
		var hay   = (label + ' ' + href).toLowerCase();

		var hit = false;
		for (var lw = 0; lw < LINK_WORDS.length; lw++) {
			if (hay.indexOf(LINK_WORDS[lw].toLowerCase()) !== -1) { hit = true; break; }
		}
		if (!hit) { continue; }

		seenHref[href] = true;
		cands.push((isExternal ? '[外部站点] ' : '') + (label || '(无文字)') + '\n      ' + href);
	}

	push('------------------------------------------------------------------');
	push('【候选链接】看起来是学费 / 募集要項的页面，逐个打开后再跑一次本脚本');
	if (cands.length === 0) {
		push('  未找到 —— 可能本页就是学费页，或该站用了图片导航。');
		push('  可手动在站内找「学費」「費用」「募集要項」「入学案内」等入口。');
	} else {
		for (var ci = 0; ci < cands.length && ci < 40; ci++) {
			push('  ' + (ci + 1) + '. ' + cands[ci]);
		}
		if (cands.length > 40) {
			push('  …（共 ' + cands.length + ' 条，只列前 40 条）');
		}
	}
	push('');

	push('==================================================================');
	push('表格 ' + tableCount + ' 个，定义列表 ' + dlCount + ' 个，其余关键行 ' + lineCount
		+ ' 条，候选链接 ' + cands.length + ' 条');
	push('==================================================================');

	var result = out.join('\n');

	/*
	 * 复制到剪贴板。
	 *
	 * copy() 是开发者工具控制台自带的函数，Chrome / Firefox / Safari 都有，
	 * 比 navigator.clipboard 可靠 —— 后者要求安全上下文与用户手势，
	 * 在控制台里直接调用经常被拒。
	 * 两者都不可用时退回手动选择。
	 */
	console.log(result);

	try {
		if (typeof copy === 'function') {
			copy(result);
			console.log('%c✓ 已复制到剪贴板，直接粘贴即可', 'color:#16a34a;font-weight:bold');
			return;
		}
	} catch (e) { /* 继续尝试下面的方式 */ }

	try {
		var ta = document.createElement('textarea');
		ta.value = result;
		ta.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:60%;z-index:2147483647;font:12px monospace';
		document.body.appendChild(ta);
		ta.select();
		var ok = document.execCommand('copy');
		if (ok) {
			ta.remove();
			console.log('%c✓ 已复制到剪贴板', 'color:#16a34a;font-weight:bold');
		} else {
			console.log('%c! 自动复制失败：内容已显示在页面顶部的文本框里，请手动全选复制',
				'color:#d97706;font-weight:bold');
		}
	} catch (e2) {
		console.log('%c! 自动复制失败：请在上面的输出里手动选择复制',
			'color:#d97706;font-weight:bold');
	}
})();
