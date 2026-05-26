# Vatly Gateway for Paid Memberships Pro

[![Tests](https://img.shields.io/badge/tests-PHPUnit-orange.svg)](#testing)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> Stop maintaining your own VAT logic. Vatly is the merchant of record — we
> handle every country, every rate, every recurring renewal, every Italian
> intra-country edge case. Your PMPro site shows the all-in price; we handle
> the VAT split; you never write a `pmpro_tax` filter snippet again.

A WordPress plugin that adds **Vatly** as a payment gateway to
[Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/),
the largest free WordPress membership plugin (100k+ installs).

## Why

PMPro merchants have been [filing VAT bugs on the official
`pmpro-vat-tax` addon](https://github.com/strangerstudios/pmpro-vat-tax/issues)
for 8+ years. The addon is half-maintained; the underlying problem (EU VAT
across countries, currencies and recurring renewals) is structurally hard.

This plugin removes the problem by making **Vatly the merchant of record** for
your memberships:

- Vatly hosts the checkout (cards, PayPal, iDEAL, Apple Pay, SEPA, …).
- Vatly charges, collects VAT, splits it correctly per country / rate / rule,
  and issues compliant invoices in your name.
- Webhooks sync subscription state back to PMPro: levels are assigned on
  payment, revoked on cancellation, orders are written on every renewal.
- Your PMPro site keeps doing what it's good at: content gating, member
  dashboards, discount codes, drip content, BuddyPress / LMS hooks.

## Requirements

- WordPress **6.2** or newer
- PHP **8.0** or newer
- Paid Memberships Pro **3.0** or newer
- A [Vatly](https://vatly.com) account (free to start)

## Quick start

### Install from a release zip

1. Download the latest release zip from the
   [Releases](https://github.com/vatly/vatly-gateway-for-paid-memberships-pro/releases)
   page (the zip ships with `vendor/` pre-built).
2. Upload via **Plugins → Add New → Upload Plugin** in WP admin.
3. Activate.
4. Browse to **Memberships → Settings → Payment Gateway**, select **Vatly**,
   and paste your live + test API keys + webhook signing secret. Save.
5. Edit each PMPro membership level you want billed through Vatly and paste
   the matching **Vatly plan ID** (`plan_xxxxxxxx`) into the "Vatly Plan ID"
   field below the existing settings.
6. In your Vatly dashboard, paste the webhook URL shown on the gateway
   settings screen and pick the matching signing secret.

### Install from source

```bash
git clone https://github.com/vatly/vatly-gateway-for-paid-memberships-pro.git
cd vatly-gateway-for-paid-memberships-pro
composer install --no-dev --optimize-autoloader
```

Then symlink/copy the directory into your `wp-content/plugins/` directory.

## How it works

```
                ┌────────────────────────────┐
                │  PMPro membership checkout │
                │  (your site)               │
                └─────────────┬──────────────┘
                              │ process()
                              ▼
                ┌────────────────────────────┐
                │ PMProGateway_vatly         │
                │  - creates Vatly customer  │
                │  - builds checkout via     │
                │    vatly/vatly-fluent-php  │
                │  - persists checkout id    │
                │  - wp_redirect → Vatly     │
                └─────────────┬──────────────┘
                              │ hosted checkout
                              ▼
                ┌────────────────────────────┐
                │ Vatly                      │
                │  - collects billing addr   │
                │  - charges + splits VAT    │
                │  - emits webhooks          │
                └─────────────┬──────────────┘
              webhook         │
                 ▼            ▼ user redirect
   /wp-json/vatly/v1/webhook   /membership-confirmation
                 │
                 ▼
   ┌──────────────────────────────────────────┐
   │  WebhookProcessor (fluent)               │
   │   ├─ SyncSubscriptionOnStarted           │
   │   ├─ StoreOrderOnPaid                    │
   │   ├─ CancelSubscriptionOnCanceled        │
   │   └─ + PMPro-specific reactions:         │
   │       ├─ AssignMembershipLevelOnStarted  │
   │       ├─ RevokeMembershipLevelOnCanceled │
   │       └─ WriteOrderRowOnPaid             │
   └──────────────────────────────────────────┘
```

The webhook is the **source of truth** for level assignment / revocation. The
in-line `process()` return-from-Vatly path is an optimisation for the common
case where the user returns to your site before the webhook arrives.

## Configuration

| Setting              | What it is                                                       |
|----------------------|------------------------------------------------------------------|
| Live API key         | Your live Vatly API key (used when `pmpro_gateway_environment` is `live`). |
| Test API key         | Your test Vatly API key (used when sandbox / test environment is active). |
| API endpoint         | Optional override for the Vatly API URL. Leave blank for production. |
| Webhook signing secret | The HMAC secret shown in your Vatly dashboard. Required to receive webhooks. |
| Webhook URL          | `/wp-json/vatly/v1/webhook` on your site — paste this into the Vatly dashboard. |

Per-level setup:

- Edit a PMPro level → scroll to **Vatly Plan** → paste the `plan_xxx` id.

## Local development

A self-contained Docker sandbox with **WordPress + PMPro + a mock Vatly server**
ships with the repo. Zero real credentials needed.

```bash
cd docker
docker compose up -d --build
docker compose run --rm wp-cli setup-vatly-pmpro.sh

open http://localhost:8080/wp-admin    # admin / admin
```

See [docker/README.md](docker/README.md) for the full guide, including how to
forge webhook deliveries manually for testing.

## Testing

```bash
composer install
composer test
```

The suite uses:

- **PHPUnit 9.6** with **Brain\Monkey** for hook/filter assertions
- **Mockery** for repository/action doubles
- Custom WP + PMPro stubs in `tests/WPStubs.php` and `tests/PMProStubs.php`
  (sufficient to exercise the plugin without booting WordPress)
- A separate `integration` test suite intended for `wp-phpunit`-driven runs
  inside the Docker container

The unit suite covers the gateway driver, both checkout flows (start + return),
all PMPro-specific webhook reactions, the REST route signature handling, the
container, the configuration accessor, and the repositories' decimal / cents
round-trips. PMPro recommends Stripe / PayPal Express as reference
implementations; this plugin's coverage is modelled after the same surface.

## Architecture notes

This plugin is intentionally thin. The interesting code lives in
[`vatly/vatly-fluent-php`](https://github.com/Vatly/vatly-fluent-php) — the
framework-agnostic SDK that handles webhook signature verification, action
dispatch, builders for checkouts/subscriptions, and the per-owner orchestrator
(`Vatly\Fluent\Billable`).

Persistence is **delegated to PMPro's existing tables**:

| What                    | Where                                              |
|-------------------------|----------------------------------------------------|
| Vatly customer id       | `usermeta.vatly_customer_id`                       |
| Subscription state      | `pmpro_subscriptions` (gateway = 'vatly')          |
| Order rows              | `pmpro_membership_orders` (gateway = 'vatly')      |
| Level → plan mapping    | `pmpro_membership_levelmeta` (`pmpro_vatly_plan_id`) |
| Vatly checkout id       | `pmpro_membership_ordermeta` (`vatly_checkout_id`) |
| Webhook audit log       | `wp_vatly_webhook_calls` (created on activation)   |

No PMPro core files are touched. The integration is purely through documented
hooks (`pmpro_gateways`, `pmpro_required_billing_fields`,
`pmpro_payment_options`, …) and the `PMProGateway_<slug>` extension point.

## Roadmap

- Refunds via `process_refund` filter
- Per-level discount codes mapped to Vatly coupons
- Multi-currency support hint UI (Vatly already handles it; we want to surface it on the PMPro admin)
- Compatibility test matrix with PMPro's other paid addons

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Acknowledgements

- [Paid Memberships Pro](https://www.paidmembershipspro.com/) for the
  pluggable gateway architecture and clear extension docs.
- All the PMPro merchants who filed VAT bugs over the years —
  [this is for you](https://github.com/strangerstudios/pmpro-vat-tax/issues).
