<?php

declare(strict_types=1);

namespace Vatly\PMPro\Gateway;

use Vatly\Fluent\Actions\GetOrder;
use Vatly\PMPro\Repositories\OrderRepository;
use Vatly\PMPro\Webhooks\Reactions\MarkOrderRefundedOnRefund;

/**
 * Surfaces Vatly's Merchant-of-Record invoice (and, on refunds, its credit
 * note) on PMPro's order views.
 *
 * Vatly generates the compliant documents; we link members and admins to them
 * from the checkout confirmation, the single-invoice view, and the admin order
 * edit screen. The links are rendered additively — PMPro's own invoice page is
 * left untouched — and simply don't appear for non-Vatly orders.
 *
 * The URLs are signed and expire 72h after Vatly mints them, so we never
 * persist a URL. We persist only ids — the Vatly order id already lives on the
 * PMPro order ({@see OrderRepository} stores it in `payment_transaction_id`),
 * and the credit-note order id is stamped by {@see MarkOrderRefundedOnRefund} —
 * and resolve a fresh link via {@see GetOrder} at display time, cached briefly
 * (well under the 72h window) so repeat views don't re-hit the API.
 */
final class InvoiceSurface {

	/** PMPro order meta key: the Vatly credit-note order id, stamped on refund. */
	public const ORDER_META_CREDIT_NOTE_ORDER_ID = MarkOrderRefundedOnRefund::ORDER_META_CREDIT_NOTE_ORDER_ID;

	/** How long a resolved, signed link is cached. Comfortably below Vatly's 72h expiry. */
	private const CACHE_TTL = 3600;

	public function __construct( private GetOrder $getOrder ) {}

	public function register(): void {
		// Member: checkout confirmation message.
		add_filter( 'pmpro_confirmation_message', [ $this, 'append_to_confirmation' ], 10, 2 );
		// Member: single invoice / order view.
		add_action( 'pmpro_invoice_bullets_bottom', [ $this, 'render_invoice_bullet' ] );
		// Admin: order edit screen.
		add_action( 'pmpro_after_order_settings', [ $this, 'render_admin_link' ] );
	}

	/**
	 * @param  string      $message
	 * @param  object|null $invoice PMPro MemberOrder
	 * @return string
	 */
	public function append_to_confirmation( $message, $invoice = null ): string {
		$message = (string) $message;

		$invoice_url = $this->invoice_url( $invoice );
		if ( $invoice_url !== '' ) {
			$message .= sprintf(
				'<p class="vatly-invoice-link"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( $invoice_url ),
				esc_html__( 'Download your invoice (from Vatly)', 'vatly-pmpro' )
			);
		}

		$credit_note_url = $this->credit_note_url( $invoice );
		if ( $credit_note_url !== '' ) {
			$message .= sprintf(
				'<p class="vatly-credit-note-link"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( $credit_note_url ),
				esc_html__( 'Download your credit note (from Vatly)', 'vatly-pmpro' )
			);
		}

		return $message;
	}

	/**
	 * @param object|null $invoice PMPro MemberOrder
	 */
	public function render_invoice_bullet( $invoice = null ): void {
		$invoice_url = $this->invoice_url( $invoice );
		if ( $invoice_url !== '' ) {
			printf(
				'<li class="vatly-invoice-link"><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
				esc_html__( 'Invoice:', 'vatly-pmpro' ),
				esc_url( $invoice_url ),
				esc_html__( 'View your official VAT invoice (Vatly)', 'vatly-pmpro' )
			);
		}

		$credit_note_url = $this->credit_note_url( $invoice );
		if ( $credit_note_url !== '' ) {
			printf(
				'<li class="vatly-credit-note-link"><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
				esc_html__( 'Credit note:', 'vatly-pmpro' ),
				esc_url( $credit_note_url ),
				esc_html__( 'View your credit note (Vatly)', 'vatly-pmpro' )
			);
		}
	}

	/**
	 * @param object|null $order PMPro MemberOrder
	 */
	public function render_admin_link( $order = null ): void {
		$invoice_url = $this->invoice_url( $order );
		if ( $invoice_url !== '' ) {
			printf(
				'<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_html__( 'Vatly invoice:', 'vatly-pmpro' ),
				esc_url( $invoice_url ),
				esc_html__( 'View on Vatly', 'vatly-pmpro' )
			);
		}

		$credit_note_url = $this->credit_note_url( $order );
		if ( $credit_note_url !== '' ) {
			printf(
				'<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_html__( 'Vatly credit note:', 'vatly-pmpro' ),
				esc_url( $credit_note_url ),
				esc_html__( 'View on Vatly', 'vatly-pmpro' )
			);
		}
	}

	/**
	 * Fresh, signed URL of the order's own Vatly invoice, or '' when none.
	 *
	 * @param object|null $order PMPro MemberOrder (needs ->gateway, ->payment_transaction_id).
	 */
	private function invoice_url( $order ): string {
		if ( ! is_object( $order ) ) {
			return '';
		}

		$gateway = isset( $order->gateway ) ? (string) $order->gateway : '';
		if ( $gateway !== OrderRepository::GATEWAY ) {
			return '';
		}

		$vatly_order_id = isset( $order->payment_transaction_id ) ? (string) $order->payment_transaction_id : '';

		return $this->resolve_invoice_url( $vatly_order_id );
	}

	/**
	 * Fresh, signed URL of the credit note for a refunded order, or '' when none.
	 *
	 * @param object|null $order PMPro MemberOrder (needs ->id).
	 */
	private function credit_note_url( $order ): string {
		$order_id = (int) ( is_object( $order ) ? ( $order->id ?? 0 ) : 0 );

		if ( $order_id === 0 || ! function_exists( 'get_pmpro_membership_order_meta' ) ) {
			return '';
		}

		$credit_note_order_id = get_pmpro_membership_order_meta(
			$order_id,
			self::ORDER_META_CREDIT_NOTE_ORDER_ID,
			true
		);

		if ( ! is_string( $credit_note_order_id ) || $credit_note_order_id === '' ) {
			return '';
		}

		return $this->resolve_invoice_url( $credit_note_order_id );
	}

	/**
	 * Resolve `links.customerInvoice` for a Vatly order id to a fresh signed URL,
	 * caching the result so repeat views don't re-hit the API. Returns '' when
	 * the id is empty, the API call fails, or the order carries no invoice link.
	 */
	private function resolve_invoice_url( string $vatly_order_id ): string {
		if ( $vatly_order_id === '' ) {
			return '';
		}

		$cache_key = 'vatly_invoice_url_' . md5( $vatly_order_id );

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}

		try {
			$order = $this->getOrder->execute( $vatly_order_id );
		} catch ( \Throwable $e ) {
			return '';
		}

		$href = ( isset( $order->links ) && $order->links->customerInvoice !== null )
			? (string) $order->links->customerInvoice->href
			: '';

		if ( $href !== '' && function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, $href, self::CACHE_TTL );
		}

		return $href;
	}
}
