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
use Vatly\Fluent\Vatly;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\Fluent\Wiring;
use Vatly\PMPro\Container;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\Reactions\ChangeMembershipLevelOnUpdated;
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
					'vatly_pmpro_api_key_test'  => \Vatly\PMPro\Secrets::encrypt('test_localdevkeyXXXXXXXXX'),
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
	}

	public function test_webhook_processor_wires_chargeback_membership_reaction(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) {
				$opts = [
					'vatly_pmpro_api_key_test'  => \Vatly\PMPro\Secrets::encrypt('test_localdevkeyXXXXXXXXX'),
					'pmpro_gateway_environment' => 'sandbox',
				];

				return $opts[ $key ] ?? $default;
			}
		);

		$container = new Container();
		$processor = $container->get( WebhookProcessor::class );

		$has_chargeback_reaction = false;
		$has_updated_reaction    = false;
		foreach ( $processor->getReactions() as $reaction ) {
			if ( $reaction instanceof SuspendMembershipOnChargeback ) {
				$has_chargeback_reaction = true;
			}
			if ( $reaction instanceof ChangeMembershipLevelOnUpdated ) {
				$has_updated_reaction = true;
			}
		}

		self::assertTrue(
			$has_chargeback_reaction,
			'Expected the WebhookProcessor to wire SuspendMembershipOnChargeback.'
		);

		self::assertTrue(
			$has_updated_reaction,
			'Expected the WebhookProcessor to wire ChangeMembershipLevelOnUpdated for subscription.updated.'
		);
	}

	/**
	 * Sandbox mode without a test key calls the API with the live key, so a
	 * test-mode webhook must be refused even though isTestmode() is true.
	 */
	public function test_webhook_processor_refuses_test_webhook_when_calling_with_live_key(): void {
		$secret = 'a-real-secret-32-bytes-long-OKKK';
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) use ( $secret ) {
				$opts = [
					'vatly_pmpro_api_key'        => \Vatly\PMPro\Secrets::encrypt( 'live_localdevkeyXXXXXXXXX' ),
					'vatly_pmpro_webhook_secret' => \Vatly\PMPro\Secrets::encrypt( $secret ),
					'pmpro_gateway_environment'  => 'sandbox',
				];

				return $opts[ $key ] ?? $default;
			}
		);

		$processor = ( new Container() )->get( WebhookProcessor::class );

		$payload   = '{"id":"evt_1","resource":"subscription","eventName":"subscription.started","entityType":"subscription","entityId":"sub_1","testmode":true,"createdAt":"2026-01-01T00:00:00Z","object":{"customerId":"cus_1"}}';
		$timestamp = time();
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$this->expectException( \Vatly\Fluent\Exceptions\WebhookModeMismatchException::class );

		$processor->handle( $payload, $signature );
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
