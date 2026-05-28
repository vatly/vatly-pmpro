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
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Wiring;
use Vatly\PMPro\Repositories\OrderRepository;
use Vatly\PMPro\Repositories\SubscriptionRepository;
use Vatly\PMPro\Repositories\UserMetaCustomerBindings;
use Vatly\PMPro\Repositories\WebhookCallRepository;
use Vatly\PMPro\Webhooks\EventDispatcher;
use Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted;
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
		// as the composition root. Everything else hangs off the facade.
		$this->set(
			Wiring::class,
			static fn( Container $c ): Wiring => new Wiring(
				config: $c->get( ConfigurationInterface::class ),
				subscriptions: $c->get( SubscriptionRepositoryInterface::class ),
				orders: $c->get( OrderRepositoryInterface::class ),
				webhookCalls: $c->get( WebhookCallRepositoryInterface::class ),
				events: $c->get( EventDispatcherInterface::class ),
				customerBindings: $c->get( CustomerBindingRepository::class ),
				additionalWebhookReactions: [
					new AssignMembershipLevelOnStarted( $c->get( CustomerBindingRepository::class ) ),
					new RevokeMembershipLevelOnCanceled( $c->get( CustomerBindingRepository::class ) ),
					new WriteOrderRowOnPaid(
						$c->get( CustomerBindingRepository::class ),
						$c->get( OrderRepositoryInterface::class )
					),
				],
			)
		);

		$this->set(
			Vatly::class,
			static fn( Container $c ): Vatly => new Vatly( $c->get( Wiring::class ) )
		);

		// WebhookProcessor is resolved through the facade rather than constructed
		// directly — keeps everything aligned on fluent's intended composition
		// shape and means we get any future processor changes for free.
		$this->set(
			WebhookProcessor::class,
			static fn( Container $c ): WebhookProcessor => $c->get( Vatly::class )->webhookProcessor()
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
	}
}
