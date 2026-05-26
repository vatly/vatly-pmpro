<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Events\SubscriptionCanceledImmediately;
use Vatly\Fluent\Events\SubscriptionCanceledWithGracePeriod;
use Vatly\PMPro\Repositories\UserBillable;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\RevokeMembershipLevelOnCanceled;
use WP_User;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\RevokeMembershipLevelOnCanceled
 */
class RevokeMembershipLevelOnCanceledTest extends TestCase {

	public function test_supports_both_cancel_event_variants(): void {
		$reaction = $this->makeReaction();

		self::assertTrue($reaction->supports(new SubscriptionCanceledImmediately(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
		)));

		self::assertTrue($reaction->supports(new SubscriptionCanceledWithGracePeriod(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
		)));
	}

	public function test_immediate_cancel_revokes_membership(): void {
		$user     = new WP_User();
		$user->ID = 7;

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(new UserBillable($user));

		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);

		Functions\expect('pmpro_changeMembershipLevel')
			->once()
			->with(0, 7, 'cancelled');

		$this->assertHookExpectations();
		(new RevokeMembershipLevelOnCanceled($customers))->handle(new SubscriptionCanceledImmediately(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
		));
	}

	public function test_grace_period_cancel_does_not_revoke_immediately(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$subs      = \Mockery::mock(SubscriptionRepositoryInterface::class);

		// pmpro_changeMembershipLevel must NOT be called. We use never().
		Functions\expect('pmpro_changeMembershipLevel')->never();

		$this->assertHookExpectations();
		(new RevokeMembershipLevelOnCanceled($customers))->handle(new SubscriptionCanceledWithGracePeriod(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable('+10 days'),
		));
	}

	public function test_unlinked_customer_is_noop(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(null);

		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);

		Functions\expect('pmpro_changeMembershipLevel')->never();

		$this->assertHookExpectations();
		(new RevokeMembershipLevelOnCanceled($customers))->handle(new SubscriptionCanceledImmediately(
			customerId: 'cus_orphan',
			subscriptionId: 'sub_1',
			endsAt: new DateTimeImmutable(),
		));
	}

	private function makeReaction(): RevokeMembershipLevelOnCanceled {
		return new RevokeMembershipLevelOnCanceled(
			\Mockery::mock(CustomerRepositoryInterface::class),
			\Mockery::mock(SubscriptionRepositoryInterface::class),
		);
	}
}
