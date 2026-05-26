<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit;

use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Contracts\EventDispatcherInterface;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\SubscriptionRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookCallRepositoryInterface;
use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Exceptions\InvalidWebhookSignatureException;
use Vatly\Fluent\Webhooks\WebhookProcessorFactory;
use Vatly\PMPro\Tests\TestCase;

/**
 * End-to-end style: a signed payload coming through the WebhookProcessor with
 * the mock signing secret should not throw. With a tampered signature it must.
 *
 * @coversNothing
 */
class WebhookSignatureRoundTripTest extends TestCase {

	public function test_invalid_signature_is_rejected(): void {
		$config = new class implements ConfigurationInterface {
			public function getApiKey(): string                  { return 'test_x'; }
			public function getApiUrl(): string                  { return 'https://api.vatly.test'; }
			public function getApiVersion(): string              { return '2024-09-01'; }
			public function getWebhookSecret(): ?string          { return 'a-real-secret-32-bytes-long-OKKK'; }
			public function isTestmode(): bool                   { return true; }
			public function getDefaultRedirectUrlSuccess(): string { return ''; }
			public function getDefaultRedirectUrlCanceled(): string { return ''; }
			public function getBillableModel(): string           { return ''; }
		};

		$subscriptions = \Mockery::mock(SubscriptionRepositoryInterface::class);
		$orders        = \Mockery::mock(OrderRepositoryInterface::class);
		$webhookCalls  = \Mockery::mock(WebhookCallRepositoryInterface::class);
		$dispatcher    = \Mockery::mock(EventDispatcherInterface::class);
		$getOrder      = \Mockery::mock(GetOrder::class);

		$processor = WebhookProcessorFactory::create(
			$config,
			$subscriptions,
			$orders,
			$webhookCalls,
			$dispatcher,
			$getOrder,
		);

		$payload   = '{"id":"evt_1","resource":"subscription","eventName":"subscription.started","entityType":"subscription","entityId":"sub_1","testmode":true,"createdAt":"2026-01-01T00:00:00Z","object":{"customerId":"cus_1"}}';
		$signature = 't=' . time() . ',v1=' . hash_hmac('sha256', 'wrong', 'a-real-secret-32-bytes-long-OKKK');

		$this->expectException(InvalidWebhookSignatureException::class);

		$processor->handle($payload, $signature);
	}

	public function test_missing_signature_is_rejected(): void {
		$config = new class implements ConfigurationInterface {
			public function getApiKey(): string                  { return 'test_x'; }
			public function getApiUrl(): string                  { return 'https://api.vatly.test'; }
			public function getApiVersion(): string              { return '2024-09-01'; }
			public function getWebhookSecret(): ?string          { return 'secret-123'; }
			public function isTestmode(): bool                   { return true; }
			public function getDefaultRedirectUrlSuccess(): string { return ''; }
			public function getDefaultRedirectUrlCanceled(): string { return ''; }
			public function getBillableModel(): string           { return ''; }
		};

		$processor = WebhookProcessorFactory::create(
			$config,
			\Mockery::mock(SubscriptionRepositoryInterface::class),
			\Mockery::mock(OrderRepositoryInterface::class),
			\Mockery::mock(WebhookCallRepositoryInterface::class),
			\Mockery::mock(EventDispatcherInterface::class),
			\Mockery::mock(GetOrder::class),
		);

		$this->expectException(InvalidWebhookSignatureException::class);

		$processor->handle('{}', '');
	}
}
