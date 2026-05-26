<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Exceptions\InvalidCustomerException;
use WP_User;

/**
 * WordPress-user-meta-backed customer repository.
 *
 * Each WP_User stores its Vatly customer id in user meta. Lookups by Vatly id
 * use a single user_meta query; saves persist the id under the same key.
 */
class CustomerRepository implements CustomerRepositoryInterface {

	public function findByVatlyId( string $vatlyId ): ?BillableInterface {
		$users = get_users(
			[
				'meta_key'    => UserBillable::META_KEY,
				'meta_value'  => $vatlyId,
				'number'      => 1,
				'count_total' => false,
				'fields'      => 'all',
			]
		);

		if ( empty( $users ) ) {
			return null;
		}

		$user = $users[0];

		if ( ! $user instanceof WP_User ) {
			return null;
		}

		return new UserBillable( $user );
	}

	public function findByVatlyIdOrFail( string $vatlyId ): BillableInterface {
		$billable = $this->findByVatlyId( $vatlyId );

		if ( $billable === null ) {
			throw InvalidCustomerException::notFound( $vatlyId );
		}

		return $billable;
	}

	public function save( BillableInterface $billable ): void {
		// setVatlyId already persists the user meta inline. We just need to
		// honor a save() call so the orchestrator contract is satisfied.
		$billable->save();
	}

	/**
	 * Helper: wrap a WP_User in a fluent billable.
	 */
	public function forUser( WP_User $user ): UserBillable {
		return new UserBillable( $user );
	}
}
