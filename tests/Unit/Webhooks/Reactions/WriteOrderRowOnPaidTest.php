<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\API\Webhooks\Events\SubscriptionStarted;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\OrderReader;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\WriteOrderRowOnPaid;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\WriteOrderRowOnPaid
 */
class WriteOrderRowOnPaidTest extends TestCase {

	public function test_supports_only_order_paid(): void {
		$reaction = new WriteOrderRowOnPaid(
			\Mockery::mock( CustomerBindingRepository::class ),
			\Mockery::mock( OrderReader::class ),
		);

		self::assertTrue( $reaction->supports( $this->orderPaid() ) );
		self::assertFalse( $reaction->supports( new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan',
			type: 'default',
			name: 'N',
			quantity: 1,
			testmode: true,
		) ) );
	}

	public function test_stamps_subscription_transaction_id_onto_existing_order(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		$orders = \Mockery::mock( OrderReader::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( new OrderModel(
			id: 11,
			userId: 7,
			membershipLevelId: 1,
			code: 'A',
			vatlyId: 'ord_1',
			status: 'success',
			total: 100,
			subtotal: 100,
			tax: 0,
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
			subscriptionTransactionId: null,
		) );


		// The reaction queries pmpro_subscriptions directly (SubscriptionReader
		// only exposes findByVatlyId, not "by user"). Stub the $wpdb response.
		global $wpdb;
		$wpdb->next_var = 'sub_xyz';

		( new WriteOrderRowOnPaid( $bindings, $orders ) )->handle( $this->orderPaid() );

		$ops = array_column( $wpdb->queries, 0 );
		self::assertContains( 'update', $ops, 'Subscription transaction id should be stamped onto the order.' );

		$update_idx        = array_search( 'update', $ops, true );
		[, $data, $where] = $wpdb->queries[ $update_idx ][1];
		self::assertSame( 'sub_xyz', $data['subscription_transaction_id'] );
		self::assertSame( 'vatly', $where['gateway'] );
		self::assertSame( 'ord_1', $where['payment_transaction_id'] );
	}

	public function test_noop_when_order_not_yet_persisted(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$orders   = \Mockery::mock( OrderReader::class );
		$orders->shouldReceive( 'findByVatlyId' )->andReturn( null );

		( new WriteOrderRowOnPaid( $bindings, $orders ) )->handle( $this->orderPaid() );

		global $wpdb;
		self::assertSame( [], $wpdb->queries );
	}

	public function test_noop_when_no_subscription_found_for_user(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		$orders = \Mockery::mock( OrderReader::class );
		$orders->shouldReceive( 'findByVatlyId' )->andReturn( new OrderModel(
			id: 1, userId: 7, membershipLevelId: 0, code: 'A', vatlyId: 'ord_1',
			status: 'success', total: 0, subtotal: 0, tax: 0, currency: 'EUR',
			invoiceNumber: null, paymentMethod: null, subscriptionTransactionId: null,
		) );


		// $wpdb->get_var returns null → no subscription → reaction exits.
		global $wpdb;
		$wpdb->next_var = null;

		( new WriteOrderRowOnPaid( $bindings, $orders ) )->handle( $this->orderPaid() );

		$ops = array_column( $wpdb->queries, 0 );
		self::assertNotContains( 'update', $ops );
	}

	private function orderPaid(): OrderPaid {
		return new OrderPaid(
			customerId: 'cus_1',
			orderId: 'ord_1',
			status: 'paid',
			total: new Money( 'EUR', '1.00' ),
			subtotal: new Money( 'EUR', '1.00' ),
			taxSummary: new TaxSummaryCollection( [] ),
			invoiceNumber: null,
			paymentMethod: null,
			testmode: true,
		);
	}
}
