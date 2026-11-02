<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Brain\Monkey\Functions;
use Vatly\API\Resources\Links\OrderLinks;
use Vatly\API\Resources\Order as ApiOrder;
use Vatly\API\Types\Link;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\VatlyApiClient;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\API\Webhooks\Events\SubscriptionResumed;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\StoreInvoiceLinkOnPaid;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\StoreInvoiceLinkOnPaid
 */
class StoreInvoiceLinkOnPaidTest extends TestCase {

	public function test_supports_only_order_paid(): void {
		$reaction = new StoreInvoiceLinkOnPaid(
			\Mockery::mock( OrderRepositoryInterface::class ),
			\Mockery::mock( GetOrder::class ),
		);

		self::assertTrue( $reaction->supports( $this->orderPaid() ) );
		self::assertFalse( $reaction->supports( new SubscriptionResumed(
			customerId: 'cus_1',
			subscriptionId: 'sub_1',
			testmode: true,
		) ) );
	}

	public function test_stamps_vatly_invoice_url_as_order_meta(): void {
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'order_1' )->andReturn( $this->order() );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldReceive( 'execute' )->with( 'order_1' )
			->andReturn( $this->apiOrder( 'order_1', 'https://vatly.test/invoice/order_1' ) );

		Functions\expect( 'update_pmpro_membership_order_meta' )
			->once()
			->with( 5, StoreInvoiceLinkOnPaid::ORDER_META_INVOICE_URL, 'https://vatly.test/invoice/order_1' );

		( new StoreInvoiceLinkOnPaid( $orders, $getOrder ) )->handle( $this->orderPaid() );

		$this->assertHookExpectations();
	}

	public function test_is_noop_when_order_has_no_invoice_link(): void {
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->with( 'order_1' )->andReturn( $this->order() );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldReceive( 'execute' )->with( 'order_1' )
			->andReturn( $this->apiOrder( 'order_1', null ) );

		Functions\expect( 'update_pmpro_membership_order_meta' )->never();

		( new StoreInvoiceLinkOnPaid( $orders, $getOrder ) )->handle( $this->orderPaid() );

		$this->assertHookExpectations();
	}

	public function test_is_noop_when_order_row_not_found(): void {
		$orders = \Mockery::mock( OrderRepositoryInterface::class );
		$orders->shouldReceive( 'findByVatlyId' )->andReturn( null );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldNotReceive( 'execute' );

		Functions\expect( 'update_pmpro_membership_order_meta' )->never();

		( new StoreInvoiceLinkOnPaid( $orders, $getOrder ) )->handle( $this->orderPaid() );

		$this->assertHookExpectations();
	}

	private function orderPaid(): OrderPaid {
		return new OrderPaid(
			customerId: 'cus_1',
			orderId: 'order_1',
			status: 'paid',
			total: new Money( 'EUR', '23.00' ),
			subtotal: new Money( 'EUR', '19.00' ),
			taxSummary: new TaxSummaryCollection( [] ),
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
			testmode: true,
		);
	}

	private function order(): OrderModel {
		return new OrderModel(
			id: 5,
			userId: 7,
			membershipLevelId: 1,
			code: 'C',
			vatlyId: 'order_1',
			status: 'success',
			total: 2300,
			subtotal: 1900,
			tax: 400,
			currency: 'EUR',
			invoiceNumber: 'INV-1',
			paymentMethod: 'creditcard',
			subscriptionTransactionId: null,
		);
	}

	private function apiOrder( string $id, ?string $invoiceHref ): ApiOrder {
		$order     = new ApiOrder( \Mockery::mock( VatlyApiClient::class ) );
		$order->id = $id;

		$links                  = new OrderLinks();
		$links->customerInvoice = $invoiceHref === null ? null : new Link( $invoiceHref, 'text/html' );
		$order->links           = $links;

		return $order;
	}
}
