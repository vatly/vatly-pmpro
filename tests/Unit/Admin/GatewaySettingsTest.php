<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Vatly\PMPro\Admin\GatewaySettings;
use Vatly\PMPro\Configuration;
use Vatly\PMPro\Secrets;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Admin\GatewaySettings
 */
class GatewaySettingsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		Functions\when('esc_attr__')->returnArg(1);
		Functions\when('get_option')->alias(fn(string $key, $default = '') => $this->options[$key] ?? $default);
		Functions\when('update_option')->alias(function (string $key, $value) {
			$this->options[$key] = $value;

			return true;
		});
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_save_encrypts_secrets_and_keeps_saved_ones_when_left_blank(): void {
		Secrets::store(Configuration::OPTION_API_KEY, 'live_realkeyXXXXXXXXX');
		$savedLiveKey = $this->options[Configuration::OPTION_API_KEY];

		$_POST = [
			Configuration::OPTION_API_KEY        => '',
			Configuration::OPTION_API_KEY_TEST   => ' test_localdevkeyXXXXXXXXX ',
			Configuration::OPTION_WEBHOOK_SECRET => 'whsec_new',
			Configuration::OPTION_API_URL        => 'https://api.example.test',
		];

		GatewaySettings::save();

		self::assertSame($savedLiveKey, $this->options[Configuration::OPTION_API_KEY]);
		self::assertSame('test_localdevkeyXXXXXXXXX', Secrets::reveal($this->options[Configuration::OPTION_API_KEY_TEST]));
		self::assertTrue(Secrets::is_encrypted($this->options[Configuration::OPTION_WEBHOOK_SECRET]));
		self::assertSame('whsec_new', Secrets::reveal($this->options[Configuration::OPTION_WEBHOOK_SECRET]));
		self::assertSame('https://api.example.test', $this->options[Configuration::OPTION_API_URL]);
	}

	public function test_the_remove_checkbox_deletes_only_the_ticked_saved_value(): void {
		Functions\when('delete_option')->alias(function (string $key) {
			unset($this->options[$key]);

			return true;
		});
		Secrets::store(Configuration::OPTION_API_KEY, 'live_realkeyXXXXXXXXX');
		Secrets::store(Configuration::OPTION_WEBHOOK_SECRET, 'whsec_keep');

		$_POST = [
			Configuration::OPTION_API_KEY               => '',
			Configuration::OPTION_API_KEY . '_remove'   => '1',
			Configuration::OPTION_WEBHOOK_SECRET        => '',
		];

		GatewaySettings::save();

		self::assertArrayNotHasKey(Configuration::OPTION_API_KEY, $this->options);
		self::assertSame('whsec_keep', Secrets::reveal($this->options[Configuration::OPTION_WEBHOOK_SECRET]));
	}

	public function test_a_new_value_wins_over_the_remove_checkbox(): void {
		Functions\expect('delete_option')->never();

		$_POST = [
			Configuration::OPTION_WEBHOOK_SECRET               => 'whsec_new',
			Configuration::OPTION_WEBHOOK_SECRET . '_remove'   => '1',
		];

		GatewaySettings::save();

		self::assertSame('whsec_new', Secrets::reveal($this->options[Configuration::OPTION_WEBHOOK_SECRET]));
	}

	public function test_render_never_prints_a_stored_secret(): void {
		Secrets::store(Configuration::OPTION_WEBHOOK_SECRET, 'whsec_do_not_print');

		ob_start();
		GatewaySettings::render();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString('whsec_do_not_print', $html);
		self::assertStringNotContainsString($this->options[Configuration::OPTION_WEBHOOK_SECRET], $html);
		self::assertSame(3, substr_count($html, 'type="password"'));
		self::assertStringContainsString('Saved. Leave blank to keep it.', $html);
		self::assertSame(1, substr_count($html, 'name="' . Configuration::OPTION_WEBHOOK_SECRET . '_remove"'), 'Only a saved value gets a remove checkbox.');
		self::assertSame(1, substr_count($html, 'type="checkbox"'));
	}
}
