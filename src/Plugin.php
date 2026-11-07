<?php

declare(strict_types=1);

namespace Vatly\PMPro;

use Vatly\Fluent\Actions\GetOrder;
use Vatly\PMPro\Admin\GatewaySettings;
use Vatly\PMPro\Admin\LevelOptions;
use Vatly\PMPro\Gateway\InvoiceSurface;
use Vatly\PMPro\Webhooks\RestRoute;

/**
 * Singleton plugin bootstrap.
 *
 * Wires the gateway driver, admin screens and webhook REST route through a
 * lightweight DI container. Kept intentionally small — most logic lives in
 * the classes below, this just orchestrates ordering of WordPress hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function reset(): void {
		self::$instance = null;
	}

	private function __construct() {
		$this->container = new Container();
	}

	public function container(): Container {
		return $this->container;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->load_textdomain();

		// The webhook listener is independent of PMPro — we want to receive and
		// audit webhooks (and tests want to assert the route exists) regardless
		// of whether PMPro is active. Reactions that touch PMPro internals
		// short-circuit themselves when PMPro is missing.
		( new RestRoute( $this->container ) )->register();

		if ( ! $this->pmpro_active() ) {
			add_action( 'admin_notices', [ $this, 'render_missing_pmpro_notice' ] );
			$this->booted = true;

			return;
		}

		// Load the global PMProGateway_vatly class file now that PMPro itself
		// is guaranteed loaded (we boot at plugins_loaded:20). We require this
		// explicitly rather than via composer's `files` autoload because the
		// latter runs immediately on plugin file load — before WP has loaded
		// PMPro — and the conditional class definition inside the file would
		// otherwise see `class_exists('PMProGateway') === false` and skip.
		require_once dirname( __DIR__ ) . '/includes/class-pmprogateway-vatly.php';

		if ( class_exists( '\PMProGateway_vatly', false ) ) {
			\PMProGateway_vatly::init( $this->container );
		}

		// Admin screens: gateway settings + level-level plan mapping.
		GatewaySettings::init();
		LevelOptions::init();

		// Surface Vatly's Merchant-of-Record invoice (and credit note) link on
		// the member- and admin-facing order views. Registered unconditionally
		// (not gated on the active gateway) so historical Vatly orders keep their
		// link even after the merchant switches gateways. Resolves fresh signed
		// links at display time via GetOrder, so it needs that action.
		( new InvoiceSurface( $this->container->get( GetOrder::class ) ) )->register();

		$this->booted = true;
	}

	private function pmpro_active(): bool {
		return defined( 'PMPRO_VERSION' ) || class_exists( 'PMProGateway' );
	}

	private function load_textdomain(): void {
		if ( ! defined( 'VATLY_PMPRO_PLUGIN_FILE' ) ) {
			return;
		}

		load_plugin_textdomain(
			'vatly-pmpro',
			false,
			dirname( plugin_basename( (string) constant( 'VATLY_PMPRO_PLUGIN_FILE' ) ) ) . '/languages'
		);
	}

	public function render_missing_pmpro_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'Vatly Gateway for Paid Memberships Pro requires Paid Memberships Pro to be installed and active.',
			'vatly-pmpro'
		);
		echo '</p></div>';
	}
}
