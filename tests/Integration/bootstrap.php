<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Boots a real WordPress install via the canonical
 * `tests/phpunit/includes/bootstrap.php` from WordPress core (downloaded by
 * `bin/install-wp-tests.sh` to `$WP_TESTS_DIR`).
 *
 * The script must have been run first — locally that means:
 *
 *   bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
 *   WP_TESTS_DIR=/tmp/wordpress-tests-lib \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist
 *
 * The CI workflow (`.github/workflows/ci.yml`) handles this end-to-end and
 * pins the test scaffold to the exact WordPress version under test.
 *
 * @package Vatly\PMPro
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir || ! is_dir( $_tests_dir . '/includes' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set or does not contain the test scaffold. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

// Force-load our plugin during the WP test bootstrap. `muplugins_loaded` fires
// before regular plugins, which gives us the same hook ordering as a real
// activated plugin without requiring PMPro to be installed for tests that
// only exercise our own classes (REST route, migration, etc.).
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// The plugin only boots its gateway and webhook route while PMPro is
		// active; the PMPro class stubs stand in for the real plugin.
		require_once dirname( __DIR__ ) . '/PMProStubs.php';
		require dirname( __DIR__, 2 ) . '/vatly-gateway-for-paid-memberships-pro.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
