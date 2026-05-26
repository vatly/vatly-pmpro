<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use Vatly\Fluent\Contracts\BillableInterface;
use WP_User;

/**
 * Wraps WP_User as a fluent BillableInterface.
 *
 * The Vatly customer id is stored in user meta under `vatly_customer_id`.
 * Save() updates that meta value; the rest is straight delegation to WP_User.
 */
class UserBillable implements BillableInterface {

	public const META_KEY = 'vatly_customer_id';

	public function __construct(
		private WP_User $user,
	) {}

	public function user(): WP_User {
		return $this->user;
	}

	public function getVatlyId(): ?string {
		$id = (string) get_user_meta( $this->user->ID, self::META_KEY, true );

		return $id !== '' ? $id : null;
	}

	public function setVatlyId( string $id ): void {
		update_user_meta( $this->user->ID, self::META_KEY, $id );
	}

	public function hasVatlyId(): bool {
		return $this->getVatlyId() !== null;
	}

	public function getVatlyEmail(): ?string {
		$email = (string) $this->user->user_email;

		return $email !== '' ? $email : null;
	}

	public function getVatlyName(): ?string {
		$name = trim( (string) $this->user->display_name );

		if ( $name === '' ) {
			$first = (string) $this->user->first_name;
			$last  = (string) $this->user->last_name;
			$name  = trim( "{$first} {$last}" );
		}

		return $name !== '' ? $name : null;
	}

	public function getKey() {
		return (int) $this->user->ID;
	}

	public function save() {
		// User meta is persisted in-place by setVatlyId. Nothing else to do.
		return true;
	}
}
