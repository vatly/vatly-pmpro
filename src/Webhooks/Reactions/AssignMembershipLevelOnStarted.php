<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\SubscriptionStarted;
use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * On `subscription.started`, assign the matching PMPro membership level to
 * the user bound to the Vatly customer in the event. The level→plan mapping
 * is configured on each PMPro level via the `pmpro_vatly_plan_id` level
 * meta (see {@see \Vatly\PMPro\Admin\LevelOptions}).
 *
 * Idempotent: PMPro's `pmpro_changeMembershipLevel()` is a no-op if the
 * user is already on the level.
 */
class AssignMembershipLevelOnStarted implements WebhookReactionInterface {

	public function __construct(
		private CustomerBindingRepository $bindings,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof SubscriptionStarted;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof SubscriptionStarted ) {
			return;
		}

		$host_id = $this->bindings->hostCustomerIdFor( $event->customerId );

		if ( $host_id === null ) {
			// Customer not yet linked to a WordPress user — this can happen
			// if the webhook arrives before the user returns from the hosted
			// checkout. The subscription row will be created by the fluent
			// built-in SyncSubscriptionOnStarted reaction either way, and we
			// can retroactively assign the level on the next event.
			return;
		}

		$user_id = (int) $host_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$level_id = $this->resolve_level_id( $event->planId );

		if ( $level_id === 0 ) {
			// Plan isn't mapped to any PMPro level. Surface for ops but don't
			// fatal — admins may finish wiring it up later.
			error_log(
				"[vatly-pmpro] No PMPro level mapped to Vatly plan '{$event->planId}' — subscription started without level change."
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
