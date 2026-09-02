<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use Vatly\API\Webhooks\Events\SubscriptionCanceledForNonpayment;
use Vatly\API\Webhooks\Events\SubscriptionCanceledImmediately;
use Vatly\API\Webhooks\Events\SubscriptionCanceledWithGracePeriod;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\RevokeMembershipLevelOnCanceled;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\RevokeMembershipLevelOnCanceled
 */
class RevokeMembershipLevelOnCanceledTest extends TestCase {

	public function test_supports_both_cancel_event_variants(): void {
		$reaction = new RevokeMembershipLevelOnCanceled( \Mockery::mock( CustomerBindingRepository::class ) );

		self::assertTrue( $reaction->supports( new SubscriptionCanceledImmediately(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
			testmode: true,
		) ) );

		self::assertTrue( $reaction->supports( new SubscriptionCanceledWithGracePeriod(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
			testmode: true,
		) ) );

		self::assertTrue( $reaction->supports( new SubscriptionCanceledForNonpayment(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
			testmode: true,
		) ) );
	}

	public function test_immediate_cancel_revokes_membership(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 0, 7, 'cancelled' );

		( new RevokeMembershipLevelOnCanceled( $bindings ) )->handle( new SubscriptionCanceledImmediately(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
			testmode: true,
		) );

		$this->assertHookExpectations();
	}

	public function test_nonpayment_cancel_revokes_membership_immediately(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 0, 7, 'cancelled' );

		( new RevokeMembershipLevelOnCanceled( $bindings ) )->handle( new SubscriptionCanceledForNonpayment(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
			testmode: true,
		) );

		$this->assertHookExpectations();
	}

	public function test_grace_period_cancel_does_not_revoke_immediately(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		$this->assertHookExpectations();
		( new RevokeMembershipLevelOnCanceled( $bindings ) )->handle( new SubscriptionCanceledWithGracePeriod(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable( '+10 days' ),
			testmode: true,
		) );
	}

	public function test_unbound_customer_is_noop(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( null );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		$this->assertHookExpectations();
		( new RevokeMembershipLevelOnCanceled( $bindings ) )->handle( new SubscriptionCanceledImmediately(
			customerId: 'cus_orphan',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
			testmode: true,
		) );
	}
}
