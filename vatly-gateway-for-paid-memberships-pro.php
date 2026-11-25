<?php
/**
 * Plugin Name:       Vatly Gateway for Paid Memberships Pro
 * Plugin URI:        https://docs.vatly.com/packages/pmpro/readme
 * Description:       Sell PMPro memberships through Vatly, the European Merchant of Record for AI, software and digital products. Vatly handles payments, tax and compliance.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
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

define( 'VATLY_PMPRO_VERSION', '0.4.0' );
define( 'VATLY_PMPRO_PLUGIN_FILE', __FILE__ );
define( 'VATLY_PMPRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VATLY_PMPRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VATLY_PMPRO_MIN_PMPRO_VERSION', '3.0' );

// The release zip ships its own vendor/. A Composer-managed site (such as
// Bedrock) autoloads the plugin and its dependencies from the site's vendor/.
$vatly_pmpro_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $vatly_pmpro_autoload ) ) {
	require_once $vatly_pmpro_autoload;
} elseif ( ! class_exists( \Vatly\PMPro\Plugin::class ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Vatly Gateway for Paid Memberships Pro is installed but Composer dependencies are missing. Run `composer install` inside the plugin directory, or install the plugin with Composer at the site level.',
				'vatly-pmpro'
			);
			echo '</p></div>';
		}
	);

	return;
}

// Boot at priority 20 — *after* PMPro's default load priority of 10. This
// guarantees the global `PMProGateway` base class exists by the time we try to
// load our `PMProGateway_vatly` subclass, regardless of plugin file ordering
// (alphabetical, MU-plugin contexts, custom loaders).
add_action(
	'plugins_loaded',
	static function () {
		\Vatly\PMPro\Plugin::instance()->boot();
	},
	20
);

register_activation_hook(
	__FILE__,
	[ \Vatly\PMPro\Install\Activator::class, 'activate' ]
);

register_deactivation_hook(
	__FILE__,
	[ \Vatly\PMPro\Install\Activator::class, 'deactivate' ]
);
