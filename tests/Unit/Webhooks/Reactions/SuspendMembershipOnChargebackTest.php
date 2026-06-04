<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\API\Types\Money;
use Vatly\API\Webhooks\Events\OrderChargebackReceived;
use Vatly\API\Webhooks\Events\OrderChargebackReversed;
use Vatly\API\Webhooks\Events\SubscriptionResumed;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\SuspendMembershipOnChargeback;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\SuspendMembershipOnChargeback
 */
class SuspendMembershipOnChargebackTest extends TestCase {

	public function test_supports_only_chargeback_events(): void {
		$reaction = new SuspendMembershipOnChargeback(
			\Mockery::mock( CustomerBindingRepository::class ),
			\Mockery::mock( OrderRepositoryInterface::class ),
		);

		self::assertTrue( $reaction->supports( $this->received() ) );
		self::assertTrue( $reaction->supports( $this->reversed() ) );
		self::assertFalse( $reaction->supports( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			testmode: true,
		) ) );
	}

	public function test_received_revokes_all_levels_via_customer_binding(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $this->order( 7, 3 ) );

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 0, 7, 'chargeback' );

		( new SuspendMembershipOnChargeback( $bindings, $orders ) )->handle( $this->received() );

		$this->assertHookExpectations();
	}

	public function test_received_falls_back_to_order_user_when_unenriched(): void {
		// Sparse webhook payload: no customer id on the event.
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldNotReceive( 'hostCustomerIdFor' );

		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $this->order( 9, 3 ) );

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 0, 9, 'chargeback' );

		( new SuspendMembershipOnChargeback( $bindings, $orders ) )->handle( $this->received( '' ) );

		$this->assertHookExpectations();
	}

	public function test_received_is_noop_when_no_user_resolves(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( null );

		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( null );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new SuspendMembershipOnChargeback( $bindings, $orders ) )->handle( $this->received() );

		$this->assertHookExpectations();
	}

	public function test_reversed_reinstates_the_orders_granting_level(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		// Disputed order granted level 3.
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $this->order( 7, 3 ) );

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 3, 7, 'chargeback' );

		( new SuspendMembershipOnChargeback( $bindings, $orders ) )->handle( $this->reversed() );

		$this->assertHookExpectations();
	}

	public function test_reversed_is_noop_when_level_unresolved(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		// Order found but grants no level (membershipLevelId 0).
		$orders->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $this->order( 7, 0 ) );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new SuspendMembershipOnChargeback( $bindings, $orders ) )->handle( $this->reversed() );

		$this->assertHookExpectations();
	}

	private function received( string $customerId = 'cus_1' ): OrderChargebackReceived {
		return new OrderChargebackReceived(
			orderId: 'ord_1',
			chargebackId: 'cb_1',
			originalOrderId: 'ord_1',
			reason: 'fraudulent',
			customerId: $customerId,
			status: 'received',
			total: new Money( 'EUR', '10.00' ),
			subtotal: new Money( 'EUR', '8.26' ),
			currency: 'EUR',
			testmode: true,
		);
	}

	private function reversed( string $customerId = 'cus_1' ): OrderChargebackReversed {
		return new OrderChargebackReversed(
			orderId: 'ord_1',
			chargebackId: 'cb_1',
			originalOrderId: 'ord_1',
			reason: 'fraudulent',
			customerId: $customerId,
			status: 'reversed',
			total: new Money( 'EUR', '10.00' ),
			subtotal: new Money( 'EUR', '8.26' ),
			currency: 'EUR',
			testmode: true,
		);
	}

	private function order( int $userId, int $levelId ): OrderModel {
		return new OrderModel(
			id: 11,
			userId: $userId,
			membershipLevelId: $levelId,
			code: 'A',
			vatlyId: 'ord_1',
			status: 'success',
			total: 1000,
			subtotal: 826,
			tax: 174,
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
			subscriptionTransactionId: null,
		);
	}
}
