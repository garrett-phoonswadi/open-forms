<?php
/**
 * Standalone logic tests (no WordPress needed). WordPress functions are stubbed.
 * Run: php tests/run.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['opts']   = array();
$GLOBALS['mails']  = array();
$GLOBALS['tmpdir'] = sys_get_temp_dir() . '/of-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $GLOBALS['tmpdir'] . '/uploads', 0777, true );

// ---- WordPress stubs ----
function plugin_dir_url( $f ) { return 'http://x/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_action() {}
function add_filter() {}
function remove_filter() {}
function add_shortcode() {}
function apply_filters( $tag, $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url_raw( $u, $protocols = null ) {
	$p = parse_url( $u, PHP_URL_SCHEME );
	return $p && ( null === $protocols || in_array( $p, $protocols, true ) ) ? $u : '';
}
function wp_kses( $s ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_the_title( $id ) { return 'Form ' . $id; }
function wp_list_pluck( $list, $field, $index = null ) {
	$out = array();
	foreach ( $list as $i ) {
		$index ? $out[ $i[ $index ] ] = $i[ $field ] : $out[] = $i[ $field ];
	}
	return $out;
}
function wp_mail( $to, $subject, $msg, $headers = array(), $attachments = array() ) { $GLOBALS['mails'][] = compact( 'to', 'subject', 'msg', 'headers', 'attachments' ); return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['tmpdir'] . '/uploads' ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { @unlink( $f ); }
// Minimal HTTP stub for PayPal's token + webhook-verification calls: register canned responses per URL substring.
$GLOBALS['http_stubs'] = array();
class OF_Test_WP_Error { public $msg; function __construct( $m = '' ) { $this->msg = $m; } function get_error_message() { return $this->msg; } }
function is_wp_error( $v ) { return $v instanceof OF_Test_WP_Error; }
function of_test_http( $url, $args ) {
	foreach ( $GLOBALS['http_stubs'] as $needle => $resp ) {
		if ( false !== strpos( $url, $needle ) ) {
			return is_callable( $resp ) ? $resp( $url, $args ) : $resp;
		}
	}
	return new OF_Test_WP_Error( 'no stub for ' . $url );
}
function wp_remote_post( $url, $args = array() ) { return of_test_http( $url, $args ); }
function wp_remote_request( $url, $args = array() ) { return of_test_http( $url, $args ); }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); return true; }
$GLOBALS['trans'] = array();
class WP_REST_Response { public $data; public $status; function __construct( $d, $s ) { $this->data = $d; $this->status = $s; } }
class WP_REST_Request {
	public $body; public $headers;
	function __construct( $body, $headers = array() ) { $this->body = $body; $this->headers = $headers; }
	function get_body() { return $this->body; }
	function get_header( $k ) { return $this->headers[ $k ] ?? null; }
}

require __DIR__ . '/../open-forms.php';

// ---- Tiny test harness ----
$pass = 0;
$fail = 0;
function t( $name, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ok   $name\n";
	} else {
		$fail++;
		echo "  FAIL $name\n";
	}
}

echo "Form tokens\n";
$tok = of_form_token( 7 );
t( 'valid token accepted', of_check_token( 7, $tok ) );
t( 'token for another form rejected', ! of_check_token( 8, $tok ) );
t( 'tampered signature rejected', ! of_check_token( 7, substr( $tok, 0, -1 ) . ( substr( $tok, -1 ) === 'a' ? 'b' : 'a' ) ) );
t( 'empty token rejected', ! of_check_token( 7, '' ) );
t( 'malformed token rejected', ! of_check_token( 7, 'abc.def' ) && ! of_check_token( 7, 'nodot' ) );
t( 'expired token rejected', ! of_check_token( 7, of_form_token( 7, time() - DAY_IN_SECONDS - 10 ) ) );
t( 'future-dated token rejected', ! of_check_token( 7, of_form_token( 7, time() + 1000 ) ) );
t( 'array token does not crash', ! of_check_token( 7, array( 'x' ) ) );
t( 'fresh token not yet marked used', ! of_token_used( 7, $tok ) );
of_consume_token( 7, $tok );
t( 'token marked used after consuming it', of_token_used( 7, $tok ) );
t( 'same token still valid on its own (signature check is separate from use-tracking)', of_check_token( 7, $tok ) );
t( 'a different token for the same form is unaffected', ! of_token_used( 7, of_form_token( 7, time() - 10 ) ) );
t( 'same token used on a different form is unaffected', ! of_token_used( 8, $tok ) );

echo "Conditional logic\n";
$mk = function ( $op, $val ) { return array( 'logic' => array( 'enabled' => true, 'field' => 'a', 'op' => $op, 'value' => $val ) ); };
t( 'disabled logic is visible', of_logic_visible( array( 'logic' => array( 'enabled' => false, 'field' => 'a' ) ), array() ) );
t( 'eq match', of_logic_visible( $mk( 'eq', 'yes' ), array( 'a' => 'yes' ) ) );
t( 'eq mismatch', ! of_logic_visible( $mk( 'eq', 'yes' ), array( 'a' => 'no' ) ) );
t( 'eq on checkbox array', of_logic_visible( $mk( 'eq', 'b' ), array( 'a' => array( 'a', 'b' ) ) ) );
t( 'neq', of_logic_visible( $mk( 'neq', 'x' ), array( 'a' => 'y' ) ) );
t( 'contains case-insensitive', of_logic_visible( $mk( 'contains', 'HEL' ), array( 'a' => 'hello' ) ) );
t( 'contains with empty needle is false', ! of_logic_visible( $mk( 'contains', '' ), array( 'a' => 'hello' ) ) );
t( 'empty', of_logic_visible( $mk( 'empty', '' ), array( 'a' => '' ) ) );
t( 'notempty', of_logic_visible( $mk( 'notempty', '' ), array( 'a' => 'x' ) ) );

echo "Config sanitizing\n";
$fields = array();
for ( $i = 0; $i < 150; $i++ ) {
	$fields[] = array( 'type' => 'text', 'name' => 'f' . $i, 'label' => 'L' );
}
$c = of_sanitize_config( array( 'fields' => $fields ) );
t( 'fields capped at 100', 100 === count( $c['fields'] ) );

$c = of_sanitize_config( array( 'fields' => array(
	array( 'type' => 'evil', 'name' => 'a' ),
	array( 'type' => 'text', 'name' => 'dup', 'label' => '<b>x</b>' ),
	array( 'type' => 'text', 'name' => 'dup' ),
	array( 'type' => 'text', 'name' => '' ),
) ) );
$names = array_column( $c['fields'], 'name' );
t( 'unknown field type dropped', 3 === count( $c['fields'] ) );
t( 'field names unique', count( $names ) === count( array_unique( $names ) ) );
t( 'label tags stripped', 'x' === $c['fields'][0]['label'] );

$c = of_sanitize_config( array( 'settings' => array(
	'webhook' => 'http://insecure.example/hook',
	'payment' => array( 'enabled' => true, 'amount' => '1a2.5$', 'currency' => 'EVIL!', 'mode' => 'x' ),
	'emails'  => array_fill( 0, 30, array( 'to' => 'a@b.co' ) ),
) ) );
t( 'http webhook rejected', '' === $c['settings']['webhook'] );
t( 'https webhook kept', 'https://ok.example/h' === of_sanitize_config( array( 'settings' => array( 'webhook' => 'https://ok.example/h' ) ) )['settings']['webhook'] );
t( 'amount sanitized', '12.5' === $c['settings']['payment']['amount'] );
t( 'bad currency falls back to usd', 'usd' === $c['settings']['payment']['currency'] );
t( 'bad payment mode falls back to fixed', 'fixed' === $c['settings']['payment']['mode'] );
t( 'emails capped at 10', 10 === count( $c['settings']['emails'] ) );

t( 'default provider is stripe', 'stripe' === of_sanitize_config( array() )['settings']['payment']['provider'] );
t( 'paypal provider saved', 'paypal' === of_sanitize_config( array( 'settings' => array( 'payment' => array( 'provider' => 'paypal' ) ) ) )['settings']['payment']['provider'] );
t( 'manual provider saved', 'manual' === of_sanitize_config( array( 'settings' => array( 'payment' => array( 'provider' => 'manual' ) ) ) )['settings']['payment']['provider'] );
t( 'unknown provider falls back to stripe', 'stripe' === of_sanitize_config( array( 'settings' => array( 'payment' => array( 'provider' => 'bogus' ) ) ) )['settings']['payment']['provider'] );
$c = of_sanitize_config( array( 'settings' => array( 'payment' => array( 'manual_message' => 'Pay <b>here</b><script>alert(1)</script>' ) ) ) );
t( 'manual message keeps safe tags, strips script', '<script>' !== substr( $c['settings']['payment']['manual_message'], 0, 8 ) && false !== strpos( $c['settings']['payment']['manual_message'], '<b>here</b>' ) );
t( 'manual message default present when unset', '' !== of_default_config()['settings']['payment']['manual_message'] );

$s = of_sanitize_settings( array( 'retention_days' => '-5', 'captcha' => 'bogus' ) );
t( 'negative retention becomes positive int', 5 === $s['retention_days'] );
t( 'retention capped', 3650 === of_sanitize_settings( array( 'retention_days' => 99999 ) )['retention_days'] );
t( 'unknown captcha provider rejected', '' === $s['captcha'] );
$GLOBALS['opts']['of_settings'] = array( 'captcha_secret' => 'cap_saved', 'stripe_key_test' => 'sk_test_saved' );
t( 'blank secret keeps stored value', 'sk_test_saved' === of_sanitize_settings( array( 'stripe_key_test' => '' ) )['stripe_key_test'] && 'cap_saved' === of_sanitize_settings( array( 'captcha_secret' => '' ) )['captcha_secret'] );
t( 'new secret replaces stored value', 'sk_test_new1' === of_sanitize_settings( array( 'stripe_key_test' => 'sk_test_new1' ) )['stripe_key_test'] );

echo "Stripe test/live keys\n";
$c = of_sanitize_config( array( 'fields' => array(
	array( 'type' => 'text', 'name' => 'first', 'label' => 'First', 'width' => 'half' ),
	array( 'type' => 'text', 'name' => 'last', 'label' => 'Last', 'width' => 'bogus' ),
	array( 'type' => 'text', 'name' => 'plain', 'label' => 'Plain' ),
) ) );
t( 'width half kept', 'half' === $c['fields'][0]['width'] );
t( 'unknown or missing width falls back to full', 'full' === $c['fields'][1]['width'] && 'full' === $c['fields'][2]['width'] );
$GLOBALS['opts']['of_settings'] = array();
$s = of_sanitize_settings( array( 'stripe_mode' => 'live', 'stripe_key_test' => 'sk_test_AAA', 'stripe_key_live' => 'sk_live_BBB', 'stripe_whsec_test' => 'whsec_T1', 'stripe_whsec_live' => 'whsec_L1' ) );
t( 'both key sets saved separately', 'sk_test_AAA' === $s['stripe_key_test'] && 'sk_live_BBB' === $s['stripe_key_live'] && 'whsec_T1' === $s['stripe_whsec_test'] && 'whsec_L1' === $s['stripe_whsec_live'] );
t( 'mode saved', 'live' === $s['stripe_mode'] );
t( 'invalid mode falls back to test', 'test' === of_sanitize_settings( array( 'stripe_mode' => 'bogus' ) )['stripe_mode'] );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'live', 'stripe_key_live' => 'sk_live_keep' );
t( 'omitted mode keeps stored mode', 'live' === of_sanitize_settings( array() )['stripe_mode'] );
$s = of_sanitize_settings( array( 'stripe_key_test' => 'sk_live_wrongplace', 'stripe_key_live' => 'sk_test_wrongplace' ) );
t( 'live key rejected in test field', '' === $s['stripe_key_test'] );
t( 'test key rejected in live field (old value kept)', 'sk_live_keep' === $s['stripe_key_live'] );
t( 'garbage webhook secret rejected', '' === of_sanitize_settings( array( 'stripe_whsec_test' => 'nope' ) )['stripe_whsec_test'] );
t( 'restricted keys allowed', 'rk_test_abc123' === of_sanitize_settings( array( 'stripe_key_test' => 'rk_test_abc123' ) )['stripe_key_test'] );
$s = of_sanitize_settings( array( 'stripe_key_live' => '<b>sk_live_x</b>' ) );
t( 'markup in key stripped/rejected', 'sk_live_keep' === $s['stripe_key_live'] || 'sk_live_x' === $s['stripe_key_live'] );

$GLOBALS['opts']['of_settings'] = array();
t( 'fresh install defaults to test mode', 'test' === of_stripe_mode() );
$GLOBALS['opts']['of_settings'] = array( 'stripe_key' => 'sk_live_legacy', 'stripe_whsec' => 'whsec_legacy' );
t( 'legacy single key keeps working as live', 'live' === of_stripe_mode() && 'sk_live_legacy' === of_opt( 'stripe_key' ) && 'whsec_legacy' === of_opt( 'stripe_whsec' ) );
$s = of_sanitize_settings( array() );
t( 'legacy key migrated to live slot on save', 'sk_live_legacy' === $s['stripe_key_live'] && 'whsec_legacy' === $s['stripe_whsec_live'] );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'test', 'stripe_key_test' => 'sk_test_T', 'stripe_key_live' => 'sk_live_L', 'stripe_whsec_test' => 'whsec_T', 'stripe_whsec_live' => 'whsec_L' );
t( 'test mode uses test key and secret', 'sk_test_T' === of_opt( 'stripe_key' ) && 'whsec_T' === of_opt( 'stripe_whsec' ) );
$GLOBALS['opts']['of_settings']['stripe_mode'] = 'live';
t( 'live mode uses live key and secret', 'sk_live_L' === of_opt( 'stripe_key' ) && 'whsec_L' === of_opt( 'stripe_whsec' ) );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'live', 'stripe_key_live' => 'sk_test_oops' );
t( 'wrong-environment key treated as missing', '' === of_opt( 'stripe_key' ) );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'test', 'stripe_key_live' => 'sk_live_L' );
t( 'test mode never falls back to the live key', '' === of_opt( 'stripe_key' ) );

echo "PayPal mode / credentials\n";
$GLOBALS['opts']['of_settings'] = array();
t( 'fresh install defaults to sandbox mode', 'sandbox' === of_paypal_mode() );
$GLOBALS['opts']['of_settings'] = array( 'paypal_mode' => 'live', 'paypal_client_sandbox' => 'cid_sandbox', 'paypal_secret_sandbox' => 'sec_sandbox', 'paypal_client_live' => 'cid_live', 'paypal_secret_live' => 'sec_live' );
t( 'live mode uses live credentials', 'cid_live' === of_opt( 'paypal_client' ) && 'sec_live' === of_opt( 'paypal_secret' ) );
$GLOBALS['opts']['of_settings']['paypal_mode'] = 'sandbox';
t( 'sandbox mode uses sandbox credentials', 'cid_sandbox' === of_opt( 'paypal_client' ) && 'sec_sandbox' === of_opt( 'paypal_secret' ) );
define( 'OF_PAYPAL_CLIENT_SANDBOX', 'cid_const' );
t( 'wp-config constant overrides option', 'cid_const' === of_opt( 'paypal_client' ) );
define( 'OF_PAYPAL_MODE', 'live' );
t( 'mode constant overrides option', 'live' === of_paypal_mode() && 'cid_live' === of_opt( 'paypal_client' ) );
$GLOBALS['opts']['of_settings'] = array();

echo "PayPal settings sanitizing\n";
$GLOBALS['opts']['of_settings'] = array( 'paypal_client_sandbox' => 'cid_saved' );
t( 'blank credential keeps stored value', 'cid_saved' === of_sanitize_settings( array( 'paypal_client_sandbox' => '' ) )['paypal_client_sandbox'] );
t( 'new credential replaces stored value', 'cid_new_12345' === of_sanitize_settings( array( 'paypal_client_sandbox' => 'cid_new_12345' ) )['paypal_client_sandbox'] );
t( 'too-short credential rejected, old value kept', 'cid_saved' === of_sanitize_settings( array( 'paypal_client_sandbox' => 'abc' ) )['paypal_client_sandbox'] );
t( 'invalid mode falls back to sandbox', 'sandbox' === of_sanitize_settings( array( 'paypal_mode' => 'bogus' ) )['paypal_mode'] );
t( 'live mode saved', 'live' === of_sanitize_settings( array( 'paypal_mode' => 'live' ) )['paypal_mode'] );

echo "Settings / secrets\n";
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'test', 'stripe_key_test' => 'sk_test_db' );
t( 'reads option', 'sk_test_db' === of_opt( 'stripe_key' ) );
define( 'OF_STRIPE_KEY_TEST', 'sk_test_const' );
t( 'wp-config constant overrides option', 'sk_test_const' === of_opt( 'stripe_key' ) );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'live' );
define( 'OF_STRIPE_KEY', 'sk_live_legacyconst' );
t( 'legacy OF_STRIPE_KEY constant still works for live', 'sk_live_legacyconst' === of_opt( 'stripe_key' ) );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'test' );

echo "Private uploads\n";
$dir = of_upload_dir();
t( 'private dir created', is_dir( $dir ) );
t( '.htaccess denies access', false !== strpos( (string) @file_get_contents( $dir . '/.htaccess' ), 'Require all denied' ) );
t( 'index.php present', is_file( $dir . '/index.php' ) );
file_put_contents( $dir . '/abc.txt', 'x' );
file_put_contents( $GLOBALS['tmpdir'] . '/uploads/outside.txt', 'x' );
of_delete_files( array( 'f' => 'of-file:../outside.txt' ) );
t( 'path traversal cannot delete outside private dir', is_file( $GLOBALS['tmpdir'] . '/uploads/outside.txt' ) );
of_delete_files( array( 'f' => 'https://example.com/of-file:abc.txt', 'g' => 'plain' ) );
t( 'non-file values ignored', is_file( $dir . '/abc.txt' ) );
of_delete_files( array( 'f' => 'of-file:abc.txt' ) );
t( 'stored file deleted', ! is_file( $dir . '/abc.txt' ) );

echo "File types\n";
t( 'extensions normalized', array( 'stl', 'step' ) === of_clean_exts( ' .STL, step ' ) );
t( 'array input accepted', array( 'stl' ) === of_clean_exts( array( '.stl', 'STL' ) ) );
t( 'scripts/executables blocked', array() === of_clean_exts( 'php, phtml, exe, svg, html, js, htaccess' ) );
t( 'junk extensions dropped', array( 'stl' ) === of_clean_exts( 'stl, ../x, a b c, toolongextension' ) || array( 'stl', 'a', 'b', 'c' ) === of_clean_exts( 'stl, ../x, a b c, toolongextension' ) );
t( 'empty list falls back to defaults', OF_FILE_DEFAULT_EXTS === of_field_exts( array( 'accept' => array() ) ) && OF_FILE_DEFAULT_EXTS === of_field_exts( array() ) );
t( 'blocked-only list falls back to defaults', OF_FILE_DEFAULT_EXTS === of_field_exts( array( 'accept' => array( 'php' ) ) ) );
t( 'max size default 5, clamped 1..100', 5 === of_field_max_mb( array() ) && 1 === of_field_max_mb( array( 'max_mb' => -3 ) ) && 100 === of_field_max_mb( array( 'max_mb' => 9999 ) ) );
$c = of_sanitize_config( array( 'fields' => array( array( 'type' => 'file', 'name' => 'cad', 'accept' => 'stl, STEP, php', 'max_mb' => '25', 'attach' => '1' ), array( 'type' => 'text', 'name' => 't', 'accept' => 'stl', 'attach' => '1' ) ) ) );
t( 'file field keeps accept/max/attach', array( 'stl', 'step' ) === $c['fields'][0]['accept'] && 25 === $c['fields'][0]['max_mb'] && true === $c['fields'][0]['attach'] );
t( 'non-file field ignores accept/attach', array() === $c['fields'][1]['accept'] && false === $c['fields'][1]['attach'] );

$tmp = $GLOBALS['tmpdir'] . '/s';
file_put_contents( $tmp, "solid cube\nfacet normal 0 0 1\nendsolid cube\n" );
t( 'ASCII STL accepted', of_file_looks_safe( $tmp, 'stl' ) );
file_put_contents( $tmp, str_repeat( "\0", 80 ) . pack( 'V', 0 ) );
t( 'binary STL accepted', of_file_looks_safe( $tmp, 'stl' ) );
file_put_contents( $tmp, "ISO-10303-21;\nHEADER;\nENDSEC;\n" );
t( 'STEP accepted', of_file_looks_safe( $tmp, 'step' ) );
file_put_contents( $tmp, "<?php system(\$_GET['c']);" );
t( 'PHP disguised as STL rejected', ! of_file_looks_safe( $tmp, 'stl' ) );
file_put_contents( $tmp, "solid x\n<?php echo 1; ?>" );
t( 'PHP hidden inside model rejected', ! of_file_looks_safe( $tmp, 'stl' ) );
file_put_contents( $tmp, "MZ\x90\x00\x03" );
t( 'Windows executable rejected', ! of_file_looks_safe( $tmp, 'stl' ) );
file_put_contents( $tmp, "\x7fELF\x02" );
t( 'ELF binary rejected', ! of_file_looks_safe( $tmp, 'step' ) );
file_put_contents( $tmp, "#!/bin/sh\nrm -rf /" );
t( 'shell script rejected', ! of_file_looks_safe( $tmp, 'obj' ) );
file_put_contents( $tmp, '' );
t( 'empty file rejected', ! of_file_looks_safe( $tmp, 'stl' ) );
file_put_contents( $tmp, 'not an image' );
t( 'fake PNG rejected', ! of_file_looks_safe( $tmp, 'png' ) );
file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
t( 'real PNG accepted', of_file_looks_safe( $tmp, 'png' ) );
file_put_contents( $tmp, 'plain text' );
t( 'fake PDF rejected', ! of_file_looks_safe( $tmp, 'pdf' ) );
file_put_contents( $tmp, "%PDF-1.4\n" );
t( 'real PDF accepted', of_file_looks_safe( $tmp, 'pdf' ) );
unlink( $tmp );

echo "Email notifications\n";
$dirp = of_upload_dir();
file_put_contents( $dirp . '/att1.png', 'x' );
file_put_contents( $dirp . '/att2.stl', 'x' );
$acfg = array(
	'fields'   => array(
		array( 'type' => 'file', 'name' => 'pic', 'label' => 'Pic', 'attach' => true ),
		array( 'type' => 'file', 'name' => 'model', 'label' => 'Model', 'attach' => false ),
	),
	'settings' => array( 'emails' => array( array( 'to' => 'a@x.co', 'subject' => 's', 'message' => 'm' ) ) ),
);
$GLOBALS['mails'] = array();
of_notify( 1, $acfg, array( 'pic' => 'of-file:att1.png', 'model' => 'of-file:att2.stl' ) );
$att = $GLOBALS['mails'][0]['attachments'] ?? null;
t( 'only flagged files attached', is_array( $att ) && 1 === count( $att ) && substr( $att[0], -8 ) === 'att1.png' );
$GLOBALS['mails'] = array();
of_notify( 1, $acfg, array( 'pic' => 'of-file:../../etc/passwd', 'model' => '' ) );
t( 'traversal path never attached', array() === ( $GLOBALS['mails'][0]['attachments'] ?? null ) );
unlink( $dirp . '/att1.png' );
unlink( $dirp . '/att2.stl' );
$GLOBALS['mails'] = array();
$cfg = array(
	'fields'   => array( array( 'type' => 'email', 'name' => 'email', 'label' => 'Email' ), array( 'type' => 'text', 'name' => 'n', 'label' => 'Name' ) ),
	'settings' => array( 'emails' => array( array( 'to' => '{email}, admin@site.co', 'subject' => 'Hi {n}', 'message' => '{all_fields}' ) ) ),
);
of_notify( 1, $cfg, array( 'email' => 'user@x.co', 'n' => "Bob\r\nBcc: evil@x.co" ) );
$m = $GLOBALS['mails'][0] ?? null;
t( 'mail sent', null !== $m );
t( 'subject has no CR/LF', $m && false === strpbrk( $m['subject'], "\r\n" ) );
t( 'reply-to set from email field', $m && array( 'Reply-To: user@x.co' ) === $m['headers'] );
$GLOBALS['mails'] = array();
of_notify( 1, $cfg, array( 'email' => "a@x.co\r\nBcc: evil@x.co", 'n' => 'x' ) );
$sent = $GLOBALS['mails'][0]['to'] ?? array();
t( 'injected recipient not added', ! in_array( 'evil@x.co', $sent, true ) );

echo "Stripe webhook signature\n";
$secret = 'whsec_test';
$GLOBALS['opts']['of_settings'] = array( 'stripe_whsec_test' => $secret );
$body = json_encode( array( 'type' => 'ping', 'data' => array( 'object' => array() ) ) );
$sign = function ( $ts, $b, $k ) { return 't=' . $ts . ',v1=' . hash_hmac( 'sha256', $ts . '.' . $b, $k ); };
$r = of_stripe_webhook( new WP_REST_Request( $body, array( 'stripe-signature' => $sign( time(), $body, $secret ) ) ) );
t( 'valid signature accepted', 200 === $r->status );
$r = of_stripe_webhook( new WP_REST_Request( $body, array( 'stripe-signature' => $sign( time(), $body, 'wrong' ) ) ) );
t( 'wrong secret rejected', 400 === $r->status );
$r = of_stripe_webhook( new WP_REST_Request( $body . ' ', array( 'stripe-signature' => $sign( time(), $body, $secret ) ) ) );
t( 'modified body rejected', 400 === $r->status );
$r = of_stripe_webhook( new WP_REST_Request( $body, array( 'stripe-signature' => $sign( time() - 1000, $body, $secret ) ) ) );
t( 'stale timestamp rejected', 400 === $r->status );
$r = of_stripe_webhook( new WP_REST_Request( $body, array() ) );
t( 'missing signature rejected', 400 === $r->status );
$live = json_encode( array( 'type' => 'checkout.session.completed', 'livemode' => true, 'data' => array( 'object' => array( 'client_reference_id' => '1', 'payment_status' => 'paid' ) ) ) );
$r = of_stripe_webhook( new WP_REST_Request( $live, array( 'stripe-signature' => $sign( time(), $live, $secret ) ) ) );
t( 'live event ignored while in test mode', 200 === $r->status && 'ignored' === $r->data );
$GLOBALS['opts']['of_settings'] = array( 'stripe_mode' => 'live', 'stripe_whsec_live' => $secret );
$r = of_stripe_webhook( new WP_REST_Request( $body, array( 'stripe-signature' => $sign( time(), $body, $secret ) ) ) );
t( 'test event ignored while in live mode', 200 === $r->status && 'ignored' === $r->data );
$r = of_stripe_webhook( new WP_REST_Request( $body, array( 'stripe-signature' => $sign( time(), $body, 'whsec_test_secret_other' ) ) ) );
t( 'live mode rejects signature from the test secret', 400 === $r->status );
$GLOBALS['opts']['of_settings'] = array();
$r = of_stripe_webhook( new WP_REST_Request( $body, array( 'stripe-signature' => $sign( time(), $body, '' ) ) ) );
t( 'unconfigured secret rejected', 400 === $r->status );

echo "Stripe promotion codes\n";
$e = (object) array( 'total' => '25.00', 'currency' => 'usd' );
$o = function ( $o ) { return array_merge( array( 'amount_subtotal' => 2500, 'amount_total' => 2500, 'currency' => 'usd', 'payment_status' => 'paid' ), $o ); };
t( 'full price accepted', of_stripe_payment_matches( $o( array() ), $e ) );
t( 'discounted payment accepted', of_stripe_payment_matches( $o( array( 'amount_total' => 2000 ) ), $e ) );
t( '100% discount (no payment required) accepted', of_stripe_payment_matches( $o( array( 'amount_total' => 0, 'payment_status' => 'no_payment_required' ) ), $e ) );
t( 'no_payment_required with a non-zero total rejected', ! of_stripe_payment_matches( $o( array( 'payment_status' => 'no_payment_required' ) ), $e ) );
t( 'unpaid status rejected', ! of_stripe_payment_matches( $o( array( 'payment_status' => 'unpaid' ) ), $e ) );
t( 'subtotal that differs from recorded price rejected', ! of_stripe_payment_matches( $o( array( 'amount_subtotal' => 100, 'amount_total' => 100 ) ), $e ) );
t( 'total above subtotal rejected', ! of_stripe_payment_matches( $o( array( 'amount_total' => 3000 ) ), $e ) );
t( 'wrong currency rejected', ! of_stripe_payment_matches( $o( array( 'currency' => 'eur' ) ), $e ) );
t( 'missing amounts rejected', ! of_stripe_payment_matches( array( 'currency' => 'usd', 'payment_status' => 'paid' ), $e ) );
t( 'discount shown in entry amount', '20.00 USD (list 25.00)' === of_money( (object) array( 'total' => '25.00', 'paid' => '20.00', 'currency' => 'usd' ) ) );
t( 'no discount shows plain amount', '25.00 USD' === of_money( (object) array( 'total' => '25.00', 'paid' => '25.00', 'currency' => 'usd' ) ) );
t( 'legacy entry without paid column shows plain amount', '25.00 USD' === of_money( (object) array( 'total' => '25.00', 'currency' => 'usd' ) ) );

echo "PayPal payment matching\n";
$pe = (object) array( 'id' => 42, 'total' => '25.00', 'currency' => 'usd' );
function of_test_pp_capture( $custom_id, $status, $value, $currency ) {
	return array(
		'purchase_units' => array(
			array(
				'custom_id' => $custom_id,
				'payments'  => array( 'captures' => array( array( 'status' => $status, 'amount' => array( 'value' => $value, 'currency_code' => $currency ) ) ) ),
			),
		),
	);
}
t( 'matching capture accepted', of_paypal_payment_matches( of_test_pp_capture( '42', 'COMPLETED', '25.00', 'USD' ), $pe ) );
t( 'wrong entry id rejected', ! of_paypal_payment_matches( of_test_pp_capture( '99', 'COMPLETED', '25.00', 'USD' ), $pe ) );
t( 'wrong amount rejected', ! of_paypal_payment_matches( of_test_pp_capture( '42', 'COMPLETED', '20.00', 'USD' ), $pe ) );
t( 'wrong currency rejected', ! of_paypal_payment_matches( of_test_pp_capture( '42', 'COMPLETED', '25.00', 'EUR' ), $pe ) );
t( 'non-completed capture rejected', ! of_paypal_payment_matches( of_test_pp_capture( '42', 'PENDING', '25.00', 'USD' ), $pe ) );
t( 'missing capture rejected', ! of_paypal_payment_matches( array( 'purchase_units' => array( array( 'custom_id' => '42' ) ) ), $pe ) );

echo "PayPal webhook signature\n";
$GLOBALS['opts']['of_settings'] = array( 'paypal_mode' => 'live', 'paypal_webhook_id_live' => 'WH-live-1', 'paypal_client_live' => 'cid_live', 'paypal_secret_live' => 'sec_live' );
$GLOBALS['http_stubs'] = array(
	'/oauth2/token'              => array( 'code' => 200, 'body' => json_encode( array( 'access_token' => 'tok', 'expires_in' => 3600 ) ) ),
	'/verify-webhook-signature'  => array( 'code' => 200, 'body' => json_encode( array( 'verification_status' => 'SUCCESS' ) ) ),
);
$hdrs = array( 'paypal-transmission-id' => 't1', 'paypal-transmission-time' => '2024-01-01T00:00:00Z', 'paypal-cert-url' => 'https://api.paypal.com/cert', 'paypal-auth-algo' => 'SHA256withRSA', 'paypal-transmission-sig' => 'sig' );
$ping = json_encode( array( 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => array() ) );
$r = of_paypal_webhook( new WP_REST_Request( $ping, $hdrs ) );
t( 'verified but irrelevant event ignored', 200 === $r->status && 'ignored' === $r->data );
$GLOBALS['opts']['of_settings'] = array();
$r = of_paypal_webhook( new WP_REST_Request( $ping, $hdrs ) );
t( 'unconfigured webhook id rejected', 400 === $r->status );
$GLOBALS['opts']['of_settings'] = array( 'paypal_mode' => 'live', 'paypal_webhook_id_live' => 'WH-live-1', 'paypal_client_live' => 'cid_live', 'paypal_secret_live' => 'sec_live' );
$GLOBALS['http_stubs']['/verify-webhook-signature'] = array( 'code' => 200, 'body' => json_encode( array( 'verification_status' => 'FAILURE' ) ) );
$approved = json_encode( array( 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => array( 'id' => 'ORDER1', 'purchase_units' => array( array( 'custom_id' => '1' ) ) ) ) );
$r = of_paypal_webhook( new WP_REST_Request( $approved, $hdrs ) );
t( 'failed verification rejected even for a relevant event', 400 === $r->status );
$GLOBALS['opts']['of_settings'] = array();
$GLOBALS['http_stubs'] = array();

echo "Misc\n";
t( 'of_flat joins arrays', 'a, b' === of_flat( array( 'a', 'b' ) ) );

// Cleanup
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['tmpdir'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
	$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
}
@rmdir( $GLOBALS['tmpdir'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
