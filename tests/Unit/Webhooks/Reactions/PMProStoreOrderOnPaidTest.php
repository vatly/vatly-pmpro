<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks\Reactions;

use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\API\Types\Money;
use Vatly\API\Types\TaxSummaryCollection;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\API\Webhooks\Events\SubscriptionStarted;
use Vatly\PMPro\Repositories\OrderModel;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\PMProStoreOrderOnPaid;

/**
 * @covers \Vatly\PMPro\Webhooks\Reactions\PMProStoreOrderOnPaid
 */
class PMProStoreOrderOnPaidTest extends TestCase {

	public function test_supports_only_order_paid(): void {
		$reaction = $this->makeReaction()['reaction'];

		self::assertTrue( $reaction->supports( $this->orderPaid() ) );
		self::assertFalse( $reaction->supports( new SubscriptionStarted(
			customerId: 'cus_1', subscriptionId: 'sub_1', planId: 'plan',
			type: 'default', name: 'N', quantity: 1,
			testmode: true,
		) ) );
	}

	public function test_existing_vatly_order_is_updated_not_reinserted(): void {
		$rig = $this->makeReaction();

		$existing = $this->existingOrder( 'ord_1' );
		$rig['orders']->shouldReceive( 'findByVatlyId' )->with( 'ord_1' )->andReturn( $existing );

		$rig['orders']->shouldReceive( 'update' )
			->once()
			->with( $existing, \Mockery::type( UpdateOrderData::class ) );

		$rig['orders']->shouldNotReceive( 'store' );
		$rig['getOrder']->shouldNotReceive( 'execute' );

		$rig['reaction']->handle( $this->orderPaid() );

		$this->assertHookExpectations();
	}

	public function test_precise_token_conversion_via_pmpro_order_code(): void {
		// The headline scenario: tab A (code TABA) and tab B (code TABB) both
		// created token orders; user pays for TABA's checkout; webhook arrives.
		// Without the metadata routing, the user+time heuristic would have
		// updated TABB. With it, TABA gets the conversion.
		$rig = $this->makeReaction();

		$converted = $this->existingOrder( 'ord_paidA' );

		// First findByVatlyId (replay check) → null. Subsequent calls (after
		// we stamp the token row) return the persisted order.
		$rig['orders']->shouldReceive( 'findByVatlyId' )
			->with( 'ord_paidA' )
			->andReturnValues( [ null, null, $converted ] );

		// Metadata identifies TABA as the originating PMPro order code.
		$rig['getOrder']->shouldReceive( 'execute' )
			->with( 'ord_paidA' )
			->andReturn( $this->apiOrder( 'ord_paidA', [ 'pmpro_order_code' => 'TABA' ] ) );

		// $wpdb's lookup returns the TABA token row.
		global $wpdb;
		$wpdb->next_row = (object) [
			'id'   => 77,
			'code' => 'TABA',
		];

		// And the post-stamp re-load triggers UpdateOrderData.
		$rig['orders']->shouldReceive( 'update' )
			->once()
			->with( $converted, \Mockery::type( UpdateOrderData::class ) );

		// store() — the INSERT path — must NOT be called.
		$rig['orders']->shouldNotReceive( 'store' );

		$rig['reaction']->handle( $this->orderPaid( 'ord_paidA' ) );

		// Verify the precise UPDATE landed on row 77 (TABA), not anyone else's.
		$ops = array_column( $wpdb->queries, 0 );
		self::assertContains( 'update', $ops );
		$upd_idx          = array_search( 'update', $ops, true );
		[, $set, $where]  = $wpdb->queries[ $upd_idx ][1];
		self::assertSame( 'ord_paidA', $set['payment_transaction_id'] );
		self::assertSame( [ 'id' => 77 ], $where );
	}

	public function test_renewal_falls_through_to_insert_when_no_metadata(): void {
		$rig = $this->makeReaction();

		$rig['orders']->shouldReceive( 'findByVatlyId' )->andReturn( null );

		$rig['getOrder']->shouldReceive( 'execute' )
			->andReturn( $this->apiOrder( 'ord_renewal', [] ) );

		$rig['bindings']->shouldReceive( 'hostCustomerIdFor' )->with( 'cus_1' )->andReturn( '7' );
		$rig['bindings']->shouldReceive( 'record' )->with( 'cus_1' );

		$rig['orders']->shouldReceive( 'store' )
			->once()
			->with( \Mockery::on( static function ( StoreOrderData $data ): bool {
				return $data->vatlyId === 'ord_renewal' && $data->hostCustomerId === '7';
			} ) );

		$rig['reaction']->handle( $this->orderPaid( 'ord_renewal' ) );

		$this->assertHookExpectations();
	}

