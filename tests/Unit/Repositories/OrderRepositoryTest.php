<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Repositories\OrderRepository;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Repositories\OrderRepository
 */
class OrderRepositoryTest extends TestCase {

	public function test_store_inserts_with_decimals_and_links_to_user(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$opts = [
					'pmpro_currency'            => 'EUR',
					'pmpro_gateway_environment' => 'sandbox',
				];

				return $opts[ $key ] ?? $default;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		// First query is the token-order lookup → no row → fall through to INSERT.
		$wpdb->next_var = null;
		$wpdb->next_row = [
			'id'                          => 1,
			'user_id'                     => 7,
			'membership_id'               => 0,
			'code'                        => 'ABC123',
			'payment_transaction_id'      => 'ord_1',
			'status'                      => 'success',
			'subtotal'                    => '19.00',
			'tax'                         => '4.00',
			'total'                       => '23.00',
			'notes'                       => json_encode( [ 'vatly_invoice_number' => 'INV-1', 'vatly_currency' => 'EUR' ] ),
			'payment_type'                => 'creditcard',
			'subscription_transaction_id' => '',
		];

		$repo  = new OrderRepository();
		$order = $repo->store( new StoreOrderData(
			vatlyId: 'ord_1',
			customerId: 'cus_1',
			status: 'paid',
			total: 2300,
			currency: 'EUR',
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
			subtotal: 1900,
			taxSummary: null,
			hostCustomerId: '7',
		) );

		self::assertInstanceOf( OrderModel::class, $order );

		// queries[0] is the token-order lookup; queries[1] is the INSERT.
		$insert = $wpdb->queries[1];
		self::assertSame( 'insert', $insert[0] );
		[ $table, $data ] = $insert[1];
		self::assertStringContainsString( 'pmpro_membership_orders', $table );
		self::assertSame( 7, $data['user_id'] );
		self::assertSame( 'success', $data['status'] );
		self::assertSame( '23.00', $data['total'] );
		self::assertSame( '19.00', $data['subtotal'] );
		self::assertSame( 'vatly', $data['gateway'] );
		self::assertSame( 'ord_1', $data['payment_transaction_id'] );
	}

	public function test_store_converts_existing_token_order_instead_of_inserting(): void {
		// Scenario: webhook arrives for the same Vatly checkout that produced
		// a token order via start_hosted_checkout(). Without the guard we'd
		// INSERT a duplicate row.
		Functions\when( 'get_option' )->justReturn( 'EUR' );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		$wpdb->next_var = 99;  // find_pending_token_order_id returns id=99
		$wpdb->next_row = [
			'id'                          => 99,
			'user_id'                     => 42,
			'membership_id'               => 0,
			'code'                        => 'TOKEN',
			'payment_transaction_id'      => 'ord_real',
			'status'                      => 'success',
			'subtotal'                    => '19.00',
			'tax'                         => '4.00',
			'total'                       => '23.00',
			'notes'                       => json_encode( [ 'vatly_currency' => 'EUR' ] ),
			'payment_type'                => 'creditcard',
			'subscription_transaction_id' => '',
		];

		( new OrderRepository() )->store( new StoreOrderData(
			vatlyId: 'ord_real',
			customerId: 'cus_1',
			status: 'paid',
			total: 2300,
			currency: 'EUR',
			paymentMethod: 'creditcard',
			hostCustomerId: '42',
		) );

		$ops = array_column( $wpdb->queries, 0 );
		self::assertContains( 'update', $ops, 'Token order should be converted in place.' );
		self::assertNotContains( 'insert', $ops, 'No duplicate INSERT should occur.' );

		$update_idx           = array_search( 'update', $ops, true );
		[, $data, $where ]   = $wpdb->queries[ $update_idx ][1];
		self::assertSame( [ 'id' => 99 ], $where );
		self::assertSame( 'success', $data['status'] );
		self::assertSame( 'ord_real', $data['payment_transaction_id'] );
	}

	public function test_store_with_no_hostCustomerId_inserts_anonymous_row(): void {
		Functions\when( 'get_option' )->justReturn( 'EUR' );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		$wpdb->next_var = null;  // no token order — host id is 0 so lookup skips
		$wpdb->next_row = [
			'id'                          => 5,
			'user_id'                     => 0,
			'membership_id'               => 0,
			'code'                        => 'ANON',
			'payment_transaction_id'      => 'ord_orphan',
			'status'                      => 'success',
			'subtotal'                    => '10.00',
			'tax'                         => '0.00',
			'total'                       => '10.00',
			'notes'                       => json_encode( [ 'vatly_currency' => 'EUR' ] ),
			'payment_type'                => '',
			'subscription_transaction_id' => '',
		];

		( new OrderRepository() )->store( new StoreOrderData(
			vatlyId: 'ord_orphan',
			customerId: 'cus_unbound',
			status: 'paid',
			total: 1000,
			currency: 'EUR',
		) );

		$ops = array_column( $wpdb->queries, 0 );
		self::assertContains( 'insert', $ops );
	}

	public function test_update_status_paid_is_translated_to_success(): void {
		$order = new OrderModel(
			id: 5,
			userId: 7,
			membershipLevelId: 1,
			code: 'ABC',
			vatlyId: 'ord_1',
			status: 'pending',
			total: 1000,
			subtotal: 1000,
			tax: 0,
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
			subscriptionTransactionId: null,
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		$wpdb->next_row = [
			'id'                          => 5,
			'user_id'                     => 7,
			'membership_id'               => 1,
			'code'                        => 'ABC',
			'payment_transaction_id'      => 'ord_1',
			'status'                      => 'success',
			'subtotal'                    => '10.00',
			'tax'                         => '0.00',
			'total'                       => '10.00',
			'notes'                       => json_encode( [ 'vatly_currency' => 'EUR' ] ),
			'payment_type'                => '',
			'subscription_transaction_id' => '',
		];

		( new OrderRepository() )->update( $order, new UpdateOrderData(
			status: 'paid',
		) );

		$update = $wpdb->queries[0];
		self::assertSame( 'update', $update[0] );
		[ $table, $data, $where ] = $update[1];
		self::assertStringContainsString( 'pmpro_membership_orders', $table );
		self::assertSame( 'success', $data['status'] );
		self::assertSame( [ 'id' => 5 ], $where );
	}

	public function test_cents_to_decimal_handles_zero_decimal_currencies(): void {
		Functions\when( 'get_option' )->justReturn( 'JPY' );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		$wpdb->next_var = null;
		$wpdb->next_row = [
			'id'                          => 1,
			'user_id'                     => 1,
			'membership_id'               => 0,
			'code'                        => 'C',
			'payment_transaction_id'      => 'ord_jpy',
			'status'                      => 'success',
			'subtotal'                    => '5000',
			'tax'                         => '0',
			'total'                       => '5000',
			'notes'                       => json_encode( [ 'vatly_currency' => 'JPY' ] ),
			'payment_type'                => '',
			'subscription_transaction_id' => '',
		];

		( new OrderRepository() )->store( new StoreOrderData(
			vatlyId: 'ord_jpy',
			customerId: 'cus_1',
			status: 'paid',
			total: 5000,
			currency: 'JPY',
			hostCustomerId: '1',
		) );

		// queries[0] is the token-order lookup; queries[1] is the INSERT.
		$insert = $wpdb->queries[1];
		self::assertSame( 'insert', $insert[0] );
		[ , $data ] = $insert[1];
		self::assertSame( '5000', $data['total'] );
	}
}
