<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads composer's autoloader plus a tiny WordPress-shim so unit tests can
 * run without a full WordPress install. Integration tests using WP_UnitTestCase
 * should subclass tests/Integration/WPIntegrationTestCase and rely on the
 * `wp-phpunit` library directly.
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!file_exists($autoload)) {
	fwrite(STDERR, "Composer dependencies are not installed. Run `composer install` first.\n");
	exit(1);
}

// Patchwork must load BEFORE any file that defines functions Brain\Monkey will
// later need to redefine — otherwise it raises DefinedTooEarly.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

require_once $autoload;

require_once __DIR__ . '/WPStubs.php';
require_once __DIR__ . '/PMProStubs.php';
require_once dirname(__DIR__) . '/includes/class-pmprogateway-vatly.php';
