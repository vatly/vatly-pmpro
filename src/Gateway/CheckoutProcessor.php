<?php

declare(strict_types=1);

namespace Vatly\PMPro\Gateway;

use Vatly\API\Resources\Checkout as ApiCheckout;
use Vatly\Fluent\Actions\CancelSubscription;
use Vatly\Fluent\Actions\GetCheckout;
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Fluent\BillableFactory;
use Vatly\PMPro\Container;
use Vatly\PMPro\Repositories\CustomerRepository;
use Vatly\PMPro\Repositories\SubscriptionRepository;

/**
 * Where the actual checkout / cancel / sync work happens for the Vatly
 * gateway driver. Kept stateless and DI-friendly so the gateway class itself
 * stays a thin shim.
 */
final class CheckoutProcessor {

	public const ORDER_META_CHECKOUT_ID = 'vatly_checkout_id';

	/**
	 * Drive the order through the Vatly hosted-checkout flow.
	 *
	 * Phase 1 (no checkout id on the order):
	 *  - Resolve/create the user's Vatly customer.
	 *  - Build a Vatly checkout for the membership level's mapped plan.
	 *  - Persist the checkout id on the order, set status=token, save.
	 *  - wp_redirect() to Vatly's hosted checkout. Halts execution.
	 *
	 * Phase 2 (return from Vatly, checkout id present):
	 *  - Fetch the checkout from Vatly.
	 *  - If it's `paid`, flip the order to `success` and return true so
	 *    PMPro proceeds with level assignment.
	 *  - If it's `expired` or `canceled`, set an error.
	 *  - Otherwise, keep status=token and return true so the user sees a
	 *    "pending" confirmation page — the webhook will catch up.
	 */
	public static function process( Container $container, $order ): bool {
		$checkout_id = self::get_existing_checkout_id( $order );

		if ( $checkout_id === '' ) {
			return self::start_hosted_checkout( $container, $order );
		}

		return self::finalize_from_return( $container, $order, $checkout_id );
	}

	private static function start_hosted_checkout( Container $container, $order ): bool {
		// PMPro guarantees $order->user_id is populated by the time it calls
		// gateway::process() — for both logged-in members AND guests (PMPro
		// creates the WP user just before this point via
		// pmpro_checkout_create_user()). This defensive fallback only kicks
		// in for pathological flows (CLI tooling, third-party plugins that
		// re-enter the gateway chain) where user_id genuinely is missing.
		$user = self::resolve_user( $order );

		if ( ! $user ) {
			$order->error      = __( 'Could not start checkout — the order is missing a user. Please refresh and try again.', 'vatly-pmpro' );
			$order->shorterror = __( 'Order user missing.', 'vatly-pmpro' );

			return false;
		}

		/** @var CustomerRepository $customers */
		$customers = $container->get( \Vatly\Fluent\Contracts\CustomerRepositoryInterface::class );
		/** @var BillableFactory $factory */
		$factory = $container->get( BillableFactory::class );

		$billable_user = $customers->forUser( $user );
		$billable      = $factory->forOwner( $billable_user );

		// Make sure the Vatly customer exists (idempotent).
		$billable->createOrGetVatlyCustomer(
			array_filter(
				[
					'email' => $user->user_email,
					'name'  => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
				]
			)
		);

		$plan_id = self::plan_id_for_level( (int) $order->membership_id );

		if ( $plan_id === '' ) {
			$order->error      = __( 'This membership level is not yet mapped to a Vatly plan. Please configure the plan id on the level edit screen.', 'vatly-pmpro' );
			$order->shorterror = $order->error;

			return false;
		}

		// Save the PMPro order early so we have a code to round-trip with.
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}
		$order->status  = 'token';
		$order->gateway = 'vatly';
		$order->saveOrder();

		$return_url = add_query_arg(
			[
				'vatly_checkout' => 'return',
				'pmpro_order'    => $order->code,
			],
			pmpro_url( 'checkout' )
		);

		$cancel_url = add_query_arg(
			[
				'vatly_checkout' => 'cancel',
				'pmpro_order'    => $order->code,
			],
			pmpro_url( 'levels' )
		);

		$checkout = $billable
			->subscribe()
			->toPlan( $plan_id )
			->withRedirectUrlSuccess( $return_url )
			->withRedirectUrlCanceled( $cancel_url )
			->create(
				[
					'metadata' => [
						'pmpro_order_code'    => $order->code,
						'pmpro_level_id'      => (int) $order->membership_id,
						'wp_user_id'          => (int) $user->ID,
					],
				]
			);

		// Persist Vatly's checkout id on the order via order meta.
		self::set_existing_checkout_id( $order, $checkout->id );

		$href = self::checkout_href( $checkout );

		if ( $href === '' ) {
			$order->error      = __( 'Vatly did not return a hosted checkout URL.', 'vatly-pmpro' );
			$order->shorterror = $order->error;

			return false;
		}

