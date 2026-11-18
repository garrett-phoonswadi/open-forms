<?php
/**
 * Integration test for S3-compatible storage against a real server (MinIO, RustFS, ...).
 *
 *   docker run -d --name of-s3 -p 9100:9000 -e RUSTFS_ACCESS_KEY=testaccess -e RUSTFS_SECRET_KEY=testsecret123 rustfs/rustfs:latest /data
 *   php -d extension=curl -d extension=openssl -d extension=mbstring tests/s3.php
 *
 * Needs the curl extension. Override the target with OF_TEST_S3_ENDPOINT / _ACCESS / _SECRET.
 */

if ( ! function_exists( 'curl_init' ) ) {
	fwrite( STDERR, "The curl extension is required (php -d extension=curl ...).\n" );
	exit( 2 );
}

define( 'MB_IN_BYTES', 1048576 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['opts']  = array();
$GLOBALS['hooks'] = array();
$GLOBALS['mails'] = array();
$GLOBALS['tmpdir'] = sys_get_temp_dir() . '/of-s3-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $GLOBALS['tmpdir'] . '/' );
mkdir( $GLOBALS['tmpdir'] . '/uploads', 0777, true );
mkdir( $GLOBALS['tmpdir'] . '/wp-admin/includes', 0777, true );
file_put_contents( $GLOBALS['tmpdir'] . '/wp-admin/includes/file.php', "<?php\n" );

// ---- WordPress stubs (hooks and HTTP behave like the real thing for what the plugin uses) ----
class WP_Error {
	public $m;
	function __construct( $c = '', $m = '' ) { $this->m = $m; }
	function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function plugin_dir_url( $f ) { return 'http://x/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_filter() {}
function remove_filter() {}
function add_shortcode() {}
function apply_filters( $t, $v ) { return $v; }
function add_action( $tag, $cb ) { $GLOBALS['hooks'][ $tag ][] = $cb; }
function remove_action( $tag, $cb ) {
	foreach ( $GLOBALS['hooks'][ $tag ] ?? array() as $i => $c ) {
		if ( $c === $cb ) {
			unset( $GLOBALS['hooks'][ $tag ][ $i ] );
		}
	}
}
function do_action( $tag, ...$args ) {
	foreach ( $GLOBALS['hooks'][ $tag ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_salt( $s = '' ) { return 'salt'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_the_title( $id ) { return 'Form'; }
function wp_list_pluck( $list, $field, $index = null ) {
	$out = array();
	foreach ( $list as $i ) {
		$index ? $out[ $i[ $index ] ] = $i[ $field ] : $out[] = $i[ $field ];
	}
	return $out;
}
function wp_generate_password( $n = 12, $special = true ) {
	$c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$o = '';
	for ( $i = 0; $i < $n; $i++ ) {
		$o .= $c[ random_int( 0, 61 ) ];
	}
	return $o;
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['tmpdir'] . '/uploads' ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_tempnam( $f = '' ) { return tempnam( sys_get_temp_dir(), 'ofs3' ); }
function wp_mail( $to, $subject, $msg, $headers = array(), $attachments = array() ) {
	$files = array();
	foreach ( $attachments as $a ) {
		$files[ $a ] = is_file( $a ) ? file_get_contents( $a ) : null; // Read now: the plugin deletes temp copies afterwards.
	}
	$GLOBALS['mails'][] = compact( 'to', 'files' );
	return true;
}
function wp_remote_request( $url, $args = array() ) {
	$ch = curl_init( $url );
	$h  = array();
	foreach ( $args['headers'] ?? array() as $k => $v ) {
		$h[] = $k . ': ' . $v;
	}
	$h[] = 'Expect:';
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $args['method'] ?? 'GET' );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $h );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $args['timeout'] ?? 30 );
	if ( ! empty( $args['body'] ) ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
	}
	$out = null;
	if ( ! empty( $args['stream'] ) ) {
		$out = fopen( $args['filename'], 'wb' );
		curl_setopt( $ch, CURLOPT_FILE, $out );
	} else {
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	}
	do_action( 'http_api_curl', $ch, $args, $url );
	$body = curl_exec( $ch );
	if ( false === $body ) {
		return new WP_Error( 'http', curl_error( $ch ) );
	}
	$code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	if ( $out ) {
		fclose( $out );
	}
	return array( 'code' => $code, 'body' => is_string( $body ) ? $body : '' );
}
function wp_remote_get( $url, $args = array() ) { $args['method'] = 'GET'; return wp_remote_request( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

require __DIR__ . '/../open-forms.php';

// ---- Harness ----
$pass = 0;
$fail = 0;
function t( $name, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ok   $name\n";
	} else {
		$fail++;
		echo "  FAIL $name" . ( '' !== $detail ? "  -> $detail" : '' ) . "\n";
	}
}
function get_url( $url ) { return wp_remote_get( $url, array( 'timeout' => 30 ) ); }

$endpoint = getenv( 'OF_TEST_S3_ENDPOINT' ) ?: 'http://127.0.0.1:9100';
$access   = getenv( 'OF_TEST_S3_ACCESS' ) ?: 'testaccess';
$secret   = getenv( 'OF_TEST_S3_SECRET' ) ?: 'testsecret123';
$bucket   = 'of-test-' . strtolower( bin2hex( random_bytes( 3 ) ) );

echo "Signing (AWS documentation test vector)\n";
$q = of_s3_presign_query( 'GET', 'examplebucket.s3.amazonaws.com', '/test.txt', array(), 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', gmmktime( 0, 0, 0, 5, 24, 2013 ), 86400 );
t( 'presigned signature matches AWS example', false !== strpos( $q, 'X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404' ), $q );

echo "Configuration\n";
$GLOBALS['opts']['of_settings'] = array( 'storage' => 'local', 's3_bucket' => $bucket, 's3_access' => $access, 's3_secret' => $secret, 's3_endpoint' => $endpoint, 's3_region' => 'us-east-1' );
t( 'local mode: S3 inactive', ! of_s3_active() );
$GLOBALS['opts']['of_settings']['storage'] = 's3';
t( 's3 mode with credentials: active', of_s3_active() );
$c = of_s3_conf();
t( 'default prefix applied', 'open-forms' === $c['prefix'] );
$tg = of_s3_target( $c, 'open-forms/a b.txt' );
t( 'custom endpoint uses path-style URL', $tg['base'] . $tg['path'] === $endpoint . '/' . $bucket . '/open-forms/a%20b.txt', $tg['base'] . $tg['path'] );
$aws = of_s3_target( array( 'endpoint' => '', 'region' => 'eu-west-2', 'bucket' => 'mybucket', 'prefix' => 'p' ), 'p/x.stl' );
t( 'AWS default uses virtual-hosted URL', 'https://mybucket.s3.eu-west-2.amazonaws.com/p/x.stl' === $aws['base'] . $aws['path'] );

echo "Live server: create bucket\n";
$t0 = of_s3_target( $c, '' );
$bp = '/' . $bucket;
$h  = of_s3_auth_headers( 'PUT', $t0['host'], $bp, array(), hash( 'sha256', '' ), $access, $secret, 'us-east-1', time() );
$r  = wp_remote_request( $endpoint . $bp, array( 'method' => 'PUT', 'headers' => $h ) );
t( 'bucket created (header-signed PUT accepted)', ! is_wp_error( $r ) && in_array( $r['code'], array( 200, 409 ), true ), is_wp_error( $r ) ? $r->get_error_message() : $r['code'] );

echo "Upload / download\n";
$f1 = $GLOBALS['tmpdir'] . '/small.stl';
file_put_contents( $f1, "solid test\nendsolid test\n" );
$name = wp_generate_password( 24, false ) . '.stl';
t( 'small upload succeeds', true === of_s3_put( $name, $f1 ), (string) of_s3_put( $name, $f1 ) );
$dl = get_url( of_s3_presigned_get( $name ) );
t( 'signed link returns the same bytes', 200 === $dl['code'] && file_get_contents( $f1 ) === $dl['body'], (string) $dl['code'] );
$raw = get_url( $endpoint . '/' . $bucket . '/open-forms/' . $name );
t( 'bucket is private: unsigned GET denied', in_array( $raw['code'], array( 401, 403 ), true ), (string) $raw['code'] );
t( 'zero-length bug guard: stored size is correct', strlen( $dl['body'] ) === filesize( $f1 ) );

echo "Large file streaming\n";
$f2 = $GLOBALS['tmpdir'] . '/big.step';
$fh = fopen( $f2, 'wb' );
$chunk = random_bytes( 1048576 );
for ( $i = 0; $i < 30; $i++ ) {
	fwrite( $fh, $chunk );
}
fclose( $fh );
$before = memory_get_peak_usage( true );
$big    = wp_generate_password( 24, false ) . '.step';
$ok     = of_s3_put( $big, $f2 );
$delta  = memory_get_peak_usage( true ) - $before;
t( '30 MB upload succeeds', true === $ok, (string) $ok );
t( 'upload streamed (peak memory grew < 12 MB)', $delta < 12 * 1048576, round( $delta / 1048576, 1 ) . ' MB' );
$tmp = of_s3_fetch( $big );
t( '30 MB round-trips byte for byte', '' !== $tmp && hash_file( 'sha256', $tmp ) === hash_file( 'sha256', $f2 ) );
wp_delete_file( $tmp );

echo "Failure and tamper cases\n";
$GLOBALS['opts']['of_settings']['s3_secret'] = 'wrongsecret123';
$bad = of_s3_put( 'x.txt', $f1 );
t( 'wrong secret: upload rejected with an error message', is_string( $bad ) && false !== strpos( $bad, '403' ), is_string( $bad ) ? $bad : 'true' );
$GLOBALS['opts']['of_settings']['s3_secret'] = $secret;
$url = of_s3_presigned_get( $name );
$other = get_url( str_replace( $name, 'other-' . $name, $url ) );
t( 'signed link cannot be reused for another file', in_array( $other['code'], array( 403, 404 ), true ), (string) $other['code'] );
$c   = of_s3_conf();
$tg  = of_s3_target( $c, of_s3_key( $c, $name ) );
$old = of_s3_presign_query( 'GET', $tg['host'], $tg['path'], array(), $access, $secret, 'us-east-1', time() - 100, 10 );
$exp = get_url( $tg['base'] . $tg['path'] . '?' . $old );
t( 'expired signed link rejected', 403 === $exp['code'], (string) $exp['code'] );
$bad_sig = get_url( preg_replace( '/X-Amz-Signature=[0-9a-f]{4}/', 'X-Amz-Signature=0000', $url ) );
t( 'altered signature rejected', 403 === $bad_sig['code'], (string) $bad_sig['code'] );
$GLOBALS['opts']['of_settings']['s3_endpoint'] = 'http://127.0.0.1:9';
$down = of_s3_put( 'y.txt', $f1 );
t( 'unreachable server: clean error, no fatal', is_string( $down ) && '' !== $down );
$GLOBALS['opts']['of_settings']['s3_endpoint'] = $endpoint;

echo "References and deletion\n";
t( 'of-s3 reference parsed', array( 's3', 'abc.stl' ) === of_file_ref( 'of-s3:abc.stl' ) );
t( 'of-file reference parsed', array( 'local', 'abc.stl' ) === of_file_ref( 'of-file:abc.stl' ) );
t( 'path traversal stripped from reference', array( 's3', 'passwd' ) === of_file_ref( 'of-s3:../../etc/passwd' ) );
t( 'non-reference values ignored', null === of_file_ref( 'hello' ) && null === of_file_ref( array( 'of-s3:x' ) ) && null === of_file_ref( 'of-s3:' ) );
of_delete_files( array( 'a' => 'of-s3:' . $name, 'b' => 'plain text', 'c' => 'of-s3:' . $big ) );
$gone = get_url( of_s3_presigned_get( $name ) );
t( 'of_delete_files removes bucket objects', 404 === $gone['code'], (string) $gone['code'] );
t( 'second object removed too', 404 === get_url( of_s3_presigned_get( $big ) )['code'] );

echo "Email attachments from the bucket\n";
$att = wp_generate_password( 24, false ) . '.png';
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
file_put_contents( $GLOBALS['tmpdir'] . '/a.png', $png );
of_s3_put( $att, $GLOBALS['tmpdir'] . '/a.png' );
$cfg = array(
	'fields'   => array( array( 'name' => 'pic', 'type' => 'file', 'label' => 'Pic', 'attach' => true ), array( 'name' => 'skip', 'type' => 'file', 'label' => 'Skip', 'attach' => false ) ),
	'settings' => array( 'emails' => array( array( 'to' => 'owner@example.com', 'subject' => 'New', 'message' => '{all_fields}' ) ) ),
);
of_notify( 1, $cfg, array( 'pic' => 'of-s3:' . $att, 'skip' => 'of-s3:' . $att ) );
$m = $GLOBALS['mails'][0] ?? null;
t( 'email sent with exactly one attachment', $m && 1 === count( $m['files'] ) );
t( 'attachment content is the stored file', $m && array_values( $m['files'] )[0] === $png );
t( 'temporary copy deleted after sending', $m && ! is_file( array_keys( $m['files'] )[0] ) );
of_s3_delete( $att );

echo "Settings page save\n";
$GLOBALS['opts']['of_settings'] = array( 's3_secret' => 'keep-this-secret', 's3_access' => 'keepaccess' );
$s = of_sanitize_settings( array( 'storage' => 's3', 's3_endpoint' => 'https://acct.r2.cloudflarestorage.com/', 's3_region' => 'auto', 's3_bucket' => 'my-bucket', 's3_prefix' => '/forms/', 's3_access' => '', 's3_secret' => '' ) );
t( 'valid storage settings saved (trailing slashes trimmed)', 's3' === $s['storage'] && 'https://acct.r2.cloudflarestorage.com' === $s['s3_endpoint'] && 'forms' === $s['s3_prefix'] && 'my-bucket' === $s['s3_bucket'] );
t( 'blank secret fields keep stored values', 'keep-this-secret' === $s['s3_secret'] && 'keepaccess' === $s['s3_access'] );
$s = of_sanitize_settings( array( 'storage' => 's3', 's3_endpoint' => 'javascript:alert(1)', 's3_bucket' => '../x', 's3_secret' => 'a b' ) );
t( 'bad endpoint / bucket / secret rejected', '' === $s['s3_endpoint'] && '' === $s['s3_bucket'] && 'keep-this-secret' === $s['s3_secret'] );
t( 'endpoint with a path is rejected', '' === of_sanitize_settings( array( 's3_endpoint' => 'https://h.example/evil/path' ) )['s3_endpoint'] );

$GLOBALS['opts']['of_settings'] = array( 'storage' => 's3', 's3_bucket' => $bucket, 's3_access' => $access, 's3_secret' => $secret, 's3_endpoint' => 'ftp://nope' );
t( 'non-http endpoint disables S3 instead of misrouting', ! of_s3_active() );
$GLOBALS['opts']['of_settings'] = array( 'storage' => 's3', 's3_bucket' => $bucket );
t( 'missing credentials disables S3', ! of_s3_active() );

// Clean up the test bucket.
$GLOBALS['opts']['of_settings'] = array( 'storage' => 's3', 's3_bucket' => $bucket, 's3_access' => $access, 's3_secret' => $secret, 's3_endpoint' => $endpoint );
$h = of_s3_auth_headers( 'DELETE', $t0['host'], $bp, array(), hash( 'sha256', '' ), $access, $secret, 'us-east-1', time() );
wp_remote_request( $endpoint . $bp, array( 'method' => 'DELETE', 'headers' => $h ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
