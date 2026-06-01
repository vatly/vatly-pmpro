<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use DateTimeImmutable;
use Vatly\API\Types\Mandate;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;

/**
 * Backs the fluent {@see SubscriptionRepositoryInterface} contract (v0.8)
 * with PMPro's `pmpro_subscriptions` table.
 *
 * Convention:
 * - `gateway` column = 'vatly'
 * - `subscription_transaction_id` column = Vatly subscription id
 * - level → plan mapping stored as level meta (`pmpro_vatly_plan_id`); we do
 *   the reverse lookup here to populate `membership_level_id` when storing.
 *
 * fluent v0.8 supplies the host customer id (PMPro user id, as a string) on
 * the `StoreSubscriptionData` DTO — no more host-side lookup inside the repo.
 */
class SubscriptionRepository implements SubscriptionRepositoryInterface {

	public const GATEWAY = 'vatly';

	public const LEVEL_META_PLAN_ID = 'pmpro_vatly_plan_id';

	/** Subscription-meta key: payment-method category on file (`card`, `paypal`, …). */
	public const META_MANDATE_METHOD = 'vatly_mandate_method';

	/** Subscription-meta key: customer-facing masked identifier (card last-4, masked IBAN). */
	public const META_MANDATE_MASKED_IDENTIFIER = 'vatly_mandate_masked_identifier';

