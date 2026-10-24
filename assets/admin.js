/* Simple Google Indexing API — admin UI behaviour (no dependencies). */
(function () {
	'use strict';

	if (typeof window.SGIA === 'undefined') {
		return;
	}

	var C = window.SGIA;
	var $ = function (sel, root) { return (root || document).querySelector(sel); };
	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
	var fa = function (n) { return Number(n).toLocaleString('fa-IR'); };
	var pretty = function (u) { try { return decodeURI(u); } catch (e) { return u; } };
	var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

	/* ---------------------------------------------------------------- *
	 * AJAX
	 * ---------------------------------------------------------------- */

	function post(action, data) {
		var fd = data instanceof FormData ? data : new FormData();
		if (data && !(data instanceof FormData)) {
			Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		}
		fd.append('action', 'sgia_' + action);
		fd.append('nonce', C.nonce);

		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) {
				return r.json().catch(function () {
					return { success: false, data: { msg: 'پاسخ نامعتبر از سرور (HTTP ' + r.status + ')' } };
				});
			})
			.then(function (j) {
				var d = (j && j.data && typeof j.data === 'object') ? j.data : {};
				if (!j || !j.success) {
					d.ok = false;
					if (!d.msg) { d.msg = j && j.data === -1 ? 'نشست منقضی شده؛ صفحه را تازه کنید.' : 'درخواست ناموفق بود.'; }
				}
				return d;
			})
			.catch(function (e) {
				return { ok: false, msg: 'خطای شبکه: ' + e.message };
			});
	}

	/* ---------------------------------------------------------------- *
	 * UI helpers
	 * ---------------------------------------------------------------- */

	function toastBox() {
		var box = $('.sgia-toasts');
		if (!box) {
			box = document.createElement('div');
			box.className = 'sgia-toasts';
			box.setAttribute('aria-live', 'polite');
			document.body.appendChild(box);
		}
		return box;
	}

	function toast(msg, kind, hint) {
		var el = document.createElement('div');
		el.className = 'sgia-toast' + (kind ? ' is-' + kind : '');
		el.setAttribute('role', kind === 'fail' ? 'alert' : 'status');

		var icon = document.createElement('span');
		icon.className = 'sgia-toast__icon';
		icon.textContent = kind === 'ok' ? '✓' : kind === 'fail' ? '!' : 'i';

		var body = document.createElement('div');
		body.textContent = msg;
		if (hint) {
			var s = document.createElement('small');
			s.textContent = hint;
			body.appendChild(s);
		}

		el.appendChild(icon);
		el.appendChild(body);
		toastBox().appendChild(el);

		setTimeout(function () {
			el.classList.add('is-leaving');
			setTimeout(function () { el.remove(); }, 220);
		}, kind === 'fail' ? 7000 : 3800);
	}

	function loading(btn, on) {
		if (!btn) { return; }
		btn.classList.toggle('is-loading', !!on);
		btn.disabled = !!on;
	}

	function setQuota(q) {
		if (!q) { return; }
		C.quota = q;
		$$('[data-q-used]').forEach(function (el) { el.textContent = fa(q.used); });
		$$('[data-q-limit]').forEach(function (el) { el.textContent = fa(q.limit); });
		$$('[data-q-bar]').forEach(function (el) { el.style.width = q.percent + '%'; });
	}

	function fmtTime(iso) {
		if (!iso) { return '—'; }
		try {
			return new Date(iso).toLocaleString('fa-IR', { dateStyle: 'medium', timeStyle: 'short' });
		} catch (e) {
			return iso;
		}
	}

	/* ---------------------------------------------------------------- *
	 * Global delegated actions
	 * ---------------------------------------------------------------- */

	document.addEventListener('click', function (e) {
		var copy = e.target.closest('[data-sgia-copy]');
		if (copy) {
			e.preventDefault();
			var text = copy.getAttribute('data-sgia-copy');
			var done = function () { toast('ایمیل کپی شد', 'ok', 'در Search Console با نقش Owner اضافه کنید.'); };
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done, function () { window.prompt('کپی کنید:', text); });
			} else {
				window.prompt('کپی کنید:', text);
			}
			return;
		}

		var test = e.target.closest('[data-sgia-test]');
		if (test) {
			e.preventDefault();
			loading(test, true);
			post('test').then(function (d) {
				loading(test, false);
				toast(d.msg, d.ok ? 'ok' : 'fail', d.hint);
			});
			return;
		}

		var toggle = e.target.closest('[data-sgia-toggle]');
		if (toggle) {
			var target = $(toggle.getAttribute('data-sgia-toggle'));
			if (target) {
				target.hidden = !target.hidden;
			}
			return;
		}

		var del = e.target.closest('[data-sgia-delete-key]');
		if (del) {
			if (!window.confirm('کلید سرویس اکانت حذف شود؟ تا بارگذاری کلید جدید، ارسال‌ها متوقف می‌شوند.')) { return; }
			loading(del, true);
			post('delete_key').then(function (d) {
				toast(d.msg, d.ok ? 'ok' : 'fail');
				if (d.ok) { setTimeout(function () { location.reload(); }, 600); } else { loading(del, false); }
			});
			return;
		}

		var row = e.target.closest('[data-sgia-row]');
		if (row) {
			e.preventDefault();
			if (row.dataset.busy) { return; }
			row.dataset.busy = '1';
			var original = row.textContent;
			row.textContent = 'در حال ارسال…';
			post('post_send', { post_id: row.getAttribute('data-sgia-row'), type: 'update' }).then(function (d) {
				delete row.dataset.busy;
				row.textContent = d.ok ? '✓ ارسال شد' : '✕ ناموفق';
				row.style.color = d.ok ? '#13894a' : '#d1343b';
				toast(d.ok ? 'درخواست ایندکس ارسال شد' : d.msg, d.ok ? 'ok' : 'fail', d.hint);
				var dot = $('[data-sgia-dot="' + row.getAttribute('data-sgia-row') + '"]');
				if (dot) { dot.className = 'sgia-dot ' + (d.ok ? 'is-ok' : 'is-fail'); }
				setTimeout(function () { row.textContent = original; row.style.color = ''; }, 4000);
			});
		}
	});

	/* ---------------------------------------------------------------- *
	 * Submit tab
	 * ---------------------------------------------------------------- */

	var urlsBox = $('#sgia-urls');

	if (urlsBox) {
		var runBtn = $('#sgia-run');
		var stopBtn = $('#sgia-stop');
		var countValid = $('#sgia-count-valid');
		var countInvalid = $('#sgia-count-invalid');
		var quotaWarn = $('#sgia-quota-warn');
		var progress = $('#sgia-progress');
		var progressBar = $('#sgia-progress-bar');
		var progressLabel = $('#sgia-progress-label');
		var progressSummary = $('#sgia-progress-summary');
		var results = $('#sgia-results');
		var stopped = false;
		var running = false;

		var currentType = function () {
			var r = $('input[name="sgia-type"]:checked');
			return r ? r.value : 'update';
		};

		var parse = function () {
			var seen = Object.create(null);
			var valid = [];
			var invalid = 0;
			urlsBox.value.split(/\r?\n/).forEach(function (raw) {
				var line = raw.trim();
				if (!line) { return; }
				try {
					var u = new URL(line);
					if (!/^https?:$/.test(u.protocol)) { throw new Error('protocol'); }
					if (!seen[u.href]) {
						seen[u.href] = true;
						valid.push(line);
					}
				} catch (err) {
					invalid++;
				}
			});
			return { valid: valid, invalid: invalid };
		};

		var refresh = function () {
			var p = parse();
			var type = currentType();
			countValid.textContent = fa(p.valid.length) + ' آدرس معتبر';
			countInvalid.hidden = !p.invalid;
			countInvalid.textContent = fa(p.invalid) + ' نامعتبر';

			var over = type !== 'status' && p.valid.length > C.quota.remaining;
			quotaWarn.hidden = !over;
			if (over) {
				quotaWarn.textContent = 'فقط ' + fa(C.quota.remaining) + ' درخواست از سهمیه امروز باقی مانده؛ ' +
					fa(p.valid.length - C.quota.remaining) + ' آدرس آخر ارسال نخواهد شد.';
			}

			var labels = { update: 'شروع ارسال', 'delete': 'ارسال اعلام حذف', status: 'بررسی وضعیت' };
			$('[data-label]', runBtn).textContent = labels[type] + (p.valid.length ? ' (' + fa(p.valid.length) + ')' : '');
			runBtn.disabled = running || !p.valid.length;
		};

		urlsBox.addEventListener('input', refresh);
		$$('input[name="sgia-type"]').forEach(function (r) { r.addEventListener('change', refresh); });

		$$('[data-sgia-fill]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				btn.classList.add('is-loading');
				post('recent', { mode: btn.getAttribute('data-sgia-fill') }).then(function (d) {
					btn.classList.remove('is-loading');
					if (!d.ok) { toast(d.msg, 'fail'); return; }
					var existing = urlsBox.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
					var added = 0;
					(d.urls || []).map(pretty).forEach(function (u) {
						if (existing.indexOf(u) === -1) { existing.push(u); added++; }
					});
					urlsBox.value = existing.join('\n');
					refresh();
					toast(added ? fa(added) + ' آدرس اضافه شد' : 'آدرس جدیدی پیدا نشد', added ? 'ok' : '');
				});
			});
		});

		$('[data-sgia-clear-urls]').addEventListener('click', function () {
			urlsBox.value = '';
			refresh();
			urlsBox.focus();
		});

		stopBtn.addEventListener('click', function () {
			stopped = true;
			stopBtn.disabled = true;
		});

		var addResult = function (url) {
			var li = document.createElement('li');
			li.className = 'sgia-result is-pending';
			li.innerHTML = '<span class="sgia-result__icon"></span><span class="sgia-result__url" dir="ltr"></span><span class="sgia-result__msg">در صف…</span>';
			$('.sgia-result__url', li).textContent = pretty(url);
			$('.sgia-result__url', li).title = pretty(url);
			results.appendChild(li);
			return li;
		};

		var setResult = function (li, kind, msg, hint) {
			li.className = 'sgia-result is-' + kind;
			$('.sgia-result__icon', li).textContent = kind === 'ok' ? '✓' : kind === 'fail' ? '!' : '–';
			var m = $('.sgia-result__msg', li);
			m.textContent = msg;
			if (hint) {
				var s = document.createElement('small');
				s.textContent = hint;
				m.appendChild(s);
			}
		};

		runBtn.addEventListener('click', async function () {
			var list = parse().valid;
			var type = currentType();
			if (!list.length) { return; }

			running = true;
			stopped = false;
			runBtn.disabled = true;
			urlsBox.readOnly = true;
			stopBtn.hidden = false;
			stopBtn.disabled = false;
			progress.hidden = false;
			results.innerHTML = '';

			var rows = list.map(addResult);
			var ok = 0;
			var fail = 0;
			var hardStop = '';

			for (var i = 0; i < list.length; i++) {
				var li = rows[i];

				if (stopped || hardStop) {
					setResult(li, 'skip', hardStop || 'لغو شد');
					continue;
				}

				$('.sgia-result__msg', li).textContent = 'در حال ارسال…';
				li.scrollIntoView({ block: 'nearest' });
				progressLabel.textContent = 'در حال پردازش ' + fa(i + 1) + ' از ' + fa(list.length);

				var d = await post('submit', { url: list[i], type: type });

				if (d.ok) {
					ok++;
					var msg = type === 'status'
						? 'آخرین به‌روزرسانی: ' + fmtTime(d.meta && d.meta.updated) + ' · آخرین حذف: ' + fmtTime(d.meta && d.meta.removed)
						: (type === 'delete' ? 'حذف اعلام شد' : 'ارسال شد');
					setResult(li, 'ok', msg);
				} else {
					fail++;
					setResult(li, 'fail', d.msg + (d.code ? ' (HTTP ' + d.code + ')' : ''), d.hint);
					if (d.code === 429 || /سهمیه|کلید|احراز/.test(d.msg || '')) {
						hardStop = 'متوقف شد: ' + (d.code === 429 ? 'سهمیه گوگل پر شده' : d.msg);
					}
				}

				setQuota(d.quota);
				progressBar.style.width = Math.round(((i + 1) / list.length) * 100) + '%';
				progressSummary.textContent = fa(ok) + ' موفق · ' + fa(fail) + ' ناموفق';

				if (i < list.length - 1) { await sleep(120); }
			}

			running = false;
			urlsBox.readOnly = false;
			stopBtn.hidden = true;
			progressLabel.textContent = stopped ? 'متوقف شد' : (hardStop ? hardStop : 'تمام شد');
			refresh();

			if (!fail && !stopped && !hardStop) {
				toast(fa(ok) + ' آدرس با موفقیت پردازش شد', 'ok');
			} else {
				toast(fa(ok) + ' موفق، ' + fa(fail) + ' ناموفق', fail ? 'fail' : '', hardStop || '');
			}
		});

		refresh();
	}

	/* ---------------------------------------------------------------- *
	 * Settings tab
	 * ---------------------------------------------------------------- */

	var settingsForm = $('#sgia-settings-form');

	if (settingsForm) {
		var drop = $('#sgia-drop');
		var fileInput = $('#sgia-key-file');
		var keyJson = $('#sgia-key-json');
		var preview = $('#sgia-drop-preview');

		var showPreview = function (ok, html) {
			if (!preview) { return; }
			preview.hidden = false;
			preview.className = 'sgia-drop-preview ' + (ok ? 'is-ok' : 'is-fail');
			preview.innerHTML = '';
			var icon = document.createElement('strong');
			icon.textContent = ok ? '✓' : '!';
			var text = document.createElement('span');
			text.innerHTML = html;
			preview.appendChild(icon);
			preview.appendChild(text);
		};

		var escapeHtml = function (s) {
			var d = document.createElement('div');
			d.textContent = s;
			return d.innerHTML;
		};

		var checkJson = function (text) {
			var data;
			try {
				data = JSON.parse(text);
			} catch (e) {
				showPreview(false, 'این فایل JSON معتبر نیست.');
				return false;
			}
			if (data.type && data.type !== 'service_account') {
				showPreview(false, 'این فایل کلید سرویس اکانت نیست (type = ' + escapeHtml(String(data.type)) + ').');
				return false;
			}
			if (!data.client_email || !data.private_key) {
				showPreview(false, 'فیلدهای client_email یا private_key پیدا نشد.');
				return false;
			}
			showPreview(true, 'فایل معتبر است: <code dir="ltr">' + escapeHtml(data.client_email) + '</code> — برای اعمال، «ذخیره تنظیمات» را بزنید.');
			return true;
		};

		var readFile = function (file) {
			if (!file) { return; }
			if (file.size > 50 * 1024) {
				showPreview(false, 'حجم فایل بیش از حد انتظار است؛ فایل کلید JSON معمولاً حدود ۲ کیلوبایت است.');
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				var text = String(reader.result || '');
				if (checkJson(text)) {
					keyJson.value = text;
				}
			};
			reader.readAsText(file);
		};

		if (drop) {
			['dragenter', 'dragover'].forEach(function (ev) {
				drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
			});
			['dragleave', 'drop'].forEach(function (ev) {
				drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
			});
			drop.addEventListener('drop', function (e) {
				readFile(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]);
			});
			fileInput.addEventListener('change', function () { readFile(fileInput.files[0]); });
			keyJson.addEventListener('input', function () {
				if (keyJson.value.trim()) { checkJson(keyJson.value); } else if (preview) { preview.hidden = true; }
			});
		}

		settingsForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = $('button[type="submit"]', settingsForm);
			loading(btn, true);
			post('save_settings', new FormData(settingsForm)).then(function (d) {
				toast(d.msg, d.ok ? 'ok' : 'fail', d.hint);
				if (d.ok && d.reload) {
					setTimeout(function () { location.reload(); }, 700);
				} else {
					loading(btn, false);
				}
			});
		});
	}

	/* ---------------------------------------------------------------- *
	 * Queue tab
	 * ---------------------------------------------------------------- */

	var queue = $('#sgia-queue');

	if (queue) {
		var qDrop = $('#sgia-q-drop');
		var qFile = $('#sgia-q-file');
		var qPicked = $('#sgia-q-picked');
		var qText = $('#sgia-q-text');
		var qImport = $('#sgia-q-import');
		var picked = null;
		var reload = function (ms) { setTimeout(function () { location.reload(); }, ms || 1200); };

		var refreshImport = function () {
			qImport.disabled = !picked && !qText.value.trim();
		};

		var setPicked = function (f) {
			picked = f || null;
			qPicked.hidden = !picked;
			if (picked) {
				$('[data-name]', qPicked).textContent = picked.name;
				$('[data-size]', qPicked).textContent = fa(Math.max(1, Math.round(picked.size / 1024))) + ' کیلوبایت';
			} else {
				qFile.value = '';
			}
			refreshImport();
		};

		['dragenter', 'dragover'].forEach(function (ev) {
			qDrop.addEventListener(ev, function (e) { e.preventDefault(); qDrop.classList.add('is-over'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			qDrop.addEventListener(ev, function (e) { e.preventDefault(); qDrop.classList.remove('is-over'); });
		});
		qDrop.addEventListener('drop', function (e) {
			setPicked(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]);
		});
		qFile.addEventListener('change', function () { setPicked(qFile.files[0]); });
		$('[data-sgia-q-unpick]').addEventListener('click', function () { setPicked(null); });
		qText.addEventListener('input', refreshImport);

		qImport.addEventListener('click', function () {
			var fd = new FormData();
			if (picked) {
				fd.append('file', picked, picked.name);
			} else {
				fd.append('text', qText.value);
			}
			loading(qImport, true);
			post('queue_import', fd).then(function (d) {
				toast(d.msg, d.ok ? 'ok' : 'fail', d.hint);
				if (d.ok && d.added) {
					reload(2200);
				} else {
					loading(qImport, false);
				}
			});
		});

		var qSettings = $('#sgia-q-settings');
		qSettings.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = $('button[type="submit"]', qSettings);
			loading(btn, true);
			post('queue_save', new FormData(qSettings)).then(function (d) {
				toast(d.msg, d.ok ? 'ok' : 'fail', d.hint);
				if (d.ok) { reload(); } else { loading(btn, false); }
			});
		});

		var runBtnQ = $('[data-sgia-q-run]');
		runBtnQ.addEventListener('click', function () {
			loading(runBtnQ, true);
			post('queue_run').then(function (d) {
				setQuota(d.quota);
				toast(d.msg, d.ok ? 'ok' : 'fail');
				reload(2200);
			});
		});

		$$('[data-sgia-q-op]', queue).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var ask = btn.getAttribute('data-confirm');
				if (ask && !window.confirm(ask)) { return; }
				var op = btn.getAttribute('data-sgia-q-op');
				btn.disabled = true;
				post('queue_action', { op: op, id: btn.getAttribute('data-id') || '' }).then(function (d) {
					toast(d.msg, d.ok ? 'ok' : 'fail');
					if (op === 'delete' && d.ok) {
						var tr = btn.closest('tr');
						if (tr) { tr.style.opacity = '0'; setTimeout(function () { tr.remove(); }, 200); }
					} else if (d.reload) {
						reload();
					} else {
						btn.disabled = false;
					}
				});
			});
		});
	}

	/* ---------------------------------------------------------------- *
	 * Log tab
	 * ---------------------------------------------------------------- */

	var logTable = $('#sgia-log-search') ? $('.sgia-table') : null;

	if (logTable) {
		var filter = 'all';
		var search = $('#sgia-log-search');
		var none = $('.sgia-table__none');

		var apply = function () {
			var q = (search.value || '').trim().toLowerCase();
			var shown = 0;
			$$('tbody tr[data-ok]', logTable).forEach(function (tr) {
				var okMatch = filter === 'all' || tr.getAttribute('data-ok') === (filter === 'ok' ? '1' : '0');
				var textMatch = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
				tr.hidden = !(okMatch && textMatch);
				if (!tr.hidden) { shown++; }
			});
			if (none) { none.hidden = shown > 0; }
		};

		$$('[data-sgia-filter]').forEach(function (b) {
			b.addEventListener('click', function () {
				$$('[data-sgia-filter]').forEach(function (x) { x.classList.remove('is-active'); });
				b.classList.add('is-active');
				filter = b.getAttribute('data-sgia-filter');
				apply();
			});
		});
		search.addEventListener('input', apply);
	}

	var clearLog = $('[data-sgia-clear-log]');
	if (clearLog) {
		clearLog.addEventListener('click', function () {
			if (!window.confirm('همه گزارش‌ها پاک شوند؟')) { return; }
			loading(clearLog, true);
			post('clear_log').then(function (d) {
				toast(d.msg, d.ok ? 'ok' : 'fail');
				setTimeout(function () { location.reload(); }, 600);
			});
		});
	}

	/* ---------------------------------------------------------------- *
	 * Edit-screen meta box
	 * ---------------------------------------------------------------- */

	$$('.sgia-mb').forEach(function (box) {
		var out = $('[data-sgia-mb-out]', box);
		$$('[data-sgia-mb]', box).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var type = btn.getAttribute('data-sgia-mb');
				btn.disabled = true;
				out.className = 'sgia-mb__out';
				out.textContent = type === 'status' ? 'در حال بررسی…' : 'در حال ارسال…';

				post('post_send', { post_id: box.getAttribute('data-post'), type: type }).then(function (d) {
					btn.disabled = false;
					out.className = 'sgia-mb__out ' + (d.ok ? 'is-ok' : 'is-fail');

					if (!d.ok) {
						out.textContent = d.msg;
						if (d.hint) {
							var h = document.createElement('small');
							h.className = 'sgia-hint';
							h.textContent = d.hint;
							out.appendChild(h);
						}
						return;
					}

					if (type === 'status') {
						out.textContent = 'آخرین به‌روزرسانی: ' + fmtTime(d.meta && d.meta.updated) + ' — آخرین حذف: ' + fmtTime(d.meta && d.meta.removed);
					} else {
						out.textContent = '✓ درخواست ایندکس ارسال شد.';
						var status = $('[data-sgia-mb-status]', box);
						if (status) {
							status.innerHTML = '<span class="sgia-dot is-ok"></span><span><strong>ارسال موفق</strong><small>به‌روزرسانی · لحظاتی پیش</small></span>';
						}
					}
				});
			});
		});
	});
})();