		wp_redirect( $href );
		exit;
	}

	private static function finalize_from_return( Container $container, $order, string $checkout_id ): bool {
		/** @var GetCheckout $action */
		$action = $container->get( GetCheckout::class );

		try {
			$checkout = $action->execute( $checkout_id );
		} catch ( \Throwable $e ) {
			// API failed — let PMPro keep the token-status order; webhook will
			// catch up. Show a friendly message to the user.
			$order->error      = __( "We couldn't confirm your payment with Vatly just yet. We'll keep checking — refresh in a moment.", 'vatly-pmpro' );
			$order->shorterror = __( 'Awaiting Vatly confirmation.', 'vatly-pmpro' );

			return false;
		}

		$status = (string) ( $checkout->status ?? '' );

		if ( $status === 'paid' || $status === 'completed' ) {
			$order->payment_transaction_id = (string) ( $checkout->orderId ?? $checkout_id );
			$order->status                 = 'success';
			$order->saveOrder();

			return true;
		}

		if ( in_array( $status, [ 'canceled', 'cancelled', 'expired', 'failed' ], true ) ) {
			$order->status     = 'error';
			$order->error      = __( 'The Vatly checkout was canceled or expired. Please try again.', 'vatly-pmpro' );
			$order->shorterror = __( 'Checkout canceled.', 'vatly-pmpro' );
			$order->saveOrder();

			return false;
		}

		// Still pending. CRITICAL: return false here, not true. PMPro's
		// checkout preheader does `$pmpro_confirmed = true` whenever process()
		// returns truthy, then calls pmpro_complete_checkout() which assigns
		// the membership level — before Vatly has confirmed payment. Returning
		// false keeps the order in `token` status and surfaces the message
		// below to the user. The webhook will independently assign the level
		// via AssignMembershipLevelOnStarted when it arrives; the user can
		// refresh and process() will re-check Vatly and complete normally.
		$order->error      = __( "Your Vatly payment is still being confirmed. Please refresh this page in a moment — we'll complete your membership as soon as the payment settles.", 'vatly-pmpro' );
		$order->shorterror = __( 'Awaiting Vatly confirmation.', 'vatly-pmpro' );

		return false;
	}

	public static function cancel( Container $container, $order ): bool {
		$transaction_id = (string) ( $order->subscription_transaction_id ?? '' );

		if ( $transaction_id === '' ) {
			return true;
		}

		/** @var CancelSubscription $action */
		$action = $container->get( CancelSubscription::class );

		try {
			$action->execute( $transaction_id );
		} catch ( \Throwable $e ) {
			$order->error = $e->getMessage();

			return false;
		}

		return true;
	}

	/**
	 * @param object $subscription PMPro_Subscription
	 */
	public static function cancel_subscription( Container $container, $subscription ): bool {
		$transaction_id = method_exists( $subscription, 'get_subscription_transaction_id' )
			? (string) $subscription->get_subscription_transaction_id()
			: '';

		if ( $transaction_id === '' ) {
			return true;
		}

		/** @var CancelSubscription $action */
		$action = $container->get( CancelSubscription::class );

		try {
			$action->execute( $transaction_id );
		} catch ( \Throwable $e ) {
			return false;
		}

		return true;
	}

	/**
	 * @param object $subscription PMPro_Subscription
	 */
	public static function sync_subscription( Container $container, $subscription ) {
		$transaction_id = method_exists( $subscription, 'get_subscription_transaction_id' )
			? (string) $subscription->get_subscription_transaction_id()
			: '';

		if ( $transaction_id === '' ) {
			return null;
		}

		/** @var GetSubscription $action */
		$action = $container->get( GetSubscription::class );

		try {
			$action->execute( $transaction_id );
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}

		return null;
	}

	private static function plan_id_for_level( int $level_id ): string {
		if ( $level_id === 0 || ! function_exists( 'get_pmpro_membership_level_meta' ) ) {
			return '';
		}

		$plan_id = get_pmpro_membership_level_meta(
			$level_id,
			SubscriptionRepository::LEVEL_META_PLAN_ID,
			true
		);

		return (string) $plan_id;
	}

	private static function get_existing_checkout_id( $order ): string {
		if ( ! empty( $order->vatly_checkout_id ) ) {
			return (string) $order->vatly_checkout_id;
		}

		if ( empty( $order->id ) || ! function_exists( 'get_pmpro_membership_order_meta' ) ) {
			return '';
		}

		$value = get_pmpro_membership_order_meta(
			(int) $order->id,
			self::ORDER_META_CHECKOUT_ID,
			true
		);

		return (string) $value;
	}

	private static function set_existing_checkout_id( $order, string $checkout_id ): void {
		$order->vatly_checkout_id = $checkout_id;

		if ( empty( $order->id ) || ! function_exists( 'update_pmpro_membership_order_meta' ) ) {
			return;
		}

		update_pmpro_membership_order_meta(
			(int) $order->id,
			self::ORDER_META_CHECKOUT_ID,
			$checkout_id
		);
	}

	/**
	 * @return \WP_User|null
	 */
	private static function resolve_user( $order ) {
		$user_id = (int) ( $order->user_id ?? 0 );

		if ( $user_id === 0 && function_exists( 'wp_get_current_user' ) ) {
			$current = wp_get_current_user();
			if ( (int) $current->ID > 0 ) {
				$user_id = (int) $current->ID;
			}
		}

		if ( $user_id === 0 ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );

		return $user ?: null;
	}

	private static function checkout_href( ApiCheckout $checkout ): string {
		if ( isset( $checkout->href ) && is_string( $checkout->href ) ) {
			return $checkout->href;
		}

		// Newer API resources may expose the URL under `_links->checkout->href`
		// or `url`. Defensively probe both.
		if ( isset( $checkout->url ) && is_string( $checkout->url ) ) {
			return (string) $checkout->url;
		}

		if ( isset( $checkout->_links->checkout->href ) ) {
			return (string) $checkout->_links->checkout->href;
		}

		return '';
	}
}
