<?php

declare(strict_types=1);

namespace Vatly\PMPro\Repositories;

use Vatly\Fluent\Contracts\OrderInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Data\StoreOrderData;
use Vatly\Fluent\Data\UpdateOrderData;
use Vatly\API\Types\TaxSummaryCollection;

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

	private const META_INVOICE_NUMBER = 'vatly_invoice_number';
	private const META_CURRENCY       = 'vatly_currency';

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

		// Token→success conversion (matching the right PMPro order via the
		// `pmpro_order_code` we stamp into Vatly checkout metadata) is handled
		// by `PMProStoreOrderOnPaid`. `store()` is purely the INSERT path now —
		// reached for renewals and any caller that hasn't pre-resolved a token.

		$code = $this->random_code();

		$wpdb->insert(
			"{$wpdb->prefix}pmpro_membership_orders",
			[
				'code'                        => $code,
				'user_id'                     => $user_id,
				'membership_id'               => $this->level_id_for_user( $user_id ),
				'gateway'                     => self::GATEWAY,
				// Freeze the record's own mode from the Vatly event, not the
				// current global PMPro environment toggle — so follow-up
				// operations stay consistent if the merchant later flips it.
				'gateway_environment'         => $data->testmode ? 'sandbox' : 'live',
				'payment_transaction_id'      => $data->vatlyId,
				'subscription_transaction_id' => '',
				'payment_type'                => (string) ( $data->paymentMethod ?? '' ),
				'status'                      => $data->status === 'paid' ? 'success' : $data->status,
				'subtotal'                    => $subtotal,
				'tax'                         => $tax,
				'total'                       => $total,
				'notes'                       => '',
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

		$id = (int) $wpdb->insert_id;
		$this->store_meta( $id, $data->invoiceNumber, $data->currency );

		return $this->find_by_id( $id );
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

		$this->store_meta( $order->id, $data->invoiceNumber, $data->currency );

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

		$id             = (int) ( $row['id'] ?? 0 );
		$invoice_number = (string) get_pmpro_membership_order_meta( $id, self::META_INVOICE_NUMBER, true );
		$currency       = strtoupper( (string) get_pmpro_membership_order_meta( $id, self::META_CURRENCY, true ) );
		$currency       = $currency !== '' ? $currency : $this->default_currency();

		$total    = $this->decimal_to_cents( (string) ( $row['total'] ?? '0' ), $currency );
		$subtotal = $this->decimal_to_cents( (string) ( $row['subtotal'] ?? '0' ), $currency );
		$tax      = $this->decimal_to_cents( (string) ( $row['tax'] ?? '0' ), $currency );

		return new OrderModel(
			id: $id,
			userId: (int) ( $row['user_id'] ?? 0 ),
			membershipLevelId: (int) ( $row['membership_id'] ?? 0 ),
			code: (string) ( $row['code'] ?? '' ),
			vatlyId: (string) ( $row['payment_transaction_id'] ?? '' ),
			status: (string) ( $row['status'] ?? '' ),
			total: $total,
			subtotal: $subtotal,
			tax: $tax,
			currency: $currency,
			invoiceNumber: $invoice_number !== '' ? $invoice_number : null,
			paymentMethod: ( $row['payment_type'] ?? '' ) ?: null,
			subscriptionTransactionId: ( $row['subscription_transaction_id'] ?? '' ) ?: null,
			testmode: ( (string) ( $row['gateway_environment'] ?? 'live' ) ) === 'sandbox',
		);
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

	private function tax_total( ?TaxSummaryCollection $summary ): int {
		if ( $summary === null ) {
			return 0;
		}

		$total = 0;
		foreach ( $summary->items as $item ) {
			$total += $item->amount->toCents();
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
	 * PMPro doesn't model invoice number or currency per order, so they live in
	 * order meta. The `notes` column belongs to the merchant (PMPro shows it as
	 * "Order Notes"), so we leave it alone.
	 */
	private function store_meta( int $order_id, ?string $invoice_number, ?string $currency ): void {
		if ( $invoice_number !== null ) {
			update_pmpro_membership_order_meta( $order_id, self::META_INVOICE_NUMBER, $invoice_number );
		}

		if ( $currency !== null ) {
			update_pmpro_membership_order_meta( $order_id, self::META_CURRENCY, strtoupper( $currency ) );
		}
	}

	private function default_currency(): string {
		$currency = (string) get_option( 'pmpro_currency', 'EUR' );

		return $currency !== '' ? strtoupper( $currency ) : 'EUR';
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
