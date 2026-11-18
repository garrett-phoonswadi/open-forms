// End-to-end S3 storage check against WordPress Playground + a local S3-compatible server.
//   docker run -d --name of-s3 -p 9100:9000 -e RUSTFS_ACCESS_KEY=testaccess -e RUSTFS_SECRET_KEY=testsecret123 rustfs/rustfs:latest /data
//   npx @wp-playground/cli@latest server --port=9401 --blueprint=tests/blueprint-s3.json --mount-dir <repo> /wordpress/wp-content/plugins/open-forms
//   node tests/e2e-s3.mjs [baseUrl]
const BASE = process.argv[2] || 'http://127.0.0.1:9401';

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
const anon = new Jar({ playground_auto_login_already_happened: '1' });
const admin = new Jar({ playground_auto_login_already_happened: '1', wordpress_test_cookie: 'WP%20Cookie%20check' });
const decode = s => s.replace(/&#038;|&amp;/g, '&');

async function submit(fields, file) {
	const fd = new FormData();
	fd.append('action', 'open_forms_submit');
	for (const [k, v] of Object.entries(fields)) fd.append(k, v);
	if (file) fd.append('cad', new Blob([file.body]), file.name);
	const res = await anon.req('/wp-admin/admin-ajax.php', { method: 'POST', body: fd, headers: { 'x-test-limit': '1000' } });
	let json = null, text = '';
	try { text = await res.text(); json = JSON.parse(text); } catch (e) { /* non-json */ }
	return { status: res.status, json, text: text.slice(0, 300) };
}

const page = await anon.req('/form/');
const html = await page.text();
const formId = (html.match(/data-form="(\d+)"/) || [])[1];
const tok = (html.match(/name="of_tok" value="([^"]+)"/) || [])[1];
t('form page renders', page.status === 200 && !!formId && !!tok, 'status ' + page.status);
if (!formId || !tok) { process.exit(1); }
const good = { form: formId, of_tok: tok, of_t: '5000', of_website: '', name: 'Alice', email: 'alice@example.com' };

console.log('Upload to the bucket');
const stl = 'solid e2e\nfacet normal 0 0 1\nendsolid e2e\n';
let r = await submit(good, { name: 'part.stl', body: stl });
t('STL submit accepted', r.status === 200, r.status + ' ' + r.text);
r = await submit(good, { name: 'evil.php', body: '<?php echo 1;' });
t('PHP still rejected with S3 on', r.status === 422);
r = await submit(good, { name: 'fake.stl', body: '<?php system($_GET[1]);' });
t('PHP content disguised as .stl still rejected', r.status === 422);

console.log('Admin');
let res = await admin.req('/wp-login.php', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'log=admin&pwd=password' });
t('admin login works', res.status === 302, 'status ' + res.status);
res = await admin.req('/wp-admin/admin.php?page=open-forms-entries&form_id=' + formId);
const ehtml = await res.text();
t('entries page shows the entry', res.status === 200 && ehtml.includes('Alice'));
const dl = (ehtml.match(/href="([^"]*action=of_download[^"]*)"/) || [])[1];
t('download link marked as bucket file', !!dl && dl.includes('s3=1'), dl);
t('no local /uploads/ URL leaked', !/href="[^"]*\/uploads\/[^"]*"/.test(ehtml));
let signed = '';
if (dl) {
	res = await admin.req(decode(dl));
	signed = res.headers.get('location') || '';
	t('download redirects to a signed bucket URL', res.status === 302 && signed.includes('X-Amz-Signature='), res.status + ' ' + signed.slice(0, 80));
	const file = await fetch(signed);
	const body = await file.text();
	t('signed URL returns the uploaded file', file.status === 200 && body === stl, file.status + ' ' + body.slice(0, 40));
	t('download served as attachment', (file.headers.get('content-disposition') || '').includes('attachment'));
	const anonDl = await anon.req(decode(dl));
	t('anonymous visitor cannot get a signed URL', anonDl.status !== 302 || !(anonDl.headers.get('location') || '').includes('X-Amz'), String(anonDl.status));
	res = await admin.req(decode(dl).replace(/_wpnonce=[^&]+/, '_wpnonce=badnonce'));
	t('bad nonce rejected', res.status === 403 || res.status === 302 && !(res.headers.get('location') || '').includes('X-Amz'), String(res.status));
	const bare = signed.split('?')[0];
	const noSig = await fetch(bare);
	t('bucket object is private without the signature', noSig.status === 403 || noSig.status === 401, String(noSig.status));
}

console.log('Settings + connection test');
res = await admin.req('/wp-admin/admin.php?page=open-forms-settings');
const shtml = await res.text();
t('settings page shows S3 active', shtml.includes('in your S3-compatible bucket'));
t('secret key is never echoed back', !shtml.includes('testsecret123'));
const testLink = (shtml.match(/href="([^"]*action=of_s3_test[^"]*)"/) || [])[1];
t('test connection button present', !!testLink);
if (testLink) {
	res = await admin.req(decode(testLink));
	t('test connection redirects back', res.status === 302);
	res = await admin.req('/wp-admin/admin.php?page=open-forms-settings');
	const after = await res.text();
	t('test connection reports OK', /notice-success[^>]*><p>OK:/.test(after), (after.match(/notice-(success|error)[^>]*><p>[^<]*/) || [])[0]);
}

console.log('Delete removes the bucket file');
const del = (ehtml.match(/href="([^"]*action=of_delete_entry[^"]*)"/) || [])[1];
t('delete link present', !!del);
if (del) {
	res = await admin.req(decode(del));
	t('entry deleted', res.status === 302);
	if (signed) {
		const gone = await fetch(signed);
		t('file removed from the bucket', gone.status === 404, String(gone.status));
	}
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
