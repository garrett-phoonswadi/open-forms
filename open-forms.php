<?php
/**
 * Plugin Name: Open Forms
 * Description: Free form builder: multi-step, conditional logic, file uploads, entries, CSV export, spam protection, webhooks.
 * Version: 1.3.1
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: open-forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OF_VER', '1.3.1' );
define( 'OF_URL', plugin_dir_url( __FILE__ ) );

const OF_FIELD_TYPES = array( 'text', 'email', 'number', 'tel', 'url', 'textarea', 'select', 'radio', 'checkbox', 'date', 'file', 'hidden', 'html', 'step' );
const OF_MAX_UPLOAD  = 5 * MB_IN_BYTES; // Default per-field limit.
/* ---------- Setup ---------- */

register_activation_hook( __FILE__, 'of_install' );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'of_purge' );
} );
add_action( 'plugins_loaded', function () {
	if ( get_option( 'of_db_ver' ) !== OF_VER ) {
		of_install();
	}
} );

function of_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = $wpdb->prefix . 'of_entries';
	dbDelta( "CREATE TABLE $t (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		form_id BIGINT UNSIGNED NOT NULL,
		data LONGTEXT NOT NULL,
		ip VARCHAR(64) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'complete',
		total DECIMAL(10,2) NOT NULL DEFAULT 0,
		paid DECIMAL(10,2) DEFAULT NULL,
		currency VARCHAR(3) NOT NULL DEFAULT '',
		txn VARCHAR(100) NOT NULL DEFAULT '',
		created DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY form_id (form_id)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'of_db_ver', OF_VER );
}

/* ---------- Global settings (CAPTCHA, Stripe, PayPal) ---------- */

function of_stripe_mode() {
	if ( defined( 'OF_STRIPE_MODE' ) && in_array( OF_STRIPE_MODE, array( 'test', 'live' ), true ) ) {
		return OF_STRIPE_MODE;
	}
	$o = get_option( 'of_settings', array() );
	$m = is_array( $o ) ? ( $o['stripe_mode'] ?? '' ) : '';
	if ( in_array( $m, array( 'test', 'live' ), true ) ) {
		return $m;
	}
	// Sites from before test/live keys existed keep charging with their single saved key.
	return is_array( $o ) && ! empty( $o['stripe_key'] ) ? 'live' : 'test';
}

// $kind: 'key' (secret API key) or 'whsec' (webhook signing secret) for the active mode.
function of_stripe_secret( $kind ) {
	$mode  = of_stripe_mode();
	$const = 'OF_STRIPE_' . strtoupper( $kind ) . '_' . strtoupper( $mode );
	$o     = get_option( 'of_settings', array() );
	$o     = is_array( $o ) ? $o : array();
	if ( defined( $const ) ) {
		$v = (string) constant( $const );
	} elseif ( ! empty( $o[ 'stripe_' . $kind . '_' . $mode ] ) ) {
		$v = (string) $o[ 'stripe_' . $kind . '_' . $mode ];
	} elseif ( 'live' === $mode ) {
		$legacy = 'OF_STRIPE_' . strtoupper( $kind );
		$v      = defined( $legacy ) ? (string) constant( $legacy ) : (string) ( $o[ 'stripe_' . $kind ] ?? '' );
	} else {
		$v = '';
	}
	// A key from the wrong environment is treated as missing so test and live can never be mixed up.
	if ( 'key' === $kind && $v && ! preg_match( '/^(sk|rk)_' . $mode . '_/', $v ) ) {
		return '';
	}
	return $v;
}

function of_paypal_mode() {
	if ( defined( 'OF_PAYPAL_MODE' ) && in_array( OF_PAYPAL_MODE, array( 'sandbox', 'live' ), true ) ) {
		return OF_PAYPAL_MODE;
	}
	$o = get_option( 'of_settings', array() );
	$m = is_array( $o ) ? ( $o['paypal_mode'] ?? '' ) : '';
	return in_array( $m, array( 'sandbox', 'live' ), true ) ? $m : 'sandbox';
}

// $kind: 'client' (client ID), 'secret' (client secret), or 'webhook_id' (webhook ID) for the active mode.
// Unlike Stripe keys, PayPal credentials have no fixed sandbox/live prefix we can check, so mixing
// environments isn't automatically caught here: double-check the mode toggle before saving credentials.
function of_paypal_secret( $kind ) {
	$mode  = of_paypal_mode();
	$const = 'OF_PAYPAL_' . strtoupper( $kind ) . '_' . strtoupper( $mode );
	if ( defined( $const ) ) {
		return (string) constant( $const );
	}
	$o = get_option( 'of_settings', array() );
	$o = is_array( $o ) ? $o : array();
	return (string) ( $o[ 'paypal_' . $kind . '_' . $mode ] ?? '' );
}

function of_opt( $key ) {
	if ( 'stripe_key' === $key ) {
		return of_stripe_secret( 'key' );
	}
	if ( 'stripe_whsec' === $key ) {
		return of_stripe_secret( 'whsec' );
	}
	if ( in_array( $key, array( 'paypal_client', 'paypal_secret', 'paypal_webhook_id' ), true ) ) {
		return of_paypal_secret( substr( $key, strlen( 'paypal_' ) ) );
	}
	// Secrets can live in wp-config.php instead of the database.
	$consts = array( 'captcha_secret' => 'OF_CAPTCHA_SECRET' );
	if ( isset( $consts[ $key ] ) && defined( $consts[ $key ] ) ) {
		return (string) constant( $consts[ $key ] );
	}
	$o = get_option( 'of_settings', array() );
	return is_array( $o ) ? ( $o[ $key ] ?? '' ) : '';
}

function of_captcha_providers() {
	return array(
		'turnstile' => array( 'name' => 'Cloudflare Turnstile', 'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'class' => 'cf-turnstile', 'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'field' => 'cf-turnstile-response' ),
		'hcaptcha'  => array( 'name' => 'hCaptcha', 'script' => 'https://js.hcaptcha.com/1/api.js', 'class' => 'h-captcha', 'verify' => 'https://api.hcaptcha.com/siteverify', 'field' => 'h-captcha-response' ),
		'recaptcha' => array( 'name' => 'Google reCAPTCHA v2', 'script' => 'https://www.google.com/recaptcha/api.js', 'class' => 'g-recaptcha', 'verify' => 'https://www.google.com/recaptcha/api/siteverify', 'field' => 'g-recaptcha-response' ),
	);
}

function of_captcha_active() {
	$p = of_captcha_providers();
	return isset( $p[ of_opt( 'captcha' ) ] ) && of_opt( 'captcha_site' ) && of_opt( 'captcha_secret' ) ? $p[ of_opt( 'captcha' ) ] : null;
}

function of_sanitize_settings( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$old = get_option( 'of_settings', array() );
	$out = array(
		'captcha'        => isset( of_captcha_providers()[ $in['captcha'] ?? '' ] ) ? $in['captcha'] : '',
		'captcha_site' => sanitize_text_field( $in['captcha_site'] ?? '' ),
		'retention_days' => min( 3650, absint( $in['retention_days'] ?? 0 ) ),
	);
	// Secrets are never echoed back; blank input keeps the stored value.
	$out['captcha_secret'] = '' !== sanitize_text_field( $in['captcha_secret'] ?? '' ) ? sanitize_text_field( $in['captcha_secret'] ) : ( $old['captcha_secret'] ?? '' );

	$out['stripe_mode'] = 'live' === ( $in['stripe_mode'] ?? '' ) ? 'live' : ( 'test' === ( $in['stripe_mode'] ?? '' ) ? 'test' : ( $old['stripe_mode'] ?? 'test' ) );
	$patterns           = array( 'key' => '/^(sk|rk)_%s_[A-Za-z0-9]+$/', 'whsec' => '/^whsec_[A-Za-z0-9]+$/' );
	foreach ( array( 'test', 'live' ) as $env ) {
		foreach ( $patterns as $kind => $re ) {
			$k   = 'stripe_' . $kind . '_' . $env;
			$v   = sanitize_text_field( $in[ $k ] ?? '' );
			$old_v = $old[ $k ] ?? '';
			// Sites upgrading from a single saved key: that key was the live one.
			if ( '' === $old_v && 'live' === $env && ! empty( $old[ 'stripe_' . $kind ] ) ) {
				$old_v = $old[ 'stripe_' . $kind ];
			}
			if ( '' === $v ) {
				$out[ $k ] = $old_v;
			} elseif ( preg_match( sprintf( $re, $env ), $v ) ) {
				$out[ $k ] = $v;
			} else {
				$out[ $k ] = $old_v;
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( 'of_settings', $k, 'Stripe ' . $env . ' ' . ( 'key' === $kind ? 'secret key must start with sk_' . $env . '_ (or rk_' . $env . '_)' : 'webhook secret must start with whsec_' ) . '. That field was not saved.' );
				}
			}
		}
	}
	$out['paypal_mode'] = 'live' === ( $in['paypal_mode'] ?? '' ) ? 'live' : ( 'sandbox' === ( $in['paypal_mode'] ?? '' ) ? 'sandbox' : ( $old['paypal_mode'] ?? 'sandbox' ) );
	// PayPal credentials have no distinguishable sandbox/live prefix to validate against (unlike Stripe keys above);
	// only the format is checked here, so double-check the mode toggle before saving.
	foreach ( array( 'sandbox', 'live' ) as $env ) {
		foreach ( array( 'client', 'secret', 'webhook_id' ) as $kind ) {
			$k   = 'paypal_' . $kind . '_' . $env;
			$v   = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
			if ( '' === $v ) {
				$out[ $k ] = $old[ $k ] ?? '';
			} elseif ( preg_match( '/^[A-Za-z0-9_\-]{10,100}$/', $v ) ) {
				$out[ $k ] = $v;
			} else {
				$out[ $k ] = $old[ $k ] ?? '';
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( 'of_settings', $k, 'PayPal ' . $env . ' ' . str_replace( '_', ' ', $kind ) . ' has an invalid format and was not saved.' );
				}
			}
		}
	}
	$out['storage'] = 's3' === ( $in['storage'] ?? '' ) ? 's3' : ( 'local' === ( $in['storage'] ?? '' ) ? 'local' : ( $old['storage'] ?? 'local' ) );
	$s3_rules       = array(
		's3_endpoint' => '#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$#',
		's3_region'   => '/^[a-z0-9\-]{2,30}$/',
		's3_bucket'   => '/^[a-z0-9][a-z0-9.\-]{2,62}$/',
		's3_prefix'   => '#^[A-Za-z0-9/_.\-]{0,100}$#',
		's3_access'   => '/^[A-Za-z0-9_\-]{3,128}$/',
		's3_secret'   => '/^\S{8,128}$/',
	);
	foreach ( $s3_rules as $k => $re ) {
		$v = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
		if ( 's3_endpoint' === $k ) {
			$v = rtrim( $v, '/' );
		} elseif ( 's3_prefix' === $k ) {
			$v = trim( $v, '/' );
		}
		$secret = in_array( $k, array( 's3_access', 's3_secret' ), true );
		if ( '' === $v ) {
			// Secrets keep their stored value when left blank; plain fields can be cleared.
			$out[ $k ] = $secret ? ( $old[ $k ] ?? '' ) : '';
		} elseif ( preg_match( $re, $v ) ) {
			$out[ $k ] = $v;
		} else {
			$out[ $k ] = $old[ $k ] ?? '';
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( 'of_settings', $k, 'The storage field "' . substr( $k, 3 ) . '" has an invalid value and was not saved.' );
			}
		}
	}
	return $out;
}

