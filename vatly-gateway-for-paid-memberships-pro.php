<?php
/**
 * Plugin Name:       Vatly Gateway for Paid Memberships Pro
 * Plugin URI:        https://vatly.com/integrations/paid-memberships-pro
 * Description:       Sell PMPro memberships through Vatly — the merchant of record that handles EU VAT, payment methods (cards, PayPal, iDEAL, Apple Pay, SEPA) and recurring billing for you.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Vatly
 * Author URI:        https://vatly.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vatly-pmpro
 * Domain Path:       /languages
 *
 * @package Vatly\PMPro
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'VATLY_PMPRO_VERSION', '0.1.0' );
define( 'VATLY_PMPRO_PLUGIN_FILE', __FILE__ );
define( 'VATLY_PMPRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VATLY_PMPRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VATLY_PMPRO_MIN_PMPRO_VERSION', '3.0' );

$vatly_pmpro_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $vatly_pmpro_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Vatly Gateway for Paid Memberships Pro is installed but Composer dependencies are missing. Run `composer install` inside the plugin directory.',
				'vatly-pmpro'
			);
			echo '</p></div>';
		}
	);

	return;
}

require_once $vatly_pmpro_autoload;

add_action(
	'plugins_loaded',
	static function () {
		\Vatly\PMPro\Plugin::instance()->boot();
	},
	5
);

register_activation_hook(
	__FILE__,
	[ \Vatly\PMPro\Install\Activator::class, 'activate' ]
);

register_deactivation_hook(
	__FILE__,
	[ \Vatly\PMPro\Install\Activator::class, 'deactivate' ]
);
