<?php

declare(strict_types=1);

namespace Vatly\PMPro;

use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Actions\CancelSubscription;
use Vatly\Fluent\Actions\GetCheckout;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\Reactions\CancelSubscriptionOnCanceled;
use Vatly\Fluent\Webhooks\Reactions\ResumeSubscriptionOnResumed;
use Vatly\Fluent\Webhooks\Reactions\SyncSubscriptionOnBillingUpdated;
use Vatly\Fluent\Webhooks\Reactions\SyncSubscriptionOnStarted;
use Vatly\Fluent\Webhooks\WebhookEventFactory;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Wiring;
use Vatly\PMPro\Repositories\OrderRepository;
use Vatly\PMPro\Repositories\SubscriptionRepository;
use Vatly\PMPro\Repositories\UserMetaCustomerBindings;
use Vatly\PMPro\Repositories\WebhookCallRepository;
use Vatly\PMPro\Webhooks\EventDispatcher;
use Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted;
use Vatly\PMPro\Webhooks\Reactions\PMProStoreOrderOnPaid;
use Vatly\PMPro\Webhooks\Reactions\ReinstateMembershipLevelOnResumed;
use Vatly\PMPro\Webhooks\Reactions\RevokeMembershipLevelOnCanceled;
use Vatly\PMPro\Webhooks\Reactions\WriteOrderRowOnPaid;

/**
 * Tiny lazy-init service container.
 *
 * fluent v0.8 introduced its own composition root ({@see Vatly}) constructed
 * from a {@see Wiring} DTO. Our container just builds the Wiring from
 * PMPro-side concretes (option-backed Configuration, $wpdb-backed
 * repositories, do_action-backed event dispatcher), constructs the Vatly
 * facade once and memoises it. Everything else — actions, builders, the
 * `WebhookProcessor`, the customer service — is resolved lazily through
 * the Vatly facade.
 */
class Container {

	/** @var array<string, mixed> */
	private array $instances = [];

	/** @var array<string, callable> */
	private array $factories = [];

	public function __construct() {
		$this->register_defaults();
	}

	/** Override or extend a service. Useful for tests. */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service.
	 *
	 * @template T of object
	 * @param  class-string<T> $id
	 * @return T
	 */
	public function get( string $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( "Service {$id} is not registered" );
		}

		$instance = ( $this->factories[ $id ] )( $this );

		$this->instances[ $id ] = $instance;