add_action( 'admin_init', function () {
	register_setting( 'of_settings_group', 'of_settings', array( 'sanitize_callback' => 'of_sanitize_settings' ) );
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'open-forms/v1', '/stripe', array(
		'methods'             => 'POST',
		'callback'            => 'of_stripe_webhook',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( 'open-forms/v1', '/paypal', array(
		'methods'             => 'POST',
		'callback'            => 'of_paypal_webhook',
		'permission_callback' => '__return_true',
	) );
} );

add_action( 'init', function () {
	register_post_type( 'open_form', array(
		'public'   => false,
		'show_ui'  => false,
		'supports' => array( 'title' ),
		'label'    => 'Open Forms',
	) );
} );

/* ---------- Retention, private uploads, form tokens ---------- */

add_action( 'of_purge', 'of_purge_old' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'of_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'of_purge' );
	}
} );

function of_upload_dir() {
	$u   = wp_upload_dir();
	$dir = trailingslashit( $u['basedir'] ) . 'open-forms-private';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}
	return $dir;
}

const OF_FILE_DEFAULT_EXTS = array( 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt' );
const OF_FILE_BLOCKED_EXTS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'js', 'mjs', 'html', 'htm', 'xhtml', 'svg', 'exe', 'dll', 'msi', 'bat', 'cmd', 'com', 'scr', 'sh', 'cgi', 'pl', 'py', 'rb', 'jsp', 'jspx', 'asp', 'aspx', 'htaccess', 'htpasswd', 'ini', 'vbs', 'ps1', 'jar' );

// Normalizes a list (array or comma/space separated string) into safe, unique extensions.
function of_clean_exts( $list ) {
	$list = is_array( $list ) ? $list : preg_split( '/[\s,;]+/', (string) $list );
	$out  = array();
	foreach ( $list as $e ) {
		$e = strtolower( ltrim( trim( (string) $e ), '.' ) );
		if ( preg_match( '/^[a-z0-9]{1,8}$/', $e ) && ! in_array( $e, OF_FILE_BLOCKED_EXTS, true ) ) {
			$out[ $e ] = true;
		}
	}
	return array_slice( array_keys( $out ), 0, 40 );
}

function of_field_exts( $f ) {
	$e = of_clean_exts( $f['accept'] ?? array() );
	return $e ? $e : OF_FILE_DEFAULT_EXTS;
}

function of_field_max_mb( $f ) {
	return max( 1, min( 100, (int) ( $f['max_mb'] ?? OF_MAX_UPLOAD / MB_IN_BYTES ) ) );
}

// Cheap content checks so a renamed executable or script can't pass as a model or image.
function of_file_looks_safe( $path, $ext ) {
	$head = (string) file_get_contents( $path, false, null, 0, 4096 );
	if ( '' === $head ) {
		return false;
	}
	if ( 0 === strpos( $head, 'MZ' ) || 0 === strpos( $head, "\x7fELF" ) || 0 === strpos( $head, '#!' ) || false !== stripos( $head, '<?php' ) || false !== strpos( $head, '<?=' ) ) {
		return false;
	}
	if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
		return false !== @getimagesize( $path );
	}
	if ( 'pdf' === $ext ) {
		return 0 === strpos( $head, '%PDF' );
	}
	return true;
}

/* ---------- Optional S3-compatible storage (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO...) ---------- */

// Active S3 settings, or null when uploads should stay on this server. wp-config.php constants win over saved options.
function of_s3_conf() {
	$o = get_option( 'of_settings', array() );
	$o = is_array( $o ) ? $o : array();
	if ( 's3' !== ( defined( 'OF_STORAGE' ) ? (string) OF_STORAGE : ( $o['storage'] ?? 'local' ) ) ) {
		return null;
	}
	$pick = function ( $k, $const ) use ( $o ) {
		return defined( $const ) ? (string) constant( $const ) : (string) ( $o[ 's3_' . $k ] ?? '' );
	};
	$c = array(
		'endpoint' => rtrim( $pick( 'endpoint', 'OF_S3_ENDPOINT' ), '/' ),
		'region'   => $pick( 'region', 'OF_S3_REGION' ) ?: 'us-east-1',
		'bucket'   => $pick( 'bucket', 'OF_S3_BUCKET' ),
		'access'   => $pick( 'access', 'OF_S3_ACCESS_KEY' ),
		'secret'   => $pick( 'secret', 'OF_S3_SECRET_KEY' ),
		'prefix'   => trim( $pick( 'prefix', 'OF_S3_PREFIX' ), '/' ) ?: 'open-forms',
	);
	if ( '' === $c['bucket'] || '' === $c['access'] || '' === $c['secret'] ) {
		return null;
	}
	if ( '' !== $c['endpoint'] && ! preg_match( '#^https?://[^/]+$#i', $c['endpoint'] ) ) {
		return null;
	}
	return $c;
}

// Where an object lives. A custom endpoint uses path-style URLs (R2, B2, Wasabi, MinIO); no endpoint means AWS virtual-hosted style.
function of_s3_target( $c, $key ) {
	$key = ltrim( $key, '/' );
	if ( '' !== $c['endpoint'] ) {
		$p      = wp_parse_url( $c['endpoint'] );
		$scheme = strtolower( $p['scheme'] );
		$port   = isset( $p['port'] ) && ! ( ( 'http' === $scheme && 80 === (int) $p['port'] ) || ( 'https' === $scheme && 443 === (int) $p['port'] ) ) ? ':' . $p['port'] : '';
		$host   = strtolower( $p['host'] ) . $port;
		$path   = '/' . $c['bucket'] . '/' . $key;
	} else {
		$scheme = 'https';
		$host   = $c['bucket'] . '.s3.' . $c['region'] . '.amazonaws.com';
		$path   = '/' . $key;
	}
	$path = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	return array( 'host' => $host, 'path' => $path, 'base' => $scheme . '://' . $host );
}

function of_s3_query_string( $q ) {
	ksort( $q );
	$parts = array();
	foreach ( $q as $k => $v ) {
		$parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
	}
	return implode( '&', $parts );
}

function of_s3_signing_key( $secret, $date, $region ) {
	$k = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
	$k = hash_hmac( 'sha256', $region, $k, true );
	$k = hash_hmac( 'sha256', 's3', $k, true );
	return hash_hmac( 'sha256', 'aws4_request', $k, true );
}

// AWS Signature V4, query-string form: a time-limited URL that needs no credentials.
function of_s3_presign_query( $method, $host, $path, $query, $access, $secret, $region, $ts, $expires ) {
	$date  = gmdate( 'Ymd', $ts );
	$amz   = gmdate( 'Ymd\THis\Z', $ts );
	$scope = $date . '/' . $region . '/s3/aws4_request';
	$query = array_merge( $query, array(
		'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
		'X-Amz-Credential'    => $access . '/' . $scope,
		'X-Amz-Date'          => $amz,
		'X-Amz-Expires'       => (string) $expires,
		'X-Amz-SignedHeaders' => 'host',
	) );
	$cq   = of_s3_query_string( $query );
	$creq = $method . "\n" . $path . "\n" . $cq . "\nhost:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";
	$sts  = "AWS4-HMAC-SHA256\n" . $amz . "\n" . $scope . "\n" . hash( 'sha256', $creq );
	return $cq . '&X-Amz-Signature=' . hash_hmac( 'sha256', $sts, of_s3_signing_key( $secret, $date, $region ) );
}

// AWS Signature V4, header form, for requests the server makes itself.
function of_s3_auth_headers( $method, $host, $path, $query, $payload_hash, $access, $secret, $region, $ts ) {
	$date  = gmdate( 'Ymd', $ts );
	$amz   = gmdate( 'Ymd\THis\Z', $ts );
	$scope = $date . '/' . $region . '/s3/aws4_request';
	$hdrs  = array( 'host' => $host, 'x-amz-content-sha256' => $payload_hash, 'x-amz-date' => $amz );
	ksort( $hdrs );
	$canon = '';
	foreach ( $hdrs as $k => $v ) {
		$canon .= $k . ':' . $v . "\n";
	}
	$signed = implode( ';', array_keys( $hdrs ) );
	$creq   = $method . "\n" . $path . "\n" . of_s3_query_string( $query ) . "\n" . $canon . "\n" . $signed . "\n" . $payload_hash;
	$sts    = "AWS4-HMAC-SHA256\n" . $amz . "\n" . $scope . "\n" . hash( 'sha256', $creq );
	$sig    = hash_hmac( 'sha256', $sts, of_s3_signing_key( $secret, $date, $region ) );
	return array(
		'x-amz-content-sha256' => $payload_hash,
		'x-amz-date'           => $amz,
		'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig,
	);
}

function of_s3_key( $c, $name ) {
	return $c['prefix'] . '/' . basename( $name );
}

// Uploads a local file to the bucket. Returns true or an error message.
function of_s3_active() {
	return null !== of_s3_conf();
}

function of_s3_put( $name, $file ) {
	$c = of_s3_conf();
	if ( ! $c || ! is_readable( $file ) ) {
		return 'Storage is not configured.';
	}
	$t    = of_s3_target( $c, of_s3_key( $c, $name ) );
	$size = filesize( $file );
	$h    = of_s3_auth_headers( 'PUT', $t['host'], $t['path'], array(), hash_file( 'sha256', $file ), $c['access'], $c['secret'], $c['region'], time() );

	$streamed = false;
	$fh       = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed to the bucket.
	// Stream from disk through cURL so large files aren't loaded into PHP memory.
	$hook = function ( $ch ) use ( $fh, $size, &$streamed ) {
		$streamed = true;
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress exposes no streaming upload API; this hooks its own cURL handle.
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $fh );
		curl_setopt( $ch, CURLOPT_INFILESIZE, $size );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
	};
	add_action( 'http_api_curl', $hook );
	$res = wp_remote_request( $t['base'] . $t['path'], array( 'method' => 'PUT', 'headers' => $h, 'timeout' => 300, 'body' => '' ) );
	remove_action( 'http_api_curl', $hook );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	if ( ! $streamed && ! is_wp_error( $res ) ) {
		// WordPress used a non-cURL transport; send the body from memory instead.
		$res = wp_remote_request( $t['base'] . $t['path'], array( 'method' => 'PUT', 'headers' => $h, 'timeout' => 300, 'body' => file_get_contents( $file ) ) );
	}
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	return 200 === $code ? true : 'Storage returned HTTP ' . $code . '.';
}

