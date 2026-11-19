<?php

declare(strict_types=1);

namespace Vatly\PMPro\Admin;

use Vatly\PMPro\Configuration;
use Vatly\PMPro\Secrets;
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
		$api_url     = (string) get_option( Configuration::OPTION_API_URL, '' );
		$webhook_url = RestRoute::url();

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
					<td><?php self::secret_input( Configuration::OPTION_API_KEY ); ?></td>
				</tr>
				<tr>
					<th><label for="vatly_pmpro_api_key_test"><?php esc_html_e( 'Test API key', 'vatly-pmpro' ); ?></label></th>
					<td><?php self::secret_input( Configuration::OPTION_API_KEY_TEST ); ?></td>
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
						<?php self::secret_input( Configuration::OPTION_WEBHOOK_SECRET ); ?>
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

	/**
	 * A password field that never prints the stored value. Left blank, the
	 * saved value is kept.
	 */
	private static function secret_input( string $option ): void {
		$saved = (string) get_option( $option, '' ) !== '';
		?>
		<input type="password" id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $saved ? esc_attr__( 'Saved. Leave blank to keep it.', 'vatly-pmpro' ) : ''; ?>" />
		<?php
	}

	public static function save(): void {
		// The API keys and webhook secret are stored encrypted, and a blank
		// field keeps the saved value, since the form never prints it.
		foreach ( Secrets::OPTIONS as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = trim( sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ) );
			if ( $value !== '' ) {
				Secrets::store( $key, $value );
			}
		}

		$keys = [
			Configuration::OPTION_API_URL,
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
