(function () {
	function init(form) {
		var steps = [].slice.call(form.querySelectorAll('.of-step'));
		var cur = 0;
		var prev = form.querySelector('.of-prev');
		var next = form.querySelector('.of-next');
		var submit = form.querySelector('.of-submit');
		var msg = form.querySelector('.of-msg');
		var tInput = form.querySelector('[name=of_t]');
		var start = Date.now();

		var progress = null, progressFill = null, progressLabel = null;
		if (steps.length > 1) {
			progress = document.createElement('div');
			progress.className = 'of-progress';
			progress.innerHTML = '<div class="of-progress-bar"><div class="of-progress-fill"></div></div><div class="of-progress-label"></div>';
			form.insertBefore(progress, steps[0]);
			progressFill = progress.querySelector('.of-progress-fill');
			progressLabel = progress.querySelector('.of-progress-label');
		}

		function resetCaptcha() {
			try {
				if (window.turnstile && form.querySelector('.cf-turnstile')) turnstile.reset();
				if (window.hcaptcha && form.querySelector('.h-captcha')) hcaptcha.reset();
				if (window.grecaptcha && form.querySelector('.g-recaptcha')) grecaptcha.reset();
			} catch (err) { /* widget not ready */ }
		}

		function value(name) {
			var out = [];
			[].forEach.call(form.elements, function (el) {
				if (el.disabled) return;
				var n = el.name.replace(/\[\]$/, '');
				if (n !== name) return;
				if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
				if (el.value !== '') out.push(el.value);
			});
			return out;
		}

		function visible(l) {
			var v = value(l.field);
			switch (l.op) {
				case 'eq': return v.indexOf(l.value) > -1;
				case 'neq': return v.indexOf(l.value) === -1;
				case 'contains': return l.value !== '' && v.some(function (x) { return x.toLowerCase().indexOf(l.value.toLowerCase()) > -1; });
				case 'empty': return !v.length;
				case 'notempty': return v.length > 0;
			}
			return true;
		}

		function applyLogic() {
			// Document order so chained conditions resolve top-down.
			form.querySelectorAll('[data-logic]').forEach(function (w) {
				var show = visible(JSON.parse(w.getAttribute('data-logic')));
				w.hidden = !show;
				w.querySelectorAll('input,select,textarea').forEach(function (el) { el.disabled = !show; });
			});
		}

		function showStep(i) {
			cur = i;
			steps.forEach(function (s, k) { s.hidden = k !== i; });
			var multi = steps.length > 1;
			prev.hidden = !multi || i === 0;
			next.hidden = !multi || i === steps.length - 1;
			submit.hidden = multi && i !== steps.length - 1;
			if (progress) {
				progressFill.style.width = ((i + 1) / steps.length * 100) + '%';
				progressLabel.textContent = 'Step ' + (i + 1) + ' of ' + steps.length;
			}
		}

		function setErr(wrap, text) {
			var e = wrap.querySelector('.of-err');
			if (e) e.textContent = text || '';
			wrap.classList.toggle('of-invalid', !!text);
		}

		function validateStep(i) {
			var ok = true;
			steps[i].querySelectorAll('.of-field').forEach(function (w) {
				setErr(w, '');
				w.querySelectorAll('input,select,textarea').forEach(function (el) {
					if (!el.disabled && el.type === 'file' && el.files && el.files[0]) {
						var max = parseInt(el.getAttribute('data-max-mb'), 10) || 5;
						var allowed = (el.getAttribute('accept') || '').toLowerCase().split(',').filter(Boolean);
						var name = el.files[0].name.toLowerCase();
						var ext = name.indexOf('.') > -1 ? '.' + name.split('.').pop() : '';
						if (el.files[0].size > max * 1048576) { setErr(w, 'File too large (max ' + max + ' MB).'); ok = false; return; }
						if (allowed.length && allowed.indexOf(ext) === -1) { setErr(w, 'File type not allowed.'); ok = false; return; }
					}
					if (el.disabled || !el.checkValidity()) {
						if (!el.disabled) { setErr(w, el.validationMessage); ok = false; }
					}
				});
				var req = w.querySelector('input[type=checkbox][required]');
				if (req) req.required = false;
				if (w.classList.contains('of-checkbox') && w.querySelector('.of-req') && !w.querySelector(':disabled') && !w.querySelector('input:checked')) {
					setErr(w, 'Select at least one option.'); ok = false;
				}
			});
			return ok;
		}

		form.addEventListener('input', applyLogic);
		form.addEventListener('change', applyLogic);
		next.addEventListener('click', function () { if (validateStep(cur)) showStep(cur + 1); });
		prev.addEventListener('click', function () { showStep(cur - 1); });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			for (var i = 0; i < steps.length; i++) {
				if (!validateStep(i)) { showStep(i); return; }
			}
			tInput.value = Date.now() - start;
			var fd = new FormData(form);
			fd.append('action', 'open_forms_submit');
			fd.append('form', form.getAttribute('data-form'));
			submit.disabled = true;
			msg.className = 'of-msg';
			msg.textContent = 'Sending…';
			fetch(OpenForms.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					resetCaptcha();
					if (res.success && res.data.redirect) {
						msg.className = 'of-msg of-ok';
						msg.textContent = res.data.message;
						window.location.href = res.data.redirect;
					} else if (res.success) {
						msg.className = 'of-msg of-ok';
						msg.innerHTML = res.data.message;
						form.reset();
						applyLogic();
						showStep(0);
						steps.forEach(function (s) { s.hidden = true; });
						submit.hidden = prev.hidden = next.hidden = true;
						if (progress) progress.hidden = true;
					} else {
						msg.className = 'of-msg of-bad';
						msg.textContent = (res.data && res.data.message) || 'Error.';
						var errs = (res.data && res.data.errors) || {};
						Object.keys(errs).forEach(function (n) {
							var w = form.querySelector('.of-field[data-name="' + n + '"]');
							if (w) { setErr(w, errs[n]); var s = steps.indexOf(w.closest('.of-step')); if (s > -1) showStep(s); }
						});
						submit.disabled = false;
					}
				})
				.catch(function () {
					resetCaptcha();
					msg.className = 'of-msg of-bad';
					msg.textContent = 'Network error. Please try again.';
					submit.disabled = false;
				});
		});

		applyLogic();
		showStep(0);
	}

	function boot() { document.querySelectorAll('form.of-form').forEach(init); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
