<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use Vatly\API\Resources\Links\OrderLinks;
use Vatly\API\Resources\Order as ApiOrder;
use Vatly\API\Types\Link;
use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\PMPro\Gateway\InvoiceSurface;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Gateway\InvoiceSurface
 */
class InvoiceSurfaceTest extends TestCase {

	public function test_no_link_and_no_error_when_the_api_client_cannot_be_built(): void {
		$surface = new InvoiceSurface(
			static function (): GetOrder {
				throw new \Vatly\API\Exceptions\ApiException( "Invalid API key: ''." );
			}
		);

		self::assertSame( 'Thanks!', $surface->append_to_confirmation( 'Thanks!', $this->memberOrder() ) );
	}

	public function test_confirmation_is_unchanged_for_non_vatly_orders(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( '' );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldNotReceive( 'execute' );

		$surface = new InvoiceSurface( static fn() => $getOrder );
		$order   = $this->memberOrder( gateway: 'stripe' );

		self::assertSame( 'Thanks!', $surface->append_to_confirmation( 'Thanks!', $order ) );
	}

	public function test_confirmation_appends_fresh_invoice_link_for_vatly_order(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( '' );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldReceive( 'execute' )->with( 'order_1' )
			->andReturn( $this->apiOrder( 'order_1', 'https://vatly.test/invoice/order_1' ) );

		$surface = new InvoiceSurface( static fn() => $getOrder );
		$result  = $surface->append_to_confirmation( 'Thanks!', $this->memberOrder() );

		self::assertStringContainsString( 'https://vatly.test/invoice/order_1', $result );
		self::assertStringContainsString( 'Download your invoice (from Vatly)', $result );
	}

	public function test_confirmation_omits_link_when_order_has_no_invoice(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( '' );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldReceive( 'execute' )->with( 'order_1' )
			->andReturn( $this->apiOrder( 'order_1', null ) );

		$surface = new InvoiceSurface( static fn() => $getOrder );

		self::assertSame( 'Thanks!', $surface->append_to_confirmation( 'Thanks!', $this->memberOrder() ) );
	}

	public function test_confirmation_appends_credit_note_link_when_stamped(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		// Original order has no invoice link; the stamped credit-note order does.
		Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( 'credit_order_1' );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldReceive( 'execute' )->with( 'order_1' )
			->andReturn( $this->apiOrder( 'order_1', null ) );
		$getOrder->shouldReceive( 'execute' )->with( 'credit_order_1' )
			->andReturn( $this->apiOrder( 'credit_order_1', 'https://vatly.test/credit/credit_order_1' ) );

		$surface = new InvoiceSurface( static fn() => $getOrder );
		$result  = $surface->append_to_confirmation( 'Thanks!', $this->memberOrder() );

		self::assertStringContainsString( 'https://vatly.test/credit/credit_order_1', $result );
		self::assertStringContainsString( 'Download your credit note (from Vatly)', $result );
	}

	public function test_resolved_link_is_cached_to_avoid_a_second_api_call(): void {
		$stored = null;
		Functions\when( 'get_transient' )->alias(
			static function () use ( &$stored ) {
				return $stored ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$stored ): bool {
				$stored = $value;

				return true;
			}
		);
		Functions\when( 'get_pmpro_membership_order_meta' )->justReturn( '' );

		$getOrder = \Mockery::mock( GetOrder::class );
		$getOrder->shouldReceive( 'execute' )->with( 'order_1' )->once()
			->andReturn( $this->apiOrder( 'order_1', 'https://vatly.test/invoice/order_1' ) );

		$surface = new InvoiceSurface( static fn() => $getOrder );
		$surface->append_to_confirmation( 'Thanks!', $this->memberOrder() );
		$second = $surface->append_to_confirmation( 'Thanks!', $this->memberOrder() );

		// execute() asserted ->once(): the second render must come from cache.
		self::assertStringContainsString( 'https://vatly.test/invoice/order_1', $second );
	}

	private function memberOrder( string $gateway = 'vatly' ): object {
		$order                         = new \stdClass();
		$order->id                     = 5;
		$order->gateway                = $gateway;
		$order->payment_transaction_id = 'order_1';

		return $order;
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
