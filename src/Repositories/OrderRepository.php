<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\Fluent\Types\TaxSummary;

/**
 * Backs the fluent {@see OrderRepositoryInterface} contract (v0.8) with
 * PMPro's `pmpro_membership_orders` table.
 *
 * Convention:
 * - `gateway` column = 'vatly'
 * - `payment_transaction_id` = Vatly order id
 * - `subscription_transaction_id` = Vatly subscription id (if any, stamped by
 *   `WriteOrderRowOnPaid` after this repo has written the order row)
 *
 * Fluent represents money in cents (int); PMPro stores decimals in DB columns.
 * Conversions live in this repository so callers never need to think about it.
 *
 * fluent v0.8 supplies the host customer id (PMPro user id, as a string) on
 * the `StoreOrderData` DTO. We use it both to populate `user_id` on the row
 * and as the lookup key for the async-checkout duplicate-protection guard.
 */
class OrderRepository implements OrderRepositoryInterface {

	public const GATEWAY = 'vatly';

	public function findByVatlyId( string $vatlyId ): ?OrderInterface {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_membership_orders
					WHERE gateway = %s AND payment_transaction_id = %s
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

	public function store( StoreOrderData $data ): OrderInterface {
		global $wpdb;

		$user_id = (int) ( $data->hostCustomerId ?? 0 );

		// Vatly events store amounts in minor units. PMPro stores decimals.
		$total    = $this->cents_to_decimal( $data->total, $data->currency );
		$subtotal = $this->cents_to_decimal( $data->subtotal ?? $data->total, $data->currency );
		$tax      = $this->cents_to_decimal( $this->tax_total( $data->taxSummary ), $data->currency );

		// Async-checkout duplicate protection. When the user submits the
		// PMPro checkout form we create a `token`-status order with an empty
		// `payment_transaction_id` (we don't have the Vatly order id yet —
		// only a checkout id). Vatly's `order.paid` webhook then arrives with
		// the actual order id; without this guard, fluent's StoreOrderOnPaid
		// would INSERT a new row (findByVatlyId can't match the empty one),
		// and the user's subsequent return-from-Vatly would update the
		// original token row — leaving two `pmpro_membership_orders` rows
		// for the same Vatly order.
		//
		// Look for a fresh token order belonging to this user that we should
		// convert in place instead. The 24-hour window covers normal user
		// completion while keeping us off any abandoned token from earlier.
		$pending_id = $this->find_pending_token_order_id( $user_id );

		if ( $pending_id !== null ) {
			$status = $data->status === 'paid' ? 'success' : $data->status;

			$wpdb->update(
				"{$wpdb->prefix}pmpro_membership_orders",
				[
					'payment_transaction_id' => $data->vatlyId,
					'payment_type'           => (string) ( $data->paymentMethod ?? '' ),
					'status'                 => $status,
					'subtotal'               => $subtotal,
					'tax'                    => $tax,
					'total'                  => $total,
					'notes'                  => $this->build_notes( $data->invoiceNumber, $data->currency ),
					'timestamp'              => current_time( 'mysql', true ),
				],
				[ 'id' => $pending_id ],
				[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			return $this->find_by_id( $pending_id );
		}

		$code = $this->random_code();

		$wpdb->insert(
			"{$wpdb->prefix}pmpro_membership_orders",
			[
				'code'                        => $code,
				'user_id'                     => $user_id,
				'membership_id'               => $this->level_id_for_user( $user_id ),
				'gateway'                     => self::GATEWAY,
				'gateway_environment'         => $this->gateway_environment(),
				'payment_transaction_id'      => $data->vatlyId,
				'subscription_transaction_id' => '',
				'payment_type'                => (string) ( $data->paymentMethod ?? '' ),
				'status'                      => $data->status === 'paid' ? 'success' : $data->status,
				'subtotal'                    => $subtotal,
				'tax'                         => $tax,
				'total'                       => $total,
				'notes'                       => $this->build_notes( $data->invoiceNumber, $data->currency ),
				'timestamp'                   => current_time( 'mysql', true ),
				'accountnumber'               => '',
				'cardtype'                    => '',
				'expirationmonth'             => '',
				'expirationyear'              => '',
			],
			[
				'%s',
'%d',
'%d',
'%s',
'%s',
'%s',
'%s',
'%s',
				'%s',
'%s',
'%s',
'%s',
'%s',
'%s',
'%s',
'%s',
'%s',
'%s',
			]
		);

		return $this->find_by_id( (int) $wpdb->insert_id );
	}

	public function update( OrderInterface $order, UpdateOrderData $data ): OrderInterface {
		global $wpdb;

		if ( ! $order instanceof OrderModel ) {
			throw new \InvalidArgumentException(
				'OrderRepository::update() requires an OrderModel; got ' . get_class( $order )
			);
		}

		$update = [];
		$format = [];

		if ( $data->status !== null ) {
			$update['status'] = $data->status === 'paid' ? 'success' : $data->status;
			$format[]         = '%s';
		}

		if ( $data->paymentMethod !== null ) {
			$update['payment_type'] = $data->paymentMethod;
			$format[]               = '%s';
		}

		$currency = $data->currency ?? $order->currency;

		if ( $data->total !== null ) {
			$update['total'] = $this->cents_to_decimal( $data->total, $currency );
			$format[]        = '%s';
		}

		if ( $data->subtotal !== null ) {
			$update['subtotal'] = $this->cents_to_decimal( $data->subtotal, $currency );
			$format[]           = '%s';
		}

		if ( $data->taxSummary !== null ) {
			$update['tax'] = $this->cents_to_decimal( $this->tax_total( $data->taxSummary ), $currency );
			$format[]      = '%s';
		}

		if ( $data->invoiceNumber !== null || $data->currency !== null ) {
			$update['notes'] = $this->build_notes(
				$data->invoiceNumber ?? $order->invoiceNumber,
				$currency
			);
			$format[]        = '%s';
		}

		if ( ! empty( $update ) ) {
			$wpdb->update(
				"{$wpdb->prefix}pmpro_membership_orders",
				$update,
				[ 'id' => $order->id ],
				$format,
				[ '%d' ]
			);
		}

		return $this->find_by_id( $order->id );
	}

	/**
	 * @param  object|array<string, mixed> $row
	 */
	private function hydrate( $row ): OrderModel {
		$row = (array) $row;

		$notes                         = (string) ( $row['notes'] ?? '' );
		[ $invoice_number, $currency ] = $this->parse_notes( $notes );

		$total    = $this->decimal_to_cents( (string) ( $row['total'] ?? '0' ), $currency );
		$subtotal = $this->decimal_to_cents( (string) ( $row['subtotal'] ?? '0' ), $currency );
		$tax      = $this->decimal_to_cents( (string) ( $row['tax'] ?? '0' ), $currency );

		return new OrderModel(
			id: (int) ( $row['id'] ?? 0 ),
			userId: (int) ( $row['user_id'] ?? 0 ),
			membershipLevelId: (int) ( $row['membership_id'] ?? 0 ),
			code: (string) ( $row['code'] ?? '' ),
			vatlyId: (string) ( $row['payment_transaction_id'] ?? '' ),
			status: (string) ( $row['status'] ?? '' ),
			total: $total,
			subtotal: $subtotal,
			tax: $tax,
			currency: $currency,
			invoiceNumber: $invoice_number,
			paymentMethod: ( $row['payment_type'] ?? '' ) ?: null,
			subscriptionTransactionId: ( $row['subscription_transaction_id'] ?? '' ) ?: null,
		);
	}

	/**
	 * Find a recent `token`-status Vatly order for this user, if any —
	 * something `start_hosted_checkout()` created and that the webhook should
	 * convert in place rather than duplicate. Returns null when no fresh
	 * token row exists (i.e. this is a true renewal, not an initial purchase).
	 */
	private function find_pending_token_order_id( int $user_id ): ?int {
		if ( $user_id === 0 ) {
			return null;
		}

		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}pmpro_membership_orders
					WHERE user_id = %d
					  AND gateway = %s
					  AND status = 'token'
					  AND ( payment_transaction_id IS NULL OR payment_transaction_id = '' )
					  AND timestamp > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
					ORDER BY id DESC
					LIMIT 1",
				$user_id,
				self::GATEWAY
			)
		);

		return $id ? (int) $id : null;
	}

