<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\API\Types\Money;
use Vatly\API\Webhooks\Events\SubscriptionStarted;
use Vatly\API\Webhooks\Events\SubscriptionUpdated;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\ChangeMembershipLevelOnUpdated;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\ChangeMembershipLevelOnUpdated
 */
class ChangeMembershipLevelOnUpdatedTest extends TestCase {

	public function test_supports_only_subscription_updated_events(): void {
		$reaction = new ChangeMembershipLevelOnUpdated( \Mockery::mock( CustomerBindingRepository::class ) );

		self::assertTrue( $reaction->supports( $this->event() ) );

		// The scheduled variant and the started event must NOT be handled here.
		self::assertFalse( $reaction->supports( new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
			testmode: true,
		) ) );
	}

	public function test_handle_changes_to_mapped_level_when_user_and_plan_resolve(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		// New plan is mapped to level 9.
		global $wpdb;
		$wpdb->next_var = 9;

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 9, 7, 'changed' )
			->andReturn( true );

		( new ChangeMembershipLevelOnUpdated( $bindings ) )->handle( $this->event( 'plan_pro' ) );

		$this->assertHookExpectations();
	}

	public function test_handle_noop_when_customer_not_bound(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( null );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new ChangeMembershipLevelOnUpdated( $bindings ) )->handle( $this->event() );

		$this->assertHookExpectations();
	}

	public function test_handle_noop_when_plan_not_mapped(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		// New plan resolves to no level.
		global $wpdb;
		$wpdb->next_var = '0';

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new ChangeMembershipLevelOnUpdated( $bindings ) )->handle( $this->event( 'plan_unmapped' ) );

		$this->assertHookExpectations();
	}

	private function event( string $plan_id = 'plan_x' ): SubscriptionUpdated {
		return new SubscriptionUpdated(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: $plan_id,
			name: 'Pro',
			description: 'Pro plan',
			basePrice: new Money( 'EUR', '29.00' ),
			quantity: 1,
			interval: 'month',
			intervalCount: 1,
			testmode: true,
		);
	}
}
