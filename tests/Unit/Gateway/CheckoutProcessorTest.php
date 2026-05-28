<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use MemberOrder;
use Vatly\API\Resources\Checkout as ApiCheckout;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\CancelSubscription;
use Vatly\Fluent\Actions\GetCheckout;
use Vatly\PMPro\Container;
use Vatly\PMPro\Gateway\CheckoutProcessor;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Gateway\CheckoutProcessor
 */
class CheckoutProcessorTest extends TestCase {

	public function test_finalize_returns_true_when_checkout_is_paid(): void {
		$container = new Container();

		$checkout         = new ApiCheckout(\Mockery::mock(VatlyApiClient::class));
		$checkout->id     = 'chk_1';
		$checkout->status = 'paid';
		$checkout->orderId = 'ord_1';

		$action = \Mockery::mock(GetCheckout::class);
		$action->shouldReceive('execute')->with('chk_1')->andReturn($checkout);
		$container->set(GetCheckout::class, static fn() => $action);

		// Pre-populate order meta lookup with the checkout id.
		Functions\when('get_pmpro_membership_order_meta')->justReturn('chk_1');
		Functions\when('update_pmpro_membership_order_meta')->justReturn(true);

		$order            = new MemberOrder();
		$order->id        = 9;
		$order->status    = 'token';
		$order->user_id   = 7;

		$result = CheckoutProcessor::process($container, $order);

		self::assertTrue($result);
		self::assertSame('success', $order->status);
		self::assertSame('ord_1', $order->payment_transaction_id);
	}

	public function test_finalize_returns_false_when_checkout_canceled(): void {
		$container = new Container();

		$checkout         = new ApiCheckout(\Mockery::mock(VatlyApiClient::class));
		$checkout->id     = 'chk_1';
		$checkout->status = 'canceled';

		$action = \Mockery::mock(GetCheckout::class);
		$action->shouldReceive('execute')->andReturn($checkout);
		$container->set(GetCheckout::class, static fn() => $action);

		Functions\when('get_pmpro_membership_order_meta')->justReturn('chk_1');
		Functions\when('update_pmpro_membership_order_meta')->justReturn(true);

		$order            = new MemberOrder();
		$order->id        = 9;
		$order->status    = 'token';

		$result = CheckoutProcessor::process($container, $order);

		self::assertFalse($result);
		self::assertSame('error', $order->status);
	}

	public function test_finalize_returns_false_when_status_is_still_pending(): void {
		$container = new Container();

		$checkout         = new ApiCheckout(\Mockery::mock(VatlyApiClient::class));
		$checkout->id     = 'chk_1';
		$checkout->status = 'open';

		$action = \Mockery::mock(GetCheckout::class);
		$action->shouldReceive('execute')->andReturn($checkout);
		$container->set(GetCheckout::class, static fn() => $action);

		Functions\when('get_pmpro_membership_order_meta')->justReturn('chk_1');
		Functions\when('update_pmpro_membership_order_meta')->justReturn(true);

		$order         = new MemberOrder();
		$order->id     = 9;
		$order->status = 'token';

		// CRITICAL: must return false so PMPro doesn't call
		// pmpro_complete_checkout() and assign the level before Vatly has
		// confirmed payment. See `pmpro_processed` check in PMPro's
		// preheaders/checkout.php — any truthy return triggers level
		// assignment, regardless of order status.
		self::assertFalse(CheckoutProcessor::process($container, $order));
		self::assertSame('token', $order->status);
		self::assertNotEmpty($order->error, 'Expected a friendly pending message to be set.');
	}

	public function test_finalize_returns_false_when_api_throws(): void {
		$container = new Container();

		$action = \Mockery::mock(GetCheckout::class);
		$action->shouldReceive('execute')->andThrow(new \RuntimeException('timeout'));
		$container->set(GetCheckout::class, static fn() => $action);

		Functions\when('get_pmpro_membership_order_meta')->justReturn('chk_1');
		Functions\when('update_pmpro_membership_order_meta')->justReturn(true);

		$order         = new MemberOrder();
		$order->id     = 9;
		$order->status = 'token';

		self::assertFalse(CheckoutProcessor::process($container, $order));
		self::assertNotEmpty($order->error);
	}

	public function test_cancel_calls_cancel_subscription_action(): void {
		$container = new Container();

		$action = \Mockery::mock(CancelSubscription::class);
		$action->shouldReceive('execute')->once()->with('sub_xyz');
		$container->set(CancelSubscription::class, static fn() => $action);

		$order                              = new MemberOrder();
		$order->subscription_transaction_id = 'sub_xyz';

		self::assertTrue(CheckoutProcessor::cancel($container, $order));
	}

	public function test_cancel_is_noop_when_no_transaction_id(): void {
		$container = new Container();

		$action = \Mockery::mock(CancelSubscription::class);
		$action->shouldNotReceive('execute');
		$container->set(CancelSubscription::class, static fn() => $action);

		$order = new MemberOrder();

		self::assertTrue(CheckoutProcessor::cancel($container, $order));
	}

	public function test_cancel_subscription_translates_pmpro_subscription(): void {
		$container = new Container();

		$action = \Mockery::mock(CancelSubscription::class);
		$action->shouldReceive('execute')->once()->with('sub_xyz');
		$container->set(CancelSubscription::class, static fn() => $action);

		$pmpro_sub = new class {
			public function get_subscription_transaction_id(): string {
				return 'sub_xyz';
			}
		};

		self::assertTrue(CheckoutProcessor::cancel_subscription($container, $pmpro_sub));
	}
}
