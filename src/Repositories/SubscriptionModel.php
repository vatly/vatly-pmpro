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
		public ?string $mandateMethod = null,
		public ?string $mandateMaskedIdentifier = null,
		public bool $testmode = false,
		public ?string $cancellationReason = null,
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

	/**
	 * Payment-method category on file (`card`, `sepa_debit`, `paypal`, …).
	 *
	 * Persisted as subscription meta by {@see SubscriptionRepository}; null
	 * when no mandate has been synced yet (e.g. ended-before-payment).
	 */
	public function getMandateMethod(): ?string {
		return $this->mandateMethod;
	}

	/**
	 * Customer-facing masked identifier for the mandate — card last 4
	 * (`4242`) or masked IBAN (`NL91****4300`). Null for mandate types
	 * without an identifier (PayPal) or when no mandate has been synced.
	 */
	public function getMandateMaskedIdentifier(): ?string {
		return $this->mandateMaskedIdentifier;
	}

	/**
	 * Test vs live, mirrored from PMPro's own per-subscription
	 * `gateway_environment` (`sandbox` = test) — frozen when the row was
	 * written from the Vatly webhook.
	 */
	public function isTestmode(): bool {
		return $this->testmode;
	}

	/**
	 * Raw Vatly cancellation reason (`payment_failure`, `merchant_request`,
	 * `customer_request`) persisted as subscription meta when a cancellation
	 * webhook ended this subscription; null when the subscription was never
	 * canceled (or the reason wasn't supplied).
	 *
	 * @see \Vatly\API\Types\CancellationReason
	 */
	public function getCancellationReason(): ?string {
		return $this->cancellationReason;
	}
}
