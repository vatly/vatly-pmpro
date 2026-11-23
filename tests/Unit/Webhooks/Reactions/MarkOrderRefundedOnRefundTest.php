<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\RefundCompleted;
use Vatly\API\Webhooks\Events\SubscriptionResumed;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\MarkOrderRefundedOnRefund;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\MarkOrderRefundedOnRefund
 */
class MarkOrderRefundedOnRefundTest extends TestCase {

	public function test_supports_only_refund_completed(): void {
		$reaction = new MarkOrderRefundedOnRefund(
			\Mockery::mock( OrderRepositoryInterface::class )
		);

		self::assertTrue( $reaction->supports( $this->refund() ) );
		self::assertFalse( $reaction->supports( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			testmode: true,
		) ) );
	}

	public function test_marks_originating_order_refunded(): void {
		$order = $this->order( 'success' );

		$captured = null;
		$orders   = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'order_1' )->andReturn( $order );
		$orders->shouldReceive( 'update' )
			->once()
			->andReturnUsing( function ( $o, UpdateOrderData $d ) use ( &$captured, $order ) {
				$captured = $d;

				return $order;
			} );

		( new MarkOrderRefundedOnRefund( $orders ) )->handle( $this->refund() );

		self::assertInstanceOf( UpdateOrderData::class, $captured );
		self::assertSame( 'refunded', $captured->status );
	}

	public function test_is_noop_when_order_not_found(): void {
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->andReturn( null );
		$orders->shouldNotReceive( 'update' );

		( new MarkOrderRefundedOnRefund( $orders ) )->handle( $this->refund() );

		self::assertTrue( true );
	}

	public function test_is_noop_when_already_refunded(): void {
		$order  = $this->order( 'refunded' );
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->andReturn( $order );
		$orders->shouldNotReceive( 'update' );

		( new MarkOrderRefundedOnRefund( $orders ) )->handle( $this->refund() );

		self::assertSame( 'refunded', $order->getStatus() );
	}

	public function test_stamps_credit_note_order_id_as_order_meta(): void {
		$order  = $this->order( 'success' );
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'order_1' )->andReturn( $order );
		$orders->shouldReceive( 'update' )->once()->andReturn( $order );

		Functions\expect( 'update_pmpro_membership_order_meta' )
			->once()
			->with( 5, MarkOrderRefundedOnRefund::ORDER_META_CREDIT_NOTE_ORDER_ID, 'credit_order_1' );

		( new MarkOrderRefundedOnRefund( $orders ) )->handle( $this->refund( 'credit_order_1' ) );

		$this->assertHookExpectations();
	}

	public function test_does_not_stamp_credit_note_when_event_has_no_credit_order(): void {
		$order  = $this->order( 'success' );
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'order_1' )->andReturn( $order );
		$orders->shouldReceive( 'update' )->once()->andReturn( $order );

		Functions\expect( 'update_pmpro_membership_order_meta' )->never();

		( new MarkOrderRefundedOnRefund( $orders ) )->handle( $this->refund() );

		$this->assertHookExpectations();
	}

	private function refund( ?string $creditOrderId = null ): RefundCompleted {
		return new RefundCompleted(
			customerId: 'cus_1',
			refundId: 'refund_1',
			status: 'refunded',
			total: new Money( 'EUR', '23.00' ),
			subtotal: new Money( 'EUR', '19.00' ),
			taxSummary: new TaxSummaryCollection( [] ),
			originalOrderId: 'order_1',
			testmode: true,
			orderId: $creditOrderId,
		);
	}

	private function order( string $status ): OrderModel {
		return new OrderModel(
			id: 5,
			userId: 7,
			membershipLevelId: 1,
			code: 'C',
			vatlyId: 'order_1',
			status: $status,
			total: 2300,
			subtotal: 1900,
			tax: 400,
			currency: 'EUR',
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
			subscriptionTransactionId: null,
		);
	}
}