function of_s3_delete( $name ) {
	$c = of_s3_conf();
	if ( ! $c ) {
		return false;
	}
	$t   = of_s3_target( $c, of_s3_key( $c, $name ) );
	$h   = of_s3_auth_headers( 'DELETE', $t['host'], $t['path'], array(), hash( 'sha256', '' ), $c['access'], $c['secret'], $c['region'], time() );
	$res = wp_remote_request( $t['base'] . $t['path'], array( 'method' => 'DELETE', 'headers' => $h, 'timeout' => 30 ) );
	return ! is_wp_error( $res ) && in_array( (int) wp_remote_retrieve_response_code( $res ), array( 200, 204 ), true );
}

function of_s3_presigned_get( $name, $ttl = 120 ) {
	$c = of_s3_conf();
	if ( ! $c ) {
		return '';
	}
	$t = of_s3_target( $c, of_s3_key( $c, $name ) );
	$q = of_s3_presign_query( 'GET', $t['host'], $t['path'], array(
		'response-content-disposition' => 'attachment; filename="' . basename( $name ) . '"',
		'response-content-type'        => 'application/octet-stream',
	), $c['access'], $c['secret'], $c['region'], time(), $ttl );
	return $t['base'] . $t['path'] . '?' . $q;
}

// Copies a stored file to a temporary local path (for email attachments). Caller deletes it.
function of_s3_fetch( $name ) {
	$url = of_s3_presigned_get( $name, 60 );
	if ( '' === $url ) {
		return '';
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp = wp_tempnam( basename( $name ) );
	$res = wp_remote_get( $url, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmp ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		wp_delete_file( $tmp );
		return '';
	}
	return $tmp;
}

// Entry values look like "of-file:<name>" (this server) or "of-s3:<name>" (bucket).
function of_file_ref( $v ) {
	if ( ! is_string( $v ) ) {
		return null;
	}
	foreach ( array( 'of-file:' => 'local', 'of-s3:' => 's3' ) as $prefix => $where ) {
		if ( 0 === strpos( $v, $prefix ) ) {
			$name = basename( substr( $v, strlen( $prefix ) ) );
			return '' === $name ? null : array( $where, $name );
		}
	}
	return null;
}

function of_delete_files( $data ) {
	foreach ( (array) $data as $v ) {
		$ref = of_file_ref( $v );
		if ( ! $ref ) {
			continue;
		}
		if ( 's3' === $ref[0] ) {
			of_s3_delete( $ref[1] );
			continue;
		}
		$path = of_upload_dir() . '/' . $ref[1];
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

function of_purge_old() {
	$days = (int) of_opt( 'retention_days' );
	if ( $days < 1 ) {
		return;
	}
	global $wpdb;
	$t    = $wpdb->prefix . 'of_entries';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, data FROM $t WHERE created < %s LIMIT 500", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) );
	foreach ( $rows as $r ) {
		of_delete_files( json_decode( $r->data, true ) ?: array() );
		$wpdb->delete( $t, array( 'id' => (int) $r->id ) );
	}
}

// Signed, time-limited token so blind POSTs to the AJAX endpoint are rejected.
function of_form_token( $form_id, $ts = null ) {
	$ts = $ts ?: time();
	return $ts . '.' . hash_hmac( 'sha256', $form_id . '|' . $ts, wp_salt( 'nonce' ) );
}

function of_check_token( $form_id, $tok ) {
	if ( ! is_string( $tok ) ) {
		return false;
	}
	$p = explode( '.', $tok, 2 );
	if ( 2 !== count( $p ) || ! ctype_digit( $p[0] ) ) {
		return false;
	}
	$age = time() - (int) $p[0];
	return $age >= 0 && $age <= DAY_IN_SECONDS && hash_equals( of_form_token( $form_id, (int) $p[0] ), $tok );
}

// A valid token can otherwise be replayed (double-click, browser back button, retried request) to
// create duplicate entries, since it's a stateless signature rather than a one-time nonce. These two
// functions track consumption separately so a second submission with the same token is rejected once
// the first one succeeds, without changing what makes a token valid in the first place.
function of_token_used( $form_id, $tok ) {
	return (bool) get_transient( 'of_tokused_' . md5( $form_id . '|' . $tok ) );
}

function of_consume_token( $form_id, $tok ) {
	$ts  = (int) explode( '.', $tok, 2 )[0];
	$ttl = max( 60, $ts + DAY_IN_SECONDS - time() );
	set_transient( 'of_tokused_' . md5( $form_id . '|' . $tok ), 1, $ttl );
}

function of_default_email() {
	return array(
		'name'    => 'Admin notification',
		'to'      => get_option( 'admin_email' ),
		'subject' => 'New submission: {form_title}',
		'message' => '{all_fields}',
	);
}

function of_default_config() {
	return array(
		'fields'   => array(),
		'settings' => array(
			'submit_text' => 'Submit',
			'success'     => 'Thanks! Your submission has been received.',
			'emails'      => array( of_default_email() ),
			'webhook'     => '',
			'captcha'     => true,
			'payment'     => array( 'enabled' => false, 'provider' => 'stripe', 'mode' => 'fixed', 'amount' => '10.00', 'field' => '', 'currency' => 'usd', 'description' => '', 'manual_message' => "Thanks! We'll email you a secure payment link within one business day." ),
		),
	);
}

function of_get_config( $form_id ) {
	$json = get_post_meta( $form_id, '_of_config', true );
	$cfg  = $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $cfg ) ) {
		return of_default_config();
	}
	$cfg['settings'] = array_merge( of_default_config()['settings'], $cfg['settings'] ?? array() );
	if ( ! is_array( $cfg['settings']['emails'] ) ) {
		$cfg['settings']['emails'] = array();
	}
	$cfg['settings']['payment'] = array_merge( of_default_config()['settings']['payment'], (array) $cfg['settings']['payment'] );
	return $cfg;
}

/* ---------- Sanitizing builder config ---------- */

function of_sanitize_config( $raw ) {
	$def = of_default_config();
	$out = array( 'fields' => array(), 'settings' => array() );
	$s   = isset( $raw['settings'] ) && is_array( $raw['settings'] ) ? $raw['settings'] : array();
	$p   = is_array( $s['payment'] ?? null ) ? $s['payment'] : array();

	$emails = array();
	foreach ( array_slice( (array) ( $s['emails'] ?? array() ), 0, 10 ) as $e ) {
		if ( ! is_array( $e ) ) {
			continue;
		}
		$emails[] = array(
			'name'    => sanitize_text_field( $e['name'] ?? '' ),
			'to'      => sanitize_text_field( $e['to'] ?? '' ),
			'subject' => sanitize_text_field( $e['subject'] ?? '' ),
			'message' => sanitize_textarea_field( $e['message'] ?? '' ),
		);
	}
	$amount = preg_replace( '/[^0-9.]/', '', (string) ( $p['amount'] ?? '' ) );

	$out['settings'] = array(
		'submit_text' => sanitize_text_field( $s['submit_text'] ?? $def['settings']['submit_text'] ),
		'success'     => wp_kses( $s['success'] ?? '', array( 'a' => array( 'href' => true ), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'br' => array(), 'p' => array() ), array( 'http', 'https', 'mailto' ) ),
		'emails'      => $emails,
		'webhook'     => esc_url_raw( $s['webhook'] ?? '', array( 'https' ) ),
		'captcha'     => ! empty( $s['captcha'] ),
		'payment'     => array(
			'enabled'        => ! empty( $p['enabled'] ),
			'provider'       => 'paypal' === ( $p['provider'] ?? '' ) ? 'paypal' : ( 'manual' === ( $p['provider'] ?? '' ) ? 'manual' : 'stripe' ),
			'mode'           => 'field' === ( $p['mode'] ?? '' ) ? 'field' : 'fixed',
			'amount'         => $amount,
			'field'          => sanitize_key( $p['field'] ?? '' ),
			'currency'       => preg_match( '/^[a-z]{3}$/', strtolower( $p['currency'] ?? '' ) ) ? strtolower( $p['currency'] ) : 'usd',
			'description'    => sanitize_text_field( $p['description'] ?? '' ),
			'manual_message' => wp_kses( $p['manual_message'] ?? '', array( 'a' => array( 'href' => true ), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'br' => array(), 'p' => array() ), array( 'http', 'https', 'mailto' ) ),
		),
	);

	$names = array();
	foreach ( array_slice( (array) ( $raw['fields'] ?? array() ), 0, 100 ) as $f ) {
		if ( ! is_array( $f ) || ! in_array( $f['type'] ?? '', OF_FIELD_TYPES, true ) ) {
			continue;
		}
		$name = sanitize_key( $f['name'] ?? '' );
		if ( '' === $name || isset( $names[ $name ] ) ) {
			$name = 'field_' . ( count( $names ) + 1 );
			while ( isset( $names[ $name ] ) ) {
				$name .= '_x';
			}
		}
		$names[ $name ] = true;
		$logic = is_array( $f['logic'] ?? null ) ? $f['logic'] : array();
		$out['fields'][] = array(
			'type'        => $f['type'],
			'name'        => $name,
			'label'       => sanitize_text_field( $f['label'] ?? '' ),
			'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
			'help'        => sanitize_text_field( $f['help'] ?? '' ),
			'width' => 'half' === ( $f['width'] ?? '' ) ? 'half' : 'full',
			'required'    => ! empty( $f['required'] ),
			'accept'      => 'file' === $f['type'] ? of_clean_exts( $f['accept'] ?? array() ) : array(),
			'max_mb'      => of_field_max_mb( $f ),
			'attach'      => 'file' === $f['type'] && ! empty( $f['attach'] ),
			'options'     => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $f['options'] ?? array() ) ), 'strlen' ) ),
			'value'       => 'html' === $f['type'] ? wp_kses_post( $f['value'] ?? '' ) : sanitize_text_field( $f['value'] ?? '' ),
			'logic'       => array(
				'enabled' => ! empty( $logic['enabled'] ),
				'field'   => sanitize_key( $logic['field'] ?? '' ),
				'op'      => in_array( $logic['op'] ?? '', array( 'eq', 'neq', 'contains', 'empty', 'notempty' ), true ) ? $logic['op'] : 'eq',
				'value'   => sanitize_text_field( $logic['value'] ?? '' ),
			),
		);
	}
	return $out;
}

/* ---------- Conditional logic (shared semantics with frontend.js) ---------- */

function of_logic_visible( $field, $values ) {
	$l = $field['logic'];
	if ( empty( $l['enabled'] ) || '' === $l['field'] ) {
		return true;
	}
	$v = $values[ $l['field'] ] ?? '';
	$v = is_array( $v ) ? $v : array( (string) $v );
	$v = array_filter( $v, 'strlen' );
	switch ( $l['op'] ) {
		case 'eq':
			return in_array( $l['value'], $v, true );
		case 'neq':
			return ! in_array( $l['value'], $v, true );
		case 'contains':
			foreach ( $v as $x ) {
				if ( '' !== $l['value'] && false !== stripos( $x, $l['value'] ) ) {
					return true;
				}
			}
			return false;
		case 'empty':
			return ! $v;
		case 'notempty':
			return (bool) $v;
	}
	return true;
}

/* ---------- Frontend rendering ---------- */

