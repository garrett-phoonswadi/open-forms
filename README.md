# Open Forms

A free, open-source WordPress form builder: multi-step forms, conditional logic, file uploads (including STL/CAD), entries, CSV export, spam protection, webhooks and Stripe/PayPal payments.

## Features

- Drag-style builder with multi-step forms and conditional logic
- Per-field file types and size limits (images, PDF, STL/CAD presets), optional email attachments
- Private upload storage with random filenames and configurable retention
- Entries list, admin-only file downloads, CSV export (formula-injection safe)
- Spam protection: signed form token, honeypot, minimum fill time, rate limit, optional CAPTCHA
- Webhooks (https only)
- Stripe Checkout, PayPal Checkout, or a manual/external payment link, chosen per form, each with separate test/live (or sandbox/live) credentials where applicable, and verified webhooks

## Install

1. Zip the plugin folder (`open-forms.php`, `uninstall.php`, `assets/`).
2. WordPress admin → Plugins → Add New → Upload Plugin → activate.
3. Build a form under **Open Forms**, then embed it with `[open_form id="123"]`.

Requires WordPress 5.8+ and PHP 7.4+.

## Stripe configuration

Choose Test or Live mode in the settings page (default: Test). Keys can also be set in `wp-config.php`, which overrides saved settings:

```php
define( 'OF_STRIPE_MODE', 'test' ); // or 'live'
define( 'OF_STRIPE_KEY_TEST', 'sk_test_...' );
define( 'OF_STRIPE_WHSEC_TEST', 'whsec_...' );
define( 'OF_STRIPE_KEY_LIVE', 'sk_live_...' );
define( 'OF_STRIPE_WHSEC_LIVE', 'whsec_...' );
```

Create one webhook endpoint in Stripe test mode and one in live mode, each with its own signing secret.

Google Pay needs no separate setup — Stripe Checkout offers it automatically once it's turned on under your Stripe Dashboard → Settings → Payment methods.

## PayPal configuration

Each form picks its payment gateway (Stripe, PayPal, or Manual) in the builder's Payment section. For PayPal, choose Sandbox or Live mode in the settings page (default: Sandbox). Credentials can also be set in `wp-config.php`, which overrides saved settings:

```php
define( 'OF_PAYPAL_MODE', 'sandbox' ); // or 'live'
define( 'OF_PAYPAL_CLIENT_SANDBOX', '...' );
define( 'OF_PAYPAL_SECRET_SANDBOX', '...' );
define( 'OF_PAYPAL_WEBHOOK_ID_SANDBOX', '...' );
define( 'OF_PAYPAL_CLIENT_LIVE', '...' );
define( 'OF_PAYPAL_SECRET_LIVE', '...' );
define( 'OF_PAYPAL_WEBHOOK_ID_LIVE', '...' );
```

1. Create a Sandbox app and a Live app at [developer.paypal.com](https://developer.paypal.com/dashboard/applications) to get a client ID and secret for each.
2. In that same dashboard, add a webhook pointing at your site's `/wp-json/open-forms/v1/paypal` for the `CHECKOUT.ORDER.APPROVED` event, once per app, and paste each Webhook ID into the matching settings field.
3. Unlike Stripe keys, PayPal credentials have no sandbox/live prefix the plugin can check automatically — double-check the mode toggle before saving credentials.

## Manual / external payment link (no API)

For payment methods with no public API — invoicing tools, Chase payment links, wire transfer, checks — pick **Manual / external payment link** as the form's gateway. No credentials are configured here, because the plugin never calls an API for this option:

1. When a visitor submits, the entry is saved to Entries with status **pending** and your form's notification email(s) fire immediately, so you know a payment request is needed.
2. The visitor sees a configurable message (per form) instead of being redirected anywhere — e.g. *"Thanks! We'll email you a secure payment link within one business day."*
3. You generate and send the actual payment link yourself (e.g. in your Chase business banking portal) outside of WordPress.
4. Once you've confirmed the money has arrived, click **Mark as paid** next to that entry under Open Forms → Entries. This is pure bookkeeping — no further emails are sent — and the same action can also be used as a manual override for a Stripe/PayPal entry if a webhook ever fails to arrive.

## Storing uploads in S3 / R2 / B2 (optional)

By default uploads stay on your web server. To keep them off it, choose **S3-compatible bucket** under Open Forms → Settings → File storage (or set the constants below in `wp-config.php`). It works with AWS S3, Cloudflare R2, Backblaze B2, Wasabi and MinIO.

```php
define( 'OF_STORAGE', 's3' );
define( 'OF_S3_ENDPOINT', 'https://<account>.r2.cloudflarestorage.com' ); // leave out for AWS S3
define( 'OF_S3_REGION', 'auto' );                                          // e.g. us-east-1 for AWS
define( 'OF_S3_BUCKET', 'my-bucket' );
define( 'OF_S3_PREFIX', 'open-forms' );                                    // optional folder
define( 'OF_S3_ACCESS_KEY', '...' );
define( 'OF_S3_SECRET_KEY', '...' );
```

- Files are validated on your server, sent straight to the bucket under random names, and not kept locally.
- Keep the bucket **private**. Admins download through short-lived signed links.
- Deleting an entry (or the retention purge) also deletes the file from the bucket. Uninstalling the plugin does not touch your bucket.
- Use a key limited to that bucket with read, write and delete permission. Use **Test connection** in the settings to verify.
- Files already stored on the server stay there and keep working.

## Server note

Uploads are stored in `wp-content/uploads/open-forms-private/`. Apache is protected by an `.htaccess` file; on nginx add:

```nginx
location ^~ /wp-content/uploads/open-forms-private/ { deny all; }
```

## Tests

```
php tests/run.php                 # unit tests (no WordPress needed)
node tests/e2e.mjs                # end-to-end, needs a running WordPress Playground server
```

S3 storage is tested against a real S3-compatible server in Docker:

```
docker run -d --name of-s3 -p 9100:9000 -e RUSTFS_ACCESS_KEY=testaccess -e RUSTFS_SECRET_KEY=testsecret123 rustfs/rustfs:latest /data
php -d extension=curl -d extension=openssl -d extension=mbstring tests/s3.php
# full WordPress flow: start Playground with tests/blueprint-s3.json on port 9401, then
node tests/e2e-s3.mjs
```

Start the Playground server with:

```
npx @wp-playground/cli@latest server --port=9400 --blueprint=tests/blueprint.json --mount-dir <path-to-repo> /wordpress/wp-content/plugins/open-forms
```

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
