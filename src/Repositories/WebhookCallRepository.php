<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use DateTimeInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\PMPro\Install\Migrations;

/**
 * Custom-table-backed webhook audit log.
 *
 * Idempotency: `vatly_id` has a UNIQUE constraint, so a replayed webhook is
 * recorded once. record() silently ignores duplicates.
 */
class WebhookCallRepository implements WebhookCallRepositoryInterface {

	/**
	 * @param  array<string, mixed> $object
	 */
	public function record(
		string $id,
		string $resource,
		string $eventName,
		string $entityType,
		string $entityId,
		bool $testmode,
		DateTimeInterface $createdAt,
		array $object,
		?string $vatlyCustomerId = null,
	): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}vatly_webhook_calls
					(vatly_id, resource, event_name, entity_type, entity_id, vatly_customer_id, testmode, payload, created_at, received_at)
					VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s, %s)",
				$id,
				$resource,
				$eventName,
				$entityType,
				$entityId,
				$vatlyCustomerId,
				$testmode ? 1 : 0,
				wp_json_encode( $object ),
				$createdAt->format( 'Y-m-d H:i:s' ),
				current_time( 'mysql', true )
			)
		);
	}

	public function cleanUp( int $days = 7 ): int {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}vatly_webhook_calls
					WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);

		return (int) $result;
	}

	/**
	 * Convenience for tests / admin tools.
	 */
	public function tableName(): string {
		return Migrations::webhook_calls_table();
	}
}