add_shortcode( 'open_form', 'of_shortcode' );
function of_shortcode( $atts ) {
	$id = absint( $atts['id'] ?? 0 );
	if ( ! $id || 'open_form' !== get_post_type( $id ) ) {
		return '';
	}
	wp_enqueue_style( 'open-forms', OF_URL . 'assets/frontend.css', array(), OF_VER );
	wp_enqueue_script( 'open-forms', OF_URL . 'assets/frontend.js', array(), OF_VER, true );
	wp_localize_script( 'open-forms', 'OpenForms', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );

	$cfg  = of_get_config( $id );
	$cap  = ! empty( $cfg['settings']['captcha'] ) ? of_captcha_active() : null;
	if ( $cap ) {
		wp_enqueue_script( 'open-forms-captcha', $cap['script'], array(), null, array( 'strategy' => 'async' ) );
	}
	$html = '';
	if ( isset( $_GET['of_paid'] ) ) {
		$html .= '<div class="of-msg of-ok">Thanks! Your payment is being processed and your submission will be confirmed shortly.</div>';
	}
	$html .= '<form class="of-form" method="post" enctype="multipart/form-data" novalidate data-form="' . $id . '">';
	$html .= '<div class="of-step">';
	foreach ( $cfg['fields'] as $f ) {
		if ( 'step' === $f['type'] ) {
			$html .= '</div><div class="of-step" hidden>';
			continue;
		}
		$html .= of_render_field( $f );
	}
	$html .= '</div>';
	$pay = $cfg['settings']['payment'];
	if ( $pay['enabled'] && 'fixed' === $pay['mode'] ) {
		$html .= '<p class="of-pay">Amount due: ' . esc_html( number_format( (float) $pay['amount'], 2 ) . ' ' . strtoupper( $pay['currency'] ) ) . '</p>';
	}
	if ( $cap ) {
		$html .= '<div class="of-captcha ' . esc_attr( $cap['class'] ) . '" data-sitekey="' . esc_attr( of_opt( 'captcha_site' ) ) . '"></div>';
	}
	$html .= '<div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="of_website" tabindex="-1" autocomplete="off"></div>';
	$html .= '<input type="hidden" name="of_t" value="0">';
	$html .= '<input type="hidden" name="of_tok" value="' . esc_attr( of_form_token( $id ) ) . '">';
	$html .= '<div class="of-nav"><button type="button" class="of-prev" hidden>Back</button><button type="button" class="of-next" hidden>Next</button>';
	$html .= '<button type="submit" class="of-submit">' . esc_html( $cfg['settings']['submit_text'] ) . '</button></div>';
	$html .= '<div class="of-msg" role="status" aria-live="polite"></div></form>';
	return $html;
}

function of_render_field( $f ) {
	$n   = esc_attr( $f['name'] );
	$req = $f['required'] ? ' required' : '';
	$ph  = $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';

	if ( 'hidden' === $f['type'] ) {
		return '<input type="hidden" name="' . $n . '" value="' . esc_attr( $f['value'] ) . '">';
	}
	$logic = ! empty( $f['logic']['enabled'] ) ? ' data-logic="' . esc_attr( wp_json_encode( $f['logic'] ) ) . '"' : '';
	$out   = '<div class="of-field of-' . esc_attr( $f['type'] ) . ( 'half' === ( $f['width'] ?? '' ) ? ' of-w-half' : '' ) . '" data-name="' . $n . '"' . $logic . '>';

	if ( 'html' === $f['type'] ) {
		return $out . wp_kses_post( $f['value'] ) . '</div>';
	}
	$label = esc_html( $f['label'] ) . ( $f['required'] ? ' <span class="of-req">*</span>' : '' );
	$out  .= in_array( $f['type'], array( 'radio', 'checkbox' ), true ) ? '<span class="of-label">' . $label . '</span>' : '<label for="of-' . $n . '" class="of-label">' . $label . '</label>';

	switch ( $f['type'] ) {
		case 'textarea':
			$out .= '<textarea id="of-' . $n . '" name="' . $n . '" rows="4"' . $req . $ph . '></textarea>';
			break;
		case 'select':
			$out .= '<select id="of-' . $n . '" name="' . $n . '"' . $req . '><option value="">Select…</option>';
			foreach ( $f['options'] as $o ) {
				$out .= '<option value="' . esc_attr( $o ) . '">' . esc_html( $o ) . '</option>';
			}
			$out .= '</select>';
			break;
		case 'radio':
		case 'checkbox':
			$multi = 'checkbox' === $f['type'];
			foreach ( $f['options'] as $i => $o ) {
				$out .= '<label class="of-opt"><input type="' . $f['type'] . '" name="' . $n . ( $multi ? '[]' : '' ) . '" value="' . esc_attr( $o ) . '"' . ( $req && ! $multi ? ' required' : '' ) . '> ' . esc_html( $o ) . '</label>';
			}
			break;
		case 'file':
			$fe   = of_field_exts( $f );
			$out .= '<input id="of-' . $n . '" type="file" name="' . $n . '" accept="' . esc_attr( '.' . implode( ',.', $fe ) ) . '" data-max-mb="' . of_field_max_mb( $f ) . '"' . $req . '>';
			$out .= '<small class="of-help">' . esc_html( 'Allowed: .' . implode( ', .', $fe ) . ' (max ' . of_field_max_mb( $f ) . ' MB)' ) . '</small>';
			break;
		default:
			$out .= '<input id="of-' . $n . '" type="' . esc_attr( $f['type'] ) . '" name="' . $n . '"' . $req . $ph . '>';
	}
	if ( $f['help'] ) {
		$out .= '<small class="of-help">' . esc_html( $f['help'] ) . '</small>';
	}
	return $out . '<div class="of-err"></div></div>';
}

/* ---------- Submission ---------- */

add_action( 'wp_ajax_open_forms_submit', 'of_submit' );
add_action( 'wp_ajax_nopriv_open_forms_submit', 'of_submit' );

