#!/bin/sh
#
# One-shot WordPress + PMPro + Vatly gateway bootstrap.
#
#   docker compose up -d
#   docker compose run --rm wp-cli setup-vatly-pmpro.sh
#
# Idempotent — safe to re-run; will only configure missing pieces.

set -e

WP_PATH="${WP_PATH:-/var/www/html}"
PLUGIN_DIR="$WP_PATH/wp-content/plugins/vatly-gateway-for-paid-memberships-pro"
SITE_URL="${SITE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
MOCK_VATLY="${MOCK_VATLY:-http://localhost:8080}"

cd "$WP_PATH"

wp_run() {
	if [ "$(id -u)" = 0 ]; then
		wp --allow-root "$@"
	else
		wp "$@"
	fi
}

if ! wp_run core is-installed >/dev/null 2>&1; then
	echo "==> Installing WordPress"
	wp_run core install \
		--url="$SITE_URL" \
		--title="Vatly PMPro Dev" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASS" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email
fi

wp_run rewrite structure '/%postname%/' --hard
wp_run rewrite flush --hard

# Install + activate PMPro. PMPro left WordPress.org in 2024, so install the
# latest GitHub release; WP-CLI names the folder after the plugin slug.
if ! wp_run plugin is-installed paid-memberships-pro; then
	PMPRO_TAG=$(wp_run eval 'echo basename( (string) wp_remote_retrieve_header( wp_remote_head( "https://github.com/strangerstudios/paid-memberships-pro/releases/latest", [ "redirection" => 0 ] ), "location" ) );')
	echo "==> Installing Paid Memberships Pro ${PMPRO_TAG}"
	wp_run plugin install "https://github.com/strangerstudios/paid-memberships-pro/archive/refs/tags/${PMPRO_TAG}.zip"
fi
wp_run plugin is-active paid-memberships-pro || wp_run plugin activate paid-memberships-pro

# Install plugin composer deps if vendor is missing.
if [ ! -d "$PLUGIN_DIR/vendor" ]; then
	echo "==> Installing plugin composer dependencies"
	(cd "$PLUGIN_DIR" && composer install --no-dev --optimize-autoloader)
fi

# Activate our gateway plugin.
wp_run plugin activate vatly-gateway-for-paid-memberships-pro

# Configure the gateway to point at the mock Vatly server.
echo "==> Wiring Vatly gateway settings"
wp_run option update pmpro_gateway vatly
wp_run option update pmpro_gateway_environment sandbox
wp_run eval '\Vatly\PMPro\Secrets::store( "vatly_pmpro_api_key_test", "test_localdevkeyXXXXXXXXX" );'
wp_run option update vatly_pmpro_api_url "$MOCK_VATLY"
wp_run eval '\Vatly\PMPro\Secrets::store( "vatly_pmpro_webhook_secret", "mock-secret-for-dev-only-do-not-use-in-prod" );'
wp_run option update pmpro_currency EUR

# PMPro's setup wizard normally creates its pages; a WP-CLI install has to.
if [ -z "$(wp_run option get pmpro_checkout_page_id 2>/dev/null)" ]; then
	echo "==> Creating PMPro pages"
	wp_run eval 'pmpro_generatePages( [ "account" => "Membership Account", "billing" => "Membership Billing", "cancel" => "Membership Cancel", "checkout" => "Membership Checkout", "confirmation" => "Membership Confirmation", "invoice" => "Membership Orders", "levels" => "Membership Levels", "login" => "Log In", "member_profile_edit" => "Your Profile" ] );'
	wp_run rewrite flush --hard
fi
# Settings are wired above, so skip the wizard PMPro opens on first admin login.
wp_run option delete pmpro_wizard_redirect >/dev/null 2>&1 || true

# Seed a recurring membership level mapped to a known mock plan id.
if ! wp_run pmpro level get 1 >/dev/null 2>&1; then
	echo "==> Creating sample membership level"
	wp_run db query "INSERT INTO wp_pmpro_membership_levels (name, description, confirmation, initial_payment, billing_amount, cycle_number, cycle_period, billing_limit, trial_amount, trial_limit, allow_signups) VALUES ('Pro', 'Vatly demo recurring plan', '', 19.00, 19.00, 1, 'Month', 0, 0, 0, 1)"
fi

LEVEL_ID=$(wp_run db query "SELECT id FROM wp_pmpro_membership_levels WHERE name='Pro' LIMIT 1" --skip-column-names --silent | head -n1 | tr -d '[:space:]')

# Validate as positive integer before interpolating into SQL — defense against
# unexpected wp-cli output and a guard rail in case this script is reused.
case "$LEVEL_ID" in
	''|*[!0-9]*) LEVEL_ID='' ;;
esac

if [ -n "$LEVEL_ID" ]; then
	echo "==> Mapping level $LEVEL_ID to Vatly plan plan_local_dev"
	wp_run db query "DELETE FROM wp_pmpro_membership_levelmeta WHERE pmpro_membership_level_id=${LEVEL_ID} AND meta_key='pmpro_vatly_plan_id'"
	wp_run db query "INSERT INTO wp_pmpro_membership_levelmeta (pmpro_membership_level_id, meta_key, meta_value) VALUES (${LEVEL_ID}, 'pmpro_vatly_plan_id', 'plan_local_dev')"
fi

echo
echo "==> Setup complete"
echo "    Site:        $SITE_URL"
echo "    Admin login: $ADMIN_USER / $ADMIN_PASS"
echo "    Mock Vatly:  $MOCK_VATLY  (browser: http://localhost:8081)"
echo
echo "    Try a checkout: $SITE_URL/membership-checkout/?pmpro_level=$LEVEL_ID"
echo "    Webhook URL:    $SITE_URL/wp-json/vatly/v1/webhook"
