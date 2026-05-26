<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit;

use Brain\Monkey\Functions;
use Vatly\Fluent\BillableFactory;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\CustomerRepositoryInterface;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\PMPro\Container;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Container
 */
class ContainerTest extends TestCase {

	public function test_container_can_resolve_all_default_services(): void {
		// Provide a valid mock API key so VatlyApiClient::setApiKey doesn't throw.
		Functions\when('get_option')->alias(static function (string $key, $default = '') {
			$opts = [
				'vatly_pmpro_api_key_test'  => 'test_localdevkeyXXXXXXXXX',
				'pmpro_gateway_environment' => 'sandbox',
			];

			return $opts[$key] ?? $default;
		});

		$container = new Container();

		self::assertInstanceOf(ConfigurationInterface::class, $container->get(ConfigurationInterface::class));
		self::assertInstanceOf(CustomerRepositoryInterface::class, $container->get(CustomerRepositoryInterface::class));
		self::assertInstanceOf(SubscriptionRepositoryInterface::class, $container->get(SubscriptionRepositoryInterface::class));
		self::assertInstanceOf(OrderRepositoryInterface::class, $container->get(OrderRepositoryInterface::class));
		self::assertInstanceOf(WebhookCallRepositoryInterface::class, $container->get(WebhookCallRepositoryInterface::class));
		self::assertInstanceOf(EventDispatcherInterface::class, $container->get(EventDispatcherInterface::class));
		self::assertInstanceOf(BillableFactory::class, $container->get(BillableFactory::class));
		self::assertInstanceOf(WebhookProcessor::class, $container->get(WebhookProcessor::class));
	}

	public function test_container_memoises_instances(): void {
		Functions\when('get_option')->justReturn('');

		$container = new Container();

		$a = $container->get(ConfigurationInterface::class);
		$b = $container->get(ConfigurationInterface::class);

		self::assertSame($a, $b);
	}

	public function test_container_set_overrides_factory(): void {
		Functions\when('get_option')->justReturn('');

		$container = new Container();

		$custom = new class implements EventDispatcherInterface {
			public function dispatch(object $event): void {}
		};

		$container->set(EventDispatcherInterface::class, static fn() => $custom);

		self::assertSame($custom, $container->get(EventDispatcherInterface::class));
	}

	public function test_unregistered_service_throws(): void {
		$container = new Container();

		$this->expectException(\RuntimeException::class);

		$container->get('NoSuch\Service');
	}
}
