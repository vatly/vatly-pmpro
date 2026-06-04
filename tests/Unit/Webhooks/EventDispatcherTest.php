<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks;

use Brain\Monkey\Actions;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\EventDispatcher;

/**
 * @covers \Vatly\PMPro\Webhooks\EventDispatcher
 */
class EventDispatcherTest extends TestCase {

	public function test_dispatches_basename_hook_and_catch_all(): void {
		$event = new OrderPaid(
			customerId: 'cus_1',
			orderId: 'ord_1',
			status: 'paid',
			total: new Money( 'EUR', '10.00' ),
			subtotal: new Money( 'EUR', '8.00' ),
			taxSummary: new TaxSummaryCollection( [] ),
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
			testmode: true,
		);

		Actions\expectDone('vatly_pmpro_order_paid')->once()->with($event);
		Actions\expectDone('vatly_pmpro_event')->once()->with($event);

		(new EventDispatcher())->dispatch($event);

		$this->assertHookExpectations();
	}
}
