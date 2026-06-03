<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks;

use Brain\Monkey\Actions;
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
			total: 1000,
			subtotal: 800,
			taxSummary: new TaxSummaryCollection( [] ),
			currency: 'EUR',
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
		);

		Actions\expectDone('vatly_pmpro_OrderPaid')->once()->with($event);
		Actions\expectDone('vatly_pmpro_event')->once()->with($event);

		(new EventDispatcher())->dispatch($event);

		$this->assertHookExpectations();
	}
}
