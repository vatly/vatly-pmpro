<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\Fluent\Types\TaxSummary;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Repositories\OrderRepository;
use Vatly\PMPro\Repositories\UserBillable;
use Vatly\PMPro\Tests\TestCase;
use WP_User;

/**
 * @covers \Vatly\PMPro\Repositories\OrderRepository
 */
class OrderRepositoryTest extends TestCase {

	public function test_store_inserts_with_decimals_and_links_to_user(): void {
		$user             = new WP_User();
		$user->ID         = 7;
		$user->user_email = 'pat@example.com';

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->with('cus_1')->andReturn(new UserBillable($user));

		Functions\when('get_option')->alias(static function (string $key, $default = '') {
			$opts = [
				'pmpro_currency'            => 'EUR',
				'pmpro_gateway_environment' => 'sandbox',
			];

			return $opts[$key] ?? $default;
		});
		Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
		Functions\when('wp_json_encode')->alias('json_encode');

		// pmpro_getMembershipLevelForUser is not defined → level_id_for_user
		// returns 0.

		global $wpdb;
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
			'notes'                       => json_encode(['vatly_invoice_number' => 'INV-1', 'vatly_currency' => 'EUR']),
			'payment_type'                => 'creditcard',
			'subscription_transaction_id' => '',
		];

		$repo  = new OrderRepository($customers);
		$order = $repo->store(new StoreOrderData(
			vatlyId: 'ord_1',
			customerId: 'cus_1',
			status: 'paid',
			total: 2300,
			currency: 'EUR',
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
			subtotal: 1900,
			taxSummary: null,
		));

		self::assertInstanceOf(OrderModel::class, $order);

		// First query is now the token-order lookup (returns nothing here so
		// we fall through to INSERT); INSERT is the second.
		$insert = $wpdb->queries[1];
		self::assertSame('insert', $insert[0]);
		[$table, $data] = $insert[1];
		self::assertStringContainsString('pmpro_membership_orders', $table);
		self::assertSame('success', $data['status']);   // 'paid' is converted to PMPro's 'success'
		self::assertSame('23.00', $data['total']);
		self::assertSame('19.00', $data['subtotal']);
		self::assertSame('vatly', $data['gateway']);
		self::assertSame('ord_1', $data['payment_transaction_id']);
	}

	public function test_store_converts_existing_token_order_instead_of_inserting(): void {
		// Scenario: webhook arrives for the same Vatly checkout that produced
		// a token order via start_hosted_checkout(). Without the guard we'd
		// INSERT a duplicate row.
		$user     = new WP_User();
		$user->ID = 42;

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(new UserBillable($user));

		Functions\when('get_option')->justReturn('EUR');
		Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
		Functions\when('wp_json_encode')->alias('json_encode');

		global $wpdb;
		// find_pending_token_order_id() returns id=99 — there *is* a pending row.
		$wpdb->next_var = 99;
		// hydrate() will pull this back after the UPDATE.
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
			'notes'                       => json_encode(['vatly_currency' => 'EUR']),
			'payment_type'                => 'creditcard',
			'subscription_transaction_id' => '',
		];

		(new OrderRepository($customers))->store(new StoreOrderData(
			vatlyId: 'ord_real',
			customerId: 'cus_1',
			status: 'paid',
			total: 2300,
			currency: 'EUR',
			paymentMethod: 'creditcard',
		));

		// We expect: get_var (token lookup) → update (convert in place) → get_row (re-read).
		// Crucially, NO insert.
		$ops = array_column($wpdb->queries, 0);
		self::assertContains('update', $ops, 'Token order should be converted in place.');
		self::assertNotContains('insert', $ops, 'No duplicate INSERT should occur.');

		// Verify the UPDATE targets the right row + transitions to success.
		$update_idx = array_search('update', $ops, true);
		[, $data, $where] = $wpdb->queries[$update_idx][1];
		self::assertSame(['id' => 99], $where);
		self::assertSame('success', $data['status']);
		self::assertSame('ord_real', $data['payment_transaction_id']);
	}

	public function test_store_raises_when_customer_unknown(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(null);

		$this->expectException(\RuntimeException::class);

		(new OrderRepository($customers))->store(new StoreOrderData(
			vatlyId: 'ord_x',
			customerId: 'cus_unknown',
			status: 'paid',
			total: 100,
			currency: 'EUR',
		));
	}

	public function test_update_status_paid_is_translated_to_success(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);

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
			owner: null,
		);

		Functions\when('wp_json_encode')->alias('json_encode');

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
			'notes'                       => json_encode(['vatly_currency' => 'EUR']),
			'payment_type'                => '',
			'subscription_transaction_id' => '',
		];

		(new OrderRepository($customers))->update($order, new UpdateOrderData(
			status: 'paid',
		));

		$update = $wpdb->queries[0];
		self::assertSame('update', $update[0]);
		[$table, $data, $where] = $update[1];
		self::assertStringContainsString('pmpro_membership_orders', $table);
		self::assertSame('success', $data['status']);
		self::assertSame(['id' => 5], $where);
	}

	public function test_cents_to_decimal_handles_zero_decimal_currencies(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);

		$user             = new WP_User();
		$user->ID         = 1;
		$customers->shouldReceive('findByVatlyId')->andReturn(new UserBillable($user));

		Functions\when('get_option')->justReturn('JPY');
		Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
		Functions\when('wp_json_encode')->alias('json_encode');

		global $wpdb;
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
			'notes'                       => json_encode(['vatly_currency' => 'JPY']),
			'payment_type'                => '',
			'subscription_transaction_id' => '',
		];

		(new OrderRepository($customers))->store(new StoreOrderData(
			vatlyId: 'ord_jpy',
			customerId: 'cus_1',
			status: 'paid',
			total: 5000,
			currency: 'JPY',
		));

		// JPY has no minor unit — the stored decimal must equal the cents value.
		// queries[0] is the token-order lookup; queries[1] is the INSERT.
		$insert = $wpdb->queries[1];
		self::assertSame('insert', $insert[0]);
		[, $data] = $insert[1];
		self::assertSame('5000', $data['total']);
	}
}