	public function test_getOrder_failure_throws_when_user_has_pending_token_orders(): void {
		// The reviewer's concern: if `GetOrder` fails, we can't tell whether
		// this is an initial purchase (which would have a matching token row
		// the browser-return will later upgrade) or a renewal (where INSERT
		// is correct). When there's *any* unmatched token order for the
		// bound user, INSERT would risk a duplicate row — throw instead so
		// Vatly retries the webhook later (when GetOrder will likely succeed,
		// or when the browser-return has already converted the token order
		// and step 1 hits the replay-safe path).
		$rig = $this->makeReaction();

		$rig['orders']->shouldReceive( 'findByVatlyId' )->andReturn( null );
		$rig['getOrder']->shouldReceive( 'execute' )->andThrow( new \RuntimeException( 'API down' ) );
		$rig['bindings']->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		// At least one pending token order for this user.
		global $wpdb;
		$wpdb->next_var = 1;

		// store() must NOT be called.
		$rig['orders']->shouldNotReceive( 'store' );
		$rig['bindings']->shouldNotReceive( 'record' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Cannot route order\.paid .* safely/' );

		$rig['reaction']->handle( $this->orderPaid() );
	}

	public function test_getOrder_failure_inserts_when_user_has_no_pending_tokens(): void {
		// User has no token orders pending → this can only be a renewal →
		// safe to INSERT. (Vatly doesn't propagate checkout metadata onto
		// renewal orders, so GetOrder failure on a renewal is indistinguishable
		// from a renewal we couldn't enrich; both end up here.)
		$rig = $this->makeReaction();

		$rig['orders']->shouldReceive( 'findByVatlyId' )->andReturn( null );
		$rig['getOrder']->shouldReceive( 'execute' )->andThrow( new \RuntimeException( 'API down' ) );
		$rig['bindings']->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );

		// Zero pending token orders.
		global $wpdb;
		$wpdb->next_var = 0;

		$rig['bindings']->shouldReceive( 'record' )->once();
		$rig['orders']->shouldReceive( 'store' )->once();

		$rig['reaction']->handle( $this->orderPaid() );

		$this->assertHookExpectations();
	}

	public function test_getOrder_failure_inserts_when_no_user_binding(): void {
		// No host binding at all — there can't be a PMPro token order
		// waiting for reconciliation, so INSERT is safe.
		$rig = $this->makeReaction();

		$rig['orders']->shouldReceive( 'findByVatlyId' )->andReturn( null );
		$rig['getOrder']->shouldReceive( 'execute' )->andThrow( new \RuntimeException( 'API down' ) );
		$rig['bindings']->shouldReceive( 'hostCustomerIdFor' )->andReturn( null );

		$rig['bindings']->shouldReceive( 'record' )->once();
		$rig['orders']->shouldReceive( 'store' )->once();

		$rig['reaction']->handle( $this->orderPaid() );

		$this->assertHookExpectations();
	}

	public function test_unknown_pmpro_order_code_falls_through_to_insert(): void {
		// Metadata present, but no PMPro order with that code in `token`
		// status. Treat as a renewal — INSERT.
		$rig = $this->makeReaction();

		$rig['orders']->shouldReceive( 'findByVatlyId' )->andReturn( null );

		$rig['getOrder']->shouldReceive( 'execute' )->andReturn(
			$this->apiOrder( 'ord_x', [ 'pmpro_order_code' => 'NOSUCH' ] )
		);

		global $wpdb;
		$wpdb->next_row = null;

		$rig['bindings']->shouldReceive( 'hostCustomerIdFor' )->andReturn( '7' );
		$rig['bindings']->shouldReceive( 'record' );
		$rig['orders']->shouldReceive( 'store' )->once();

		$rig['reaction']->handle( $this->orderPaid( 'ord_x' ) );

		$this->assertHookExpectations();
	}

	// --- helpers ---

	/**
	 * @return array{
	 *     reaction: PMProStoreOrderOnPaid,
	 *     orders: \Mockery\MockInterface,
	 *     bindings: \Mockery\MockInterface,
	 *     getOrder: \Mockery\MockInterface,
	 * }
	 */
	private function makeReaction(): array {
		$orders   = \Mockery::mock( OrderRepositoryInterface::class );
		$bindings = \Mockery::mock( CustomerBindingRepository::class );
		$getOrder = \Mockery::mock( GetOrder::class );

		return [
			'reaction' => new PMProStoreOrderOnPaid( $orders, $bindings, $getOrder ),
			'orders'   => $orders,
			'bindings' => $bindings,
			'getOrder' => $getOrder,
		];
	}

	private function orderPaid( string $orderId = 'ord_1' ): OrderPaid {
		return new OrderPaid(
			customerId: 'cus_1',
			orderId: $orderId,
			status: 'paid',
			total: new Money( 'EUR', '1.00' ),
			subtotal: new Money( 'EUR', '1.00' ),
			taxSummary: new TaxSummaryCollection( [] ),
			invoiceNumber: null,
			paymentMethod: null,
			testmode: true,
		);
	}

	private function existingOrder( string $vatlyId ): OrderModel {
		return new OrderModel(
			id: 1, userId: 7, membershipLevelId: 0, code: 'EXISTING',
			vatlyId: $vatlyId, status: 'success', total: 0, subtotal: 0,
			tax: 0, currency: 'EUR', invoiceNumber: null,
			paymentMethod: null, subscriptionTransactionId: null,
		);
	}

	/**
	 * @param  array<string, mixed> $metadata
	 */
	private function apiOrder( string $id, array $metadata ): \Vatly\API\Resources\Order {
		$order           = new \Vatly\API\Resources\Order( \Mockery::mock( VatlyApiClient::class ) );
		$order->id       = $id;
		$order->metadata = $metadata;

		return $order;
	}
}
