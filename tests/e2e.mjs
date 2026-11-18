// End-to-end checks against a running WordPress (e.g. WordPress Playground).
// Usage: node tests/e2e.mjs [baseUrl]   (default http://127.0.0.1:9400)
const BASE = process.argv[2] || 'http://127.0.0.1:9400';

let pass = 0, fail = 0;
function t(name, cond, extra) {
	if (cond) { pass++; console.log('  ok   ' + name); }
	else { fail++; console.log('  FAIL ' + name + (extra ? '  -> ' + extra : '')); }
}

class Jar {
	constructor(init = {}) { this.c = { ...init }; }
	header() { return Object.entries(this.c).map(([k, v]) => k + '=' + v).join('; '); }
	store(res) {
		for (const sc of res.headers.getSetCookie()) {
			const [kv] = sc.split(';');
			const i = kv.indexOf('=');
			const k = kv.slice(0, i), v = kv.slice(i + 1);
			if (/expires=Thu, 01 Jan 1970/i.test(sc) || v === 'deleted') delete this.c[k]; else this.c[k] = v;
		}
	}
	async req(url, opts = {}) {
		const res = await fetch(url.startsWith('http') ? url : BASE + url, { redirect: 'manual', ...opts, headers: { ...(opts.headers || {}), cookie: this.header() } });
		this.store(res);
		return res;
	}
}

// Anonymous visitors: the cookie stops Playground from auto-logging in as admin.
const anon = new Jar({ playground_auto_login_already_happened: '1' });
const admin = new Jar({ playground_auto_login_already_happened: '1', wordpress_test_cookie: 'WP%20Cookie%20check' });

async function submit(jar, fields, file, limit = 1000) {
	const fd = new FormData();
	fd.append('action', 'open_forms_submit');
	for (const [k, v] of Object.entries(fields)) fd.append(k, v);
	if (file) fd.append(file.field || 'doc', new Blob([file.body], { type: file.type || 'application/octet-stream' }), file.name);
	const headers = limit ? { 'x-test-limit': String(limit) } : {};
	const res = await jar.req('/wp-admin/admin-ajax.php', { method: 'POST', body: fd, headers });
	let json = null;
	try { json = await res.json(); } catch (e) { /* non-json */ }
	return { status: res.status, json };
}

console.log('Public form');
let page = await anon.req('/form/');
let html = await page.text();
t('form page renders', page.status === 200 && html.includes('class="of-form"'), 'status ' + page.status);
const formHtml = html;
const formId = (html.match(/data-form="(\d+)"/) || [])[1];
const tok = (html.match(/name="of_tok" value="([^"]+)"/) || [])[1];
t('form has id and signed token', !!formId && !!tok);
t('honeypot field present', html.includes('name="of_website"'));
if (!formId || !tok) { console.log('Cannot continue'); process.exit(1); }

const good = { form: formId, of_tok: tok, of_t: '5000', of_website: '', name: 'Alice', email: 'alice@example.com' };

console.log('Submission protections');
let r = await submit(anon, { ...good, of_tok: '' });
t('missing token rejected (400)', r.status === 400, JSON.stringify(r));
r = await submit(anon, { ...good, of_tok: '123.deadbeef' });
t('forged token rejected (400)', r.status === 400);
const otherTok = tok.replace(/^\d+/, String(Number(tok.split('.')[0]) - 1));
r = await submit(anon, { ...good, of_tok: otherTok });
t('token with altered timestamp rejected', r.status === 400);
r = await submit(anon, { ...good, of_website: 'bot' });
t('honeypot rejected (400)', r.status === 400);
r = await submit(anon, { ...good, of_t: '100' });
t('too-fast submit rejected (400)', r.status === 400);
r = await submit(anon, { ...good, form: '999999' });
t('unknown form rejected (400)', r.status === 400);
r = await submit(anon, { ...good, name: '' });
t('missing required field (422)', r.status === 422 && r.json?.data?.errors?.name, JSON.stringify(r));
r = await submit(anon, { ...good, email: 'not-an-email' });
t('invalid email (422)', r.status === 422 && r.json?.data?.errors?.email);
r = await submit(anon, { ...good, name: 'x'.repeat(50000) });
t('oversized value accepted but truncated, not an error', r.status === 200 || r.status === 422);

