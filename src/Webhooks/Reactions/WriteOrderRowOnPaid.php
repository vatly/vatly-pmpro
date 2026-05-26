<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Events\OrderPaid;
use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * Write a `success`-status row to `pmpro_membership_orders` for every paid
 * Vatly order — both initial purchases and recurring renewals — and link it to
 * the active Vatly subscription where possible.
 *
 * The built-in `StoreOrderOnPaid` reaction in fluent already persists via the
 * OrderRepository contract; this PMPro-specific reaction runs *after* it (we
 * register it after the built-in in the WebhookProcessorFactory call) and
 * patches `subscription_transaction_id` into the newly written row so PMPro's
 * subscription→order joins work.
 *
 * Order of reactions registered: built-in StoreOrderOnPaid → this. fluent
 * iterates `$reactions` in order.
 */
class WriteOrderRowOnPaid implements WebhookReactionInterface {

	public function __construct(
		private CustomerRepositoryInterface $customers,
		private OrderRepositoryInterface $orders,
		private SubscriptionRepositoryInterface $subscriptions,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof OrderPaid;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof OrderPaid ) {
			return;
		}

		// fluent's built-in StoreOrderOnPaid already wrote the row. We
		// re-read it to find the PMPro id, then stamp the active
		// subscription_transaction_id onto it.
		$order = $this->orders->findByVatlyId( $event->orderId );

		if ( $order === null ) {
			return;
		}

		$user_billable = $this->customers->findByVatlyId( $event->customerId );

		if ( $user_billable === null ) {
			return;
		}

		$subscription = $this->subscriptions->findByOwnerAndType( $user_billable, 'default' );

		if ( $subscription === null ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}pmpro_membership_orders",
			[ 'subscription_transaction_id' => $subscription->getVatlyId() ],
			[
'gateway' => SubscriptionRepository::GATEWAY,
'payment_transaction_id' => $event->orderId,
],
			[ '%s' ],
			[ '%s', '%s' ]
		);
	}
}
