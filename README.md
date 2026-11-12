![Vatly for Paid Memberships Pro](art/banner.png)

# Vatly Gateway for Paid Memberships Pro

[![Tests](https://img.shields.io/badge/tests-PHPUnit-orange.svg)](#testing)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> Stop maintaining your own VAT logic. Vatly is the European Merchant of Record
> for AI, software and digital products: it is the legal seller on every
> sale and handles payments, tax and compliance, in every country, at every
> rate, on every renewal. You never write a `pmpro_tax` filter snippet again.

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
  and issues compliant invoices as the seller.
- Webhooks sync subscription state back to PMPro: levels are assigned on
  payment, changed when a member switches plan, revoked on cancellation,
  orders are written on every renewal.
- Your PMPro site keeps doing what it's good at: content gating, member
  dashboards, discount codes, drip content, BuddyPress / LMS hooks.

## Requirements

- WordPress **6.2** or newer
- PHP **8.0** or newer
- Paid Memberships Pro **3.0** or newer
- A [Vatly](https://vatly.com) account (free to start)

## Quick start

### Install from a release zip (recommended for end users)

1. Download the latest release zip from the
   [Releases](https://github.com/vatly/vatly-gateway-for-paid-memberships-pro/releases)
   page. The zip ships with:
   - `vendor/` already vendored, so **no Composer needed** on the target
     site;
   - every PHP namespace prefixed to `Vatly\PMPro\Vendor\…` via
     [php-scoper](https://github.com/humbug/php-scoper), so the plugin
     can never collide with another plugin shipping an incompatible
     version of the same library (`composer/ca-bundle`, future HTTP libs,
     etc.).
2. Upload via **Plugins → Add New → Upload Plugin** in WP admin.
3. Activate.
4. Browse to **Memberships → Settings → Payment Gateway**, select **Vatly**,
   and paste your live + test API keys + webhook signing secret. Save.
5. Edit each PMPro membership level you want billed through Vatly and paste
   the matching **Vatly plan ID** (`plan_xxxxxxxx`) into the "Vatly Plan ID"
   field below the existing settings.
6. In your Vatly dashboard, paste the webhook URL shown on the gateway
   settings screen and pick the matching signing secret.

### Install from source (developer mode)

```bash
git clone https://github.com/vatly/vatly-gateway-for-paid-memberships-pro.git
cd vatly-gateway-for-paid-memberships-pro
composer install --no-dev --optimize-autoloader
```

Then symlink/copy the directory into your `wp-content/plugins/` directory.

> Heads-up: a `composer install` from source uses **un-scoped** namespaces
> (`Vatly\Fluent\…`, `Composer\CaBundle\…`). That's fine for development on
> a clean WP install, but on a production site that runs other plugins
> shipping the same libraries you should always install from the release
> zip — it's the only artifact that has php-scoper applied.

### Building a release zip locally

```bash
bin/build-release.sh                  # → build/vatly-gateway-for-paid-memberships-pro.zip
VERSION=0.1.0 bin/build-release.sh    # → build/vatly-gateway-for-paid-memberships-pro-0.1.0.zip
```

The script stages a clean copy of the source, runs `composer install --no-dev`,
applies php-scoper (config in [`scoper.inc.php`](scoper.inc.php)), regenerates
the autoload classmap and zips the result. CI runs the same script on every
PR as a smoke test, and on every `v*` tag as the actual release artifact.

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

### Pricing and VAT

Members pay the price on the Vatly plan you map to a level, not the PMPro
level price. Set both to the same amount so your pricing page matches
checkout.

Selling to consumers? Create the plan as tax-inclusive: buyers pay exactly
the price you show, and the VAT comes out of it. Tax-exclusive plans (the
default) add VAT at checkout, so show prices as "excl. VAT". A plan's tax
behaviour can't be changed after it is created.

### Test vs live (per-record)

Each Vatly order/subscription freezes its test/live mode when its
`pmpro_membership_orders` / `pmpro_subscriptions` row is written from the
webhook: a test-mode Vatly record is stored with PMPro's `gateway_environment`
set to `sandbox`, a live one with `live` — taken from the event itself, not the
current global toggle. The mode is read back per record via `isTestmode()` on
the Vatly order/subscription models, keeping test and live data cleanly
separated even if you later flip the gateway environment.

## FAQ

**Will this break my LearnDash / BuddyBoss / WP Fusion / [LMS] integration?**
No. We assign levels via `pmpro_changeMembershipLevel()`, which fires
`pmpro_after_change_membership_level` — the same action your LMS / community
plugin / CRM sync is already wired to. From PMPro's perspective the gateway
is irrelevant; the level change looks identical to a Stripe checkout.

**Do recurring renewals work without the member doing anything?**
Yes. Vatly bills the customer on the plan's cycle and fires an `order.paid`
webhook. Our reaction writes the renewal order row to
`pmpro_membership_orders` and stamps the active subscription id onto it.
The member's level doesn't change (it's already assigned); the next-payment
date moves forward via PMPro's subscription record.

**How do refunds work?**
Customer-initiated refunds happen at Vatly (it's the merchant of record).
When Vatly issues a refund, the `subscription.canceled_immediately` or
equivalent webhook fires and our reaction revokes the level via
`pmpro_changeMembershipLevel(0, $user_id)`. A PMPro-admin-side "refund this
order" button that drives Vatly's API is on the roadmap; today refunds are
issued from your Vatly dashboard.

**What about discount codes?**
PMPro's own discount codes work — they apply pre-checkout, and we send the
discounted total to Vatly. Vatly's own coupon system isn't surfaced through
PMPro yet (separate roadmap item).

**Can I switch existing Stripe / PayPal members to Vatly?**
Realistically no — and we don't try. New subscriptions go to Vatly, existing
ones keep billing through whatever they started on. Switching a live
subscription between gateways means refunding + re-collecting, which most
PMPro merchants won't accept. Treat this as a **new-customer-only**
acquisition path.

**What's not yet supported?**
- Vatly-side refunds triggered from the PMPro admin order screen
- Vatly coupons exposed as a PMPro discount code source
- Surfacing the MoR invoice PDF URL on the PMPro member dashboard
- Cancellation-with-grace-period notification email

These are in [Roadmap](#roadmap). Each is small.

**What versions are actually tested?**

| Axis        | Required-pass corners                  | Forward-warning |
|-------------|----------------------------------------|-----------------|
| PHP         | 8.1, 8.2, 8.3                          | —               |
| WordPress   | 6.5 (minimum we claim), latest stable  | trunk           |
| PMPro       | 3.0+                                   | —               |
| Vatly SDK   | `vatly-fluent-php ^0.8.0-alpha.18`     | —               |

CI runs unit + integration suites across these on every PR.

**I want to hook custom logic when payments happen — what do I add_action to?**
Two surfaces:

- PMPro's native hooks (`pmpro_after_change_membership_level`,
  `pmpro_after_checkout`, etc.) — fired by us indirectly, same as any
  other gateway.
- Vatly-specific events we dispatch alongside the PMPro hooks:
  `vatly_pmpro_order_paid`, `vatly_pmpro_subscription_started`,
  `vatly_pmpro_subscription_billing_updated`,
  `vatly_pmpro_subscription_canceled_immediately`,
  `vatly_pmpro_subscription_canceled_with_grace_period`,
  `vatly_pmpro_subscription_resumed`, plus a
  `vatly_pmpro_event` catch-all. These carry full fluent event DTOs with
  tax breakdown, invoice number, payment method — handy for emitting your
  own member-facing emails or pushing into a CRM with Vatly-specific
  context PMPro doesn't carry.

**Can I show the customer's payment method on file ("card ending 4242")?**
Yes. We persist the subscription's mandate (payment-method category +
masked identifier) as PMPro subscription meta, kept current via the
`subscription.started` and `subscription.billing_updated` webhooks — so a
billing portal can read `getMandateMethod()` / `getMandateMaskedIdentifier()`
off the subscription without a per-request Vatly API call.

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
- A `wp-phpunit`-backed integration suite (`composer test:integration`)
  that boots a real WordPress install, exercises plugin activation, the
  `dbDelta` migration, and the webhook REST route end-to-end with a real
  HMAC signature. Run automatically in CI across WP 6.2 / latest / trunk.

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
