(function () {
	'use strict';

	var D = window.PEI_DATA || { widgets: {} };
	var marks = [];
	var palette = {};
	var filterKey = null;

	function color(key) {
		if (palette[key]) return palette[key];
		if (key === 'unknown') return (palette[key] = '#d63638');
		var h = 0;
		for (var i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) >>> 0;
		return (palette[key] = 'hsl(' + (h % 360) + ',70%,42%)');
	}
	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function mark(el, info) {
		if (el.__pei || el.closest('#pei-panel')) return;
		el.__pei = info;
		el.setAttribute('data-pei', info.key);
		el.style.setProperty('--pei-color', color(info.key));
		marks.push({ el: el, info: info });
	}

	function collect() {
		// 1. Shortcodes and blocks (wrapped in HTML comments by the server).
		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT, null);
		var stack = [], pairs = [], node;
		while ((node = walker.nextNode())) {
			var v = node.nodeValue;
			if (v.indexOf('pei-start ') === 0) {
				var data = null;
				try { data = JSON.parse(v.slice(10)); } catch (e) {}
				stack.push({ node: node, data: data });
			} else if (v === 'pei-end' && stack.length) {
				var s = stack.pop();
				if (s.data) pairs.push({ start: s.node, end: node, d: s.data });
			}
		}
		pairs.forEach(function (p) {
			if (p.start.parentNode !== p.end.parentNode) return;
			for (var n = p.start.nextSibling; n && n !== p.end; n = n.nextSibling) {
				if (n.nodeType === 1) mark(n, { plugin: p.d.p, key: p.d.s, kind: p.d.k, name: p.d.n });
			}
		});

		// 2. Elementor widgets (also covers header/footer/popup templates).
		document.querySelectorAll('[data-widget_type]').forEach(function (el) {
			var t = (el.getAttribute('data-widget_type') || '').split('.')[0];
			var w = D.widgets[t];
			mark(el, w
				? { plugin: w.p, key: w.k, kind: 'Elementor widget', name: w.t + ' (' + t + ')' }
				: { plugin: 'Not registered (plugin inactive / widget disabled)', key: 'unknown', kind: 'Elementor widget', name: t });
		});
	}

	function group(list, keyFn) {
		var g = {};
		list.forEach(function (x) {
			var k = keyFn(x);
			if (!g[k.key]) g[k.key] = { key: k.key, name: k.name, items: [] };
			g[k.key].items.push(x);
		});
		return Object.keys(g).map(function (k) { return g[k]; })
			.sort(function (a, b) { return b.items.length - a.items.length; });
	}

	function buildPanel() {
		var panel = document.createElement('div');
		panel.id = 'pei-panel';

		var byPlugin = group(marks, function (m) { return { key: m.info.key, name: m.info.plugin }; });
		var elHtml = byPlugin.length ? byPlugin.map(function (g) {
			var names = {};
			g.items.forEach(function (m) { names[m.info.name] = (names[m.info.name] || 0) + 1; });
			var sub = Object.keys(names).map(function (n) { return esc(n) + (names[n] > 1 ? ' \u00d7' + names[n] : ''); }).join(', ');
			return '<li data-key="' + esc(g.key) + '"><span class="pei-sw" style="background:' + color(g.key) + '"></span>' +
				'<b>' + esc(g.name) + '</b> <span class="pei-n">' + g.items.length + '</span><div class="pei-sub">' + sub + '</div></li>';
		}).join('') : '<li class="pei-empty">No plugin elements detected on this page.</li>';

		var A = window.PEI_ASSETS || { scripts: [], styles: [] };
		var all = A.scripts.map(function (a) { return { h: a.h, p: a.p, k: a.k, t: 'JS' }; })
			.concat(A.styles.map(function (a) { return { h: a.h, p: a.p, k: a.k, t: 'CSS' }; }));
		var byAsset = group(all, function (a) { return { key: a.k, name: a.p }; });
		var asHtml = byAsset.map(function (g) {
			var js = g.items.filter(function (a) { return a.t === 'JS'; }).length;
			return '<li><span class="pei-sw" style="background:' + color(g.key) + '"></span><b>' + esc(g.name) + '</b> ' +
				'<span class="pei-n">' + js + ' JS \u00b7 ' + (g.items.length - js) + ' CSS</span>' +
				'<div class="pei-sub">' + g.items.map(function (a) { return esc(a.h); }).join(', ') + '</div></li>';
		}).join('');

		panel.innerHTML =
			'<div class="pei-head"><strong>Smart Plugin Inspector</strong>' +
			'<span><button type="button" data-act="outline" title="Show/hide outlines">Outlines</button>' +
			'<button type="button" data-act="min" title="Minimise">\u2013</button>' +
			'<a href="' + esc(D.exitUrl || '?') + '" title="Exit">\u00d7</a></span></div>' +
			'<div class="pei-body">' +
			'<div class="pei-tabs"><button type="button" data-tab="el" class="on">Elements (' + marks.length + ')</button>' +
			'<button type="button" data-tab="as">CSS/JS files (' + all.length + ')</button></div>' +
			'<div class="pei-tab" data-pane="el"><p class="pei-hint">Hover any outlined element to see its plugin. Click a plugin below to highlight only its elements.</p><ul>' + elHtml + '</ul></div>' +
			'<div class="pei-tab" data-pane="as" hidden><p class="pei-hint">Files loaded on this page. A plugin that loads files here but has no elements may be slowing the page for nothing.</p><ul>' + asHtml + '</ul></div>' +
			'<p class="pei-foot"><a href="' + esc(D.report || '#') + '" target="_blank">Open full site report \u2192</a></p>' +
			'</div>';
		document.body.appendChild(panel);

		panel.addEventListener('click', function (e) {
			var t = e.target.closest('button, li[data-key]');
			if (!t) return;
			if (t.dataset.act === 'min') { panel.classList.toggle('pei-min'); return; }
			if (t.dataset.act === 'outline') { document.body.classList.toggle('pei-on'); return; }
			if (t.dataset.tab) {
				panel.querySelectorAll('[data-tab]').forEach(function (b) { b.classList.toggle('on', b === t); });
				panel.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== t.dataset.tab; });
				return;
			}
			if (t.dataset.key) setFilter(t.dataset.key === filterKey ? null : t.dataset.key);
		});
	}

	function setFilter(key) {
		filterKey = key;
		document.body.classList.toggle('pei-filter', !!key);
		document.querySelectorAll('#pei-panel li[data-key]').forEach(function (li) { li.classList.toggle('on', li.dataset.key === key); });
		var first = null;
		marks.forEach(function (m) {
			var on = m.info.key === key;
			m.el.classList.toggle('pei-sel', on);
			if (on && !first) first = m.el;
		});
		if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	function tooltip() {
		var tip = document.createElement('div');
		tip.id = 'pei-tip';
		document.body.appendChild(tip);
		var current = null;
		document.addEventListener('mouseover', function (e) {
			if (!document.body.classList.contains('pei-on')) return;
			var el = e.target.closest ? e.target.closest('[data-pei]') : null;
			if (e.target.closest && e.target.closest('#pei-panel')) el = null;
			if (current && current !== el) current.classList.remove('pei-hover');
			current = el;
			if (!el) { tip.style.display = 'none'; return; }
			el.classList.add('pei-hover');
			var i = el.__pei;
			tip.innerHTML = '<b>' + esc(i.plugin) + '</b><br>' + esc(i.kind) + ': ' + esc(i.name);
			tip.style.background = color(i.key);
			tip.style.display = 'block';
		});
		document.addEventListener('mousemove', function (e) {
			if (tip.style.display !== 'block') return;
			var x = e.clientX + 14, y = e.clientY + 14;
			if (x + tip.offsetWidth > window.innerWidth) x = e.clientX - tip.offsetWidth - 10;
			if (y + tip.offsetHeight > window.innerHeight) y = e.clientY - tip.offsetHeight - 10;
			tip.style.left = x + 'px';
			tip.style.top = y + 'px';
		});
	}

	function start() {
		collect();
		document.body.classList.add('pei-on');
		buildPanel();
		tooltip();
	}

	if (document.readyState === 'complete') start();
	else window.addEventListener('load', start);
})();
