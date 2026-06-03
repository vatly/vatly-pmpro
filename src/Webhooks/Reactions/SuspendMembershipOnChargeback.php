<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderChargebackReceived;
use Vatly\API\Webhooks\Events\OrderChargebackReversed;
use Vatly\PMPro\Repositories\OrderModel;

/**
 * Suspend (and later reinstate) a member's PMPro access in response to a
 * chargeback against one of their Vatly orders.
 *
 * - `order.chargeback_received`: the member disputed a charge — revoke their
 *   membership now, mirroring {@see RevokeMembershipLevelOnCanceled} (pass
 *   level 0 to pull every active membership). Holding access open while the
 *   merchant has lost the funds is the wrong default for a membership site.
 * - `order.chargeback_reversed`: the dispute was won/withdrawn — reinstate the
 *   level the disputed order granted, mirroring
 *   {@see ReinstateMembershipLevelOnResumed}.
 *
 * User resolution is two-tier so it works whether or not the event was enriched
 * via `GetChargeback`:
 *   1. Prefer the Vatly `customerId` → host user via the binding repository
 *      (only present on enriched events).
 *   2. Fall back to the locally-stored order keyed by `originalOrderId` — the
 *      sparse webhook payload always carries that, and the PMPro order row
 *      records the `user_id`. This also yields the membership level to
 *      reinstate on reversal, which the event itself never carries.
 *
 * Find-or-skip throughout: a chargeback for an unknown customer/order, or a
 * reversal where the granting level can't be resolved, is a logged no-op rather
 * than a fatal — webhook delivery must not 500 on edge data.
 */
class SuspendMembershipOnChargeback implements WebhookReactionInterface {

	public function __construct(
		private CustomerBindingRepository $bindings,
		private OrderRepositoryInterface $orders,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof OrderChargebackReceived
			|| $event instanceof OrderChargebackReversed;
	}

	public function handle( object $event ): void {
		if ( $event instanceof OrderChargebackReceived ) {
			$this->revoke( $event );

			return;
		}

		if ( $event instanceof OrderChargebackReversed ) {
			$this->reinstate( $event );
		}
	}

	private function revoke( OrderChargebackReceived $event ): void {
		$order   = $this->resolve_order( $event->originalOrderId );
		$user_id = $this->resolve_user_id( $event->customerId, $order );

		if ( $user_id <= 0 ) {
			error_log(
				"[vatly-pmpro] Chargeback {$event->chargebackId} received for order '{$event->originalOrderId}' but no PMPro user could be resolved — membership left unchanged."
			);

			return;
		}

		if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
			// Passing 0 cancels all of the user's memberships, matching how
			// RevokeMembershipLevelOnCanceled handles an immediate cancel.
			pmpro_changeMembershipLevel( 0, $user_id, 'chargeback' );
		}
	}

	private function reinstate( OrderChargebackReversed $event ): void {
		$order   = $this->resolve_order( $event->originalOrderId );
		$user_id = $this->resolve_user_id( $event->customerId, $order );

		if ( $user_id <= 0 ) {
			error_log(
				"[vatly-pmpro] Chargeback {$event->chargebackId} reversed for order '{$event->originalOrderId}' but no PMPro user could be resolved — membership left unchanged."
			);

			return;
		}

		// The reversal event carries no level, so reinstate the level the
		// disputed order originally granted (recorded on the PMPro order row).
		$level_id = $order !== null ? $order->membershipLevelId : 0;

		if ( $level_id <= 0 ) {
			error_log(
				"[vatly-pmpro] Chargeback {$event->chargebackId} reversed for order '{$event->originalOrderId}' but the granting membership level could not be resolved — reinstate skipped."
			);

			return;
		}

		if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
			pmpro_changeMembershipLevel( $level_id, $user_id, 'chargeback' );
		}
	}

	private function resolve_order( string $original_order_id ): ?OrderModel {
		if ( $original_order_id === '' ) {
			return null;
		}

		$order = $this->orders->findByVatlyId( $original_order_id );

		return $order instanceof OrderModel ? $order : null;
	}

	/**
	 * Resolve the PMPro user id, preferring the enriched customer binding and
	 * falling back to the user recorded on the disputed order.
	 */
	private function resolve_user_id( string $customer_id, ?OrderModel $order ): int {
		if ( $customer_id !== '' ) {
			$host_id = $this->bindings->hostCustomerIdFor( $customer_id );

			if ( $host_id !== null && (int) $host_id > 0 ) {
				return (int) $host_id;
			}
		}

		return $order !== null ? $order->userId : 0;
	}
}
