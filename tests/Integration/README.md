# Integration tests

These tests run **inside** a real WordPress install — they need the
`wp-phpunit` test library that ships with the WP core repo. The Docker
environment in `docker/` is the canonical place to run them.

```bash
# From the project root:
docker compose -f docker/docker-compose.yml run --rm wp-cli sh -c '
  cd /var/www/html/wp-content/plugins/vatly-gateway-for-paid-memberships-pro &&
  composer install &&
  WP_TESTS_DIR=/var/www/html/wp-content/plugins/vatly-gateway-for-paid-memberships-pro/vendor/wp-phpunit/wp-phpunit \
    vendor/bin/phpunit --testsuite=integration
'
```

The integration suite is currently a placeholder — the unit suite covers the
bulk of the logic without needing a real WP environment.

Recommended scenarios to add as the plugin matures:

- Full plugin activation creates the `wp_vatly_webhook_calls` table.
- POST to `/wp-json/vatly/v1/webhook` with a real signed payload writes an
  audit row, dispatches the typed event, and updates the PMPro subscription.
- A complete checkout flow against the bundled mock-vatly container assigns
  the right PMPro level on subscription start.
