<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Integration;

use Vatly\PMPro\Configuration;
use Vatly\PMPro\Webhooks\RestRoute;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \Vatly\PMPro\Webhooks\RestRoute
 */
class WebhookRouteTest extends WP_UnitTestCase {

	private const SECRET = 'integration-test-secret-32-bytes-long-please';

	protected function setUp(): void {
		parent::setUp();

		update_option( Configuration::OPTION_WEBHOOK_SECRET, self::SECRET );

		// Boot the plugin's REST routes against the test server. The plugin
		// already hooked into rest_api_init during bootstrap; we just need
		// to dispatch.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	protected function tearDown(): void {
		delete_option( Configuration::OPTION_WEBHOOK_SECRET );
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

	public function test_valid_signature_is_accepted_and_returns_200(): void {
		$payload = wp_json_encode(
			[
				'id'         => 'evt_test_' . uniqid(),
				'resource'   => 'subscription',
				'eventName'  => 'subscription.started',
				'entityType' => 'subscription',
				'entityId'   => 'sub_test',
				'testmode'   => true,
				'createdAt'  => gmdate( 'c' ),
				'object'     => [
					'customerId'         => 'cus_test',
					'subscriptionPlanId' => 'plan_test',
					'name'               => 'Test',
					'quantity'           => 1,
				],
			]
		);

		$timestamp = (string) time();
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, self::SECRET );

		$response = $this->dispatch( (string) $payload, $signature );

		self::assertSame( 200, $response->get_status(), 'Expected a signed payload to be accepted.' );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertTrue( $data['received'] ?? false );
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
