<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Vatly\API\Types\Money;
use Vatly\API\Types\OrderLineData;
use Vatly\API\Types\OrderLineRelatedResources;
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

	public function test_stamps_the_subscription_named_on_the_order_lines(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldNotReceive( 'hostCustomerIdFor' );

		$orders = \Mockery::mock( OrderReader::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $this->storedOrder() );

		// The member's most recent subscription row is a different one; the
		// line's own link must win over that guess.
		global $wpdb;
		$wpdb->next_var = 'sub_latest_for_user';

		( new WriteOrderRowOnPaid( $bindings, $orders ) )->handle( $this->orderPaid( [
			$this->line( 'line_1', 'sub_from_line' ),
			$this->line( 'line_2', null ),
		] ) );

		$ops = array_column( $wpdb->queries, 0 );
		self::assertSame( [ 'update' ], $ops, 'The line link needs no member lookup.' );
		[, $data, $where] = $wpdb->queries[0][1];
		self::assertSame( 'sub_from_line', $data['subscription_transaction_id'] );
		self::assertSame( 'ord_1', $where['payment_transaction_id'] );
	}

	public function test_falls_back_to_member_lookup_when_lines_name_several_subscriptions(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		$orders = \Mockery::mock( OrderReader::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $this->storedOrder() );

		global $wpdb;
		$wpdb->next_var = 'sub_latest_for_user';

		( new WriteOrderRowOnPaid( $bindings, $orders ) )->handle( $this->orderPaid( [
			$this->line( 'line_1', 'sub_a' ),
			$this->line( 'line_2', 'sub_b' ),
		] ) );

		$ops        = array_column( $wpdb->queries, 0 );
		$update_idx = array_search( 'update', $ops, true );
		self::assertNotFalse( $update_idx );
		self::assertSame( 'sub_latest_for_user', $wpdb->queries[ $update_idx ][1][1]['subscription_transaction_id'] );
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

	private function storedOrder(): OrderModel {
		return new OrderModel(
			id: 11, userId: 7, membershipLevelId: 1, code: 'A', vatlyId: 'ord_1',
			status: 'success', total: 100, subtotal: 100, tax: 0, currency: 'EUR',
			invoiceNumber: null, paymentMethod: null, subscriptionTransactionId: null,
		);
	}

	private function line( string $id, ?string $subscription_id ): OrderLineData {
		return new OrderLineData(
			vatlyId: $id,
			description: 'Membership',
			quantity: 1,
			basePrice: new Money( 'EUR', '1.00' ),
			total: new Money( 'EUR', '1.00' ),
			subtotal: new Money( 'EUR', '1.00' ),
			productType: 'subscription',
			productId: 'sub_product_ref',
			relatedResources: $subscription_id === null ? null : new OrderLineRelatedResources( $subscription_id ),
		);
	}

	/**
	 * @param OrderLineData[] $lines
	 */
	private function orderPaid( array $lines = [] ): OrderPaid {
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
			lines: $lines,
		);
	}
}
