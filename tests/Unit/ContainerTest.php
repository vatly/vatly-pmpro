<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit;

use Brain\Monkey\Functions;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\CustomerBindingRepository;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Actions\GetChargeback;
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Wiring;
use Vatly\PMPro\Container;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\SuspendMembershipOnChargeback;

/**
 * @covers \Vatly\PMPro\Container
 */
class ContainerTest extends TestCase {

	public function test_container_resolves_every_default_service(): void {
		// VatlyApiClient::setApiKey validates the format, so feed a plausible
		// test key when the api client wakes up inside the Vatly composition root.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$opts = [
					'vatly_pmpro_api_key_test'  => 'test_localdevkeyXXXXXXXXX',
					'pmpro_gateway_environment' => 'sandbox',
				];

				return $opts[ $key ] ?? $default;
			}
		);

		$container = new Container();

		self::assertInstanceOf( ConfigurationInterface::class, $container->get( ConfigurationInterface::class ) );
		self::assertInstanceOf( CustomerBindingRepository::class, $container->get( CustomerBindingRepository::class ) );
		self::assertInstanceOf( SubscriptionRepositoryInterface::class, $container->get( SubscriptionRepositoryInterface::class ) );
		self::assertInstanceOf( OrderRepositoryInterface::class, $container->get( OrderRepositoryInterface::class ) );
		self::assertInstanceOf( WebhookCallRepositoryInterface::class, $container->get( WebhookCallRepositoryInterface::class ) );
		self::assertInstanceOf( EventDispatcherInterface::class, $container->get( EventDispatcherInterface::class ) );
		self::assertInstanceOf( Wiring::class, $container->get( Wiring::class ) );
		self::assertInstanceOf( Vatly::class, $container->get( Vatly::class ) );
		self::assertInstanceOf( WebhookProcessor::class, $container->get( WebhookProcessor::class ) );
		self::assertInstanceOf( GetChargeback::class, $container->get( GetChargeback::class ) );
	}

	public function test_webhook_processor_wires_chargeback_membership_reaction(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$opts = [
					'vatly_pmpro_api_key_test'  => 'test_localdevkeyXXXXXXXXX',
					'pmpro_gateway_environment' => 'sandbox',
				];

				return $opts[ $key ] ?? $default;
			}
		);

		$container = new Container();
		$processor = $container->get( WebhookProcessor::class );

		$has_chargeback_reaction = false;
		foreach ( $processor->getReactions() as $reaction ) {
			if ( $reaction instanceof SuspendMembershipOnChargeback ) {
				$has_chargeback_reaction = true;
				break;
			}
		}

		self::assertTrue(
			$has_chargeback_reaction,
			'Expected the WebhookProcessor to wire SuspendMembershipOnChargeback.'
		);
	}

	public function test_container_memoises_instances(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$container = new Container();

		$a = $container->get( ConfigurationInterface::class );
		$b = $container->get( ConfigurationInterface::class );

		self::assertSame( $a, $b );
	}

	public function test_container_set_overrides_factory(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$container = new Container();

		$custom = new class implements EventDispatcherInterface {
			public function dispatch( object $event ): void {}
		};

		$container->set( EventDispatcherInterface::class, static fn() => $custom );

		self::assertSame( $custom, $container->get( EventDispatcherInterface::class ) );
	}

	public function test_unregistered_service_throws(): void {
		$container = new Container();

		$this->expectException( \RuntimeException::class );

		$container->get( 'NoSuch\Service' );
	}
}