function of_submit() {
	$form_id = absint( $_POST['form'] ?? 0 );
	if ( ! $form_id || 'open_form' !== get_post_type( $form_id ) ) {
		wp_send_json_error( array( 'message' => 'Invalid form.' ), 400 );
	}

	$tok = wp_unslash( $_POST['of_tok'] ?? '' );
	if ( count( $_POST ) > 300 || ! of_check_token( $form_id, $tok ) || of_token_used( $form_id, $tok ) ) {
		wp_send_json_error( array( 'message' => 'Session expired. Please reload the page and try again.' ), 400 );
	}

	// Spam: honeypot, minimum fill time, per-IP rate limit.
	$ip  = of_ip();
	$key = 'of_rl_' . md5( $ip );
	$cnt = (int) get_transient( $key );
	if ( ! empty( $_POST['of_website'] ) || (int) ( $_POST['of_t'] ?? 0 ) < 2500 ) {
		wp_send_json_error( array( 'message' => 'Submission rejected.' ), 400 );
	}
	if ( $cnt >= (int) apply_filters( 'of_rate_limit', 10 ) ) {
		wp_send_json_error( array( 'message' => 'Too many submissions. Try again later.' ), 429 );
	}
	set_transient( $key, $cnt + 1, 10 * MINUTE_IN_SECONDS );

	$cfg = of_get_config( $form_id );
	$cap = ! empty( $cfg['settings']['captcha'] ) ? of_captcha_active() : null;
	if ( $cap ) {
		$token = sanitize_text_field( wp_unslash( $_POST[ $cap['field'] ] ?? '' ) );
		$res   = '' === $token ? null : wp_safe_remote_post( $cap['verify'], array( 'timeout' => 10, 'body' => array( 'secret' => of_opt( 'captcha_secret' ), 'response' => $token, 'remoteip' => $ip ) ) );
		$body  = $res && ! is_wp_error( $res ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
		if ( empty( $body['success'] ) ) {
			wp_send_json_error( array( 'message' => 'Please complete the CAPTCHA.' ), 400 );
		}
	}

	$post   = wp_unslash( $_POST );
	$values = array();
	foreach ( $cfg['fields'] as $f ) {
		if ( ! in_array( $f['type'], array( 'step', 'html', 'file' ), true ) ) {
			$raw = $post[ $f['name'] ] ?? '';
			$values[ $f['name'] ] = is_array( $raw ) ? array_map( 'sanitize_text_field', array_slice( $raw, 0, 100 ) ) : ( 'textarea' === $f['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
			if ( is_array( $values[ $f['name'] ] ) ) {
				$values[ $f['name'] ] = array_map( function ( $x ) { return mb_substr( $x, 0, 1000 ); }, $values[ $f['name'] ] );
			} else {
				$values[ $f['name'] ] = mb_substr( $values[ $f['name'] ], 0, 10000 );
			}
		}
	}

	$errors = array();
	$data   = array();
	$visible = array();
	foreach ( $cfg['fields'] as $f ) {
		$name = $f['name'];
		if ( in_array( $f['type'], array( 'step', 'html' ), true ) ) {
			continue;
		}
		if ( ! of_logic_visible( $f, $values ) ) {
			$values[ $name ] = '';
			continue;
		}
		$visible[ $name ] = true;

		if ( 'file' === $f['type'] ) {
			$file = $_FILES[ $name ] ?? null;
			if ( ! $file || UPLOAD_ERR_NO_FILE === $file['error'] ) {
				if ( $f['required'] ) {
					$errors[ $name ] = 'This field is required.';
				}
				continue;
			}
			$exts   = of_field_exts( $f );
			$max_mb = of_field_max_mb( $f );
			if ( UPLOAD_ERR_INI_SIZE === $file['error'] || UPLOAD_ERR_FORM_SIZE === $file['error'] || ( UPLOAD_ERR_OK === $file['error'] && $file['size'] > $max_mb * MB_IN_BYTES ) ) {
				$errors[ $name ] = 'File too large (max ' . $max_mb . ' MB).';
				continue;
			}
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				$errors[ $name ] = 'Upload failed. Please try again.';
				continue;
			}
			$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $exts, true ) ) {
				$errors[ $name ] = 'File type not allowed. Allowed: .' . implode( ', .', $exts );
				continue;
			}
			if ( ! is_uploaded_file( $file['tmp_name'] ) || ! of_file_looks_safe( $file['tmp_name'], $ext ) ) {
				$errors[ $name ] = 'This file could not be accepted.';
				continue;
			}
			if ( of_s3_active() ) {
				$stored = wp_generate_password( 24, false ) . '.' . $ext;
				$put    = of_s3_put( $stored, $file['tmp_name'] );
				if ( true === $put ) {
					$data[ $name ] = 'of-s3:' . $stored;
				} else {
					error_log( 'Open Forms: S3 upload failed: ' . $put ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$errors[ $name ] = 'The file could not be stored. Please try again later.';
				}
				continue;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$priv_dir = of_upload_dir();
			$private  = function ( $u ) use ( $priv_dir ) {
				$u['path']    = $priv_dir;
				$u['basedir'] = $priv_dir;
				$u['subdir']  = '';
				$u['url']     = '';
				return $u;
			};
			add_filter( 'upload_dir', $private );
			// Extension and content were validated above; WordPress's mime allowlist doesn't know CAD/STL types.
			$res = wp_handle_upload( $file, array(
				'test_form'                => false,
				'test_type'                => false,
				'unique_filename_callback' => function () use ( $ext ) {
					return wp_generate_password( 24, false ) . '.' . $ext;
				},
			) );
			remove_filter( 'upload_dir', $private );
			if ( isset( $res['error'] ) ) {
				$errors[ $name ] = $res['error'];
			} else {
				$data[ $name ] = 'of-file:' . basename( $res['file'] );
			}
			continue;
		}

		$v     = $values[ $name ];
		$empty = is_array( $v ) ? ! array_filter( $v, 'strlen' ) : '' === $v;
		if ( $empty ) {
			if ( $f['required'] ) {
				$errors[ $name ] = 'This field is required.';
			}
			$data[ $name ] = $v;
			continue;
		}
		if ( 'email' === $f['type'] && ! is_email( $v ) ) {
			$errors[ $name ] = 'Enter a valid email address.';
		} elseif ( 'url' === $f['type'] && ! wp_http_validate_url( $v ) ) {
			$errors[ $name ] = 'Enter a valid URL.';
		} elseif ( 'number' === $f['type'] && ! is_numeric( $v ) ) {
			$errors[ $name ] = 'Enter a number.';
		} elseif ( 'date' === $f['type'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			$errors[ $name ] = 'Enter a valid date.';
		} elseif ( in_array( $f['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
			foreach ( (array) $v as $x ) {
				if ( '' !== $x && ! in_array( $x, $f['options'], true ) ) {
					$errors[ $name ] = 'Invalid choice.';
				}
			}
		}
		$data[ $name ] = $v;
	}

	if ( $errors ) {
		wp_send_json_error( array( 'message' => 'Please fix the highlighted fields.', 'errors' => $errors ), 422 );
	}

	$pay      = $cfg['settings']['payment'];
	$provider = 'paypal' === ( $pay['provider'] ?? '' ) ? 'paypal' : ( 'manual' === ( $pay['provider'] ?? '' ) ? 'manual' : 'stripe' );
	$amount   = 0;
	if ( $pay['enabled'] ) {
		$amount     = 'field' === $pay['mode'] ? (float) ( $values[ $pay['field'] ] ?? 0 ) : (float) $pay['amount'];
		// A manual/external link needs no API credentials; Stripe and PayPal each need their own configured.
		$configured = 'manual' === $provider ? true : ( 'paypal' === $provider ? ( of_opt( 'paypal_client' ) && of_opt( 'paypal_secret' ) ) : (bool) of_opt( 'stripe_key' ) );
		if ( ! $configured ) {
			wp_send_json_error( array( 'message' => 'Payments are not configured.' ), 500 );
		}
		if ( $amount < 0.5 || $amount > 99999 ) {
			$msg = array( 'message' => 'Invalid payment amount.' );
			if ( 'field' === $pay['mode'] && $pay['field'] ) {
				$msg['errors'] = array( $pay['field'] => 'Enter an amount between 0.50 and 99999.' );
			}
			wp_send_json_error( $msg, 422 );
		}
		$amount = round( $amount, 2 );
	}

	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'of_entries', array(
		'form_id'  => $form_id,
		'data'     => wp_json_encode( $data ),
		'ip'       => $ip,
		'status'   => $pay['enabled'] ? 'pending' : 'complete',
		'total'    => $amount,
		'currency' => $pay['enabled'] ? $pay['currency'] : '',
		'created'  => current_time( 'mysql' ),
	) );
	$entry_id = (int) $wpdb->insert_id;
	of_consume_token( $form_id, $tok );

	if ( $pay['enabled'] ) {
		// Manual/external payment: no API call, no redirect. The entry stays "pending" for the
		// admin to invoice and mark paid themselves (see admin_post_of_mark_paid), but notifications
		// fire immediately so the admin knows a payment request is needed.
		if ( 'manual' === $provider ) {
			of_finalize( $entry_id, $form_id, $cfg, $data );
			wp_send_json_success( array( 'message' => wp_kses_post( $pay['manual_message'] ?: of_default_config()['settings']['payment']['manual_message'] ) ) );
		}
		$back    = wp_validate_redirect( esc_url_raw( wp_get_raw_referer() ?: '' ), home_url( '/' ) );
		$success = add_query_arg( 'of_paid', '1', $back );
		if ( 'paypal' === $provider ) {
			$order    = of_paypal_create_order( $entry_id, $amount, $pay['currency'], $pay['description'] ?: get_the_title( $form_id ), $success, $back );
			$redirect = of_paypal_approve_link( $order );
		} else {
			$resp = of_stripe_request( 'checkout/sessions', array(
				'mode'                                              => 'payment',
				'allow_promotion_codes'                             => 'true',
				'client_reference_id'                               => (string) $entry_id,
				'success_url'                                       => $success,
				'cancel_url'                                        => $back,
				'line_items[0][quantity]'                           => 1,
				'line_items[0][price_data][currency]'               => $pay['currency'],
				'line_items[0][price_data][unit_amount]'            => (int) round( $amount * 100 ),
				'line_items[0][price_data][product_data][name]'     => $pay['description'] ?: get_the_title( $form_id ),
			) );
			$redirect = $resp['url'] ?? '';
		}
		if ( ! $redirect ) {
			$wpdb->delete( $wpdb->prefix . 'of_entries', array( 'id' => $entry_id ) );
			wp_send_json_error( array( 'message' => 'Could not start payment. Please try again.' ), 502 );
		}
		wp_send_json_success( array( 'message' => 'Redirecting to payment…', 'redirect' => esc_url_raw( $redirect ) ) );
	}

	of_finalize( $entry_id, $form_id, $cfg, $data );
	wp_send_json_success( array( 'message' => wp_kses_post( $cfg['settings']['success'] ) ) );
}

// Runs once an entry is complete: immediately, or after Stripe confirms payment.
function of_finalize( $entry_id, $form_id, $cfg, $data ) {
	of_notify( $form_id, $cfg, $data );
	if ( $cfg['settings']['webhook'] ) {
		wp_safe_remote_post( $cfg['settings']['webhook'], array(
			'blocking' => false,
			'timeout'  => 5,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'form_id' => $form_id, 'entry_id' => $entry_id, 'form_title' => get_the_title( $form_id ), 'data' => $data ) ),
		) );
	}
	do_action( 'open_forms_submitted', $entry_id, $form_id, $data );
}

/* ---------- Stripe ---------- */

function of_stripe_request( $path, $body ) {
	$res = wp_remote_post( 'https://api.stripe.com/v1/' . $path, array(
		'timeout' => 20,
		'headers' => array( 'Authorization' => 'Bearer ' . of_opt( 'stripe_key' ) ),
		'body'    => $body,
	) );
	return is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
}

function of_stripe_webhook( WP_REST_Request $req ) {
	$secret  = of_opt( 'stripe_whsec' );
	$payload = $req->get_body();
	$sig     = (string) $req->get_header( 'stripe-signature' );
	if ( ! $secret ) {
		return new WP_REST_Response( 'Not configured', 400 );
	}
	$parts = array();
	foreach ( explode( ',', $sig ) as $kv ) {
		$kv = explode( '=', $kv, 2 );
		if ( 2 === count( $kv ) ) {
			$parts[ trim( $kv[0] ) ][] = trim( $kv[1] );
		}
	}
	$ts = (int) ( $parts['t'][0] ?? 0 );
	if ( ! $ts || abs( time() - $ts ) > 300 ) {
		return new WP_REST_Response( 'Bad timestamp', 400 );
	}
	$expected = hash_hmac( 'sha256', $ts . '.' . $payload, $secret );
	$ok       = false;
	foreach ( $parts['v1'] ?? array() as $v1 ) {
		$ok = hash_equals( $expected, $v1 ) || $ok;
	}
	if ( ! $ok ) {
		return new WP_REST_Response( 'Bad signature', 400 );
	}

	$ev  = json_decode( $payload, true );
	// An event from the other Stripe environment (test vs live) is acknowledged but ignored.
	if ( ! is_array( $ev ) || (bool) ( $ev['livemode'] ?? false ) !== ( 'live' === of_stripe_mode() ) ) {
		return new WP_REST_Response( 'ignored', 200 );
	}
	$obj = $ev['data']['object'] ?? array();
	if ( in_array( $ev['type'] ?? '', array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) && in_array( $obj['payment_status'] ?? '', array( 'paid', 'no_payment_required' ), true ) ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'of_entries';
		$id    = absint( $obj['client_reference_id'] ?? 0 );
		$entry = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ) ) : null;
		if ( $entry && 'pending' === $entry->status && of_stripe_payment_matches( $obj, $entry ) ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status='paid', paid=%f, txn=%s WHERE id=%d AND status='pending'", (int) $obj['amount_total'] / 100, sanitize_text_field( $obj['payment_intent'] ?? $obj['id'] ?? '' ), $id ) );
			if ( $done ) {
				of_finalize( $id, (int) $entry->form_id, of_get_config( (int) $entry->form_id ), json_decode( $entry->data, true ) ?: array() );
			}
		}
	}
	return new WP_REST_Response( 'ok', 200 );
}

// The pre-discount subtotal must equal the price we recorded; promotion codes may only lower what is actually charged.
function of_stripe_payment_matches( $obj, $entry ) {
	$expected = (int) round( $entry->total * 100 );
	$sub      = (int) ( $obj['amount_subtotal'] ?? $obj['amount_total'] ?? -1 );
	$total    = (int) ( $obj['amount_total'] ?? -1 );
	if ( $sub !== $expected || $total < 0 || $total > $sub ) {
		return false;
	}
	if ( strtolower( (string) ( $obj['currency'] ?? '' ) ) !== strtolower( $entry->currency ) ) {
		return false;
	}
	// A fully discounted checkout has nothing to pay; anything else must be marked paid.
	return 'paid' === ( $obj['payment_status'] ?? '' ) || ( 'no_payment_required' === ( $obj['payment_status'] ?? '' ) && 0 === $total );
}

/* ---------- PayPal ---------- */