	private function find_by_id( int $id ): OrderModel {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}pmpro_membership_orders WHERE id = %d LIMIT 1",
				$id
			)
		);

		if ( ! $row ) {
			throw new \RuntimeException( "pmpro_membership_orders row #{$id} disappeared between write and read." );
		}

		return $this->hydrate( $row );
	}

	private function tax_total( ?TaxSummary $summary ): int {
		if ( $summary === null ) {
			return 0;
		}

		$total = 0;
		foreach ( $summary->items as $item ) {
			$total += $item->amount;
		}

		return $total;
	}

	/**
	 * Cents → decimal-as-string, currency-aware. JPY and a few others have no
	 * minor unit and PMPro stores them as whole numbers.
	 */
	private function cents_to_decimal( int $cents, string $currency ): string {
		$divisor = $this->minor_unit_divisor( $currency );

		if ( $divisor === 1 ) {
			return (string) $cents;
		}

		return number_format( $cents / $divisor, 2, '.', '' );
	}

	private function decimal_to_cents( string $decimal, string $currency ): int {
		$divisor = $this->minor_unit_divisor( $currency );

		return (int) round( ( (float) $decimal ) * $divisor );
	}

	private function minor_unit_divisor( string $currency ): int {
		$upper = strtoupper( $currency );
		// Zero-decimal currencies per ISO 4217.
		$zero_decimal = [ 'JPY', 'KRW', 'VND', 'CLP', 'PYG', 'XAF', 'XOF', 'BIF', 'DJF', 'GNF', 'RWF', 'UGX' ];

		return in_array( $upper, $zero_decimal, true ) ? 1 : 100;
	}

	/**
	 * PMPro doesn't model invoice number or currency per order, so we tuck them
	 * into the `notes` JSON blob alongside any merchant-supplied notes.
	 */
	private function build_notes( ?string $invoice_number, string $currency ): string {
		return wp_json_encode(
			[
				'vatly_invoice_number' => $invoice_number,
				'vatly_currency'       => strtoupper( $currency ),
			]
		);
	}

	/**
	 * @return array{0: ?string, 1: string} [invoice_number, currency]
	 */
	private function parse_notes( string $notes ): array {
		if ( $notes === '' ) {
			return [ null, $this->default_currency() ];
		}

		$decoded = json_decode( $notes, true );
		if ( ! is_array( $decoded ) ) {
			return [ null, $this->default_currency() ];
		}

		return [
			isset( $decoded['vatly_invoice_number'] ) ? (string) $decoded['vatly_invoice_number'] : null,
			isset( $decoded['vatly_currency'] ) ? (string) $decoded['vatly_currency'] : $this->default_currency(),
		];
	}

	private function default_currency(): string {
		$currency = (string) get_option( 'pmpro_currency', 'EUR' );

		return $currency !== '' ? strtoupper( $currency ) : 'EUR';
	}

	private function gateway_environment(): string {
		return (string) get_option( 'pmpro_gateway_environment', 'sandbox' );
	}

	private function level_id_for_user( int $user_id ): int {
		if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) || $user_id === 0 ) {
			return 0;
		}

		$level = pmpro_getMembershipLevelForUser( $user_id );

		return $level ? (int) $level->id : 0;
	}

	private function random_code(): string {
		if ( class_exists( 'MemberOrder' ) ) {
			$generator_class = 'MemberOrder';
			$generator       = new $generator_class();

			if ( method_exists( $generator, 'getRandomCode' ) ) {
				return (string) $generator->getRandomCode();
			}
		}

		return strtoupper( substr( bin2hex( random_bytes( 8 ) ), 0, 10 ) );
	}
}
