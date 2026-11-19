<?php

declare(strict_types=1);

namespace Vatly\PMPro\Install;

/**
 * Database migrations.
 *
 * Runs on activation and on `admin_init`; it does nothing once DB_VERSION is stored.
 *
 * Currently a single table: `wp_vatly_webhook_calls`. Holds an audit trail of
 * incoming webhook payloads — useful for debugging and replay during outages.
 * PMPro's own `pmpro_membership_orders` and `pmpro_subscriptions` cover order
 * and subscription state so we don't need to mirror those.
 */
final class Migrations {

	public const DB_VERSION_OPTION = 'vatly_pmpro_db_version';
	public const DB_VERSION        = '1.1.0';

	public static function run(): void {
		global $wpdb;

		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		if ( $installed === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::webhook_calls_table();

		// dbDelta requires a very specific, two-space-indented format and no
		// backticks around column names.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vatly_id VARCHAR(191) NOT NULL,
			resource VARCHAR(64) NOT NULL,
			event_name VARCHAR(128) NOT NULL,
			entity_type VARCHAR(64) NOT NULL,
			entity_id VARCHAR(191) NOT NULL,
			vatly_customer_id VARCHAR(191) NULL,
			testmode TINYINT(1) NOT NULL DEFAULT 0,
			payload LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			received_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY vatly_id (vatly_id),
			KEY entity (entity_type, entity_id),
			KEY event_name (event_name),
			KEY received_at (received_at)
		) {$charset_collate};";

		dbDelta( $sql );

		// 1.1.0: encrypt API keys and the webhook secret stored in plain text.
		\Vatly\PMPro\Secrets::encrypt_stored();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function webhook_calls_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'vatly_webhook_calls';
	}
}
