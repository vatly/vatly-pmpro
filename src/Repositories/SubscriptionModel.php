<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\SubscriptionInterface;

/**
 * Read-model wrapper around a row in `pmpro_subscriptions`.
 *
 * Stays decoupled from PMPro_Subscription — both for testability and because
 * the PMPro class is loaded too late to use in some webhook contexts. Mutations
 * go through SubscriptionRepository::update().
 */
class SubscriptionModel implements SubscriptionInterface {

	public const GATEWAY = 'vatly';

	public function __construct(
		public int $id,
		public int $userId,
		public int $membershipLevelId,
		public string $vatlyId,
		public string $planId,
		public string $name,
		public int $quantity,
		public string $status,
		public ?DateTimeInterface $endsAt,
		public ?BillableInterface $owner,
	) {}

	public function getVatlyId(): string {
		return $this->vatlyId;
	}

	public function getType(): string {
		return 'default';
	}

	public function getPlanId(): string {
		return $this->planId;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getQuantity(): int {
		return $this->quantity;
	}

	public function getEndsAt(): ?DateTimeInterface {
		return $this->endsAt;
	}

	public function isCancelled(): bool {
		// PMPro statuses: active, cancelled (with grace period until enddate), error
		if ( $this->endsAt !== null ) {
			return true;
		}

		return in_array( $this->status, [ 'cancelled', 'canceled', 'expired' ], true );
	}

	public function isOnGracePeriod(): bool {
		if ( $this->endsAt === null ) {
			return false;
		}

		return $this->endsAt > new DateTimeImmutable();
	}

	public function isActive(): bool {
		if ( ! $this->isCancelled() ) {
			return true;
		}

		return $this->isOnGracePeriod();
	}

	public function getOwner(): BillableInterface {
		if ( $this->owner === null ) {
			throw new \LogicException(
				'SubscriptionModel was constructed without an owner. Use SubscriptionRepository::findByVatlyId().'
			);
		}

		return $this->owner;
	}
}