function of_paypal_base() {
	return 'live' === of_paypal_mode() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

// Cached briefly so repeat requests in quick succession don't each pay the OAuth round trip.
function of_paypal_token() {
	$mode   = of_paypal_mode();
	$cached = get_transient( 'of_paypal_token_' . $mode );
	if ( $cached ) {
		return $cached;
	}
	$client = of_opt( 'paypal_client' );
	$secret = of_opt( 'paypal_secret' );
	if ( ! $client || ! $secret ) {
		return '';
	}
	$res = wp_remote_post( of_paypal_base() . '/v1/oauth2/token', array(
		'timeout' => 20,
		'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $client . ':' . $secret ) ),
		'body'    => array( 'grant_type' => 'client_credentials' ),
	) );
	if ( is_wp_error( $res ) ) {
		return '';
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	$tok  = (string) ( $body['access_token'] ?? '' );
	if ( $tok ) {
		set_transient( 'of_paypal_token_' . $mode, $tok, max( 60, (int) ( $body['expires_in'] ?? 300 ) - 60 ) );
	}
	return $tok;
}

function of_paypal_request( $method, $path, $body = null, $headers = array() ) {
	$token = of_paypal_token();
	if ( ! $token ) {
		return array();
	}
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array_merge( array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), $headers ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( of_paypal_base() . $path, $args );
	return is_wp_error( $res ) ? array() : (array) ( json_decode( wp_remote_retrieve_body( $res ), true ) ?: array() );
}

function of_paypal_create_order( $entry_id, $amount, $currency, $description, $return_url, $cancel_url ) {
	$body = array(
		'intent'          => 'CAPTURE',
		'purchase_units'  => array( array(
			'custom_id'   => (string) $entry_id,
			'description' => $description ?: 'Form payment',
			'amount'      => array( 'currency_code' => strtoupper( $currency ), 'value' => number_format( $amount, 2, '.', '' ) ),
		) ),
		'payment_source'  => array( 'paypal' => array( 'experience_context' => array(
			'return_url'  => $return_url,
			'cancel_url'  => $cancel_url,
			'user_action' => 'PAY_NOW',
		) ) ),
	);
	// A deterministic idempotency key tied to the entry so a retried request can't create a second order.
	return of_paypal_request( 'POST', '/v2/checkout/orders', $body, array( 'PayPal-Request-Id' => 'of-entry-' . $entry_id ) );
}

function of_paypal_approve_link( $order ) {
	foreach ( (array) ( $order['links'] ?? array() ) as $l ) {
		if ( in_array( $l['rel'] ?? '', array( 'payer-action', 'approve' ), true ) ) {
			return (string) $l['href'];
		}
	}
	return '';
}

function of_paypal_capture_order( $order_id ) {
	return of_paypal_request( 'POST', '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture', new stdClass() );
}

// PayPal has no local signature scheme like Stripe's; verifying requires calling their API with the
// transmission headers and the webhook ID configured for the currently active mode.
function of_paypal_webhook( WP_REST_Request $req ) {
	$webhook_id = of_opt( 'paypal_webhook_id' );
	$payload    = $req->get_body();
	$event      = json_decode( $payload, true );
	if ( ! $webhook_id || ! is_array( $event ) ) {
		return new WP_REST_Response( 'Not configured', 400 );
	}
	$verify = of_paypal_request( 'POST', '/v1/notifications/verify-webhook-signature', array(
		'auth_algo'         => (string) $req->get_header( 'paypal-auth-algo' ),
		'cert_url'          => (string) $req->get_header( 'paypal-cert-url' ),
		'transmission_id'   => (string) $req->get_header( 'paypal-transmission-id' ),
		'transmission_sig'  => (string) $req->get_header( 'paypal-transmission-sig' ),
		'transmission_time' => (string) $req->get_header( 'paypal-transmission-time' ),
		'webhook_id'        => $webhook_id,
		'webhook_event'     => $event,
	) );
	if ( 'SUCCESS' !== ( $verify['verification_status'] ?? '' ) ) {
		return new WP_REST_Response( 'Bad signature', 400 );
	}
	// Only the subscription for the currently active mode verifies successfully, so a sandbox event
	// can never finalize a live entry (or vice versa) — same model as the Stripe webhook above.
	if ( 'CHECKOUT.ORDER.APPROVED' !== ( $event['event_type'] ?? '' ) ) {
		return new WP_REST_Response( 'ignored', 200 );
	}
	$order_id = (string) ( $event['resource']['id'] ?? '' );
	$pu       = $event['resource']['purchase_units'][0] ?? array();
	$id       = absint( $pu['custom_id'] ?? 0 );
	if ( ! $order_id || ! $id ) {
		return new WP_REST_Response( 'ignored', 200 );
	}
	global $wpdb;
	$t     = $wpdb->prefix . 'of_entries';
	$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ) );
	if ( $entry && 'pending' === $entry->status ) {
		$capture = of_paypal_capture_order( $order_id );
		if ( of_paypal_payment_matches( $capture, $entry ) ) {
			$cap  = $capture['purchase_units'][0]['payments']['captures'][0] ?? array();
			$done = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status='paid', paid=%f, txn=%s WHERE id=%d AND status='pending'", (float) ( $cap['amount']['value'] ?? $entry->total ), sanitize_text_field( $cap['id'] ?? $order_id ), $id ) );
			if ( $done ) {
				of_finalize( $id, (int) $entry->form_id, of_get_config( (int) $entry->form_id ), json_decode( $entry->data, true ) ?: array() );
			}
		}
	}
	return new WP_REST_Response( 'ok', 200 );
}

// PayPal has no concept of promotion codes lowering a Checkout price like Stripe, so this is a plain equality check.
function of_paypal_payment_matches( $capture, $entry ) {
	$pu = $capture['purchase_units'][0] ?? array();
	if ( (string) ( $pu['custom_id'] ?? '' ) !== (string) $entry->id ) {
		return false;
	}
	$cap = $pu['payments']['captures'][0] ?? array();
	$amt = (float) ( $cap['amount']['value'] ?? -1 );
	$cur = strtolower( (string) ( $cap['amount']['currency_code'] ?? '' ) );
	return 'COMPLETED' === ( $cap['status'] ?? '' ) && abs( $amt - (float) $entry->total ) < 0.001 && $cur === strtolower( $entry->currency );
}

function of_ip() {
	// Behind a proxy/CDN, hook this filter to return the trusted client IP.
	return sanitize_text_field( apply_filters( 'of_client_ip', $_SERVER['REMOTE_ADDR'] ?? '' ) );
}

// "25.00 USD", or "20.00 USD (list 25.00)" when a promotion code lowered the charge.
function of_money( $r ) {
	if ( $r->total <= 0 ) {
		return '';
	}
	$cur  = strtoupper( $r->currency );
	$paid = $r->paid ?? null;
	if ( null !== $paid && '' !== $paid && abs( (float) $paid - (float) $r->total ) > 0.001 ) {
		return number_format( (float) $paid, 2, '.', '' ) . ' ' . $cur . ' (list ' . number_format( (float) $r->total, 2, '.', '' ) . ')';
	}
	return $r->total . ' ' . $cur;
}

function of_flat( $v ) {
	return is_array( $v ) ? implode( ', ', $v ) : (string) $v;
}

function of_notify( $form_id, $cfg, $data ) {
	$labels = wp_list_pluck( $cfg['fields'], 'label', 'name' );
	$all    = '';
	$map    = array( '{form_title}' => get_the_title( $form_id ) );
	foreach ( $data as $k => $v ) {
		$all .= ( $labels[ $k ] ?: $k ) . ': ' . of_flat( $v ) . "\n";
		$map[ '{' . $k . '}' ] = of_flat( $v );
	}
	$map['{all_fields}'] = $all;

	$reply = '';
	foreach ( $cfg['fields'] as $f ) {
		if ( 'email' === $f['type'] && ! empty( $data[ $f['name'] ] ) && is_email( $data[ $f['name'] ] ) ) {
			$reply = $data[ $f['name'] ];
			break;
		}
	}

	// Files flagged "attach to emails" are sent with each notification.
	$attach = array();
	$temps  = array();
	foreach ( $cfg['fields'] as $f ) {
		$ref = 'file' === $f['type'] && ! empty( $f['attach'] ) ? of_file_ref( $data[ $f['name'] ] ?? '' ) : null;
		if ( ! $ref ) {
			continue;
		}
		if ( 's3' === $ref[0] ) {
			$p = of_s3_fetch( $ref[1] );
			if ( '' !== $p ) {
				$temps[]  = $p;
				$attach[] = $p;
			}
		} else {
			$p = of_upload_dir() . '/' . $ref[1];
			if ( is_file( $p ) ) {
				$attach[] = $p;
			}
		}
	}

	// Each template can address admins or the submitter via merge tags like {email}.
	foreach ( $cfg['settings']['emails'] as $e ) {
		$to = array_filter( array_map( 'trim', explode( ',', str_replace( array( "\r", "\n" ), '', strtr( $e['to'], $map ) ) ) ), 'is_email' );
		if ( ! $to ) {
			continue;
		}
		$headers = $reply ? array( 'Reply-To: ' . $reply ) : array();
		wp_mail( $to, str_replace( array( "\r", "\n" ), ' ', strtr( $e['subject'], $map ) ), strtr( $e['message'], $map ), $headers, $attach );
	}
	foreach ( $temps as $p ) {
		wp_delete_file( $p );
	}
}

/* ---------- Admin ---------- */

add_action( 'admin_menu', function () {
	add_menu_page( 'Open Forms', 'Open Forms', 'manage_options', 'open-forms', 'of_page_forms', 'dashicons-feedback', 30 );
	add_submenu_page( 'open-forms', 'Entries', 'Entries', 'manage_options', 'open-forms-entries', 'of_page_entries' );
	add_submenu_page( 'open-forms', 'Settings', 'Settings', 'manage_options', 'open-forms-settings', 'of_page_settings' );
} );

