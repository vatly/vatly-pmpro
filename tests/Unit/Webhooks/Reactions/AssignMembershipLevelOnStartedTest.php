<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Events\OrderPaid;
use Vatly\Fluent\Events\SubscriptionStarted;
use Vatly\Fluent\Types\TaxSummary;
use Vatly\PMPro\Repositories\UserBillable;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted;
use WP_User;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted
 */
class AssignMembershipLevelOnStartedTest extends TestCase {

	public function test_supports_only_subscription_started_events(): void {
		$reaction = $this->makeReaction();

		self::assertTrue($reaction->supports(new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		)));

		self::assertFalse($reaction->supports(new OrderPaid(
			customerId: 'cus_1',
			orderId: 'ord_1',
			total: 100,
			subtotal: 100,
			taxSummary: TaxSummary::empty(),
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
		)));
	}

	public function test_handle_assigns_mapped_level_when_user_and_plan_resolve(): void {
		$user             = new WP_User();
		$user->ID         = 7;
		$user->user_email = 'pat@example.com';

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->with('cus_1')->andReturn(new UserBillable($user));

		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);

		// Plan is mapped to level 5.
		global $wpdb;
		$wpdb->next_var = 5;

		Functions\expect('pmpro_changeMembershipLevel')
			->once()
			->with(5, 7, 'changed')
			->andReturn(true);

		$this->assertHookExpectations();
		(new AssignMembershipLevelOnStarted($customers))->handle(new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		));
	}

	public function test_handle_noop_when_customer_not_linked(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(null);

		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);

		// Should not call pmpro_changeMembershipLevel — set a fail-on-call expectation.
		Functions\expect('pmpro_changeMembershipLevel')->never();

		$this->assertHookExpectations();
		(new AssignMembershipLevelOnStarted($customers))->handle(new SubscriptionStarted(
			customerId: 'cus_orphan',
			subscriptionId: 'sub_1',
			planId: 'plan_x',
			type: 'default',
			name: 'X',
			quantity: 1,
		));
	}

	public function test_handle_noop_when_plan_not_mapped(): void {
		$user     = new WP_User();
		$user->ID = 7;

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(new UserBillable($user));

		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);

		// Plan resolves to no level.
		global $wpdb;
		$wpdb->next_var = '0';

		Functions\expect('pmpro_changeMembershipLevel')->never();

		$this->assertHookExpectations();
		(new AssignMembershipLevelOnStarted($customers))->handle(new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan_unmapped',
			type: 'default',
			name: 'X',
			quantity: 1,
		));
	}

	private function makeReaction(): AssignMembershipLevelOnStarted {
		return new AssignMembershipLevelOnStarted(
			\Mockery::mock(CustomerRepositoryInterface::class),
			\Mockery::mock(SubscriptionRepositoryInterface::class),
		);
	}
}
