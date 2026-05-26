<?php
/**
 * Global `PMProGateway_vatly` class definition.
 *
 * PMPro instantiates gateway drivers as `new PMProGateway_{slug}()` in the
 * global namespace. PSR-4 cannot satisfy that, so this file lives outside of
 * `src/` and is loaded via Composer's `files` autoload directive.
 *
 * All real logic lives in {@see \Vatly\PMPro\Gateway\GatewayHooks} and
 * {@see \Vatly\PMPro\Gateway\CheckoutProcessor}. This class is a thin shim
 * over those — kept small so PMPro's introspection / supports() checks see a
 * minimal surface.
 *
 * @package Vatly\PMPro
 */

declare(strict_types=1);

// No ABSPATH guard here: this file is loaded by composer's autoloader, which
// runs before WordPress defines ABSPATH. The real guard is the class_exists()
// check below — PMProGateway only exists when PMPro is active, so the class
// body is skipped entirely outside a WP+PMPro context.

if ( ! class_exists( 'PMProGateway_vatly', false ) && class_exists( 'PMProGateway' ) ) {

	#[\AllowDynamicProperties]
	class PMProGateway_vatly extends \PMProGateway {

		private static ?\Vatly\PMPro\Container $container = null;

		/** @var string */
		public $gateway;

		public function __construct( $gateway = null ) {
			$this->gateway = (string) ( $gateway ?? 'vatly' );
		}

		public static function init( \Vatly\PMPro\Container $container ): void {
			self::$container = $container;
			\Vatly\PMPro\Gateway\GatewayHooks::register( $container );
		}

		public static function container(): \Vatly\PMPro\Container {
			if ( self::$container === null ) {
				throw new \LogicException( 'PMProGateway_vatly used before init().' );
			}

			return self::$container;
		}

		/**
		 * Add Vatly to the PMPro gateway dropdown.
		 *
		 * @param  array<string, string> $gateways
		 * @return array<string, string>
		 */
		public static function pmpro_gateways( $gateways ) {
			if ( empty( $gateways['vatly'] ) ) {
				$gateways['vatly'] = __( 'Vatly (Merchant of Record — EU VAT handled for you)', 'vatly-pmpro' );
			}

			return $gateways;
		}

		public static function get_description_for_gateway_settings() {
			return esc_html__(
				'Vatly is the merchant of record for your memberships. We handle EU VAT (every country, every rate, every recurring renewal), accept cards, PayPal, iDEAL, Apple Pay and SEPA, and issue compliant invoices. Your site shows the all-in price; we handle the VAT split.',
				'vatly-pmpro'
			);
		}

		public static function supports( $feature ) {
			$supports = [
				'subscription_sync'      => true,
				'payment_method_updates' => true,
			];

			return $supports[ $feature ] ?? false;
		}

		public static function show_settings_fields() {
			\Vatly\PMPro\Admin\GatewaySettings::render();
		}

		public static function save_settings_fields() {
			\Vatly\PMPro\Admin\GatewaySettings::save();
		}

		public function process( &$order ) {
			return \Vatly\PMPro\Gateway\CheckoutProcessor::process( self::container(), $order );
		}

		public function cancel( &$order ) {
			return \Vatly\PMPro\Gateway\CheckoutProcessor::cancel( self::container(), $order );
		}

		public function cancel_subscription( $subscription ) {
			return \Vatly\PMPro\Gateway\CheckoutProcessor::cancel_subscription( self::container(), $subscription );
		}

		public function update( &$order ) {
			return true;
		}

		public function update_subscription_info( $subscription ) {
			return \Vatly\PMPro\Gateway\CheckoutProcessor::sync_subscription( self::container(), $subscription );
		}
	}
}
