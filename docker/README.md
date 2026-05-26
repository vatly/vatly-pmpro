# Local Docker dev environment

Self-contained WordPress + Paid Memberships Pro + Vatly gateway sandbox.
Everything runs against a **mock Vatly server** — no real API key needed.

## TL;DR

```bash
cd docker
docker compose up -d --build

# One-shot install: WP core, PMPro, gateway plugin, options, sample level.
docker compose run --rm wp-cli setup-vatly-pmpro.sh

# Visit the site (login admin / admin):
open http://localhost:8080/wp-admin
```

## What you get

| Service       | URL                          | Purpose                                          |
|---------------|------------------------------|--------------------------------------------------|
| `wordpress`   | http://localhost:8080        | WordPress with PMPro + this plugin pre-installed |
| `mock-vatly`  | http://localhost:8081        | Pretends to be the Vatly API for local testing   |
| `db`          | (internal)                   | MariaDB 11                                       |
| `wp-cli`      | (run-on-demand)              | WP-CLI for scripting / scripting setup           |

The plugin source on your host is **bind-mounted** into the WordPress
container — edits are picked up instantly, no rebuild needed.

## Running a checkout

1. Browse to <http://localhost:8080/membership-account/membership-checkout/?level=1>.
2. Sign up / log in.
3. Click **Continue to Vatly checkout**. You land on the mock hosted checkout
   page (port 8081).
4. Click **Pay €19.00**. The mock server:
   - Flips the checkout to `paid`.
   - Creates a subscription and an order.
   - Signs and POSTs `subscription.started` + `order.paid` webhooks to
     `http://wordpress/wp-json/vatly/v1/webhook`.
   - Redirects you back to PMPro's confirmation page.
5. The webhook reactions assign the PMPro level and write the order row.
   Verify under **Memberships → Orders** and **Users → Profile → Membership**.

## Forging webhooks manually

```bash
# Fire an order.paid webhook against your local WP install.
curl -s -X POST http://localhost:8081/__webhook/order.paid \
  -H 'Content-Type: application/json' \
  -d '{"entityId":"ord_test","object":{"customerId":"cus_test"}}' \
  | jq
```

The mock signs the request with the secret
`mock-secret-for-dev-only-do-not-use-in-prod` — that same secret is configured
on the WP side by `setup.sh`.

## Tearing it down

```bash
docker compose down -v   # -v also nukes the MariaDB volume
```

## Running the test suite inside the container

```bash
docker compose run --rm wp-cli sh -c '
  cd /var/www/html/wp-content/plugins/vatly-gateway-for-paid-memberships-pro &&
  composer install &&
  vendor/bin/phpunit
'
```
