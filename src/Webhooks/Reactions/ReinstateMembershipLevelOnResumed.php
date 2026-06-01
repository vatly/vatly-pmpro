<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\Fluent\Events\SubscriptionResumed;
use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * Reinstate the user's PMPro membership level when a grace-period
 * cancellation is reversed at Vatly (`subscription.resumed`).
 *
 * The inverse of {@see RevokeMembershipLevelOnCanceled}. Only
 * grace-period cancellations can be resumed (an immediate cancellation is
 * terminal), so when this fires the membership is typically still active —
 * but the grace-period cancellation scheduled an expiration that PMPro's
 * cron would otherwise honor at grace end. Re-asserting the level clears
 * that scheduled expiration: `pmpro_changeMembershipLevel()` deactivates
 * the expiring membership row and inserts a fresh active one with no end
 * date (for an unlimited level).
 *
 * Record-side bookkeeping (clearing the subscription row's `endsAt`) is
 * handled separately by fluent's built-in
 * {@see \Vatly\Fluent\Webhooks\Reactions\ResumeSubscriptionOnResumed},
 * mirroring how {@see \Vatly\Fluent\Webhooks\Reactions\CancelSubscriptionOnCanceled}
 * owns the record side of cancellation.
 *
 * Find-or-skip: a resume for an unbound customer or an untracked
 * subscription is a no-op.
 */
class ReinstateMembershipLevelOnResumed implements WebhookReactionInterface {

	public function __construct(
		private CustomerBindingRepository $bindings,
		private SubscriptionRepositoryInterface $subscriptions,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof SubscriptionResumed;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof SubscriptionResumed ) {
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

		// The resumed event carries no plan id, so resolve the membership
		// level from the locally-stored subscription.
		$subscription = $this->subscriptions->findByVatlyId( $event->subscriptionId );

		if ( $subscription === null ) {
			return;
		}

		$level_id = $this->resolve_level_id( $subscription->getPlanId() );

		if ( $level_id === 0 ) {
			// Plan isn't mapped to any PMPro level — nothing to reinstate.
			// Surface for ops but don't fatal.
			error_log(
				"[vatly-pmpro] No PMPro level mapped to Vatly plan '{$subscription->getPlanId()}' — subscription resumed without level change."
			);

			return;
		}

		if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
			pmpro_changeMembershipLevel( $level_id, $user_id, 'changed' );
		}
	}

	private function resolve_level_id( string $plan_id ): int {
		if ( $plan_id === '' ) {
			return 0;
		}

		global $wpdb;

		$level_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT membership_id FROM {$wpdb->pmpro_membership_levelmeta}
					WHERE meta_key = %s AND meta_value = %s
					LIMIT 1",
				SubscriptionRepository::LEVEL_META_PLAN_ID,
				$plan_id
			)
		);

		return (int) $level_id;
	}
}
