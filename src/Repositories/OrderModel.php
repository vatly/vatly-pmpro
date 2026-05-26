<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use Vatly\Fluent\Contracts\BillableInterface;
use Vatly\Fluent\Contracts\OrderInterface;

/**
 * Read-model wrapper around a row in `pmpro_membership_orders`.
 *
 * Vatly amounts are stored in PMPro orders as decimal totals (matching PMPro's
 * `total`/`subtotal` columns); we round-trip to cents via Money helpers in the
 * repository when comparing against fluent payloads.
 */
class OrderModel implements OrderInterface {

	public const GATEWAY = 'vatly';

	public function __construct(
		public int $id,
		public int $userId,
		public int $membershipLevelId,
		public string $code,
		public string $vatlyId,
		public string $status,
		public int $total,
		public int $subtotal,
		public int $tax,
		public string $currency,
		public ?string $invoiceNumber,
		public ?string $paymentMethod,
		public ?string $subscriptionTransactionId,
		public ?BillableInterface $owner,
	) {}

	public function getVatlyId(): string {
		return $this->vatlyId;
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function getInvoiceNumber(): ?string {
		return $this->invoiceNumber;
	}

	public function getTotal(): int {
		return $this->total;
	}

	public function getCurrency(): string {
		return $this->currency;
	}

	public function getPaymentMethod(): ?string {
		return $this->paymentMethod;
	}

	public function getOwner(): BillableInterface {
		if ( $this->owner === null ) {
			throw new \LogicException(
				'OrderModel was constructed without an owner. Use OrderRepository::findByVatlyId().'
			);
		}

		return $this->owner;
	}

	public function isPaid(): bool {
		return $this->status === 'success';
	}
}
