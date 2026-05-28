<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\Fluent\Events\OrderPaid;
use Vatly\PMPro\Repositories\OrderRepository;

/**
 * Replaces fluent's built-in {@see \Vatly\Fluent\Webhooks\Reactions\StoreOrderOnPaid}
 * for the PMPro driver — routes the webhook to the exact `pmpro_membership_orders`
 * row using the `pmpro_order_code` we stamp into Vatly's checkout metadata at
 * {@see \Vatly\PMPro\Gateway\CheckoutProcessor::start_hosted_checkout()}, rather
 * than the user-keyed time-window heuristic that misbehaves when a single member
 * has multiple open checkouts in different tabs.
 *
 * Flow:
 *   1. Existing order at this Vatly id?  → UPDATE (replay-safe).
 *   2. Pull `pmpro_order_code` from the order metadata.
 *   3. PMPro order with that code in `token` status?  → convert in place.
 *   4. Otherwise (renewal / no metadata)  → INSERT.
 *
 * The metadata is set when we create the Vatly checkout. Vatly propagates it
 * onto the resulting order (it does not propagate onto *renewal* orders, by
 * design — those legitimately have no PMPro counterpart yet), so this routing
 * is precise for initial purchases and naturally falls through to INSERT for
 * recurring billing.
 *
 * fluent's `OrderPaid` event doesn't currently expose order metadata, so we
 * re-fetch via {@see GetOrder}. It's the same call fluent already makes during
 * event enrichment; expect this to collapse to a single fetch once upstream
 * exposes metadata on the event (tracking: vatly-fluent-php#TBD).
 */
class PMProStoreOrderOnPaid implements WebhookReactionInterface {

	public function __construct(
		private OrderRepositoryInterface $orders,
		private CustomerBindingRepository $bindings,
		private GetOrder $getOrder,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof OrderPaid;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof OrderPaid ) {
			return;
		}

		// 1. Replay-safe path: order already persisted at this Vatly id.
		$existing = $this->orders->findByVatlyId( $event->orderId );

		if ( $existing !== null ) {
			$this->orders->update( $existing, $this->update_payload( $event ) );

			return;
		}

		// 2 + 3. Precise routing via metadata.pmpro_order_code.
		$pmpro_order_code = $this->fetch_pmpro_order_code( $event->orderId );

		if ( $pmpro_order_code !== null && $this->convert_token_by_code( $pmpro_order_code, $event ) ) {
			return;
		}

		// 4. Fall through: renewal / no metadata. fluent's StoreOrderOnPaid
		// equivalent — just INSERT via the repository.
		$host_customer_id = $this->bindings->hostCustomerIdFor( $event->customerId );
		$this->bindings->record( $event->customerId );

		$this->orders->store( new StoreOrderData(
			vatlyId: $event->orderId,
			customerId: $event->customerId,
			status: 'paid',
			total: $event->total,
			currency: $event->currency,
			invoiceNumber: $event->invoiceNumber,
			paymentMethod: $event->paymentMethod,
			subtotal: $event->subtotal,
			taxSummary: $event->taxSummary,
			hostCustomerId: $host_customer_id,
		) );
	}

	/**
	 * Pull `pmpro_order_code` from the Vatly order's metadata. Returns null
	 * when the order has no metadata (renewals) or when the field is empty.
	 */
	private function fetch_pmpro_order_code( string $vatly_order_id ): ?string {
		try {
			$api_order = $this->getOrder->execute( $vatly_order_id );
		} catch ( \Throwable $e ) {
			// Stay graceful: if we can't fetch metadata, the fall-through
			// INSERT path still works for renewals; for token orders, the
			// browser-return path will pick them up.
			return null;
		}

		$metadata = (array) ( $api_order->metadata ?? [] );
		$code     = $metadata['pmpro_order_code'] ?? null;

		if ( ! is_string( $code ) || $code === '' ) {
			return null;
		}

		return $code;
	}

	/**
	 * Convert the specific PMPro token order keyed by `code` to a paid row.
	 * Returns true when a matching token row existed and was converted.
	 */
	private function convert_token_by_code( string $pmpro_order_code, OrderPaid $event ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_membership_orders
					WHERE code = %s AND gateway = %s AND status = 'token'
					LIMIT 1",
				$pmpro_order_code,
				OrderRepository::GATEWAY
			)
		);

		if ( ! $row ) {
			return false;
		}

		$existing = $this->orders->findByVatlyId( $event->orderId );

		// If the same Vatly id already landed on a different row between
		// findByVatlyId() above and now, prefer the precise match.
		if ( $existing !== null ) {
			$this->orders->update( $existing, $this->update_payload( $event ) );

			return true;
		}

		// Stamp the Vatly order id onto the token row directly. The status
		// flip happens via the same path fluent's StoreOrderOnPaid uses —
		// load via the repository, then apply UpdateOrderData.
		$wpdb->update(
			"{$wpdb->prefix}pmpro_membership_orders",
			[ 'payment_transaction_id' => $event->orderId ],
			[ 'id' => (int) $row->id ],
			[ '%s' ],
			[ '%d' ]
		);

		$rehydrated = $this->orders->findByVatlyId( $event->orderId );

		if ( $rehydrated !== null ) {
			$this->orders->update( $rehydrated, $this->update_payload( $event ) );
		}

		return true;
	}

	private function update_payload( OrderPaid $event ): UpdateOrderData {
		return new UpdateOrderData(
			status: 'paid',
			total: $event->total,
			currency: $event->currency,
			invoiceNumber: $event->invoiceNumber,
			paymentMethod: $event->paymentMethod,
			subtotal: $event->subtotal,
			taxSummary: $event->taxSummary,
		);
	}
}
