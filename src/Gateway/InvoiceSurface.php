<?php

declare(strict_types=1);

namespace Vatly\PMPro\Gateway;

use Vatly\PMPro\Webhooks\Reactions\StoreInvoiceLinkOnPaid;

/**
 * Surfaces Vatly's Merchant-of-Record invoice on PMPro's order views.
 *
 * Vatly generates the compliant invoice; {@see StoreInvoiceLinkOnPaid} stamps
 * its URL onto the order. Here we link members and admins to that invoice from
 * the checkout confirmation, the single-invoice view, and the admin order edit
 * screen. The link is rendered additively — PMPro's own invoice page is left
 * untouched — and simply doesn't appear for orders that have no stored Vatly
 * invoice URL (non-Vatly orders, or orders paid before this was added).
 */
final class InvoiceSurface {

	public static function register(): void {
		// Member: checkout confirmation message.
		add_filter( 'pmpro_confirmation_message', [ self::class, 'append_to_confirmation' ], 10, 2 );
		// Member: single invoice / order view.
		add_action( 'pmpro_invoice_bullets_bottom', [ self::class, 'render_invoice_bullet' ] );
		// Admin: order edit screen.
		add_action( 'pmpro_after_order_settings', [ self::class, 'render_admin_link' ] );
	}

	/**
	 * Absolute URL of the Vatly invoice stored on an order, or '' when none.
	 *
	 * @param object|null $order PMPro MemberOrder (needs ->id).
	 */
	private static function invoice_url_for_order( $order ): string {
		$order_id = (int) ( is_object( $order ) ? ( $order->id ?? 0 ) : 0 );

		if ( $order_id === 0 || ! function_exists( 'get_pmpro_membership_order_meta' ) ) {
			return '';
		}

		$url = get_pmpro_membership_order_meta(
			$order_id,
			StoreInvoiceLinkOnPaid::ORDER_META_INVOICE_URL,
			true
		);

		return ( is_string( $url ) && $url !== '' ) ? $url : '';
	}

	/**
	 * @param  string $message
	 * @param  object $invoice PMPro MemberOrder
	 * @return string
	 */
	public static function append_to_confirmation( $message, $invoice = null ): string {
		$url = self::invoice_url_for_order( $invoice );

		if ( $url === '' ) {
			return (string) $message;
		}

		return (string) $message . sprintf(
			'<p class="vatly-invoice-link"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Download your invoice (from Vatly)', 'vatly-pmpro' )
		);
	}

	/**
	 * @param object $invoice PMPro MemberOrder
	 */
	public static function render_invoice_bullet( $invoice ): void {
		$url = self::invoice_url_for_order( $invoice );

		if ( $url === '' ) {
			return;
		}

		printf(
			'<li class="vatly-invoice-link"><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
			esc_html__( 'Invoice:', 'vatly-pmpro' ),
			esc_url( $url ),
			esc_html__( 'View your official VAT invoice (Vatly)', 'vatly-pmpro' )
		);
	}

	/**
	 * @param object $order PMPro MemberOrder
	 */
	public static function render_admin_link( $order ): void {
		$url = self::invoice_url_for_order( $order );

		if ( $url === '' ) {
			return;
		}

		printf(
			'<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_html__( 'Vatly invoice:', 'vatly-pmpro' ),
			esc_url( $url ),
			esc_html__( 'View on Vatly', 'vatly-pmpro' )
		);
	}
}
