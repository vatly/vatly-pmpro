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
 *   1. Existing order at this Vatly id?               → UPDATE (replay-safe).
 *   2. Pull `pmpro_order_code` from order metadata.
 *      → PMPro token order with that code?            → convert in place.
 *      → metadata succeeded but no pmpro_order_code   → renewal: INSERT.
 *   3. `GetOrder` failed (transient API error):
 *      → user has unmatched token orders pending      → THROW (retry-safe).
 *      → user has no pending tokens                   → renewal: INSERT.
 *      → no user binding at all                       → renewal: INSERT.
 *
 * The "throw on ambiguous failure" branch matters: if we INSERT on a metadata
 * fetch failure for what might be an initial purchase, the user's eventual
 * browser-return would then convert the original token order — leaving two
 * `pmpro_membership_orders` rows for one Vatly payment. Throwing yields a 500
 * back to Vatly, which retries the webhook; by then `GetOrder` will likely
 * succeed, or the browser-return will have reconciled the token order first
 * (in which case the retry hits the replay-safe path at step 1).
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

		// 2. Precise routing via metadata.pmpro_order_code.
		try {
			$pmpro_order_code = $this->fetch_pmpro_order_code( $event->orderId );
		} catch ( \Throwable $e ) {
			// 3. Metadata fetch failed. Pick a safe failure mode.
			$this->handle_metadata_unavailable( $event, $e );

			return;
		}

		if ( $pmpro_order_code !== null && $this->convert_token_by_code( $pmpro_order_code, $event ) ) {
			return;
		}

		// 2b. Metadata succeeded but had no `pmpro_order_code` — that's a
		// genuine renewal (Vatly doesn't propagate checkout metadata onto
		// recurring orders). INSERT.
		$this->insert_event( $event );
	}

	/**
	 * Pull `pmpro_order_code` from the Vatly order's metadata. Throws when
	 * `GetOrder` fails (so callers can distinguish between "API unavailable"
	 * and "metadata absent"). Returns null when the call succeeds but the
	 * field is missing or empty.
	 */
	private function fetch_pmpro_order_code( string $vatly_order_id ): ?string {
		$api_order = $this->getOrder->execute( $vatly_order_id );

		$metadata = (array) ( $api_order->metadata ?? [] );
		$code     = $metadata['pmpro_order_code'] ?? null;

		if ( ! is_string( $code ) || $code === '' ) {
			return null;
		}

		return $code;
	}

	/**
	 * Decide the safe action when `GetOrder` failed and we therefore can't
	 * tell whether this is an initial purchase or a renewal.
	 *
	 * - If the bound user has *any* recent unmatched token order, we can't
	 *   safely route — throw so Vatly retries (or, if the user has already
	 *   returned via the browser before the retry, step 1 above hits).
	 * - If the user has no pending tokens, this can only be a renewal —
	 *   INSERT is safe.
	 * - No binding at all → also can only be a renewal — INSERT.
	 */
	private function handle_metadata_unavailable( OrderPaid $event, \Throwable $cause ): void {
		$host_id = $this->bindings->hostCustomerIdFor( $event->customerId );

		if ( $host_id === null ) {
			$this->insert_event( $event );

			return;
		}

		if ( $this->has_pending_token_orders( (int) $host_id ) ) {
			throw new \RuntimeException(
				sprintf(
					'Cannot route order.paid %s safely: GetOrder failed and user %d has unmatched token order(s). Retry will resolve. Underlying error: %s',
					$event->orderId,
					(int) $host_id,
					$cause->getMessage()
				),
				0,
				$cause
			);
		}

		$this->insert_event( $event );
	}

	/**
	 * Does the bound user have a token-status Vatly order that hasn't yet
	 * been paired with a Vatly order id? If yes, an arriving `order.paid`
	 * webhook might belong to it — we can't tell without metadata.
	 */
	private function has_pending_token_orders( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}pmpro_membership_orders
					WHERE user_id = %d
					  AND gateway = %s
					  AND status = 'token'
					  AND ( payment_transaction_id IS NULL OR payment_transaction_id = '' )
					  AND timestamp > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)",
				$user_id,
				OrderRepository::GATEWAY
			)
		);

		return ( (int) $count ) > 0;
	}

	private function insert_event( OrderPaid $event ): void {
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
