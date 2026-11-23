=== Vatly Gateway for Paid Memberships Pro ===
Contributors: vatly
Tags: paid memberships pro, vat, eu vat, payment gateway, merchant of record
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell PMPro memberships through Vatly, the European Merchant of Record for AI, software and digital products. Vatly handles payments, tax and compliance.

== Description ==

Vatly is the European Merchant of Record for AI, software and digital products, paid memberships included. It is the legal seller on every sale and handles payments, tax and compliance. This plugin adds Vatly as a gateway option to Paid Memberships Pro, so:

* Your customers pay at Vatly's hosted checkout (cards, PayPal, iDEAL, Apple Pay, SEPA).
* Vatly collects VAT per country, applies the right rate, and issues compliant invoices as the seller.
* PMPro continues to handle membership levels, content gating, member dashboards and discount codes.
* Webhooks sync subscription state — levels are assigned on payment and revoked on cancellation.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin and activate it.
2. Browse to Memberships → Settings → Payment Gateway and pick "Vatly".
3. Paste your Live + Test API keys and your webhook signing secret.
4. Copy the webhook URL shown on the settings page into your Vatly dashboard.
5. For each membership level you want billed through Vatly, edit the level and paste the matching Vatly plan id into the "Vatly Plan ID" field.

== Frequently Asked Questions ==

= Does this replace the pmpro-vat-tax addon? =

Yes. With Vatly as the merchant of record, Vatly handles the VAT split, every country, every rate, every recurring renewal. You no longer need to maintain `pmpro_tax` filter snippets.

= Which price do members pay? =

The price on the Vatly plan you map to a level, not the PMPro level price. Set both to the same amount so your pricing page matches checkout. Selling to consumers? Create the plan as tax-inclusive: buyers pay exactly the price you show, and the VAT comes out of it. Tax-exclusive plans (the default) add VAT at checkout, so show prices as "excl. VAT".

= What payment methods are supported? =

Cards, PayPal, iDEAL, Apple Pay, SEPA, and a growing list — Vatly enables them per merchant via the hosted checkout.

= Where can I report bugs or request features? =

GitHub: https://github.com/vatly/vatly-gateway-for-paid-memberships-pro/issues

== Changelog ==

= 0.3.0 =
* Vatly's invoice, and on refunded orders its credit note, are linked from the checkout confirmation, the member invoice page and the admin order screen. The signed links are fetched fresh when shown and never stored. A completed refund marks the PMPro order as refunded and keeps the membership.
* Fixed: plan-to-level lookups read the wrong level meta column, so subscription webhooks could not find the mapped membership level.
* Webhooks are refused until a secret is configured. While no webhook secret is saved, the webhook endpoint answers 503 and processes nothing; Vatly retries the delivery once the secret is saved. If your site has no webhook secret saved, save the secret from your Vatly dashboard in the gateway settings to keep receiving webhooks. The webhook endpoint is only registered while Paid Memberships Pro is active.
* The API keys and webhook secret are stored encrypted, keyed from your WordPress salts or `VATLY_PMPRO_ENCRYPTION_KEY` in wp-config.php, and not autoloaded. The settings screen never shows saved values: leave a field blank to keep it, or tick "Remove saved value" to delete it. If the WordPress salts (or the encryption key) change, enter the API keys and webhook secret again. Values saved by pre-release builds are not read: enter them again.
* Requires vatly-fluent-php 0.8.0-alpha.33 (with vatly-api-php 0.1.0-alpha.36). The webhook-call log no longer keeps the customer's personal data.

= 0.2.0 =
* Adopt vatly-fluent-php 0.8.0-alpha.23. Handle the new `subscription.updated` webhook: an immediate plan change now moves the member to the PMPro level mapped to the new plan (ChangeMembershipLevelOnUpdated), and the stored subscription row is kept in step. `subscription.update_scheduled` (a change queued for the next billing cycle) is exposed on the `vatly_pmpro_subscription_update_scheduled` action but does not change the level until it takes effect.

= 0.1.0 =
* Initial release: gateway driver, webhook listener, PMPro-specific reactions, admin settings, level → plan mapping, Docker dev environment.
