<?php

declare(strict_types=1);

namespace Vatly\PMPro\Gateway;

use Vatly\PMPro\Container;

/**
 * WordPress hook registrations for the Vatly gateway.
 *
 * Kept separate from the PMProGateway_vatly class definition so we don't have
 * to instantiate that class to register filters — PMPro lazily news up the
 * gateway when an order is processed, but we need the dropdown filter wired
 * regardless.
 */
final class GatewayHooks {

	public const SLUG = 'vatly';

	public static function register( Container $container ): void {
		add_filter( 'pmpro_gateways', [ \PMProGateway_vatly::class, 'pmpro_gateways' ] );

		// Only wire the checkout-flow filters when Vatly is the active gateway.
		$active = function_exists( 'pmpro_getGateway' ) ? pmpro_getGateway() : '';

		if ( $active === self::SLUG ) {
			// Vatly's hosted checkout collects billing and payment details. PMPro
			// should not render its own.
			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			add_filter( 'pmpro_required_billing_fields', [ self::class, 'strip_required_billing_fields' ] );
			add_filter( 'pmpro_checkout_default_submit_button', [ self::class, 'render_submit_button' ] );
		}

		// Return-from-Vatly hook — runs on every checkout page load so we can
		// pick up returns even when the active gateway has shifted between
		// requests (e.g. user changes selection).
		add_action( 'pmpro_checkout_preheader', [ self::class, 'maybe_finalize_return' ] );

		// Settings page assets.
		add_filter( 'pmpro_payment_options', [ self::class, 'pmpro_payment_options' ] );
	}

	/**
	 * Vatly captures billing details, so PMPro's required-fields gate would
	 * otherwise reject the submission for missing bfirstname etc. Strip them.
	 *
	 * @param  array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public static function strip_required_billing_fields( array $fields ): array {
		$drop = [
			'bfirstname',
			'blastname',
			'baddress1',
			'bcity',
			'bstate',
			'bzipcode',
			'bcountry',
			'bphone',
			'bemail',
			'bconfirmemail',
			'CardType',
			'AccountNumber',
			'ExpirationMonth',
			'ExpirationYear',
			'CVV',
		];

		foreach ( $drop as $key ) {
			unset( $fields[ $key ] );
		}

		return $fields;
	}

	public static function render_submit_button( bool $show ): bool {
		global $gateway, $pmpro_requirebilling;

		if ( $gateway !== self::SLUG || ! $pmpro_requirebilling ) {
			return $show;
		}

		?>
		<span id="pmpro_vatly_checkout">
			<input type="hidden" name="submit-checkout" value="1" />
			<button type="submit"
				id="pmpro_btn-submit-vatly"
				class="<?php echo esc_attr( function_exists( 'pmpro_get_element_class' ) ? pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout' ) : 'pmpro_btn pmpro_btn-submit-checkout' ); ?>">
				<?php esc_html_e( 'Continue to Vatly checkout', 'vatly-pmpro' ); ?>
			</button>
		</span>
		<?php

		return false;
	}

	public static function maybe_finalize_return(): void {
		// Only act when the user is returning from Vatly's hosted checkout.
		if ( empty( $_GET['vatly_checkout'] ) || empty( $_GET['pmpro_order'] ) ) {
			return;
		}

		// Defense against attackers crafting arbitrary `?pmpro_order=...` URLs to
		// retrigger our flow: refuse to auto-submit unless the referenced order
		// actually exists, is a Vatly order in `token` status, and has a stored
		// Vatly checkout id (i.e. we did send the user to Vatly for this order).
		$order_code = sanitize_text_field( wp_unslash( (string) $_GET['pmpro_order'] ) );

		if ( ! self::is_legitimate_vatly_return( $order_code ) ) {
			return;
		}

		// Re-trigger PMPro's checkout submission so process() runs and verifies.
		// Vatly's API is still the source of truth — we only finalize the order
		// to `success` if Vatly confirms the checkout is `paid`.
		$_REQUEST['submit-checkout']      = 1;
		$_REQUEST['pmpro_checkout_nonce'] = wp_create_nonce( 'pmpro_checkout_nonce' );
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function is_legitimate_vatly_return( string $order_code ): bool {
		if ( $order_code === '' ) {
			return false;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, gateway, status FROM {$wpdb->prefix}pmpro_membership_orders
					WHERE code = %s LIMIT 1",
				$order_code
			)
		);

		if ( ! $row || $row->gateway !== self::SLUG || $row->status !== 'token' ) {
			return false;
		}

		if ( ! function_exists( 'get_pmpro_membership_order_meta' ) ) {
			return false;
		}

		$checkout_id = (string) get_pmpro_membership_order_meta(
			(int) $row->id,
			\Vatly\PMPro\Gateway\CheckoutProcessor::ORDER_META_CHECKOUT_ID,
			true
		);

		return $checkout_id !== '';
	}

	/**
	 * Declare the option keys this gateway persists. PMPro uses this to know
	 * what to save when admins hit "Save Settings".
	 *
	 * @param  array<int, string> $options
	 * @return array<int, string>
	 */
	public static function pmpro_payment_options( array $options ): array {
		return array_merge(
			[
				\Vatly\PMPro\Configuration::OPTION_API_KEY,
				\Vatly\PMPro\Configuration::OPTION_API_KEY_TEST,
				\Vatly\PMPro\Configuration::OPTION_API_URL,
				\Vatly\PMPro\Configuration::OPTION_WEBHOOK_SECRET,
				\Vatly\PMPro\Configuration::OPTION_TESTMODE,
			],
			$options
		);
	}
}
