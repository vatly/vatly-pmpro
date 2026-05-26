<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Repositories;

use DateTimeImmutable;
use Vatly\PMPro\Repositories\SubscriptionModel;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Repositories\SubscriptionModel
 */
class SubscriptionModelTest extends TestCase {

	public function test_active_subscription_is_active_and_not_cancelled(): void {
		$model = $this->make([
			'status'  => 'active',
			'endsAt'  => null,
		]);

		self::assertTrue($model->isActive());
		self::assertFalse($model->isCancelled());
		self::assertFalse($model->isOnGracePeriod());
	}

	public function test_subscription_with_future_endsAt_is_on_grace_period_and_active(): void {
		$model = $this->make([
			'status' => 'cancelled',
			'endsAt' => (new DateTimeImmutable('+10 days')),
		]);

		self::assertTrue($model->isCancelled());
		self::assertTrue($model->isOnGracePeriod());
		self::assertTrue($model->isActive());
	}

	public function test_subscription_with_past_endsAt_is_cancelled_and_inactive(): void {
		$model = $this->make([
			'status' => 'cancelled',
			'endsAt' => (new DateTimeImmutable('-1 day')),
		]);

		self::assertTrue($model->isCancelled());
		self::assertFalse($model->isOnGracePeriod());
		self::assertFalse($model->isActive());
	}

	public function test_status_expired_is_cancelled_even_without_endsAt(): void {
		$model = $this->make([
			'status' => 'expired',
			'endsAt' => null,
		]);

		self::assertTrue($model->isCancelled());
	}

	public function test_get_owner_throws_when_owner_missing(): void {
		$model = $this->make([], withOwner: false);

		$this->expectException(\LogicException::class);
		$model->getOwner();
	}

	private function make(array $overrides, bool $withOwner = true): SubscriptionModel {
		$owner = $withOwner ? \Mockery::mock(\Vatly\Fluent\Contracts\BillableInterface::class) : null;

		return new SubscriptionModel(
			id: $overrides['id'] ?? 1,
			userId: $overrides['userId'] ?? 7,
			membershipLevelId: $overrides['membershipLevelId'] ?? 1,
			vatlyId: $overrides['vatlyId'] ?? 'sub_test',
			planId: $overrides['planId'] ?? 'plan_test',
			name: $overrides['name'] ?? 'Test',
			quantity: $overrides['quantity'] ?? 1,
			status: $overrides['status'] ?? 'active',
			endsAt: $overrides['endsAt'] ?? null,
			owner: $owner,
		);
	}
}
