<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit;

use Brain\Monkey\Functions;
use Vatly\PMPro\Configuration;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Configuration
 */
class ConfigurationTest extends TestCase {

	public function test_returns_test_api_key_when_in_testmode(): void {
		$opts = [
			Configuration::OPTION_API_KEY_TEST            => 'test_localdevkeyXXXXXXXXX',
			Configuration::OPTION_API_KEY                 => 'live_realkeyXXXXXXXXX',
			Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY => 'sandbox',
		];

		Functions\when('get_option')->alias(static fn(string $key, $default = '') => $opts[$key] ?? $default);

		self::assertSame('test_localdevkeyXXXXXXXXX', (new Configuration())->getApiKey());
	}

	public function test_returns_live_api_key_when_in_live_mode(): void {
		$opts = [
			Configuration::OPTION_API_KEY                 => 'live_realkeyXXXXXXXXX',
			Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY => 'live',
		];

		Functions\when('get_option')->alias(static fn(string $key, $default = '') => $opts[$key] ?? $default);

		self::assertSame('live_realkeyXXXXXXXXX', (new Configuration())->getApiKey());
	}

	public function test_falls_back_to_live_key_when_test_key_missing_in_testmode(): void {
		$opts = [
			Configuration::OPTION_API_KEY                 => 'live_realkeyXXXXXXXXX',
			Configuration::OPTION_API_KEY_TEST            => '',
			Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY => 'sandbox',
		];

		Functions\when('get_option')->alias(static fn(string $key, $default = '') => $opts[$key] ?? $default);

		self::assertSame('live_realkeyXXXXXXXXX', (new Configuration())->getApiKey());
	}

	public function test_default_api_url_used_when_unset(): void {
		Functions\when('get_option')->justReturn('');

		self::assertSame(Configuration::DEFAULT_API_URL, (new Configuration())->getApiUrl());
	}

	public function test_webhook_secret_returns_null_when_blank(): void {
		Functions\when('get_option')->justReturn('');

		self::assertNull((new Configuration())->getWebhookSecret());
	}

	public function test_webhook_secret_returns_value_when_set(): void {
		$opts = [ Configuration::OPTION_WEBHOOK_SECRET => 'secret-abc' ];
		Functions\when('get_option')->alias(static fn(string $key, $default = '') => $opts[$key] ?? $default);

		self::assertSame('secret-abc', (new Configuration())->getWebhookSecret());
	}

	public function test_is_testmode_true_for_sandbox_environment(): void {
		Functions\when('get_option')->alias(static function (string $key, $default = '') {
			return $key === Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY ? 'sandbox' : $default;
		});

		self::assertTrue((new Configuration())->isTestmode());
	}

	public function test_is_testmode_false_for_live_environment(): void {
		Functions\when('get_option')->alias(static function (string $key, $default = '') {
			return $key === Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY ? 'live' : $default;
		});

		self::assertFalse((new Configuration())->isTestmode());
	}

	public function test_default_redirects_use_pmpro_url_when_available(): void {
		Functions\when('get_option')->justReturn('');
		// pmpro_url default stub returns "https://example.org/<page>".

		$config = new Configuration();

		self::assertSame('https://example.org/confirmation', $config->getDefaultRedirectUrlSuccess());
		self::assertSame('https://example.org/levels', $config->getDefaultRedirectUrlCanceled());
	}
}
