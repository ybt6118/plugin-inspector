(function () {
	'use strict';
	var btn = document.getElementById('pei-scan');
	if (!btn) return;
	var progress = document.getElementById('pei-progress');
	var bar = document.querySelector('.pei-bar');
	var BATCH = 20;

	function post(action, extra) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', PEI_ADMIN.nonce);
		(extra || []).forEach(function (pair) { body.append(pair[0], pair[1]); });
		return fetch(PEI_ADMIN.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (!j || !j.success) throw new Error((j && j.data) || 'Request failed'); return j.data; });
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		progress.textContent = 'Collecting pages\u2026';
		bar.hidden = false;
		post('pei_scan_start').then(function (data) {
			var ids = data.ids, done = 0;
			function next() {
				if (done >= ids.length) {
					progress.textContent = 'Finishing\u2026';
					return post('pei_scan_finish').then(function () { location.reload(); });
				}
				var chunk = ids.slice(done, done + BATCH);
				return post('pei_scan_batch', chunk.map(function (id) { return ['ids[]', id]; })).then(function () {
					done += chunk.length;
					progress.textContent = 'Scanned ' + done + ' of ' + ids.length;
					bar.firstElementChild.style.width = (ids.length ? (done / ids.length) * 100 : 100) + '%';
					return next();
				});
			}
			return next();
		}).catch(function (e) {
			progress.textContent = 'Error: ' + e.message;
			btn.disabled = false;
		});
	});
})();