	public function findByVatlyId( string $vatlyId ): ?SubscriptionInterface {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_subscriptions
					WHERE gateway = %s AND subscription_transaction_id = %s
					LIMIT 1",
				self::GATEWAY,
				$vatlyId
			)
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	public function store( StoreSubscriptionData $data ): SubscriptionInterface {
		global $wpdb;

		$user_id = (int) ( $data->hostCustomerId ?? 0 );
		// Anonymous subscriptions can technically exist at Vatly; on PMPro
		// they're effectively useless until a user gets bound, but we still
		// persist a row so we can correlate when the binding lands.

		$level_id = $this->level_id_for_plan( $data->planId );

		$wpdb->insert(
			"{$wpdb->prefix}pmpro_subscriptions",
			[
				'user_id'                     => $user_id,
				'membership_level_id'         => $level_id,
				'gateway'                     => self::GATEWAY,
				'gateway_environment'         => $this->gateway_environment(),
				'subscription_transaction_id' => $data->vatlyId,
				'status'                      => 'active',
				'startdate'                   => current_time( 'mysql', true ),
				'enddate'                     => null,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$subscription_id = (int) $wpdb->insert_id;

		// Persist the payment method on file so consumer-facing surfaces can
		// render "card ending 4242" without a per-request GetSubscription call.
		$this->write_mandate( $subscription_id, $data->mandate );

		return $this->find_by_id( $subscription_id );
	}

	public function update( SubscriptionInterface $subscription, UpdateSubscriptionData $data ): SubscriptionInterface {
		global $wpdb;

		if ( ! $subscription instanceof SubscriptionModel ) {
			throw new \InvalidArgumentException(
				'SubscriptionRepository::update() requires a SubscriptionModel; got ' . get_class( $subscription )
			);
		}

		$update = [];
		$format = [];

		if ( $data->planId !== null ) {
			$level_id = $this->level_id_for_plan( $data->planId );
			if ( $level_id !== 0 ) {
				$update['membership_level_id'] = $level_id;
				$format[]                      = '%d';
			}
		}

		if ( $data->endsAt !== null ) {
			$update['enddate'] = $data->endsAt->format( 'Y-m-d H:i:s' );
			$update['status']  = 'cancelled';
			$format[]          = '%s';
			$format[]          = '%s';
		} elseif ( $data->clearEndsAt ) {
			// Resume: drop the scheduled end date and flip the row back to
			// active. The inverse of the cancellation branch above; a
			// non-null endsAt always wins (matches UpdateSubscriptionData's
			// clearEndsAt convention). $wpdb->update writes NULL for a null
			// value regardless of the declared format.
			$update['enddate'] = null;
			$update['status']  = 'active';
			$format[]          = '%s';
			$format[]          = '%s';
		}

		if ( ! empty( $update ) ) {
			$wpdb->update(
				"{$wpdb->prefix}pmpro_subscriptions",
				$update,
				[ 'id' => $subscription->id ],
				$format,
				[ '%d' ]
			);
		}

		// Mandate is stored as subscription meta, independent of the row
		// columns above. A non-null mandate is an atomic replacement (both
		// method + masked identifier); `clearMandate` removes it. A null
		// mandate with clearMandate=false means "no change" — leave the
		// stored mandate untouched so a transient webhook null never wipes
		// a good value.
		if ( $data->mandate !== null ) {
			$this->write_mandate( $subscription->id, $data->mandate );
		} elseif ( $data->clearMandate ) {
			$this->write_mandate( $subscription->id, null );
		}

		return $this->find_by_id( $subscription->id );
	}

	/**
	 * @param  object|array<string, mixed> $row
	 */
	private function hydrate( $row ): SubscriptionModel {
		$row = (array) $row;

		$enddate = $row['enddate'] ?? null;
		if ( $enddate === '0000-00-00 00:00:00' || $enddate === null || $enddate === '' ) {
			$ends_at = null;
		} else {
			try {
				$ends_at = new DateTimeImmutable( (string) $enddate );
			} catch ( \Exception $e ) {
				$ends_at = null;
			}
		}

		$plan_id = $this->plan_id_for_level( (int) ( $row['membership_level_id'] ?? 0 ) );

		$subscription_id = (int) ( $row['id'] ?? 0 );

		return new SubscriptionModel(
			id: $subscription_id,
			userId: (int) ( $row['user_id'] ?? 0 ),
			membershipLevelId: (int) ( $row['membership_level_id'] ?? 0 ),
			vatlyId: (string) ( $row['subscription_transaction_id'] ?? '' ),
			planId: $plan_id,
			name: $this->level_name( (int) ( $row['membership_level_id'] ?? 0 ) ),
			quantity: 1,
			status: (string) ( $row['status'] ?? 'active' ),
			endsAt: $ends_at,
			mandateMethod: $this->read_mandate_meta( $subscription_id, self::META_MANDATE_METHOD ),
			mandateMaskedIdentifier: $this->read_mandate_meta( $subscription_id, self::META_MANDATE_MASKED_IDENTIFIER ),
		);
	}

	/**
	 * Persist (or clear) the payment method on file as subscription meta.
	 *
	 * A non-null Mandate writes both parts atomically; passing null removes
	 * both keys so a card→paypal switch can't leave a stale last-4 behind.
	 *
	 * Guarded with function_exists(): the subscription-meta API arrived in
	 * PMPro 3.0, and the plugin degrades gracefully (mandate simply isn't
	 * persisted) on anything older.
	 */
	private function write_mandate( int $subscription_id, ?Mandate $mandate ): void {
		if ( $subscription_id === 0 ) {
			return;
		}

		if ( $mandate === null ) {
			if ( function_exists( 'delete_pmpro_subscription_meta' ) ) {
				delete_pmpro_subscription_meta( $subscription_id, self::META_MANDATE_METHOD );
				delete_pmpro_subscription_meta( $subscription_id, self::META_MANDATE_MASKED_IDENTIFIER );
			}

			return;
		}

		if ( ! function_exists( 'update_pmpro_subscription_meta' ) ) {
			return;
		}

		update_pmpro_subscription_meta( $subscription_id, self::META_MANDATE_METHOD, $mandate->method );
		update_pmpro_subscription_meta( $subscription_id, self::META_MANDATE_MASKED_IDENTIFIER, $mandate->maskedIdentifier ?? '' );
	}

	private function read_mandate_meta( int $subscription_id, string $key ): ?string {
		if ( $subscription_id === 0 || ! function_exists( 'get_pmpro_subscription_meta' ) ) {
			return null;
		}

		$value = get_pmpro_subscription_meta( $subscription_id, $key, true );

		return ( $value === '' || $value === false || $value === null ) ? null : (string) $value;
	}

	private function find_by_id( int $id ): SubscriptionModel {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_subscriptions WHERE id = %d LIMIT 1",
				$id
			)
		);

		if ( ! $row ) {
			throw new \RuntimeException( "pmpro_subscriptions row #{$id} disappeared between write and read." );
		}

		return $this->hydrate( $row );
	}

	/**
	 * Reverse lookup: given a Vatly plan id, find the PMPro membership level
	 * configured to map onto it.
	 */
	private function level_id_for_plan( string $plan_id ): int {
		global $wpdb;

		$level_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pmml.membership_id
				 FROM {$wpdb->pmpro_membership_levelmeta} pmml
				 WHERE pmml.meta_key = %s AND pmml.meta_value = %s
				 LIMIT 1",
				self::LEVEL_META_PLAN_ID,
				$plan_id
			)
		);

		return (int) $level_id;
	}

	private function plan_id_for_level( int $level_id ): string {
		if ( $level_id === 0 ) {
			return '';
		}

		if ( ! function_exists( 'get_pmpro_membership_level_meta' ) ) {
			return '';
		}

		$meta = get_pmpro_membership_level_meta( $level_id, self::LEVEL_META_PLAN_ID, true );

		return (string) $meta;
	}

	private function level_name( int $level_id ): string {
		if ( $level_id === 0 || ! function_exists( 'pmpro_getLevel' ) ) {
			return '';
		}

		$level = pmpro_getLevel( $level_id );

		return $level ? (string) $level->name : '';
	}

	private function gateway_environment(): string {
		return (string) get_option( 'pmpro_gateway_environment', 'sandbox' );
	}
}
