<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Events\SubscriptionCanceledImmediately;
use Vatly\Fluent\Events\SubscriptionCanceledWithGracePeriod;

/**
 * Revoke (or schedule revocation of) the user's PMPro membership level on
 * subscription cancellation.
 *
 * - `subscription.canceled_immediately`: revoke now.
 * - `subscription.canceled_with_grace_period`: store the grace end date on the
 *   subscription row (handled by fluent's built-in CancelSubscriptionOnCanceled
 *   reaction) and rely on PMPro's expiration cron to revoke at grace end.
 */
class RevokeMembershipLevelOnCanceled implements WebhookReactionInterface {

	public function __construct(
		private CustomerRepositoryInterface $customers,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof SubscriptionCanceledImmediately
			|| $event instanceof SubscriptionCanceledWithGracePeriod;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof SubscriptionCanceledImmediately
			&& ! $event instanceof SubscriptionCanceledWithGracePeriod ) {
			return;
		}

		// Only immediate cancellations should pull the membership now;
		// grace-period cancellations are honored by PMPro's own expiration
		// flow once the grace `enddate` passes.
		if ( ! $event instanceof SubscriptionCanceledImmediately ) {
			return;
		}

		$user_billable = $this->customers->findByVatlyId( $event->customerId );

		if ( $user_billable === null ) {
			return;
		}

		if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
			// Passing 0 cancels all of the user's memberships.
			pmpro_changeMembershipLevel( 0, (int) $user_billable->getKey(), 'cancelled' );
		}
	}
}
