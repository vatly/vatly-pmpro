<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\SubscriptionUpdated;
use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * On `subscription.updated` — an **immediate** plan/price/interval/quantity
 * change at Vatly — move the member to the PMPro level mapped to the new plan.
 * The level→plan mapping is configured on each PMPro level via the
 * `pmpro_vatly_plan_id` level meta (see {@see \Vatly\PMPro\Admin\LevelOptions}),
 * the same mapping {@see AssignMembershipLevelOnStarted} uses on start.
 *
 * This handles the immediate case only. `subscription.update_scheduled` (a
 * change queued for the next billing cycle) is dispatched-only — the member's
 * level must NOT change until the new cycle actually applies, at which point
 * Vatly emits `subscription.updated` and this reaction runs.
 *
 * Idempotent: PMPro's `pmpro_changeMembershipLevel()` is a no-op if the user is
 * already on the level.
 */
class ChangeMembershipLevelOnUpdated implements WebhookReactionInterface {

	public function __construct(
		private CustomerBindingRepository $bindings,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof SubscriptionUpdated;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof SubscriptionUpdated ) {
			return;
		}

		$host_id = $this->bindings->hostCustomerIdFor( $event->customerId );

		if ( $host_id === null ) {
			// Customer not yet linked to a WordPress user. The subscription row
			// is kept in step by the fluent built-in SyncSubscriptionOnUpdated
			// reaction either way; the level can be reconciled on the next event.
			return;
		}

		$user_id = (int) $host_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$level_id = $this->resolve_level_id( $event->planId );

		if ( $level_id === 0 ) {
			// New plan isn't mapped to any PMPro level. Surface for ops but
			// don't fatal — admins may finish wiring it up later.
			error_log(
				"[vatly-pmpro] No PMPro level mapped to Vatly plan '{$event->planId}' — subscription updated without level change."
			);

			return;
		}

		if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
			pmpro_changeMembershipLevel( $level_id, $user_id, 'changed' );
		}
	}

	private function resolve_level_id( string $plan_id ): int {
		global $wpdb;

		$level_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta}
					WHERE meta_key = %s AND meta_value = %s
					LIMIT 1",
				SubscriptionRepository::LEVEL_META_PLAN_ID,
				$plan_id
			)
		);

		return (int) $level_id;
	}
}
