<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Webhooks;

use Brain\Monkey\Functions;
use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Exceptions\InvalidWebhookSignatureException;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\PMPro\Container;
use Vatly\PMPro\Tests\TestCase;
use Vatly\PMPro\Webhooks\RestRoute;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \Vatly\PMPro\Webhooks\RestRoute
 */
class RestRouteTest extends TestCase {

	public function test_returns_400_for_empty_body(): void {
		$request = new WP_REST_Request('', ['vatly-signature' => 't=1,v1=abc']);

		$container = new Container();
		$route     = new RestRoute($container);

		$response = $route->handle($request);

		self::assertInstanceOf(WP_Error::class, $response);
		self::assertSame('vatly_webhook_empty_body', $response->code);
	}

	public function test_returns_400_on_invalid_signature(): void {
		$request = new WP_REST_Request('{}', ['vatly-signature' => 't=1,v1=abc']);

		$processor = \Mockery::mock(WebhookProcessor::class);
		$processor->shouldReceive('handle')->andThrow(InvalidWebhookSignatureException::invalidSignature());

		$container = $this->containerWithSecret('secret');
		$container->set(WebhookProcessor::class, static fn() => $processor);

		$response = (new RestRoute($container))->handle($request);

		self::assertInstanceOf(WP_Error::class, $response);
		self::assertSame('vatly_webhook_invalid_signature', $response->code);
	}

	public function test_returns_500_on_unexpected_exception(): void {
		$request = new WP_REST_Request('{}', ['vatly-signature' => 't=1,v1=abc']);

		$processor = \Mockery::mock(WebhookProcessor::class);
		$processor->shouldReceive('handle')->andThrow(new \RuntimeException('boom'));

		$container = $this->containerWithSecret('secret');
		$container->set(WebhookProcessor::class, static fn() => $processor);

		$response = (new RestRoute($container))->handle($request);

		self::assertInstanceOf(WP_Error::class, $response);
		self::assertSame('vatly_webhook_failed', $response->code);
	}

	public function test_returns_200_on_success(): void {
		$request = new WP_REST_Request('{}', ['vatly-signature' => 't=1,v1=abc']);

		$processor = \Mockery::mock(WebhookProcessor::class);
		$processor->shouldReceive('handle')->once()->with('{}', 't=1,v1=abc');

		$container = $this->containerWithSecret('secret');
		$container->set(WebhookProcessor::class, static fn() => $processor);

		$response = (new RestRoute($container))->handle($request);

		self::assertInstanceOf(WP_REST_Response::class, $response);
		self::assertSame(200, $response->get_status());
		self::assertSame(['received' => true], $response->get_data());
	}

	/**
	 * @dataProvider missingSecrets
	 */
	public function test_returns_503_without_building_the_processor_while_no_secret_is_configured(?string $secret): void {
		$request = new WP_REST_Request('{}', ['vatly-signature' => 't=1,v1=abc']);

		$container = $this->containerWithSecret($secret);
		$container->set(WebhookProcessor::class, static function (): WebhookProcessor {
			throw new \LogicException('The processor must not be built without a secret.');
		});

		$response = (new RestRoute($container))->handle($request);

		self::assertInstanceOf(WP_Error::class, $response);
		self::assertSame('webhook_secret_not_configured', $response->code);
		self::assertSame(503, $response->data['status']);
	}

	/**
	 * @return array<string, array{?string}>
	 */
	public static function missingSecrets(): array {
		return ['null' => [null], 'empty' => [''], 'whitespace' => ['  ']];
	}

	public function test_returns_503_when_the_processor_reports_no_secret(): void {
		$request = new WP_REST_Request('{}', ['vatly-signature' => 't=1,v1=abc']);

		$processor = \Mockery::mock(WebhookProcessor::class);
		$processor->shouldReceive('handle')->andThrow(InvalidWebhookSignatureException::secretNotConfigured());

		$container = $this->containerWithSecret('secret');
		$container->set(WebhookProcessor::class, static fn() => $processor);

		$response = (new RestRoute($container))->handle($request);

		self::assertInstanceOf(WP_Error::class, $response);
		self::assertSame('webhook_secret_not_configured', $response->code);
	}

	public function test_url_helper_returns_fully_qualified_rest_url(): void {
		Functions\when('rest_url')->alias(static fn(string $path) => "https://example.org/wp-json/{$path}");
		Functions\when('trailingslashit')->alias(static fn(string $s) => rtrim($s, '/') . '/');

		self::assertSame('https://example.org/wp-json/vatly/v1/webhook', RestRoute::url());
	}

	private function containerWithSecret(?string $secret): Container {
		$config = \Mockery::mock(ConfigurationInterface::class);
		$config->shouldReceive('getWebhookSecret')->andReturn($secret);

		$container = new Container();
		$container->set(ConfigurationInterface::class, static fn() => $config);

		return $container;
	}
}