function of_page_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$o = get_option( 'of_settings', array() );
	$has = function ( $k ) use ( $o ) {
		return ! empty( $o[ $k ] ) ? ' (saved — leave blank to keep)' : '';
	};
	?>
	<div class="wrap"><h1>Open Forms Settings</h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'of_settings_group' ); ?>
		<h2>CAPTCHA</h2>
		<table class="form-table">
			<tr><th>Provider</th><td><select name="of_settings[captcha]"><option value="">None</option>
			<?php foreach ( of_captcha_providers() as $k => $p ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['captcha'] ?? '', $k ); ?>><?php echo esc_html( $p['name'] ); ?></option>
			<?php endforeach; ?></select></td></tr>
			<tr><th>Site key</th><td><input type="text" class="regular-text" name="of_settings[captcha_site]" value="<?php echo esc_attr( $o['captcha_site'] ?? '' ); ?>"></td></tr>
			<tr><th>Secret key</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[captcha_secret]"><?php echo esc_html( $has( 'captcha_secret' ) ); ?></td></tr>
		</table>
		<h2>Stripe payments</h2>
		<?php
		$mode   = of_stripe_mode();
		$const  = defined( 'OF_STRIPE_MODE' );
		$stripe_saved = function ( $kind, $env ) use ( $o ) {
			$k = 'stripe_' . $kind . '_' . $env;
			if ( defined( 'OF_STRIPE_' . strtoupper( $kind ) . '_' . strtoupper( $env ) ) ) {
				return ' (set in wp-config.php)';
			}
			return ! empty( $o[ $k ] ) || ( 'live' === $env && ! empty( $o[ 'stripe_' . $kind ] ) ) ? ' (saved — leave blank to keep)' : '';
		};
		?>
		<p><strong>Currently using: <?php echo 'live' === $mode ? '<span style="color:#b32d2e">LIVE — real payments</span>' : '<span style="color:#996800">TEST — no real money moves</span>'; ?></strong>
		<?php echo ! of_opt( 'stripe_key' ) ? ' &mdash; no ' . esc_html( $mode ) . ' API key saved yet, so payments are disabled.' : ''; ?></p>
		<table class="form-table">
			<tr><th>Mode</th><td>
				<label><input type="radio" name="of_settings[stripe_mode]" value="test" <?php checked( $mode, 'test' ); ?> <?php disabled( $const ); ?>> Test</label>
				&nbsp; <label><input type="radio" name="of_settings[stripe_mode]" value="live" <?php checked( $mode, 'live' ); ?> <?php disabled( $const ); ?>> Live</label>
				<p class="description">Switch to Live only after a successful test payment. Test and live keys are never mixed: a key from the wrong environment is ignored.<?php echo $const ? ' Mode is fixed by OF_STRIPE_MODE in wp-config.php.' : ''; ?></p>
			</td></tr>
		</table>
		<h3>Test keys</h3>
		<table class="form-table">
			<tr><th>Test secret key</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[stripe_key_test]" placeholder="sk_test_…"><?php echo esc_html( $stripe_saved( 'key', 'test' ) ); ?></td></tr>
			<tr><th>Test webhook signing secret</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[stripe_whsec_test]" placeholder="whsec_…"><?php echo esc_html( $stripe_saved( 'whsec', 'test' ) ); ?></td></tr>
		</table>
		<h3>Live keys</h3>
		<table class="form-table">
			<tr><th>Live secret key</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[stripe_key_live]" placeholder="sk_live_…"><?php echo esc_html( $stripe_saved( 'key', 'live' ) ); ?></td></tr>
			<tr><th>Live webhook signing secret</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[stripe_whsec_live]" placeholder="whsec_…"><?php echo esc_html( $stripe_saved( 'whsec', 'live' ) ); ?></td></tr>
		</table>
		<p class="description">In Stripe, add a webhook endpoint at <code><?php echo esc_html( rest_url( 'open-forms/v1/stripe' ) ); ?></code> for events <code>checkout.session.completed</code> and <code>checkout.session.async_payment_succeeded</code>. Do this once in Stripe's test mode and once in live mode; each gives its own signing secret. Only events from the active mode are processed.<br>
		For better security, keys can be set in wp-config.php instead: <code>OF_STRIPE_KEY_TEST</code>, <code>OF_STRIPE_WHSEC_TEST</code>, <code>OF_STRIPE_KEY_LIVE</code>, <code>OF_STRIPE_WHSEC_LIVE</code>.<br>
		Google Pay needs no separate setup here — Stripe Checkout offers it automatically once it's turned on under your Stripe Dashboard → Settings → Payment methods.</p>
		<h2>PayPal payments</h2>
		<?php
		$pp_mode  = of_paypal_mode();
		$pp_const = defined( 'OF_PAYPAL_MODE' );
		$pp_saved = function ( $kind, $env ) use ( $o ) {
			if ( defined( 'OF_PAYPAL_' . strtoupper( $kind ) . '_' . strtoupper( $env ) ) ) {
				return ' (set in wp-config.php)';
			}
			return ! empty( $o[ 'paypal_' . $kind . '_' . $env ] ) ? ' (saved — leave blank to keep)' : '';
		};
		?>
		<p><strong>Currently using: <?php echo 'live' === $pp_mode ? '<span style="color:#b32d2e">LIVE — real payments</span>' : '<span style="color:#996800">SANDBOX — no real money moves</span>'; ?></strong>
		<?php echo ( ! of_opt( 'paypal_client' ) || ! of_opt( 'paypal_secret' ) ) ? ' &mdash; no ' . esc_html( $pp_mode ) . ' credentials saved yet, so PayPal payments are disabled.' : ''; ?></p>
		<table class="form-table">
			<tr><th>Mode</th><td>
				<label><input type="radio" name="of_settings[paypal_mode]" value="sandbox" <?php checked( $pp_mode, 'sandbox' ); ?> <?php disabled( $pp_const ); ?>> Sandbox</label>
				&nbsp; <label><input type="radio" name="of_settings[paypal_mode]" value="live" <?php checked( $pp_mode, 'live' ); ?> <?php disabled( $pp_const ); ?>> Live</label>
				<p class="description">Switch to Live only after a successful sandbox payment.<?php echo $pp_const ? ' Mode is fixed by OF_PAYPAL_MODE in wp-config.php.' : ''; ?> Unlike Stripe keys, PayPal credentials have no sandbox/live prefix the plugin can check automatically — double-check the mode before saving.</p>
			</td></tr>
		</table>
		<h3>Sandbox credentials</h3>
		<table class="form-table">
			<tr><th>Sandbox client ID</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[paypal_client_sandbox]"><?php echo esc_html( $pp_saved( 'client', 'sandbox' ) ); ?></td></tr>
			<tr><th>Sandbox client secret</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[paypal_secret_sandbox]"><?php echo esc_html( $pp_saved( 'secret', 'sandbox' ) ); ?></td></tr>
			<tr><th>Sandbox webhook ID</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[paypal_webhook_id_sandbox]"><?php echo esc_html( $pp_saved( 'webhook_id', 'sandbox' ) ); ?></td></tr>
		</table>
		<h3>Live credentials</h3>
		<table class="form-table">
			<tr><th>Live client ID</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[paypal_client_live]"><?php echo esc_html( $pp_saved( 'client', 'live' ) ); ?></td></tr>
			<tr><th>Live client secret</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[paypal_secret_live]"><?php echo esc_html( $pp_saved( 'secret', 'live' ) ); ?></td></tr>
			<tr><th>Live webhook ID</th><td><input type="password" class="regular-text" autocomplete="new-password" name="of_settings[paypal_webhook_id_live]"><?php echo esc_html( $pp_saved( 'webhook_id', 'live' ) ); ?></td></tr>
		</table>
		<p class="description">Get a client ID/secret from <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener">developer.paypal.com</a> (create a Sandbox app and, separately, a Live app). In that same dashboard, add a webhook pointing at <code><?php echo esc_html( rest_url( 'open-forms/v1/paypal' ) ); ?></code> subscribed to the <code>CHECKOUT.ORDER.APPROVED</code> event, and paste the Webhook ID it gives you above. Do this once per sandbox app and once per live app. Only events from the active mode are processed.<br>
		Keys can also be set in wp-config.php: <code>OF_PAYPAL_MODE</code>, <code>OF_PAYPAL_CLIENT_SANDBOX</code>, <code>OF_PAYPAL_SECRET_SANDBOX</code>, <code>OF_PAYPAL_WEBHOOK_ID_SANDBOX</code>, <code>OF_PAYPAL_CLIENT_LIVE</code>, <code>OF_PAYPAL_SECRET_LIVE</code>, <code>OF_PAYPAL_WEBHOOK_ID_LIVE</code>.</p>
		<h2>File storage</h2>
		<?php
		$s3c     = of_s3_conf();
		$s3_const = defined( 'OF_STORAGE' );
		$s3_sel   = $s3_const ? (string) OF_STORAGE : ( $o['storage'] ?? 'local' );
		$s3_set   = function ( $k, $const ) use ( $o ) {
			return defined( $const ) ? array( (string) constant( $const ), true ) : array( (string) ( $o[ 's3_' . $k ] ?? '' ), false );
		};
		$test_msg = get_transient( 'of_s3_test_' . get_current_user_id() );
		if ( $test_msg ) {
			delete_transient( 'of_s3_test_' . get_current_user_id() );
			echo '<div class="notice notice-' . ( 0 === strpos( $test_msg, 'OK' ) ? 'success' : 'error' ) . '"><p>' . esc_html( $test_msg ) . '</p></div>';
		}
		?>
		<p><strong>Currently storing uploads: <?php echo $s3c ? 'in your S3-compatible bucket' : 'on this web server (private folder)'; ?></strong></p>
		<table class="form-table">
			<tr><th>Store uploads</th><td>
				<label><input type="radio" name="of_settings[storage]" value="local" 				<?php checked( 's3' !== $s3_sel ); ?> <?php disabled( $s3_const ); ?>> On this server</label>
								&nbsp; <label><input type="radio" name="of_settings[storage]" value="s3" <?php checked( 's3' === $s3_sel ); ?> <?php disabled( $s3_const ); ?>> S3-compatible bucket</label>
				<p class="description">Works with AWS S3, Cloudflare R2, Backblaze B2, Wasabi and MinIO. Keep the bucket private; downloads use short-lived signed links. Files already stored elsewhere stay where they are.<?php echo $s3_const ? ' Fixed by OF_STORAGE in wp-config.php.' : ''; ?></p>
			</td></tr>
			<?php
			$fields = array(
				'endpoint' => array( 'Endpoint URL', 'OF_S3_ENDPOINT', 'https://<account>.r2.cloudflarestorage.com — leave empty for AWS S3' ),
				'region'   => array( 'Region', 'OF_S3_REGION', 'us-east-1 (use "auto" for Cloudflare R2)' ),
				'bucket'   => array( 'Bucket name', 'OF_S3_BUCKET', '' ),
				'prefix'   => array( 'Folder prefix', 'OF_S3_PREFIX', 'open-forms' ),
				'access'   => array( 'Access key ID', 'OF_S3_ACCESS_KEY', '' ),
				'secret'   => array( 'Secret access key', 'OF_S3_SECRET_KEY', '' ),
			);
			foreach ( $fields as $k => $f ) {
				list( $val, $from_const ) = $s3_set( $k, $f[1] );
				$is_secret = in_array( $k, array( 'access', 'secret' ), true );
				echo '<tr><th>' . esc_html( $f[0] ) . '</th><td><input type="' . ( 'secret' === $k ? 'password' : 'text' ) . '" class="regular-text" autocomplete="off" name="of_settings[s3_' . esc_attr( $k ) . ']" placeholder="' . esc_attr( $f[2] ) . '"'
					. ( $is_secret ? '' : ' value="' . esc_attr( $from_const ? '' : $val ) . '"' ) . ( $from_const ? ' disabled' : '' ) . '>'
					. ( $from_const ? ' (set in wp-config.php)' : ( $is_secret && '' !== $val ? ' (saved — leave blank to keep)' : '' ) ) . '</td></tr>';
			}
			?>
		</table>
		<p class="description">These can also be set in wp-config.php: <code>OF_STORAGE</code> ('s3'), <code>OF_S3_ENDPOINT</code>, <code>OF_S3_REGION</code>, <code>OF_S3_BUCKET</code>, <code>OF_S3_PREFIX</code>, <code>OF_S3_ACCESS_KEY</code>, <code>OF_S3_SECRET_KEY</code>. Uninstalling the plugin does not delete files in your bucket.</p>
		<?php if ( $s3c ) : ?>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=of_s3_test' ), 'of_s3_test' ) ); ?>">Test connection</a> <span class="description">Uploads, reads and deletes a tiny test file using the saved settings.</span></p>
		<?php endif; ?>
		<h2>Privacy</h2>
		<table class="form-table">
			<tr><th>Delete entries after (days)</th><td><input type="number" min="0" max="3650" name="of_settings[retention_days]" value="<?php echo esc_attr( $o['retention_days'] ?? 0 ); ?>"><p class="description">0 keeps entries forever. Uploaded files are deleted with their entries.</p></td></tr>
		</table>
		<?php submit_button(); ?>
	</form></div>
	<?php
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( $hook, 'open-forms' ) ) {
		return;
	}
	wp_enqueue_style( 'open-forms-admin', OF_URL . 'assets/admin.css', array(), OF_VER );
	if ( isset( $_GET['form'] ) ) {
		wp_enqueue_script( 'open-forms-builder', OF_URL . 'assets/builder.js', array(), OF_VER, true );
	}
} );

