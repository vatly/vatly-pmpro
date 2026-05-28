<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use Vatly\Fluent\Contracts\CustomerBindingRepository;

/**
 * User-meta-backed implementation of fluent v0.8's
 * {@see CustomerBindingRepository} contract.
 *
 * For the PMPro driver, the "host customer id" is the numeric `WP_User->ID`
 * rendered as a string. We persist the link as a user_meta row
 * (`vatly_customer_id`) on that user, which keeps lookups O(1) in both
 * directions and avoids a dedicated mapping table.
 *
 * `record()` handles the anonymous-checkout flow — a Vatly customer created
 * via webhook for someone who isn't (yet) a known WP user. PMPro's checkout
 * always creates the user before calling our gateway, so we never see this in
 * practice; we stash the bare id in an option for forward-compat anyway.
 */
class UserMetaCustomerBindings implements CustomerBindingRepository {

	public const USER_META_KEY      = 'vatly_customer_id';
	public const UNBOUND_OPTION_KEY = 'vatly_pmpro_unbound_customers';

	public function bind( string $vatlyCustomerId, string $hostCustomerId ): void {
		$user_id = (int) $hostCustomerId;

		if ( $user_id <= 0 ) {
			return;
		}

		update_user_meta( $user_id, self::USER_META_KEY, $vatlyCustomerId );

		// Clean up the orphan list if this id had been recorded earlier.
		$this->forget_unbound( $vatlyCustomerId );
	}

	public function record( string $vatlyCustomerId ): void {
		$unbound = (array) get_option( self::UNBOUND_OPTION_KEY, [] );

		if ( ! in_array( $vatlyCustomerId, $unbound, true ) ) {
			$unbound[] = $vatlyCustomerId;
			update_option( self::UNBOUND_OPTION_KEY, $unbound, false );
		}
	}

	public function hostCustomerIdFor( string $vatlyCustomerId ): ?string {
		$users = get_users(
			[
				'meta_key'    => self::USER_META_KEY,
				'meta_value'  => $vatlyCustomerId,
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'ID',
			]
		);

		if ( empty( $users ) ) {
			return null;
		}

		return (string) $users[0];
	}

	public function vatlyCustomerIdFor( string $hostCustomerId ): ?string {
		$user_id = (int) $hostCustomerId;

		if ( $user_id <= 0 ) {
			return null;
		}

		$id = (string) get_user_meta( $user_id, self::USER_META_KEY, true );

		return $id !== '' ? $id : null;
	}

	private function forget_unbound( string $vatlyCustomerId ): void {
		$unbound = (array) get_option( self::UNBOUND_OPTION_KEY, [] );

		$filtered = array_values(
			array_filter(
				$unbound,
				static fn( $id ) => $id !== $vatlyCustomerId
			)
		);

		if ( count( $filtered ) !== count( $unbound ) ) {
			update_option( self::UNBOUND_OPTION_KEY, $filtered, false );
		}
	}
}