		return $instance;
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	private function register_defaults(): void {
		// Configuration — option-backed, cheap to instantiate.
		$this->set(
			ConfigurationInterface::class,
			static fn(): Configuration => new Configuration()
		);

		// Driver-side concrete repositories + dispatcher.
		$this->set(
			CustomerBindingRepository::class,
			static fn(): CustomerBindingRepository => new UserMetaCustomerBindings()
		);
		$this->set(
			SubscriptionRepositoryInterface::class,
			static fn(): SubscriptionRepositoryInterface => new SubscriptionRepository()
		);
		$this->set(
			OrderRepositoryInterface::class,
			static fn(): OrderRepositoryInterface => new OrderRepository()
		);
		$this->set(
			WebhookCallRepositoryInterface::class,
			static fn(): WebhookCallRepositoryInterface => new WebhookCallRepository()
		);
		$this->set(
			EventDispatcherInterface::class,
			static fn(): EventDispatcherInterface => new EventDispatcher()
		);

		// Wiring + Vatly facade. fluent v0.8 wants a single Wiring DTO that
		// bundles every driver-supplied contract, and constructs Vatly once
		// as the composition root. Customer-facing flows (builders, customer
		// service) all hang off the facade. The webhook processor below is
		// the one place we deviate — see comment there.
		$this->set(
			Wiring::class,
			static fn( Container $c ): Wiring => new Wiring(
				config: $c->get( ConfigurationInterface::class ),
				subscriptions: $c->get( SubscriptionRepositoryInterface::class ),
				orders: $c->get( OrderRepositoryInterface::class ),
				webhookCalls: $c->get( WebhookCallRepositoryInterface::class ),
				events: $c->get( EventDispatcherInterface::class ),
				customerBindings: $c->get( CustomerBindingRepository::class ),
			)
		);

		$this->set(
			Vatly::class,
			static fn( Container $c ): Vatly => new Vatly( $c->get( Wiring::class ) )
		);

		// We construct WebhookProcessor directly rather than going through
		// `Vatly::webhookProcessor()`. The facade hardcodes fluent's built-in
		// `StoreOrderOnPaid`, which uses a user-keyed time-window heuristic to
		// reconcile token orders with paid-order webhooks. That heuristic
		// misroutes the webhook when the same member has multiple open
		// checkouts (tab A + tab B). We replace it with our own
		// `PMProStoreOrderOnPaid`, which uses the `pmpro_order_code` from
		// the Vatly order's metadata for precise routing.
		$this->set(
			WebhookProcessor::class,
			static function ( Container $c ): WebhookProcessor {
				$config           = $c->get( ConfigurationInterface::class );
				$subscriptions    = $c->get( SubscriptionRepositoryInterface::class );
				$orders           = $c->get( OrderRepositoryInterface::class );
				$bindings         = $c->get( CustomerBindingRepository::class );
				$dispatcher       = $c->get( EventDispatcherInterface::class );
				$webhookCalls     = $c->get( WebhookCallRepositoryInterface::class );
				$get_order        = $c->get( GetOrder::class );
				$get_subscription = $c->get( GetSubscription::class );

				return new WebhookProcessor(
					// fluent v0.8.0-alpha.6 made GetSubscription a required
					// second arg: `subscription.started` is enriched via the
					// API so the dispatched event carries the mandate (payment
					// method on file). Enrichment is best-effort — a transient
					// failure falls back to the webhook payload.
					eventFactory: new WebhookEventFactory( $get_order, $get_subscription ),
					repository: $webhookCalls,
					dispatcher: $dispatcher,
					webhookSecret: $config->getWebhookSecret() ?? '',
					reactions: [
						// Built-in fluent reactions we keep as-is.
						new SyncSubscriptionOnStarted( $subscriptions, $bindings, $dispatcher ),
						// Keeps the locally-stored mandate in step when a
						// customer changes their payment method via Vatly's
						// hosted billing-update flow (subscription.billing_updated).
						new SyncSubscriptionOnBillingUpdated( $subscriptions ),
						new CancelSubscriptionOnCanceled( $subscriptions ),
						// Resume (subscription.resumed) is the inverse of cancel:
						// fluent clears the record's endsAt, our PMPro reaction
						// reinstates the membership level (clearing the
						// scheduled grace-period expiration).
						new ResumeSubscriptionOnResumed( $subscriptions ),
						// PMPro-specific replacement for fluent's StoreOrderOnPaid.
						new PMProStoreOrderOnPaid( $orders, $bindings, $get_order ),
						// PMPro-specific level + subscription wiring.
						new AssignMembershipLevelOnStarted( $bindings ),
						new RevokeMembershipLevelOnCanceled( $bindings ),
						new ReinstateMembershipLevelOnResumed( $bindings, $subscriptions ),
						new WriteOrderRowOnPaid( $bindings, $orders ),
					],
				);
			}
		);

		// Action shortcuts. fluent exposes most via accessors on the facade,
		// but registering them here lets CheckoutProcessor resolve uniformly
		// and lets tests swap any single action without mocking the whole
		// Vatly facade. GetCheckout in particular has no facade accessor.
		$this->set(
			GetCheckout::class,
			static fn( Container $c ): GetCheckout => new GetCheckout( $c->get( Vatly::class )->getApiClient() )
		);
		$this->set(
			CancelSubscription::class,
			static fn( Container $c ): CancelSubscription => $c->get( Vatly::class )->cancelSubscription()
		);
		$this->set(
			GetSubscription::class,
			static fn( Container $c ): GetSubscription => $c->get( Vatly::class )->getSubscription()
		);
		$this->set(
			GetOrder::class,
			static fn( Container $c ): GetOrder => $c->get( Vatly::class )->getOrder()
		);
	}
}
