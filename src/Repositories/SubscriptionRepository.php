<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Data\StoreSubscriptionData;
use Vatly\Fluent\Data\UpdateSubscriptionData;

/**
 * Backs the fluent SubscriptionRepository contract with PMPro's
 * `pmpro_subscriptions` table.
 *
 * Convention:
 * - `gateway` column = 'vatly'
 * - `subscription_transaction_id` column = Vatly subscription id
 * - level → plan mapping stored as level meta (pmpro_vatly_plan_id) — looked up
 *   in reverse here to derive membership_level_id from a Vatly plan id when
 *   storing a new subscription.
 */
class SubscriptionRepository implements SubscriptionRepositoryInterface {

	public const GATEWAY = 'vatly';

	public const LEVEL_META_PLAN_ID = 'pmpro_vatly_plan_id';

	public function __construct(
		private CustomerRepositoryInterface $customers,
	) {}

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

	public function findByOwnerAndType( BillableInterface $owner, string $type ): ?SubscriptionInterface {
		global $wpdb;

		$user_id = (int) $owner->getKey();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_subscriptions
					WHERE gateway = %s AND user_id = %d
					ORDER BY (status = 'active') DESC, id DESC
					LIMIT 1",
				self::GATEWAY,
				$user_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row, $owner );
	}

	/**
	 * @return SubscriptionInterface[]
	 */
	public function findAllByOwner( BillableInterface $owner ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_subscriptions
					WHERE gateway = %s AND user_id = %d
					ORDER BY id DESC",
				self::GATEWAY,
				(int) $owner->getKey()
			)
		);

		return array_map( fn( $row ) => $this->hydrate( $row, $owner ), $rows ?: [] );
	}

	public function ownerHasActiveSubscription( BillableInterface $owner, string $type ): bool {
		$subscription = $this->findByOwnerAndType( $owner, $type );

		return $subscription !== null && $subscription->isActive();
	}

	public function store( StoreSubscriptionData $data ): SubscriptionInterface {
		global $wpdb;

		$owner = $this->customers->findByVatlyId( $data->customerId );

		if ( $owner === null ) {
			throw new \RuntimeException(
				"No WordPress user is linked to Vatly customer {$data->customerId}; cannot create subscription."
			);
		}

		$level_id = $this->level_id_for_plan( $data->planId );

		$wpdb->insert(
			"{$wpdb->prefix}pmpro_subscriptions",
			[
				'user_id'                     => (int) $owner->getKey(),
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

		$id = (int) $wpdb->insert_id;

		return $this->find_by_id( $id, $owner );
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

		return $this->find_by_id( $subscription->id, $subscription->owner );
	}

	/**
	 * Hydrate a `pmpro_subscriptions` row to SubscriptionModel.
	 *
	 * @param  object|array<string, mixed> $row
	 */
	private function hydrate( $row, ?BillableInterface $owner = null ): SubscriptionModel {
		$row = (array) $row;

		if ( $owner === null ) {
			$user_id = (int) ( $row['user_id'] ?? 0 );
			$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
			$owner   = $user ? new UserBillable( $user ) : null;
		}

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

		return new SubscriptionModel(
			id: (int) ( $row['id'] ?? 0 ),
			userId: (int) ( $row['user_id'] ?? 0 ),
			membershipLevelId: (int) ( $row['membership_level_id'] ?? 0 ),
			vatlyId: (string) ( $row['subscription_transaction_id'] ?? '' ),
			planId: $plan_id,
			name: $this->level_name( (int) ( $row['membership_level_id'] ?? 0 ) ),
			quantity: 1,
			status: (string) ( $row['status'] ?? 'active' ),
			endsAt: $ends_at,
			owner: $owner,
		);
	}

	private function find_by_id( int $id, ?BillableInterface $owner ): SubscriptionModel {
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

		return $this->hydrate( $row, $owner );
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