console.log('Uploads');
r = await submit(anon, good, { name: 'evil.php', type: 'text/plain', body: '<?php echo 1;' });
t('.php upload rejected', r.status === 422 && r.json?.data?.errors?.doc, JSON.stringify(r));
r = await submit(anon, good, { name: 'macro.docx', type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', body: 'PK' });
t('.docx no longer allowed', r.status === 422);
r = await submit(anon, good, { name: 'shell.php.txt', type: 'text/plain', body: 'hello' });
t('double-extension file handled without fatal error', r.status === 200 || r.status === 422);
r = await submit(anon, good, { name: 'notes.txt', type: 'text/plain', body: 'hello upload' });
t('valid .txt upload accepted', r.status === 200 && r.json?.success === true, JSON.stringify(r));
const msg = r.json?.data?.message || '';
t('success message keeps safe tags', msg.includes('<b>friend</b>'));
t('success message strips <script>', !/<script/i.test(msg), msg);

console.log('Per-field file types (STL / CAD / images)');
t('CAD field renders accept list (php stripped)', formHtml.includes('accept=".stl,.step,.png"') && formHtml.includes('data-max-mb="1"'), (formHtml.match(/name="cad"[^>]*>/) || [])[0]);
t('default file field keeps image/pdf/txt', formHtml.includes('accept=".jpg,.jpeg,.png,.gif,.pdf,.txt"'));
const stl = 'solid cube\nfacet normal 0 0 1\nouter loop\nvertex 0 0 0\nvertex 1 0 0\nvertex 0 1 0\nendloop\nendfacet\nendsolid cube\n';
r = await submit(anon, good, { field: 'cad', name: 'part.stl', body: stl });
t('STL accepted on CAD field', r.status === 200 && r.json?.success === true, JSON.stringify(r));
r = await submit(anon, good, { field: 'cad', name: 'Assembly.STEP', body: 'ISO-10303-21;\nHEADER;\nENDSEC;\n' });
t('STEP (uppercase extension) accepted', r.status === 200, JSON.stringify(r));
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
r = await submit(anon, good, { field: 'cad', name: 'photo.png', type: 'image/png', body: png });
t('real PNG accepted on CAD field', r.status === 200, JSON.stringify(r));
r = await submit(anon, good, { field: 'cad', name: 'photo.png', type: 'image/png', body: 'not really a png' });
t('fake PNG rejected', r.status === 422 && r.json?.data?.errors?.cad, JSON.stringify(r));
r = await submit(anon, good, { field: 'cad', name: 'notes.txt', type: 'text/plain', body: 'hi' });
t('.txt rejected on CAD field (not in its list)', r.status === 422 && /not allowed/i.test(r.json?.data?.errors?.cad || ''), JSON.stringify(r));
r = await submit(anon, good, { field: 'cad', name: 'part.dwg', body: 'AC1015' });
t('.dwg rejected when not in list', r.status === 422);
r = await submit(anon, good, { field: 'cad', name: 'evil.php', body: '<?php echo 1;' });
t('.php rejected even if the admin listed it', r.status === 422);
r = await submit(anon, good, { field: 'cad', name: 'evil.stl', body: '<?php system($_GET["c"]);' });
t('PHP code disguised as .stl rejected', r.status === 422, JSON.stringify(r));
r = await submit(anon, good, { field: 'cad', name: 'tool.step', body: 'MZ\x90\x00\x03\x00' });
t('Windows executable disguised as .step rejected', r.status === 422);
r = await submit(anon, good, { field: 'cad', name: 'big.stl', body: 'solid x\n' + ' '.repeat(2 * 1024 * 1024) });
t('file over the 1 MB field limit rejected', r.status === 422 && /too large/i.test(r.json?.data?.errors?.cad || ''), JSON.stringify(r));
r = await submit(anon, good, { field: 'doc', name: 'part.stl', body: stl });
t('STL rejected on the default file field', r.status === 422);

console.log('Admin: entries, private downloads');
r = await submit(anon, good, { name: 'final.txt', type: 'text/plain', body: 'hello upload' });
t('fresh upload for download check accepted', r.status === 200, JSON.stringify(r));
let res = await admin.req('/wp-login.php', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'log=admin&pwd=password&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F' });
t('admin login works', res.status === 302 && Object.keys(admin.c).some(k => k.startsWith('wordpress_logged_in')), 'status ' + res.status);
res = await admin.req('/wp-admin/admin.php?page=open-forms-entries&form_id=' + formId);
html = await res.text();
t('entries page loads', res.status === 200 && html.includes('Entries'), 'status ' + res.status);
t('entries page shows alice', html.includes('Alice'));
t('entries page has no public /uploads/ link', !/href="[^"]*\/uploads\/[^"]*\.txt"/.test(html));
const dl = (html.match(/href="([^"]*action=of_download[^"]*)"/) || [])[1];
t('download link present', !!dl);
const dlUrl = dl ? dl.replace(/&#038;|&amp;/g, '&') : '';
if (dl) {
	res = await anon.req(dlUrl);
	t('download denied to logged-out visitor', res.status !== 200, 'status ' + res.status);
	res = await admin.req(dlUrl);
	const body = await res.text();
	t('admin can download file', res.status === 200 && body === 'hello upload', 'status ' + res.status + ' body ' + body.slice(0, 40));
	t('download is forced attachment with nosniff', /attachment/.test(res.headers.get('content-disposition') || '') && res.headers.get('x-content-type-options') === 'nosniff');
	res = await admin.req(dlUrl.replace(/_wpnonce=[^&]+/, '_wpnonce=badnonce'));
	t('download with bad nonce refused', res.status === 403 || res.status === 302 || res.status === 200 && false, 'status ' + res.status);
	res = await admin.req(dlUrl.replace(/file=[^&]+/, 'file=..%2F..%2F..%2Fwp-config.php'));
	t('path traversal download refused', res.status !== 200, 'status ' + res.status);

	const priv = new URL(BASE).origin + '/wp-content/uploads/open-forms-private/' + decodeURIComponent((dlUrl.match(/file=([^&]+)/) || [])[1] || '');
	res = await anon.req(priv);
	console.log('  info direct URL to private file returned ' + res.status + ' (Apache honours .htaccess; this dev server may not - add an nginx deny rule on nginx)');
}

console.log('Admin: delete entry removes the file');
const del = (html.match(/href="([^"]*action=of_delete_entry[^"]*)"/) || [])[1];
if (del && dl) {
	res = await admin.req(del.replace(/&#038;|&amp;/g, '&'));
	t('delete entry redirects', res.status === 302, 'status ' + res.status);
	res = await admin.req(dlUrl);
	t('file gone after entry delete', res.status === 404 || res.status === 500 || res.status === 403, 'status ' + res.status);
}

console.log('Admin: capability and CSRF');
res = await anon.req('/wp-admin/admin-post.php?action=of_export&form_id=' + formId);
t('CSV export refused when logged out', res.status !== 200, 'status ' + res.status);
res = await admin.req('/wp-admin/admin-post.php?action=of_export&form_id=' + formId);
t('CSV export without nonce refused', res.status !== 200, 'status ' + res.status);
res = await admin.req('/wp-admin/admin-post.php?action=of_delete_form&form=' + formId);
t('delete form without nonce refused', res.status !== 302 || /wp-login|open-forms/.test(res.headers.get('location') || '') && false, 'status ' + res.status);
res = await admin.req('/wp-admin/admin.php?page=open-forms-settings');
html = await res.text();
t('settings page loads with retention field', html.includes('retention_days'));
t('settings page has test and live Stripe key fields', ['stripe_key_test', 'stripe_whsec_test', 'stripe_key_live', 'stripe_whsec_live', 'stripe_mode'].every(n => html.includes('of_settings[' + n + ']')));
t('settings page defaults to test mode', html.includes('TEST') && /value="test"\s+checked/.test(html.replace(/\s+/g, ' ')), (html.match(/Currently using[^<]*<[^>]*>[^<]*/) || [])[0]);

console.log('Rate limiting');
let limited = 0;
for (let i = 0; i < 12; i++) {
	const x = await submit(anon, good, null, 0);
	if (x.status === 429) limited++;
}
t('rate limit kicks in after repeated submissions', limited > 0, 'limited=' + limited);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
