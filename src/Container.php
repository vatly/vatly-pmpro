<?php

declare(strict_types=1);

namespace Vatly\PMPro;

use Vatly\API\VatlyApiClient;
use Vatly\Fluent\Actions\CancelSubscription;
use Vatly\Fluent\Actions\CreateCheckout;
use Vatly\Fluent\Actions\CreateCustomer;
use Vatly\Fluent\Actions\GetCheckout;
use Vatly\Fluent\Actions\GetCustomer;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Actions\GetSubscription;
use Vatly\Fluent\Actions\SwapSubscriptionPlan;
use Vatly\Fluent\Actions\UpdateSubscriptionBilling;
use Vatly\Fluent\BillableFactory;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Webhooks\WebhookProcessorFactory;
use Vatly\PMPro\Repositories\CustomerRepository;
use Vatly\PMPro\Repositories\OrderRepository;
use Vatly\PMPro\Repositories\SubscriptionRepository;
use Vatly\PMPro\Repositories\WebhookCallRepository;
use Vatly\PMPro\Webhooks\EventDispatcher;
use Vatly\PMPro\Webhooks\Reactions\AssignMembershipLevelOnStarted;
use Vatly\PMPro\Webhooks\Reactions\RevokeMembershipLevelOnCanceled;
use Vatly\PMPro\Webhooks\Reactions\WriteOrderRowOnPaid;

/**
 * Tiny lazy-init service container.
 *
 * Avoids pulling in a full DI library — this plugin has a small graph and
 * everything is keyed by class name. Each ::get() memoises so the per-request
 * cost stays minimal.
 */
class Container {

	/** @var array<string, mixed> */
	private array $instances = [];

	/** @var array<string, callable> */
	private array $factories = [];

	public function __construct() {
		$this->register_defaults();
	}

	/**
	 * Override or extend a service. Useful for tests.
	 */
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
		// Configuration is option-backed; cheap to instantiate.
		$this->set(
			ConfigurationInterface::class,
			static fn(): Configuration => new Configuration()
		);

		// Low-level Vatly API client, configured from settings.
		$this->set(
			VatlyApiClient::class,
			static function ( Container $c ): VatlyApiClient {
				/** @var ConfigurationInterface $config */
				$config = $c->get( ConfigurationInterface::class );
				$client = new VatlyApiClient();
				if ( $config->getApiKey() !== '' ) {
					$client->setApiKey( $config->getApiKey() );
				}
				if ( $config->getApiUrl() !== '' ) {
					$client->setApiEndpoint( $config->getApiUrl() );
				}
				if ( $config->getApiVersion() !== '' ) {
					$client->setApiVersion( $config->getApiVersion() );
				}

				return $client;
			}
		);

		// Repositories backed by PMPro tables / WP options.
		$this->set(
			CustomerRepositoryInterface::class,
			static fn(): CustomerRepositoryInterface => new CustomerRepository()
		);
		$this->set(
			SubscriptionRepositoryInterface::class,
			static fn( Container $c ): SubscriptionRepositoryInterface => new SubscriptionRepository(
				$c->get( CustomerRepositoryInterface::class )
			)
		);
		$this->set(
			OrderRepositoryInterface::class,
			static fn( Container $c ): OrderRepositoryInterface => new OrderRepository(
				$c->get( CustomerRepositoryInterface::class )
			)
		);
		$this->set(
			WebhookCallRepositoryInterface::class,
			static fn(): WebhookCallRepositoryInterface => new WebhookCallRepository()
		);

		// Event dispatcher bridges fluent events to WordPress do_action().
		$this->set(
			EventDispatcherInterface::class,
			static fn(): EventDispatcherInterface => new EventDispatcher()
		);

		// Fluent Actions.
		foreach ( [
			CreateCheckout::class,
			CreateCustomer::class,
			GetCheckout::class,
			GetCustomer::class,
			GetOrder::class,
			GetSubscription::class,
			SwapSubscriptionPlan::class,
			CancelSubscription::class,
			UpdateSubscriptionBilling::class,
		] as $action_class ) {
			$this->set(
				$action_class,
				static fn( Container $c ) => new $action_class( $c->get( VatlyApiClient::class ) )
			);
		}

		// Billable factory.
		$this->set(
			BillableFactory::class,
			static fn( Container $c ): BillableFactory => new BillableFactory(
				subscriptions: $c->get( SubscriptionRepositoryInterface::class ),
				customers: $c->get( CustomerRepositoryInterface::class ),
				orders: $c->get( OrderRepositoryInterface::class ),
				config: $c->get( ConfigurationInterface::class ),
				createCheckoutAction: $c->get( CreateCheckout::class ),
				createCustomerAction: $c->get( CreateCustomer::class ),
				getCustomerAction: $c->get( GetCustomer::class ),
				getSubscriptionAction: $c->get( GetSubscription::class ),
				swapSubscriptionPlanAction: $c->get( SwapSubscriptionPlan::class ),
				cancelSubscriptionAction: $c->get( CancelSubscription::class ),
				updateBillingAction: $c->get( UpdateSubscriptionBilling::class ),
			)
		);

		// Webhook processor (includes built-in + PMPro reactions).
		$this->set(
			WebhookProcessor::class,
			static fn( Container $c ): WebhookProcessor => WebhookProcessorFactory::create(
				config: $c->get( ConfigurationInterface::class ),
				subscriptions: $c->get( SubscriptionRepositoryInterface::class ),
				orders: $c->get( OrderRepositoryInterface::class ),
				webhookCalls: $c->get( WebhookCallRepositoryInterface::class ),
				dispatcher: $c->get( EventDispatcherInterface::class ),
				getOrder: $c->get( GetOrder::class ),
				additionalReactions: [
					new AssignMembershipLevelOnStarted(
						$c->get( CustomerRepositoryInterface::class )
					),
					new RevokeMembershipLevelOnCanceled(
						$c->get( CustomerRepositoryInterface::class )
					),
					new WriteOrderRowOnPaid(
						$c->get( CustomerRepositoryInterface::class ),
						$c->get( OrderRepositoryInterface::class ),
						$c->get( SubscriptionRepositoryInterface::class )
					),
				],
			)
		);
	}
}
