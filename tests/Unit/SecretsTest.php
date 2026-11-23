<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit;

use Brain\Monkey\Functions;
use Vatly\PMPro\Configuration;
use Vatly\PMPro\Secrets;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Secrets
 * @covers \Vatly\PMPro\Configuration
 */
class SecretsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		Functions\when('wp_salt')->justReturn('site-salt-one');
		Functions\when('get_option')->alias(fn(string $key, $default = '') => $this->options[$key] ?? $default);
		Functions\when('update_option')->alias(function (string $key, $value) {
			$this->options[$key] = $value;

			return true;
		});
	}

	public function test_a_stored_secret_is_encrypted_and_read_back_by_configuration(): void {
		Secrets::store(Configuration::OPTION_WEBHOOK_SECRET, 'whsec_plain_value');
		Secrets::store(Configuration::OPTION_API_KEY, 'live_realkeyXXXXXXXXX');

		$stored = $this->options[Configuration::OPTION_WEBHOOK_SECRET];
		self::assertTrue(Secrets::is_encrypted($stored));
		self::assertStringNotContainsString('whsec_plain_value', $stored);
		self::assertStringNotContainsString('live_realkey', $this->options[Configuration::OPTION_API_KEY]);

		$this->options[Configuration::OPTION_GATEWAY_ENVIRONMENT_KEY] = 'live';
		$config = new Configuration();
		self::assertSame('whsec_plain_value', $config->getWebhookSecret());
		self::assertSame('live_realkeyXXXXXXXXX', $config->getApiKey());
	}

	public function test_encrypting_twice_gives_different_ciphertexts(): void {
		self::assertNotSame(Secrets::encrypt('same'), Secrets::encrypt('same'));
	}

	public function test_a_value_sealed_with_other_salts_reads_as_empty(): void {
		$sealed = Secrets::encrypt('whsec_plain_value');

		Functions\when('wp_salt')->justReturn('site-salt-two');

		self::assertSame('', Secrets::reveal($sealed));
		self::assertSame('', Secrets::reveal('vatly-enc:v1:not-base64!'));
	}

	public function test_a_plain_stored_value_is_not_used(): void {
		$this->options[Configuration::OPTION_WEBHOOK_SECRET] = 'plain_value_written_outside_the_plugin';

		self::assertNull((new Configuration())->getWebhookSecret());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_encryption_key_constant_takes_precedence_over_the_salts(): void {
		$sealed = Secrets::encrypt('whsec_plain_value');

		define('VATLY_PMPRO_ENCRYPTION_KEY', 'dedicated-key');

		self::assertSame('', Secrets::reveal($sealed));
		self::assertSame('whsec_plain_value', Secrets::reveal(Secrets::encrypt('whsec_plain_value')));
	}
}
