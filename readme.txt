=== Open Forms ===
Contributors: garrett
Tags: forms, contact form, file upload, stripe, stl, paypal
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free multi-step form builder with conditional logic, STL/CAD file uploads, private storage, entries, CSV export and Stripe/PayPal payments.

== Description ==

Open Forms is a free form builder with no locked features.

* Multi-step forms and conditional logic
* Per-field file types and size limits, with presets for images, PDF/text and STL/CAD files
* Private upload storage with random filenames, admin-only downloads and configurable retention, on your server or in your own S3-compatible bucket (S3, R2, B2, Wasabi, MinIO)
* Optional email attachments for selected file fields
* Entries list and CSV export
* Spam protection: signed form token, honeypot, minimum fill time, rate limiting, optional CAPTCHA
* Webhooks (HTTPS only)
* Stripe Checkout, PayPal Checkout, or a manual/external payment link (e.g. invoicing tools without an API), chosen per form, with separate test/live (or sandbox/live) keys and promotion codes (Stripe)

= External services =

This plugin only contacts third-party services when the site owner enables them in the settings.

**Stripe** (payments): when a form has a payment using the Stripe gateway, the visitor is sent to Stripe Checkout, and the plugin calls the Stripe API with the order amount, currency and form entry ID. Stripe sends webhooks back to your site. [Terms](https://stripe.com/legal/ssa) | [Privacy](https://stripe.com/privacy)

**PayPal** (payments): when a form has a payment using the PayPal gateway, the visitor is sent to PayPal Checkout, and the plugin calls the PayPal API with the order amount, currency and form entry ID. PayPal sends webhooks back to your site. [Terms](https://www.paypal.com/us/legalhub/useragreement-full) | [Privacy](https://www.paypal.com/us/legalhub/privacy-full)

**CAPTCHA** (optional): if enabled, the visitor's CAPTCHA response token and IP address are sent to the provider you choose for verification (for example Cloudflare Turnstile, hCaptcha or Google reCAPTCHA), subject to that provider's terms and privacy policy.

**Webhooks** (optional): if you configure a webhook URL, submitted form data is sent to that URL.

**S3-compatible storage** (optional): if you choose it in the settings, uploaded files are sent to the bucket you configure (for example Amazon S3, Cloudflare R2 or Backblaze B2) and downloaded from it with signed links. Nothing is sent unless you enable it. See your provider's terms and privacy policy.

== Installation ==

1. Upload the plugin and activate it.
2. Create a form under Open Forms and embed it with `[open_form id="123"]`.
3. For payments, enable them on the form and pick a gateway: Stripe or PayPal (enter the matching keys under Open Forms > Settings), or Manual/external payment link if you invoice clients yourself (e.g. a Chase payment link, wire transfer or check) — mark those entries paid from the Entries screen once you confirm the money has arrived.

Uploads are stored in `wp-content/uploads/open-forms-private/`. On nginx, add `location ^~ /wp-content/uploads/open-forms-private/ { deny all; }`.

== Changelog ==

= 1.3.1 =
* The submission token is now single-use: resubmitting the exact same token (double-click, back button, retried request) is rejected instead of creating a duplicate entry.

= 1.3.0 =
* Added "Manual / external payment link" as a third payment option — no API needed, for site owners who invoice clients directly (e.g. bank transfer, wire, or a Chase payment link). Entries are saved as "pending" with immediate notification, and can be marked paid from the Entries screen.

= 1.2.0 =
* Added PayPal Checkout as a second payment gateway, selectable per form, with separate sandbox/live credentials and webhook verification.

= 1.1.0 =
* Separate Stripe test and live keys, promotion codes, per-field file types, private uploads, retention setting.
