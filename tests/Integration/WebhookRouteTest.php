<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Integration;

use Vatly\PMPro\Configuration;
use Vatly\PMPro\Install\Activator;
use Vatly\PMPro\Install\Migrations;
use Vatly\PMPro\Secrets;
use Vatly\PMPro\Webhooks\RestRoute;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \Vatly\PMPro\Webhooks\RestRoute
 */
class WebhookRouteTest extends WP_UnitTestCase {

	private const SECRET = 'integration-test-secret-32-bytes-long-please';
	// Fluent v0.8's Vatly composition root constructs a VatlyApiClient eagerly
	// and validates the key format (`/^(live|test)_[\w|]{18,}$/`). The webhook
	// flow doesn't actually call the API, but the construction has to succeed.
	private const TEST_API_KEY = 'test_integrationXXXXXXXXX';

	protected function setUp(): void {
		parent::setUp();

		// Activation hook normally fires on plugin install; in the test
		// environment we trigger it explicitly so the audit table exists
		// when the WebhookProcessor writes its record.
		Activator::activate();

		update_option( Configuration::OPTION_WEBHOOK_SECRET, self::SECRET );
		update_option( Configuration::OPTION_API_KEY_TEST, self::TEST_API_KEY );
		update_option( Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY, 'sandbox' );

		// Boot the plugin's REST routes against the test server. The plugin
		// already hooked into rest_api_init during bootstrap; we just need
		// to dispatch.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	protected function tearDown(): void {
		delete_option( Configuration::OPTION_WEBHOOK_SECRET );
		delete_option( Configuration::OPTION_API_KEY_TEST );
		delete_option( Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY );
		parent::tearDown();
	}

	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		self::assertArrayHasKey( '/vatly/v1/webhook', $routes );
	}

	public function test_empty_body_returns_400(): void {
		$response = $this->dispatch( '', 'any-signature' );

		self::assertSame( 400, $response->get_status() );
	}

	public function test_invalid_signature_returns_400(): void {
		$response = $this->dispatch(
			'{"id":"evt_1","resource":"order","eventName":"order.paid"}',
			't=' . time() . ',v1=garbage'
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_missing_signature_returns_400(): void {
		$response = $this->dispatch( '{"foo":"bar"}', '' );

		self::assertSame( 400, $response->get_status() );
	}

	public function test_returns_503_and_records_nothing_while_no_secret_is_configured(): void {
		delete_option( Configuration::OPTION_WEBHOOK_SECRET );

		$event_id  = 'evt_test_' . uniqid();
		$payload   = (string) wp_json_encode(
			[
				'id'         => $event_id,
				'resource'   => 'test',
				'eventName'  => 'test.echo',
				'entityType' => 'test',
				'entityId'   => 'tst_1',
				'testmode'   => true,
				'createdAt'  => gmdate( 'c' ),
			]
		);
		$timestamp = (string) time();
		// Signed with the empty key, so only the missing-secret check can refuse it.
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, '' );

		$response = $this->dispatch( $payload, $signature );

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'webhook_secret_not_configured', $response->get_data()['code'] ?? null );

		global $wpdb;
		self::assertNull(
			$wpdb->get_row(
				$wpdb->prepare( "SELECT vatly_id FROM {$wpdb->prefix}vatly_webhook_calls WHERE vatly_id = %s", $event_id )
			)
		);
	}

	public function test_a_secret_encrypted_by_the_upgrade_still_verifies_webhooks(): void {
		// setUp stored the secret in plain text, as earlier builds did.
		delete_option( Migrations::DB_VERSION_OPTION );
		Migrations::run();

		$stored = (string) get_option( Configuration::OPTION_WEBHOOK_SECRET );
		self::assertTrue( Secrets::is_encrypted( $stored ) );
		self::assertStringNotContainsString( self::SECRET, $stored );

		$payload   = (string) wp_json_encode(
			[
				'id'         => 'evt_test_' . uniqid(),
				'resource'   => 'test',
				'eventName'  => 'test.echo',
				'entityType' => 'test',
				'entityId'   => 'tst_1',
				'testmode'   => true,
				'createdAt'  => gmdate( 'c' ),
			]
		);
		$timestamp = (string) time();
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, self::SECRET );

		self::assertSame( 200, $this->dispatch( $payload, $signature )->get_status() );
	}

	public function test_valid_signature_is_accepted_and_returns_200(): void {
		// Pick an event name fluent doesn't recognize — it'll dispatch an
		// `UnsupportedWebhookReceived` event and skip every reaction. This
		// isolates the test to "signature passes, audit row written, route
		// returns 200" without depending on PMPro tables existing.
		$event_id = 'evt_test_' . uniqid();
		$payload  = wp_json_encode(
			[
				'id'         => $event_id,
				'resource'   => 'test',
				'eventName'  => 'test.echo',
				'entityType' => 'test',
				'entityId'   => 'tst_1',
				'testmode'   => true,
				'createdAt'  => gmdate( 'c' ),
				'object'     => [ 'customerId' => 'cus_test' ],
			]
		);

		$timestamp = (string) time();
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, self::SECRET );

		$response = $this->dispatch( (string) $payload, $signature );

		self::assertSame( 200, $response->get_status(), 'Expected a signed payload to be accepted.' );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertTrue( $data['received'] ?? false );

		// The audit row should exist now.
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT vatly_id, event_name FROM {$wpdb->prefix}vatly_webhook_calls WHERE vatly_id = %s",
				$event_id
			),
			ARRAY_A
		);
		self::assertNotNull( $row, 'Expected the webhook to be audit-logged.' );
		self::assertSame( 'test.echo', $row['event_name'] );
	}

	private function dispatch( string $body, string $signature ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/vatly/v1/webhook' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		if ( $signature !== '' ) {
			$request->set_header( 'vatly-signature', $signature );
		}

		$response = rest_get_server()->dispatch( $request );

		// rest_ensure_response shape: convert WP_Error → WP_REST_Response.
		if ( $response instanceof \WP_Error ) {
			$status = $response->get_error_data()['status'] ?? 500;

			return new \WP_REST_Response(
				[
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				],
				(int) $status
			);
		}

		return rest_ensure_response( $response );
	}
}
