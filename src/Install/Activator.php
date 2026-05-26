<?php

declare(strict_types=1);

namespace Vatly\PMPro\Install;

/**
 * Activation / deactivation hooks.
 *
 * Creates the webhook_calls audit table and flushes rewrite rules so the
 * webhook REST route is picked up on first activation.
 */
final class Activator {

	public static function activate(): void {
		Migrations::run();

		// REST endpoints don't actually need rewrite flushing on most setups,
		// but doing so guarantees the route works on installs that have
		// permalink rewriting forced on.
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}
}
