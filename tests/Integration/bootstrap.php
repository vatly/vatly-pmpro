<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Drives a real WordPress install via wp-phpunit. The caller must provide:
 *   - `WP_PHPUNIT__WP_DIR` env var pointing to WordPress core source.
 *   - A MySQL/MariaDB instance reachable via `WP_TESTS_DB_*` env vars.
 *
 * Locally:
 *   WP_PHPUNIT__WP_DIR=$HOME/wordpress \
 *   WP_TESTS_DB_NAME=wordpress_test \
 *   vendor/bin/phpunit -c phpunit-integration.xml.dist
 *
 * The CI workflow (`.github/workflows/ci.yml`) handles this end-to-end.
 *
 * @package Vatly\PMPro
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wp_phpunit_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! is_dir( $wp_phpunit_dir ) ) {
	fwrite( STDERR, "wp-phpunit is missing. Run `composer install` first.\n" );
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . dirname( __DIR__ ) . '/wp-tests-config.php' );

require_once $wp_phpunit_dir . '/includes/functions.php';

// Force-load our plugin during the WP test bootstrap. `muplugins_loaded` fires
// before regular plugins, giving us the same priority as a real activated
// plugin but without needing PMPro to be installed for unit-style assertions
// against our own classes.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/vatly-gateway-for-paid-memberships-pro.php';
	}
);

require $wp_phpunit_dir . '/includes/bootstrap.php';
