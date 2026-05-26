<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Events\OrderPaid;
use Vatly\Fluent\Events\SubscriptionStarted;
use Vatly\Fluent\Types\TaxSummary;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Repositories\SubscriptionModel;
use Vatly\PMPro\Repositories\UserBillable;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\WriteOrderRowOnPaid;
use WP_User;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\WriteOrderRowOnPaid
 */
class WriteOrderRowOnPaidTest extends TestCase {

	public function test_supports_only_order_paid(): void {
		$reaction = new WriteOrderRowOnPaid(
			\Mockery::mock(CustomerRepositoryInterface::class),
			\Mockery::mock(OrderRepositoryInterface::class),
			\Mockery::mock(SubscriptionRepositoryInterface::class),
		);

		self::assertTrue($reaction->supports($this->orderPaid()));
		self::assertFalse($reaction->supports(new SubscriptionStarted(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			planId: 'plan',
			type: 'default',
			name: 'N',
			quantity: 1,
		)));
	}

	public function test_stamps_subscription_transaction_id_onto_existing_order(): void {
		$user     = new WP_User();
		$user->ID = 7;

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->with('cus_1')->andReturn(new UserBillable($user));

		$orders = \Mockery::mock(OrderRepositoryInterface::class);
		$order  = new OrderModel(
			id: 11,
			userId: 7,
			membershipLevelId: 1,
			code: 'A',
			vatlyId: 'ord_1',
			status: 'success',
			total: 100,
			subtotal: 100,
			tax: 0,
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
			subscriptionTransactionId: null,
			owner: new UserBillable($user),
		);
		$orders->shouldReceive('findByVatlyId')->with('ord_1')->andReturn($order);

		$subs         = \Mockery::mock(SubscriptionRepositoryInterface::class);
		$subscription = new SubscriptionModel(
			id: 22,
			userId: 7,
			membershipLevelId: 1,
			vatlyId: 'sub_xyz',
			planId: 'plan_x',
			name: 'X',
			quantity: 1,
			status: 'active',
			endsAt: null,
			owner: new UserBillable($user),
		);
		$subs->shouldReceive('findByOwnerAndType')->andReturn($subscription);

		(new WriteOrderRowOnPaid($customers, $orders, $subs))->handle($this->orderPaid());

		global $wpdb;
		self::assertNotEmpty($wpdb->queries);
		$update = $wpdb->queries[0];
		self::assertSame('update', $update[0]);
		[, $data, $where] = $update[1];
		self::assertSame('sub_xyz', $data['subscription_transaction_id']);
		self::assertSame('vatly', $where['gateway']);
		self::assertSame('ord_1', $where['payment_transaction_id']);
	}

	public function test_noop_when_order_not_yet_persisted(): void {
		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$orders    = \Mockery::mock(OrderRepositoryInterface::class);
		$orders->shouldReceive('findByVatlyId')->andReturn(null);
		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);

		(new WriteOrderRowOnPaid($customers, $orders, $subs))->handle($this->orderPaid());

		global $wpdb;
		self::assertSame([], $wpdb->queries);
	}

	public function test_noop_when_no_active_subscription(): void {
		$user     = new WP_User();
		$user->ID = 7;

		$customers = \Mockery::mock(CustomerRepositoryInterface::class);
		$customers->shouldReceive('findByVatlyId')->andReturn(new UserBillable($user));

		$orders = \Mockery::mock(OrderRepositoryInterface::class);
		$orders->shouldReceive('findByVatlyId')->andReturn(new OrderModel(
			id: 1, userId: 7, membershipLevelId: 0, code: 'A', vatlyId: 'ord_1',
			status: 'success', total: 0, subtotal: 0, tax: 0, currency: 'EUR',
			invoiceNumber: null, paymentMethod: null, subscriptionTransactionId: null,
			owner: new UserBillable($user),
		));

		$subs = \Mockery::mock(SubscriptionRepositoryInterface::class);
		$subs->shouldReceive('findByOwnerAndType')->andReturn(null);

		(new WriteOrderRowOnPaid($customers, $orders, $subs))->handle($this->orderPaid());

		global $wpdb;
		self::assertSame([], $wpdb->queries);
	}

	private function orderPaid(): OrderPaid {
		return new OrderPaid(
			customerId: 'cus_1',
			orderId: 'ord_1',
			total: 100,
			subtotal: 100,
			taxSummary: TaxSummary::empty(),
			currency: 'EUR',
			invoiceNumber: null,
			paymentMethod: null,
		);
	}
}
