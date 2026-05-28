<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\OrderReader;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Events\OrderPaid;
use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * Stamps the active Vatly subscription's `subscription_transaction_id` onto
 * the just-written `pmpro_membership_orders` row.
 *
 * Order of reactions: fluent's built-in {@see \Vatly\Fluent\Webhooks\Reactions\StoreOrderOnPaid}
 * writes the row first (via our `OrderRepository::store()`, which now also
 * handles the token→success conversion case), and our reaction runs after
 * it to attach the subscription link — handy for `pmpro_subscriptions →
 * pmpro_membership_orders` joins in admin views.
 *
 * Looking up the subscription by the user means we don't need fluent to
 * expose a subscription id on the `OrderPaid` event (it doesn't); a single
 * user has at most one active Vatly subscription in the PMPro model, so the
 * most-recent active row wins.
 */
class WriteOrderRowOnPaid implements WebhookReactionInterface {

	public function __construct(
		private CustomerBindingRepository $bindings,
		private OrderReader $orders,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof OrderPaid;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof OrderPaid ) {
			return;
		}

		// fluent's built-in StoreOrderOnPaid already wrote the row. Confirm
		// it exists (otherwise there's nothing to stamp) before we go look
		// for the subscription.
		$order = $this->orders->findByVatlyId( $event->orderId );

		if ( $order === null ) {
			return;
		}

		$host_id = $this->bindings->hostCustomerIdFor( $event->customerId );

		if ( $host_id === null ) {
			return;
		}

		$user_id = (int) $host_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$subscription_vatly_id = $this->latest_subscription_id_for_user( $user_id );

		if ( $subscription_vatly_id === null ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}pmpro_membership_orders",
			[ 'subscription_transaction_id' => $subscription_vatly_id ],
			[
				'gateway'                => SubscriptionRepository::GATEWAY,
				'payment_transaction_id' => $event->orderId,
			],
			[ '%s' ],
			[ '%s', '%s' ]
		);
	}

	/**
	 * fluent's `SubscriptionReader` only exposes `findByVatlyId()`. Look up
	 * by user directly against the table — we know it well enough.
	 */
	private function latest_subscription_id_for_user( int $user_id ): ?string {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT subscription_transaction_id FROM {$wpdb->prefix}pmpro_subscriptions
					WHERE user_id = %d AND gateway = %s
					ORDER BY (status = 'active') DESC, id DESC
					LIMIT 1",
				$user_id,
				SubscriptionRepository::GATEWAY
			)
		);

		return $id ? (string) $id : null;
	}
}
