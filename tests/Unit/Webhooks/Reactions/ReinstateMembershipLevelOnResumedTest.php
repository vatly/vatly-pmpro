<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Events\SubscriptionResumed;
use Vatly\Fluent\Events\SubscriptionStarted;
use Vatly\PMPro\Repositories\SubscriptionModel;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\ReinstateMembershipLevelOnResumed;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\ReinstateMembershipLevelOnResumed
 */
class ReinstateMembershipLevelOnResumedTest extends TestCase {

	public function test_supports_only_subscription_resumed_events(): void {
		$reaction = new ReinstateMembershipLevelOnResumed(
			\Mockery::mock( CustomerBindingRepository::class ),
			\Mockery::mock( SubscriptionRepositoryInterface::class ),
		);

		self::assertTrue( $reaction->supports( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
		) ) );

		self::assertFalse( $reaction->supports( new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		) ) );
	}

	public function test_reinstates_mapped_level_when_user_and_plan_resolve(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		$subscriptions = \Mockery::mock( SubscriptionRepositoryInterface::class );
		$subscriptions->shouldReceive( 'findByVatlyId' )->with( 'sub_1' )
			->andReturn( $this->subscription( 'plan_x' ) );

		// Plan 'plan_x' is mapped to level 5.
		global $wpdb;
		$wpdb->next_var = 5;

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 5, 7, 'changed' )
			->andReturn( true );

		( new ReinstateMembershipLevelOnResumed( $bindings, $subscriptions ) )->handle( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
		) );

		$this->assertHookExpectations();
	}

	public function test_noop_when_customer_not_bound(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( null );

		$subscriptions = \Mockery::mock( SubscriptionRepositoryInterface::class );
		$subscriptions->shouldNotReceive( 'findByVatlyId' );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new ReinstateMembershipLevelOnResumed( $bindings, $subscriptions ) )->handle( new SubscriptionResumed(
			customerId: 'cus_orphan',
			subscriptionId: 'sub_1',
		) );

		$this->assertHookExpectations();
	}

	public function test_noop_when_subscription_untracked(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		$subscriptions = \Mockery::mock( SubscriptionRepositoryInterface::class );
		$subscriptions->shouldReceive( 'findByVatlyId' )->with( 'sub_1' )->andReturn( null );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new ReinstateMembershipLevelOnResumed( $bindings, $subscriptions ) )->handle( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
		) );

		$this->assertHookExpectations();
	}

	public function test_noop_when_plan_not_mapped(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		$subscriptions = \Mockery::mock( SubscriptionRepositoryInterface::class );
		$subscriptions->shouldReceive( 'findByVatlyId' )->andReturn( $this->subscription( 'plan_unmapped' ) );

		// Plan resolves to no level.
		global $wpdb;
		$wpdb->next_var = '0';

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new ReinstateMembershipLevelOnResumed( $bindings, $subscriptions ) )->handle( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
		) );

		$this->assertHookExpectations();
	}

	private function subscription( string $planId ): SubscriptionModel {
		return new SubscriptionModel(
			id: 1,
			userId: 7,
			membershipLevelId: 5,
			vatlyId: 'sub_1',
			planId: $planId,
			name: 'Pro',
			quantity: 1,
			status: 'cancelled',
			endsAt: null,
		);
	}
}
