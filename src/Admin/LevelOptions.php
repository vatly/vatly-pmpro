<?php

declare(strict_types=1);

namespace Vatly\PMPro\Admin;

use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * Adds a "Vatly Plan ID" field to each PMPro membership level so admins can
 * map their levels onto Vatly subscription plans. The value is stored as
 * level meta under `pmpro_vatly_plan_id` (see SubscriptionRepository::LEVEL_META_PLAN_ID).
 */
final class LevelOptions {

	public static function init(): void {
		add_action( 'pmpro_membership_level_after_other_settings', [ self::class, 'render' ] );
		add_action( 'pmpro_save_membership_level', [ self::class, 'save' ] );
	}

	public static function render(): void {
		$level_id = isset( $_REQUEST['edit'] ) ? absint( $_REQUEST['edit'] ) : 0;

		$plan_id = '';
		if ( $level_id > 0 && function_exists( 'get_pmpro_membership_level_meta' ) ) {
			$plan_id = (string) get_pmpro_membership_level_meta(
				$level_id,
				SubscriptionRepository::LEVEL_META_PLAN_ID,
				true
			);
		}

		?>
		<h3 class="topborder"><?php esc_html_e( 'Vatly Plan', 'vatly-pmpro' ); ?></h3>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row" valign="top">
						<label for="pmpro_vatly_plan_id"><?php esc_html_e( 'Vatly plan ID', 'vatly-pmpro' ); ?>:</label>
					</th>
					<td>
						<input type="text" id="pmpro_vatly_plan_id" name="pmpro_vatly_plan_id" value="<?php echo esc_attr( $plan_id ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Paste the plan_xxx id from your Vatly dashboard. Required for members on this level to be billed through Vatly.', 'vatly-pmpro' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public static function save( int $level_id ): void {
		if ( $level_id <= 0 || ! function_exists( 'update_pmpro_membership_level_meta' ) ) {
			return;
		}

		$plan_id = isset( $_REQUEST['pmpro_vatly_plan_id'] )
			? sanitize_text_field( wp_unslash( (string) $_REQUEST['pmpro_vatly_plan_id'] ) )
			: '';

		update_pmpro_membership_level_meta(
			$level_id,
			SubscriptionRepository::LEVEL_META_PLAN_ID,
			$plan_id
		);
	}
}