add_action( 'admin_post_of_save_form', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'of_save_form' );
	$id    = absint( $_POST['form_id'] ?? 0 );
	$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ) ?: 'Untitled form';
	$cfg   = of_sanitize_config( json_decode( wp_unslash( $_POST['config'] ?? '' ), true ) ?: array() );

	if ( $id && 'open_form' === get_post_type( $id ) ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
	} else {
		$id = wp_insert_post( array( 'post_type' => 'open_form', 'post_status' => 'publish', 'post_title' => $title ) );
	}
	update_post_meta( $id, '_of_config', wp_slash( wp_json_encode( $cfg ) ) );
	wp_safe_redirect( admin_url( 'admin.php?page=open-forms&form=' . $id . '&saved=1' ) );
	exit;
} );

add_action( 'admin_post_of_delete_form', function () {
	$id = absint( $_GET['form'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'of_delete_form_' . $id );
	if ( 'open_form' === get_post_type( $id ) ) {
		global $wpdb;
		wp_delete_post( $id, true );
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}of_entries WHERE form_id=%d", $id ) ) as $json ) {
			of_delete_files( json_decode( $json, true ) ?: array() );
		}
		$wpdb->delete( $wpdb->prefix . 'of_entries', array( 'form_id' => $id ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=open-forms' ) );
	exit;
} );

add_action( 'admin_post_of_delete_entry', function () {
	$id = absint( $_GET['entry'] ?? 0 );
	$fid = absint( $_GET['form_id'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'of_delete_entry_' . $id );
	global $wpdb;
	$json = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}of_entries WHERE id=%d", $id ) );
	of_delete_files( json_decode( (string) $json, true ) ?: array() );
	$wpdb->delete( $wpdb->prefix . 'of_entries', array( 'id' => $id ) );
	wp_safe_redirect( admin_url( 'admin.php?page=open-forms-entries&form_id=' . $fid ) );
	exit;
} );

// For gateways with no webhook (manual/external links) or as a manual override if a Stripe/PayPal
// webhook never arrives. Pure bookkeeping: no notifications are re-sent, since of_finalize() already
// ran at submission time for manual payments.
add_action( 'admin_post_of_mark_paid', function () {
	$id  = absint( $_GET['entry'] ?? 0 );
	$fid = absint( $_GET['form_id'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'of_mark_paid_' . $id );
	global $wpdb;
	$t = $wpdb->prefix . 'of_entries';
	$wpdb->query( $wpdb->prepare( "UPDATE $t SET status='paid', paid=total WHERE id=%d AND status='pending'", $id ) );
	wp_safe_redirect( admin_url( 'admin.php?page=open-forms-entries&form_id=' . $fid ) );
	exit;
} );

add_action( 'admin_post_of_s3_test', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'of_s3_test' );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$msg  = 'Storage is not configured.';
	$name = 'connection-test-' . wp_generate_password( 12, false ) . '.txt';
	$tmp  = wp_tempnam( $name );
	$body = 'Open Forms connection test ' . wp_generate_password( 16, false );
	if ( of_s3_active() && file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$put = of_s3_put( $name, $tmp );
		if ( true !== $put ) {
			$msg = 'Upload failed: ' . $put;
		} else {
			$got = wp_remote_get( of_s3_presigned_get( $name, 60 ), array( 'timeout' => 30 ) );
			$ok  = ! is_wp_error( $got ) && 200 === (int) wp_remote_retrieve_response_code( $got ) && wp_remote_retrieve_body( $got ) === $body;
			$del = of_s3_delete( $name );
			$msg = $ok && $del ? 'OK: uploaded, downloaded through a signed link, and deleted a test file.' : ( $ok ? 'Upload and download worked, but deleting the test file failed. Check the key has delete permission.' : 'Upload worked but the signed download link failed. Check the bucket is reachable and the region is correct.' );
			if ( ! $ok ) {
				of_s3_delete( $name );
			}
		}
	}
	wp_delete_file( $tmp );
	set_transient( 'of_s3_test_' . get_current_user_id(), $msg, 120 );
	wp_safe_redirect( admin_url( 'admin.php?page=open-forms-settings' ) );
	exit;
} );

add_action( 'admin_post_of_download', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	$f = basename( sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) ) );
	check_admin_referer( 'of_download_' . $f );
	if ( ! empty( $_GET['s3'] ) ) {
		$url = '' === $f ? '' : of_s3_presigned_get( $f );
		if ( '' === $url ) {
			wp_die( 'Not found', 404 );
		}
		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- short-lived presigned URL on the configured bucket.
		exit;
	}
	$path = of_upload_dir() . '/' . $f;
	if ( '' === $f || ! is_file( $path ) ) {
		wp_die( 'Not found', 404 );
	}
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . $f . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private upload to an authorized admin.
	exit;
} );

add_action( 'admin_post_of_export', function () {
	$fid = absint( $_GET['form_id'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'of_export_' . $fid );
	global $wpdb;
	$cfg  = of_get_config( $fid );
	$cols = wp_list_pluck( $cfg['fields'], 'label', 'name' );
	foreach ( $cfg['fields'] as $f ) {
		if ( in_array( $f['type'], array( 'step', 'html' ), true ) ) {
			unset( $cols[ $f['name'] ] );
		}
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}of_entries WHERE form_id = %d ORDER BY id DESC", $fid ) );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="form-' . $fid . '-entries.csv"' );
	$out = fopen( 'php://output', 'w' );
	$esc = function ( $v ) {
		$v = of_flat( $v );
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; // neutralize spreadsheet formulas
	};
	fputcsv( $out, array_merge( array( 'ID', 'Date', 'Status', 'Total' ), array_map( $esc, array_map( fn( $k, $l ) => $l ?: $k, array_keys( $cols ), $cols ) ) ) );
	foreach ( $rows as $r ) {
		$d    = json_decode( $r->data, true ) ?: array();
		$line = array( $r->id, $r->created, $r->status, of_money( $r ) );
		foreach ( array_keys( $cols ) as $k ) {
			$line[] = $esc( $d[ $k ] ?? '' );
		}
		fputcsv( $out, $line );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
	exit;
} );

function of_page_forms() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_GET['form'] ) ) {
		return of_page_builder( absint( $_GET['form'] ) );
	}
	$forms = get_posts( array( 'post_type' => 'open_form', 'numberposts' => -1, 'post_status' => 'any' ) );
	echo '<div class="wrap"><h1 class="wp-heading-inline">Open Forms</h1> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=open-forms&form=0' ) ) . '">Add New</a><hr class="wp-header-end">';
	echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Shortcode</th><th>Entries</th><th></th></tr></thead><tbody>';
	global $wpdb;
	foreach ( $forms as $f ) {
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}of_entries WHERE form_id=%d", $f->ID ) );
		echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=open-forms&form=' . $f->ID ) ) . '">' . esc_html( $f->post_title ) . '</a></td>';
		echo '<td><code>[open_form id="' . (int) $f->ID . '"]</code></td>';
		echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=open-forms-entries&form_id=' . $f->ID ) ) . '">' . $n . '</a></td>';
		echo '<td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=of_delete_form&form=' . $f->ID ), 'of_delete_form_' . $f->ID ) ) . '" onclick="return confirm(\'Delete form and all entries?\')">Delete</a></td></tr>';
	}
	if ( ! $forms ) {
		echo '<tr><td colspan="4">No forms yet.</td></tr>';
	}
	echo '</tbody></table></div>';
}

function of_page_builder( $id ) {
	$cfg   = $id ? of_get_config( $id ) : of_default_config();
	$title = $id ? get_the_title( $id ) : '';
	?>
	<div class="wrap">
		<h1><?php echo $id ? 'Edit form' : 'New form'; ?></h1>
		<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Saved. Shortcode: <code>[open_form id="<?php echo (int) $id; ?>"]</code></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="of-builder-form">
			<input type="hidden" name="action" value="of_save_form">
			<input type="hidden" name="form_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'of_save_form' ); ?>
			<input type="hidden" name="config" id="of-config" value="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>">
			<input type="text" name="title" value="<?php echo esc_attr( $title ); ?>" placeholder="Form title" class="regular-text" style="font-size:1.4em;width:100%;margin-bottom:12px">
			<div id="of-builder" data-types="<?php echo esc_attr( wp_json_encode( OF_FIELD_TYPES ) ); ?>"></div>
			<p><button class="button button-primary button-hero">Save form</button></p>
		</form>
	</div>
	<?php
}

function of_page_entries() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$fid   = absint( $_GET['form_id'] ?? 0 );
	$forms = get_posts( array( 'post_type' => 'open_form', 'numberposts' => -1, 'post_status' => 'any' ) );
	echo '<div class="wrap"><h1>Entries</h1><form method="get"><input type="hidden" name="page" value="open-forms-entries"><select name="form_id" onchange="this.form.submit()"><option value="0">Select a form…</option>';
	foreach ( $forms as $f ) {
		echo '<option value="' . (int) $f->ID . '"' . selected( $fid, $f->ID, false ) . '>' . esc_html( $f->post_title ) . '</option>';
	}
	echo '</select></form>';
	if ( $fid && 'open_form' === get_post_type( $fid ) ) {
		$cfg  = of_get_config( $fid );
		$cols = array();
		foreach ( $cfg['fields'] as $f ) {
			if ( ! in_array( $f['type'], array( 'step', 'html' ), true ) ) {
				$cols[ $f['name'] ] = $f['label'] ?: $f['name'];
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}of_entries WHERE form_id=%d ORDER BY id DESC LIMIT 500", $fid ) );
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=of_export&form_id=' . $fid ), 'of_export_' . $fid ) ) . '">Export CSV</a></p>';
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Status</th><th>Total</th>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$d = json_decode( $r->data, true ) ?: array();
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( $r->created ) . '</td><td>' . esc_html( $r->status ) . '</td><td>' . esc_html( of_money( $r ) ) . '</td>';
			foreach ( array_keys( $cols ) as $k ) {
				$v = of_flat( $d[ $k ] ?? '' );
				$ref = of_file_ref( $v );
				$url = $ref ? wp_nonce_url( admin_url( 'admin-post.php?action=of_download&file=' . rawurlencode( $ref[1] ) . ( 's3' === $ref[0] ? '&s3=1' : '' ) ), 'of_download_' . $ref[1] ) : '';
				echo '<td>' . ( $ref ? '<a href="' . esc_url( $url ) . '">File</a>' : esc_html( of_flat( $v ) ) ) . '</td>';
			}
			$actions = array();
			if ( 'pending' === $r->status ) {
				$actions[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=of_mark_paid&entry=' . $r->id . '&form_id=' . $fid ), 'of_mark_paid_' . $r->id ) ) . '" onclick="return confirm(\'Mark this entry as paid?\')">Mark as paid</a> | ';
			}
			$actions[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=of_delete_entry&entry=' . $r->id . '&form_id=' . $fid ), 'of_delete_entry_' . $r->id ) ) . '" onclick="return confirm(\'Delete entry?\')">Delete</a>';
			echo '<td>' . implode( '', $actions ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( count( $cols ) + 5 ) . '">No entries yet.</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	echo '</div>';
}
