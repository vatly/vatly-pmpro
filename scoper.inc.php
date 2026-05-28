<?php
/**
 * php-scoper configuration for the release build.
 *
 * Wraps every namespaced symbol in our plugin + its vendor dependencies in a
 * `Vatly\PMPro\Vendor\…` prefix so we never collide with another WordPress
 * plugin that ships an incompatible version of the same library (most
 * importantly `composer/ca-bundle` today, and any HTTP / Symfony / Monolog /
 * Guzzle dep that vatly-fluent-php may pick up as it stabilises).
 *
 * Why we scope our own `Vatly\Fluent\…` and `Vatly\API\…` namespaces too:
 *
 * If we excluded them, php-scoper would leave their internal references to
 * `Composer\CaBundle\CaBundle` intact — but we'd have rewritten that class to
 * `Vatly\PMPro\Vendor\Composer\CaBundle\CaBundle`. The lookup would then fail
 * at runtime. Scoping the entire graph (vendor + our src) is simpler and
 * leaves no holes.
 *
 * See: https://github.com/humbug/php-scoper
 *
 * @package Vatly\PMPro
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
	'prefix' => 'Vatly\\PMPro\\Vendor',

	'finders' => [
		Finder::create()
			->files()
			->ignoreVCS( true )
			->notName( '/\.(md|dist|neon|xml|json|lock|yml|yaml|txt|sh|sample)$/' )
			->exclude(
				[
					'doc',
					'test',
					'tests',
					'Test',
					'Tests',
					'test_old',
					'vendor-bin',
				]
			)
			->in( 'vendor' ),

		Finder::create()
			->files()
			->ignoreVCS( true )
			->name( '*.php' )
			->in( 'src' ),

		Finder::create()
			->files()
			->ignoreVCS( true )
			->name( '*.php' )
			->in( 'includes' ),

		Finder::create()
			->files()
			->name( 'vatly-gateway-for-paid-memberships-pro.php' )
			->depth( '== 0' )
			->in( '.' ),
	],

	/*
	 * WordPress core lives in the global namespace (functions, WP_User,
	 * WP_REST_Request, etc.). php-scoper already leaves global functions
	 * alone by default; we explicitly tell it not to prefix WP_-prefixed
	 * global classes either.
	 *
	 * `PMProGateway` and our own global `PMProGateway_vatly` (in
	 * includes/class-pmprogateway-vatly.php) also belong here — PMPro
	 * instantiates the latter by its exact unprefixed name.
	 */
	'exclude-namespaces' => [
		// Stay out of WordPress's own namespaces (there are none, but a safe
		// guard for future plugins like wp-rest etc.).
		'WP',
	],

	'exclude-classes' => [
		'/^WP_/',
		'PMProGateway',
		'PMProGateway_vatly',
		'MemberOrder',
		'wpdb',
	],

	'exclude-functions' => [
		// Match everything global — WordPress's API surface is enormous and
		// php-scoper's default is already to leave global functions alone,
		// but we make this explicit so future changes don't accidentally
		// scope something like `__()` or `add_action()`.
		'/.*/',
	],

	'exclude-constants' => [
		'/^WP_/',
		'/^WPINC$/',
		'/^ABSPATH$/',
		'/^VATLY_PMPRO_/',
	],
];
