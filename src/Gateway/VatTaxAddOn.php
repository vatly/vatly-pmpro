<?php

declare(strict_types=1);

namespace Vatly\PMPro\Gateway;

/**
 * Keeps the PMPro VAT Tax add-on (pmpro-vat-tax) out of checkouts Vatly
 * handles, and leaves it working for every other gateway.
 *
 * Vatly is the merchant of record: it determines the customer's country,
 * validates VAT numbers and charges VAT on its hosted checkout. The add-on's
 * country and VAT number box, its validation, its tax and its order notes
 * would ask the customer twice and could disagree with Vatly.
 *
 * Per checkout request, when the gateway is Vatly:
 * - Its validation, tax and order notes are always unhooked.
 * - When Vatly is the only gateway, its checkout box, script and price
 *   wording are unhooked too.
 * - When another gateway is offered, the box and the price wording stay on
 *   the page; {@see GatewayHooks::render_gateway_toggle_script()} hides them
 *   while Vatly is the selected gateway.
 */
final class VatTaxAddOn {

	/** Wraps the add-on's price wording so the checkout script can hide it. */
	public const COST_NOTE_CLASS = 'pmprovat-cost-note';

	public static function register(): void {
		add_action( 'pmpro_checkout_preheader', [ self::class, 'bypass_for_vatly_checkout' ] );
		add_action( 'admin_notices', [ self::class, 'render_admin_notice' ] );
	}

	public static function is_active(): bool {
		return function_exists( 'pmprovat_pmpro_tax' );
	}

	public static function bypass_for_vatly_checkout(): void {
		global $gateway;

		if ( ! self::is_active() || $gateway !== GatewayHooks::SLUG ) {
			return;
		}

		remove_filter( 'pmpro_registration_checks', 'pmprovat_check_vat_fields_submission' );
		remove_filter( 'pmpro_tax', 'pmprovat_pmpro_tax' );
		remove_action( 'pmpro_added_order', 'pmprovat_pmpro_added_order' );

		if ( GatewayHooks::offers_other_gateways() ) {
			add_filter( 'pmpro_level_cost_text', [ self::class, 'wrap_cost_note' ], 11 );

			return;
		}

		remove_action( 'pmpro_checkout_after_billing_fields', 'pmprovat_pmpro_checkout_boxes' );
		remove_action( 'wp_enqueue_scripts', 'pmprovat_enqueue_scripts' );
		remove_filter( 'pmpro_level_cost_text', 'pmprovat_pmpro_level_cost_text' );
	}

	/**
	 * @param  string $cost Level cost text after the add-on appended its note.
	 */
	public static function wrap_cost_note( $cost ): string {
		$cost = (string) $cost;
		// The add-on's own string, translated in its own text domain.
		$note = __( 'VAT may be charged based on your country of residence.', 'pmpro-vat-tax' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Matches the add-on's text.

		if ( $note === '' || substr( $cost, -strlen( $note ) ) !== $note ) {
			return $cost;
		}

		return substr( $cost, 0, -strlen( $note ) )
			. '<span class="' . self::COST_NOTE_CLASS . '">' . $note . '</span>';
	}

	public static function render_admin_notice(): void {
		if ( ! self::is_active() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';

		if ( $id !== 'plugins' && strpos( $id, 'pmpro' ) === false ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'The Paid Memberships Pro VAT Tax add-on is active. Vatly calculates and charges VAT itself, so on checkouts paid with Vatly the add-on\'s country and VAT number fields, its checks, its tax and its price note are skipped. Checkouts with other gateways still use the add-on. If Vatly is your only gateway, you can deactivate the add-on.',
			'vatly-pmpro'
		);
		echo '</p></div>';
	}
}
