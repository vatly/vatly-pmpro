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
		$model = $this->make( [
			'status' => 'active',
			'endsAt' => null,
		] );

		self::assertTrue( $model->isActive() );
		self::assertFalse( $model->isCancelled() );
		self::assertFalse( $model->isOnGracePeriod() );
		self::assertTrue( $model->isValid() );
		self::assertTrue( $model->isRecurring() );
		self::assertFalse( $model->isEnded() );
	}

	public function test_subscription_with_future_endsAt_is_on_grace_period_and_active(): void {
		$model = $this->make( [
			'status' => 'cancelled',
			'endsAt' => new DateTimeImmutable( '+10 days' ),
		] );

		self::assertTrue( $model->isCancelled() );
		self::assertTrue( $model->isOnGracePeriod() );
		self::assertTrue( $model->isActive() );
		self::assertFalse( $model->isRecurring() );
		self::assertFalse( $model->isEnded() );
	}

	public function test_subscription_with_past_endsAt_is_cancelled_and_inactive(): void {
		$model = $this->make( [
			'status' => 'cancelled',
			'endsAt' => new DateTimeImmutable( '-1 day' ),
		] );

		self::assertTrue( $model->isCancelled() );
		self::assertFalse( $model->isOnGracePeriod() );
		self::assertFalse( $model->isActive() );
		self::assertTrue( $model->isEnded() );
	}

	public function test_endsAt_null_is_recurring_and_not_ended(): void {
		$model = $this->make( [
			'status' => 'active',
			'endsAt' => null,
		] );

		self::assertTrue( $model->isRecurring() );
		self::assertFalse( $model->isEnded() );
	}

	public function test_mandate_getters_default_to_null(): void {
		$model = $this->make( [ 'status' => 'active', 'endsAt' => null ] );

		self::assertNull( $model->getMandateMethod() );
		self::assertNull( $model->getMandateMaskedIdentifier() );
	}

	public function test_mandate_getters_return_persisted_values(): void {
		$model = $this->make( [
			'status'                  => 'active',
			'endsAt'                  => null,
			'mandateMethod'           => 'card',
			'mandateMaskedIdentifier' => '4242',
		] );

		self::assertSame( 'card', $model->getMandateMethod() );
		self::assertSame( '4242', $model->getMandateMaskedIdentifier() );
	}

	public function test_is_testmode_reflects_the_constructor_flag(): void {
		self::assertTrue( $this->make( [ 'testmode' => true ] )->isTestmode() );
		self::assertFalse( $this->make( [ 'testmode' => false ] )->isTestmode() );
	}

	private function make( array $overrides ): SubscriptionModel {
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
			mandateMethod: $overrides['mandateMethod'] ?? null,
			mandateMaskedIdentifier: $overrides['mandateMaskedIdentifier'] ?? null,
			testmode: $overrides['testmode'] ?? false,
		);
	}
}
