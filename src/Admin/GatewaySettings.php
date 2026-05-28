<?php

declare(strict_types=1);

namespace Vatly\PMPro\Admin;

use Vatly\PMPro\Configuration;
use Vatly\PMPro\Webhooks\RestRoute;

/**
 * Settings UI for the Vatly gateway on PMPro's Payments page.
 *
 * Rendered both via PMPro 3.5's `show_settings_fields()` static call and via
 * the older `pmpro_payment_option_fields` filter for back-compat.
 */
final class GatewaySettings {

	public static function init(): void {
		add_filter( 'pmpro_payment_option_fields', [ self::class, 'legacy_fields' ], 10, 2 );
	}

	public static function render(): void {
		$api_key      = (string) get_option( Configuration::OPTION_API_KEY, '' );
		$api_key_test = (string) get_option( Configuration::OPTION_API_KEY_TEST, '' );
		$api_url      = (string) get_option( Configuration::OPTION_API_URL, '' );
		$secret       = (string) get_option( Configuration::OPTION_WEBHOOK_SECRET, '' );
		$webhook_url  = RestRoute::url();

		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th colspan="2">
						<h2 class="title"><?php esc_html_e( 'Vatly Settings', 'vatly-pmpro' ); ?></h2>
					</th>
				</tr>
				<tr>
					<th><label for="vatly_pmpro_api_key"><?php esc_html_e( 'Live API key', 'vatly-pmpro' ); ?></label></th>
					<td><input type="text" id="vatly_pmpro_api_key" name="<?php echo esc_attr( Configuration::OPTION_API_KEY ); ?>" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th><label for="vatly_pmpro_api_key_test"><?php esc_html_e( 'Test API key', 'vatly-pmpro' ); ?></label></th>
					<td><input type="text" id="vatly_pmpro_api_key_test" name="<?php echo esc_attr( Configuration::OPTION_API_KEY_TEST ); ?>" value="<?php echo esc_attr( $api_key_test ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th><label for="vatly_pmpro_api_url"><?php esc_html_e( 'API endpoint', 'vatly-pmpro' ); ?></label></th>
					<td>
						<input type="url" id="vatly_pmpro_api_url" name="<?php echo esc_attr( Configuration::OPTION_API_URL ); ?>" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Configuration::DEFAULT_API_URL ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to use the default Vatly endpoint.', 'vatly-pmpro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="vatly_pmpro_webhook_secret"><?php esc_html_e( 'Webhook signing secret', 'vatly-pmpro' ); ?></label></th>
					<td>
						<input type="text" id="vatly_pmpro_webhook_secret" name="<?php echo esc_attr( Configuration::OPTION_WEBHOOK_SECRET ); ?>" value="<?php echo esc_attr( $secret ); ?>" class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'The signing secret shown in your Vatly dashboard for this webhook.', 'vatly-pmpro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Webhook URL', 'vatly-pmpro' ); ?></th>
					<td>
						<code><?php echo esc_html( $webhook_url ); ?></code>
						<p class="description"><?php esc_html_e( 'Paste this URL into your Vatly dashboard under Developer → Webhooks.', 'vatly-pmpro' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public static function save(): void {
		$keys = [
			Configuration::OPTION_API_KEY,
			Configuration::OPTION_API_KEY_TEST,
			Configuration::OPTION_API_URL,
			Configuration::OPTION_WEBHOOK_SECRET,
			Configuration::OPTION_TESTMODE,
		];

		foreach ( $keys as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $key ] );

			if ( $key === Configuration::OPTION_API_URL ) {
				$value = esc_url_raw( (string) $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}

			update_option( $key, $value );
		}
	}

	/**
	 * Back-compat shim for PMPro versions that still use the filter.
	 *
	 * @param  mixed $values
	 * @param  mixed $gateway
	 */
	/**
	 * @param  mixed $values
	 * @param  mixed $gateway
	 * @return mixed
	 */
	public static function legacy_fields( $values, $gateway ) {
		if ( $gateway === 'vatly' ) {
			self::render();
		}

		return $values;
	}
}
