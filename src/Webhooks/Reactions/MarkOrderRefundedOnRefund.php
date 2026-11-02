<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\API\Webhooks\Events\RefundCompleted;
use Vatly\PMPro\Repositories\OrderModel;

/**
 * Reflect a Vatly-issued refund onto the originating PMPro order.
 *
 * Vatly is the Merchant of Record, so it owns the refund and the credit note.
 * When a refund completes we flip the local order to `refunded` so PMPro's
 * reporting and the member's order history reconcile with Vatly's records.
 *
 * We deliberately do NOT revoke membership here. Unlike a chargeback (see
 * {@see SuspendMembershipOnChargeback}, which pulls access immediately), a
 * refund may be partial or a goodwill gesture, so auto-revoking on every refund
 * would be the wrong default for a membership site. An admin can cancel the
 * membership manually when a refund represents a full cancellation.
 *
 * `RefundCompleted` carries `originalOrderId` (the Vatly order id), which maps
 * to our `payment_transaction_id`. Find-or-skip: a refund for an order we don't
 * track is a no-op rather than a 500. Replay-safe: already-`refunded` orders are
 * left untouched.
 */
class MarkOrderRefundedOnRefund implements WebhookReactionInterface {

	public function __construct(
		private OrderRepositoryInterface $orders,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof RefundCompleted;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof RefundCompleted ) {
			return;
		}

		$order = $this->orders->findByVatlyId( $event->originalOrderId );

		if ( ! $order instanceof OrderModel ) {
			return;
		}

		if ( $order->getStatus() === 'refunded' ) {
			return;
		}

		$this->orders->update( $order, new UpdateOrderData( status: 'refunded' ) );
	}
}
