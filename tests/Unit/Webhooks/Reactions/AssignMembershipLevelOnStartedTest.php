<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Events\OrderPaid;
use Vatly\Fluent\Events\SubscriptionStarted;
use Vatly\Fluent\Types\TaxSummary;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted
 */
class AssignMembershipLevelOnStartedTest extends TestCase {

	public function test_supports_only_subscription_started_events(): void {
		$reaction = new AssignMembershipLevelOnStarted( \Mockery::mock( CustomerBindingRepository::class ) );

		self::assertTrue( $reaction->supports( new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		) ) );

		self::assertFalse( $reaction->supports( new OrderPaid(
			customerId: 'cus_1',
			orderId: 'ord_1',
			status: 'paid',
			total: 100,
			subtotal: 100,
			taxSummary: TaxSummary::empty(),
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
		) ) );
	}

	public function test_handle_assigns_mapped_level_when_user_and_plan_resolve(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );

		// Plan is mapped to level 5.
		global $wpdb;
		$wpdb->next_var = 5;

		Functions\expect( 'pmpro_changeMembershipLevel' )
			->once()
			->with( 5, 7, 'changed' )
			->andReturn( true );

		( new AssignMembershipLevelOnStarted( $bindings ) )->handle( new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		) );

		$this->assertHookExpectations();
	}

	public function test_handle_noop_when_customer_not_bound(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( null );

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new AssignMembershipLevelOnStarted( $bindings ) )->handle( new SubscriptionStarted(
			customerId: 'cus_orphan',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		) );

		$this->assertHookExpectations();
	}

	public function test_handle_noop_when_plan_not_mapped(): void {
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$bindings->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		// Plan resolves to no level.
		global $wpdb;
		$wpdb->next_var = '0';

		Functions\expect( 'pmpro_changeMembershipLevel' )->never();

		( new AssignMembershipLevelOnStarted( $bindings ) )->handle( new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_unmapped',
			type: 'default',
			name: 'X',
			quantity: 1,
		) );

		$this->assertHookExpectations();
	}
}
