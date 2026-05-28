<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Integration;

use Vatly\PMPro\Install\Activator;
use Vatly\PMPro\Install\Migrations;
use WP_UnitTestCase;

/**
 * @covers \Vatly\PMPro\Install\Activator
 * @covers \Vatly\PMPro\Install\Migrations
 */
class PluginActivationTest extends WP_UnitTestCase {

	public function test_plugin_classes_load(): void {
		self::assertTrue( class_exists( \Vatly\PMPro\Plugin::class ) );
		self::assertTrue( class_exists( \Vatly\PMPro\Container::class ) );
		self::assertTrue( class_exists( \Vatly\PMPro\Configuration::class ) );
		self::assertTrue( class_exists( \Vatly\PMPro\Install\Activator::class ) );
	}

	public function test_activator_creates_webhook_calls_table_via_dbDelta(): void {
		global $wpdb;

		// dbDelta is idempotent — even if the table already exists from a
		// previous test run, this should succeed without erroring.
		Activator::activate();

		$table = Migrations::webhook_calls_table();

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		self::assertSame( $table, $result, 'Expected migration to create the webhook_calls table.' );
	}

	public function test_db_version_option_is_set_after_activation(): void {
		Activator::activate();

		self::assertSame(
			Migrations::DB_VERSION,
			get_option( Migrations::DB_VERSION_OPTION )
		);
	}

	public function test_webhook_calls_table_has_expected_columns(): void {
		global $wpdb;

		Activator::activate();

		$table   = Migrations::webhook_calls_table();
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

		$expected = [
			'id',
			'vatly_id',
			'resource',
			'event_name',
			'entity_type',
			'entity_id',
			'vatly_customer_id',
			'testmode',
			'payload',
			'created_at',
			'received_at',
		];

		foreach ( $expected as $column ) {
			self::assertContains( $column, $columns, "Missing column: {$column}" );
		}
	}
}
