<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use Vatly\PMPro\Gateway\VatTaxAddOn;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Gateway\VatTaxAddOn
 */
class VatTaxAddOnTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The add-on's hooks, as pmpro-vat-tax registers them.
		Functions\when( 'pmprovat_pmpro_tax' )->returnArg( 1 );
		add_filter( 'pmpro_registration_checks', 'pmprovat_check_vat_fields_submission' );
		add_filter( 'pmpro_tax', 'pmprovat_pmpro_tax', 10, 3 );
		add_action( 'pmpro_added_order', 'pmprovat_pmpro_added_order' );
		add_action( 'pmpro_checkout_after_billing_fields', 'pmprovat_pmpro_checkout_boxes' );
		add_action( 'wp_enqueue_scripts', 'pmprovat_enqueue_scripts' );
		add_filter( 'pmpro_level_cost_text', 'pmprovat_pmpro_level_cost_text', 10, 2 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['gateway'] );
		parent::tearDown();
	}

	public function test_a_vatly_only_checkout_drops_every_add_on_hook(): void {
		$this->checkout( 'vatly', [ 'vatly' ] );

		self::assertFalse( has_filter( 'pmpro_registration_checks', 'pmprovat_check_vat_fields_submission' ) );
		self::assertFalse( has_filter( 'pmpro_tax', 'pmprovat_pmpro_tax' ) );
		self::assertFalse( has_action( 'pmpro_added_order', 'pmprovat_pmpro_added_order' ) );
		self::assertFalse( has_action( 'pmpro_checkout_after_billing_fields', 'pmprovat_pmpro_checkout_boxes' ) );
		self::assertFalse( has_action( 'wp_enqueue_scripts', 'pmprovat_enqueue_scripts' ) );
		self::assertFalse( has_filter( 'pmpro_level_cost_text', 'pmprovat_pmpro_level_cost_text' ) );
	}

	public function test_a_vatly_checkout_next_to_another_gateway_keeps_the_box_for_that_gateway(): void {
		$this->checkout( 'vatly', [ 'vatly', 'check' ] );

		// What Vatly does itself is dropped for this submission ...
		self::assertFalse( has_filter( 'pmpro_registration_checks', 'pmprovat_check_vat_fields_submission' ) );
		self::assertFalse( has_filter( 'pmpro_tax', 'pmprovat_pmpro_tax' ) );
		self::assertFalse( has_action( 'pmpro_added_order', 'pmprovat_pmpro_added_order' ) );
		// ... but the page keeps the box, script and note for the other gateway.
		self::assertNotFalse( has_action( 'pmpro_checkout_after_billing_fields', 'pmprovat_pmpro_checkout_boxes' ) );
		self::assertNotFalse( has_action( 'wp_enqueue_scripts', 'pmprovat_enqueue_scripts' ) );
		self::assertNotFalse( has_filter( 'pmpro_level_cost_text', 'pmprovat_pmpro_level_cost_text' ) );
	}

	public function test_a_checkout_with_another_gateway_leaves_the_add_on_alone(): void {
		$this->checkout( 'check', [ 'vatly', 'check' ] );

		self::assertNotFalse( has_filter( 'pmpro_registration_checks', 'pmprovat_check_vat_fields_submission' ) );
		self::assertNotFalse( has_filter( 'pmpro_tax', 'pmprovat_pmpro_tax' ) );
		self::assertNotFalse( has_action( 'pmpro_added_order', 'pmprovat_pmpro_added_order' ) );
		self::assertNotFalse( has_action( 'pmpro_checkout_after_billing_fields', 'pmprovat_pmpro_checkout_boxes' ) );
	}

	public function test_wrap_cost_note_wraps_only_the_add_ons_note(): void {
		self::assertSame(
			'€20.00 per Month. <span class="pmprovat-cost-note">VAT may be charged based on your country of residence.</span>',
			VatTaxAddOn::wrap_cost_note( '€20.00 per Month. VAT may be charged based on your country of residence.' )
		);
		self::assertSame( '€20.00 per Month.', VatTaxAddOn::wrap_cost_note( '€20.00 per Month.' ) );
	}

	/**
	 * @param array<int, string> $valid_gateways
	 */
	private function checkout( string $gateway, array $valid_gateways ): void {
		$GLOBALS['gateway'] = $gateway;
		Functions\when( 'get_option' )->justReturn( 'vatly' );
		\Brain\Monkey\Filters\expectApplied( 'pmpro_valid_gateways' )->zeroOrMoreTimes()->andReturn( $valid_gateways );

		VatTaxAddOn::bypass_for_vatly_checkout();
	}
}
