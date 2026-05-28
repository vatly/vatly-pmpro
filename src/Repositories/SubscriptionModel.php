<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use DateTimeInterface;
use Vatly\Fluent\Concerns\DerivesSubscriptionState;
use Vatly\Fluent\Contracts\SubscriptionInterface;

/**
 * Read-model wrapper around a row in `pmpro_subscriptions`.
 *
 * State predicates (isCancelled/isActive/isValid/isRecurring/isEnded) are
 * inherited from fluent's `DerivesSubscriptionState` trait — keeps semantics
 * in sync across drivers without us reimplementing the lattice.
 */
class SubscriptionModel implements SubscriptionInterface {

	use DerivesSubscriptionState;

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
}
