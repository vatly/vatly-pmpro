<?php
/**
 * wp-phpunit configuration for our integration suite.
 *
 * Loaded via the `WP_PHPUNIT__TESTS_CONFIG` env var from
 * `tests/Integration/bootstrap.php`. All connection details come from env vars
 * so the same config works locally (DDEV, Lando, plain MySQL) and in CI.
 *
 * @package Vatly\PMPro
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	$wp_dir = getenv( 'WP_PHPUNIT__WP_DIR' );
	if ( $wp_dir && is_dir( $wp_dir ) ) {
		define( 'ABSPATH', rtrim( $wp_dir, '/' ) . '/' );
	} else {
		fwrite( STDERR, "ABSPATH cannot be derived. Set WP_PHPUNIT__WP_DIR to your WordPress source directory.\n" );
		exit( 1 );
	}
}

// Database. CI provides these via env; locally, defaults assume a `wordpress_test` DB on localhost.
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) !== false ? getenv( 'WP_TESTS_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Vatly PMPro Test Suite' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );

// Auth keys/salts — random per-process to avoid coupling tests to env state.
foreach ( [
	'AUTH_KEY',
	'SECURE_AUTH_KEY',
	'LOGGED_IN_KEY',
	'NONCE_KEY',
	'AUTH_SALT',
	'SECURE_AUTH_SALT',
	'LOGGED_IN_SALT',
	'NONCE_SALT',
] as $vatly_pmpro_key ) {
	if ( ! defined( $vatly_pmpro_key ) ) {
		define( $vatly_pmpro_key, 'vatly-pmpro-test-' . $vatly_pmpro_key );
	}
}
unset( $vatly_pmpro_key );
