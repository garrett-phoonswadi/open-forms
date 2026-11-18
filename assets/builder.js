(function () {
	var root = document.getElementById('of-builder');
	var out = document.getElementById('of-config');
	if (!root) return;

	var cfg = JSON.parse(out.value);
	cfg.fields = cfg.fields || [];
	var types = JSON.parse(root.getAttribute('data-types'));
	var sel = -1;
	var PRESETS = {
		images: ['jpg', 'jpeg', 'png', 'gif', 'webp'],
		documents: ['pdf', 'txt'],
		cad: ['stl', 'step', 'stp', 'iges', 'igs', 'obj', '3mf', 'dxf', 'dwg', 'ply']
	};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function uniqueName(base) {
		base = (base || 'field').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '') || 'field';
		var n = base, i = 2;
		while (cfg.fields.some(function (f) { return f.name === n; })) n = base + '_' + i++;
		return n;
	}

	function sync() { out.value = JSON.stringify(cfg); }

	function addField(type) {
		cfg.fields.push({
			type: type, name: uniqueName(type), label: type === 'step' ? 'Next step' : type.charAt(0).toUpperCase() + type.slice(1),
			placeholder: '', help: '', required: false, width: 'full', options: [], value: '',
			accept: type === 'file' ? PRESETS.images.concat(['pdf', 'txt']) : [], max_mb: 5, attach: false,
			logic: { enabled: false, field: '', op: 'eq', value: '' }
		});
		sel = cfg.fields.length - 1;
		render();
	}

	function move(i, d) {
		var j = i + d;
		if (j < 0 || j >= cfg.fields.length) return;
		var t = cfg.fields[i]; cfg.fields[i] = cfg.fields[j]; cfg.fields[j] = t;
		sel = j; render();
	}

	function addName() {
		['first', 'last'].forEach(function (p) {
			cfg.fields.push({
				type: 'text', name: uniqueName(p + '_name'), label: p === 'first' ? 'First name' : 'Last name',
				placeholder: '', help: '', required: true, width: 'half', options: [], value: '', accept: [], max_mb: 5, attach: false,
				logic: { enabled: false, field: '', op: 'eq', value: '' }
			});
		});
		sel = cfg.fields.length - 2;
		render();
	}

	function render() {
		sync();
		var s = cfg.settings = cfg.settings || {};
		var html = '<div class="of-pal"><strong>Add field</strong><br><br>' +
			'<button type="button" class="button button-primary" data-addname="1">First + last name</button>' +
			types.map(function (t) { return '<button type="button" class="button" data-add="' + t + '">' + (t === 'step' ? 'Step break' : t) + '</button>'; }).join('') + '</div>';

		html += '<div class="of-list"><strong>Fields</strong><br><br>' + (cfg.fields.length ? '' : '<em>Add a field from the left.</em>') +
			cfg.fields.map(function (f, i) {
				return '<div class="of-item' + (i === sel ? ' sel' : '') + '" data-sel="' + i + '"><span>' + esc(f.label || f.name) + '<small>' + f.type + '</small></span><span>' +
					'<button type="button" class="button-link" data-up="' + i + '">↑</button> <button type="button" class="button-link" data-down="' + i + '">↓</button> ' +
					'<button type="button" class="button-link" data-del="' + i + '">✕</button></span></div>';
			}).join('') + '</div>';

		html += '<div class="of-edit">' + (sel > -1 ? fieldEditor(cfg.fields[sel]) : '') + settingsEditor(s) + '</div>';
		root.innerHTML = html;
		bind();
	}

	function fieldEditor(f) {
		var h = '<h3 style="margin-top:0">Field settings</h3>';
		var hasOpts = ['select', 'radio', 'checkbox'].indexOf(f.type) > -1;
		if (f.type !== 'step' || true) h += '<label>Label</label><input type="text" data-k="label" value="' + esc(f.label) + '">';
		if (f.type !== 'step' && f.type !== 'html') h += '<label>Name (key)</label><input type="text" data-k="name" value="' + esc(f.name) + '">';
		if (['text', 'email', 'number', 'tel', 'url', 'textarea'].indexOf(f.type) > -1) h += '<label>Placeholder</label><input type="text" data-k="placeholder" value="' + esc(f.placeholder) + '">';
		if (f.type !== 'step' && f.type !== 'html' && f.type !== 'hidden') h += '<label>Help text</label><input type="text" data-k="help" value="' + esc(f.help) + '">' +
			'<label><input type="checkbox" data-k="required"' + (f.required ? ' checked' : '') + '> Required</label>';
		if (f.type !== 'step' && f.type !== 'html' && f.type !== 'hidden') h += '<label>Width</label><select data-k="width"><option value="full"' + (f.width !== 'half' ? ' selected' : '') + '>Full width (own line)</option><option value="half"' + (f.width === 'half' ? ' selected' : '') + '>Half width (sits next to another half-width field)</option></select>';
		if (hasOpts) h += '<label>Options (one per line)</label><textarea rows="4" data-k="options">' + esc((f.options || []).join('\n')) + '</textarea>';
		if (f.type === 'file') {
			h += '<label>Allowed file types (extensions, comma separated)</label><input type="text" data-k="accept" value="' + esc((f.accept || []).join(', ')) + '">' +
				'<p class="description">Presets: <button type="button" class="button-link" data-preset="images">Images</button> · ' +
				'<button type="button" class="button-link" data-preset="documents">PDF/Text</button> · ' +
				'<button type="button" class="button-link" data-preset="cad">STL / CAD (STL, STEP, IGES, OBJ, 3MF, DXF, DWG)</button> · ' +
				'<button type="button" class="button-link" data-preset="cad+images">CAD + images</button><br>Click a preset to replace the list. Scripts and executables are always blocked.</p>' +
				'<label>Max file size (MB, 1–100)</label><input type="number" min="1" max="100" data-k="max_mb" value="' + esc(f.max_mb || 5) + '">' +
				'<label><input type="checkbox" data-k="attach"' + (f.attach ? ' checked' : '') + '> Attach file to notification emails</label>' +
				'<p class="description">Your host\'s PHP and mail-server limits still apply; large files are better kept in Entries than emailed.</p>';
		}
		if (f.type === 'hidden') h += '<label>Value</label><input type="text" data-k="value" value="' + esc(f.value) + '">';
		if (f.type === 'html') h += '<label>HTML content</label><textarea rows="5" data-k="value">' + esc(f.value) + '</textarea>';

		if (f.type !== 'step' && f.type !== 'hidden') {
			var l = f.logic = f.logic || { enabled: false, field: '', op: 'eq', value: '' };
			var others = cfg.fields.filter(function (x) { return x !== f && ['step', 'html', 'file'].indexOf(x.type) === -1; });
			h += '<h3>Conditional logic</h3><label><input type="checkbox" data-l="enabled"' + (l.enabled ? ' checked' : '') + '> Show only if…</label>';
			if (l.enabled) {
				h += '<select data-l="field"><option value="">Choose field</option>' + others.map(function (o) {
					return '<option value="' + esc(o.name) + '"' + (o.name === l.field ? ' selected' : '') + '>' + esc(o.label || o.name) + '</option>';
				}).join('') + '</select><select data-l="op">' + [['eq', 'equals'], ['neq', 'does not equal'], ['contains', 'contains'], ['empty', 'is empty'], ['notempty', 'is not empty']].map(function (o) {
					return '<option value="' + o[0] + '"' + (o[0] === l.op ? ' selected' : '') + '>' + o[1] + '</option>';
				}).join('') + '</select><input type="text" data-l="value" placeholder="Value" value="' + esc(l.value) + '">';
			}
		}
		return h;
	}

	function settingsEditor(s) {
		var p = s.payment = s.payment || { enabled: false, provider: 'stripe', mode: 'fixed', amount: '10.00', field: '', currency: 'usd', description: '', manual_message: "Thanks! We'll email you a secure payment link within one business day." };
		var nums = cfg.fields.filter(function (f) { return f.type === 'number'; });
		var h = '<h3>Form settings</h3>' +
			'<label>Submit button text</label><input type="text" data-s="submit_text" value="' + esc(s.submit_text) + '">' +
			'<label>Success message</label><textarea rows="2" data-s="success">' + esc(s.success) + '</textarea>' +
			'<label>Webhook URL (JSON POST)</label><input type="text" data-s="webhook" value="' + esc(s.webhook) + '">' +
			'<label><input type="checkbox" data-sc="captcha"' + (s.captcha ? ' checked' : '') + '> Use CAPTCHA (configure under Settings)</label>';

		h += '<h3>Email notifications</h3><p class="description">Merge tags: {field_name}, {all_fields}, {form_title}. Use e.g. {email} in "To" to send a confirmation to the submitter.</p>';
		(s.emails = s.emails || []).forEach(function (e, i) {
			h += '<fieldset class="of-email"><legend>' + esc(e.name || 'Email ' + (i + 1)) + ' <button type="button" class="button-link" data-edel="' + i + '">remove</button></legend>' +
				'<label>Template name</label><input type="text" data-e="' + i + ':name" value="' + esc(e.name) + '">' +
				'<label>To (comma separated)</label><input type="text" data-e="' + i + ':to" value="' + esc(e.to) + '">' +
				'<label>Subject</label><input type="text" data-e="' + i + ':subject" value="' + esc(e.subject) + '">' +
				'<label>Message</label><textarea rows="4" data-e="' + i + ':message">' + esc(e.message) + '</textarea></fieldset>';
		});
		h += '<button type="button" class="button" data-eadd>Add email template</button>';

		h += '<h3>Payment</h3><label><input type="checkbox" data-p="enabled"' + (p.enabled ? ' checked' : '') + '> Require payment</label>';
		if (p.enabled) {
			h += '<label>Gateway</label><select data-p="provider"><option value="stripe"' + (p.provider !== 'paypal' && p.provider !== 'manual' ? ' selected' : '') + '>Stripe</option><option value="paypal"' + (p.provider === 'paypal' ? ' selected' : '') + '>PayPal</option><option value="manual"' + (p.provider === 'manual' ? ' selected' : '') + '>Manual / external payment link</option></select>';
			h += p.provider === 'manual'
				? '<p class="description">No credentials needed — the entry is saved as "pending" and your email notification fires immediately so you can send a payment link yourself (e.g. a Chase invoice, wire transfer or check). Mark it paid from the Entries screen once you see the money land.</p>'
				: '<p class="description">Configure credentials for the chosen gateway under Open Forms → Settings.</p>';
			h += '<label>Amount source</label><select data-p="mode"><option value="fixed"' + (p.mode === 'fixed' ? ' selected' : '') + '>Fixed amount</option><option value="field"' + (p.mode === 'field' ? ' selected' : '') + '>From a number field</option></select>';
			if (p.mode === 'field') {
				h += '<label>Number field</label><select data-p="field"><option value="">Choose field</option>' + nums.map(function (n) {
					return '<option value="' + esc(n.name) + '"' + (n.name === p.field ? ' selected' : '') + '>' + esc(n.label || n.name) + '</option>';
				}).join('') + '</select>';
			} else {
				h += '<label>Amount</label><input type="text" data-p="amount" value="' + esc(p.amount) + '">';
			}
			h += '<label>Currency (3-letter code)</label><input type="text" data-p="currency" value="' + esc(p.currency) + '">' +
				'<label>Description</label><input type="text" data-p="description" value="' + esc(p.description) + '">';
			if (p.provider === 'manual') {
				h += '<label>Message shown after submitting</label><textarea rows="2" data-p="manual_message">' + esc(p.manual_message) + '</textarea>';
			}
		}
		return h;
	}

	function bind() {
		root.querySelectorAll('[data-addname]').forEach(function (b) { b.onclick = addName; });
		root.querySelectorAll('[data-add]').forEach(function (b) {
			b.onclick = function () { addField(b.getAttribute('data-add')); };
			b.draggable = true;
			b.ondragstart = function (e) { e.dataTransfer.setData('text/plain', 'add:' + b.getAttribute('data-add')); e.dataTransfer.effectAllowed = 'copy'; };
		});

		var list = root.querySelector('.of-list');
		function drop(e, at) {
			e.preventDefault(); e.stopPropagation();
			list.classList.remove('over');
			var d = (e.dataTransfer.getData('text/plain') || '').split(':');
			if (d[0] === 'add' && types.indexOf(d[1]) > -1) {
				addField(d[1]);
				var f = cfg.fields.pop();
				cfg.fields.splice(at, 0, f);
				sel = at;
			} else if (d[0] === 'move') {
				var from = +d[1];
				var f2 = cfg.fields.splice(from, 1)[0];
				if (at > from) at--;
				cfg.fields.splice(at, 0, f2);
				sel = at;
			} else return;
			render();
		}
		list.ondragover = function (e) { e.preventDefault(); list.classList.add('over'); };
		list.ondragleave = function () { list.classList.remove('over'); };
		list.ondrop = function (e) { drop(e, cfg.fields.length); };
		root.querySelectorAll('[data-sel]').forEach(function (b) {
			var i = +b.getAttribute('data-sel');
			b.draggable = true;
			b.ondragstart = function (e) { e.dataTransfer.setData('text/plain', 'move:' + i); e.dataTransfer.effectAllowed = 'move'; };
			b.ondrop = function (e) { drop(e, i); };
			b.onclick = function () { sel = i; render(); };
		});
		root.querySelectorAll('[data-up]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); move(+b.getAttribute('data-up'), -1); }; });
		root.querySelectorAll('[data-down]').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); move(+b.getAttribute('data-down'), 1); }; });
		root.querySelectorAll('[data-del]').forEach(function (b) {
			b.onclick = function (e) { e.stopPropagation(); cfg.fields.splice(+b.getAttribute('data-del'), 1); sel = -1; render(); };
		});

		root.querySelectorAll('[data-eadd]').forEach(function (b) {
			b.onclick = function () { cfg.settings.emails.push({ name: 'Confirmation', to: '{email}', subject: 'Thanks for contacting us', message: '{all_fields}' }); render(); };
		});
		root.querySelectorAll('[data-edel]').forEach(function (b) {
			b.onclick = function () { cfg.settings.emails.splice(+b.getAttribute('data-edel'), 1); render(); };
		});
		root.querySelectorAll('[data-e]').forEach(function (el) {
			el.oninput = function () { var p = el.getAttribute('data-e').split(':'); cfg.settings.emails[+p[0]][p[1]] = el.value; sync(); };
		});
		root.querySelectorAll('[data-p]').forEach(function (el) {
			el.onchange = el.oninput = function () {
				var k = el.getAttribute('data-p');
				cfg.settings.payment[k] = el.type === 'checkbox' ? el.checked : el.value;
				sync();
				if (el.type === 'checkbox' || k === 'mode' || k === 'provider') render();
			};
		});
		root.querySelectorAll('[data-sc]').forEach(function (el) {
			el.onchange = function () { cfg.settings[el.getAttribute('data-sc')] = el.checked; sync(); };
		});

		root.querySelectorAll('[data-k]').forEach(function (el) {
			el.oninput = el.onchange = function () {
				var f = cfg.fields[sel], k = el.getAttribute('data-k');
				if (k === 'required' || k === 'attach') f[k] = el.checked;
				else if (k === 'accept') f.accept = el.value.split(/[\s,;]+/).map(function (x) { return x.replace(/^\./, '').toLowerCase(); }).filter(Boolean);
				else if (k === 'max_mb') f.max_mb = Math.max(1, Math.min(100, parseInt(el.value, 10) || 5));
				else if (k === 'options') f.options = el.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
				else f[k] = el.value;
				sync();
			};
			if (el.getAttribute('data-k') === 'label') el.onchange = function () { cfg.fields[sel].label = el.value; render(); };
		});
		root.querySelectorAll('[data-preset]').forEach(function (b) {
			b.onclick = function () {
				var p = b.getAttribute('data-preset');
				cfg.fields[sel].accept = p === 'cad+images' ? PRESETS.cad.concat(PRESETS.images) : PRESETS[p].slice();
				render();
			};
		});
		root.querySelectorAll('[data-l]').forEach(function (el) {
			el.onchange = el.oninput = function () {
				var k = el.getAttribute('data-l'), l = cfg.fields[sel].logic;
				l[k] = el.type === 'checkbox' ? el.checked : el.value;
				sync();
				if (k === 'enabled') render();
			};
		});
		root.querySelectorAll('[data-s]').forEach(function (el) {
			el.oninput = function () { cfg.settings[el.getAttribute('data-s')] = el.value; sync(); };
		});
	}

	document.getElementById('of-builder-form').addEventListener('submit', sync);
	render();
})();
