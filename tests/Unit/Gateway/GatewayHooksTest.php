<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Gateway;

use Vatly\PMPro\Gateway\GatewayHooks;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Gateway\GatewayHooks
 */
class GatewayHooksTest extends TestCase {

	public function test_strip_required_billing_fields_drops_pmpro_billing_keys(): void {
		$input = [
			'bfirstname'      => 'required',
			'blastname'       => 'required',
			'baddress1'       => 'required',
			'bcity'           => 'required',
			'CardType'        => 'required',
			'AccountNumber'   => 'required',
			'CVV'             => 'required',
			'something_else'  => 'required',
			'first_name'      => 'required',
		];

		$out = GatewayHooks::strip_required_billing_fields($input);

		self::assertArrayNotHasKey('bfirstname', $out);
		self::assertArrayNotHasKey('blastname', $out);
		self::assertArrayNotHasKey('baddress1', $out);
		self::assertArrayNotHasKey('bcity', $out);
		self::assertArrayNotHasKey('CardType', $out);
		self::assertArrayNotHasKey('AccountNumber', $out);
		self::assertArrayNotHasKey('CVV', $out);
		// Non-billing keys must be preserved.
		self::assertArrayHasKey('something_else', $out);
		self::assertArrayHasKey('first_name', $out);
	}

	public function test_is_legitimate_vatly_return_rejects_blank_code(): void {
		self::assertFalse( GatewayHooks::is_legitimate_vatly_return( '' ) );
	}

	public function test_is_legitimate_vatly_return_rejects_unknown_order(): void {
		global $wpdb;
		$wpdb->next_row = [];

		self::assertFalse( GatewayHooks::is_legitimate_vatly_return( 'NOTAVALIDCODE' ) );
	}

	public function test_is_legitimate_vatly_return_rejects_orders_from_other_gateways(): void {
		global $wpdb;
		$wpdb->next_row = (object) [
			'id'      => 1,
			'gateway' => 'stripe',
			'status'  => 'token',
		];

		self::assertFalse( GatewayHooks::is_legitimate_vatly_return( 'OTHERGATEWAY' ) );
	}

	public function test_is_legitimate_vatly_return_rejects_orders_already_completed(): void {
		global $wpdb;
		$wpdb->next_row = (object) [
			'id'      => 1,
			'gateway' => 'vatly',
			'status'  => 'success', // not 'token'
		];

		self::assertFalse( GatewayHooks::is_legitimate_vatly_return( 'ALREADYDONE' ) );
	}

	public function test_is_legitimate_vatly_return_rejects_orders_without_checkout_id(): void {
		global $wpdb;
		$wpdb->next_row = (object) [
			'id'      => 1,
			'gateway' => 'vatly',
			'status'  => 'token',
		];

		\Brain\Monkey\Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( '' );

		self::assertFalse( GatewayHooks::is_legitimate_vatly_return( 'NOCHECKOUT' ) );
	}

	public function test_is_legitimate_vatly_return_accepts_well_formed_returns(): void {
		global $wpdb;
		$wpdb->next_row = (object) [
			'id'      => 1,
			'gateway' => 'vatly',
			'status'  => 'token',
		];

		\Brain\Monkey\Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( 'chk_abc' );

		self::assertTrue( GatewayHooks::is_legitimate_vatly_return( 'VALIDCODE' ) );
	}

	public function test_pmpro_payment_options_appends_our_keys(): void {
		$existing = [ 'other_gateway_option' ];

		$result = GatewayHooks::pmpro_payment_options($existing);

		self::assertContains('vatly_pmpro_api_key', $result);
		self::assertContains('vatly_pmpro_api_key_test', $result);
		self::assertContains('vatly_pmpro_api_url', $result);
		self::assertContains('vatly_pmpro_webhook_secret', $result);
		self::assertContains('vatly_pmpro_testmode', $result);
		self::assertContains('other_gateway_option', $result);
	}
}
