<?php

declare(strict_types=1);

namespace Vatly\PMPro;

use Vatly\Fluent\Contracts\ConfigurationInterface;

/**
 * Option-backed implementation of the fluent ConfigurationInterface.
 *
 * Reads from WordPress options; the API keys and webhook secret are stored
 * encrypted ({@see Secrets}). Defaults match Vatly's production API URL and
 * the current API version pinned by vatly/vatly-api-php.
 */
class Configuration implements ConfigurationInterface {

	public const OPTION_API_KEY                 = 'vatly_pmpro_api_key';
	public const OPTION_API_KEY_TEST            = 'vatly_pmpro_api_key_test';
	public const OPTION_API_URL                 = 'vatly_pmpro_api_url';
	public const OPTION_WEBHOOK_SECRET          = 'vatly_pmpro_webhook_secret';
	public const OPTION_TESTMODE                = 'vatly_pmpro_testmode';
	public const OPTION_GATEWAY_ENVIRONMENT_KEY = 'pmpro_gateway_environment';

	public const DEFAULT_API_URL     = 'https://api.vatly.com';
	public const DEFAULT_API_VERSION = '2024-09-01';

	public function getApiKey(): string {
		// Honor PMPro's global gateway_environment so live/sandbox can be flipped
		// from the main PMPro settings page just like every other gateway.
		if ( $this->isTestmode() ) {
			$key = Secrets::reveal( get_option( self::OPTION_API_KEY_TEST, '' ) );
			if ( $key !== '' ) {
				return $key;
			}
		}

		return Secrets::reveal( get_option( self::OPTION_API_KEY, '' ) );
	}

	public function getApiUrl(): string {
		$url = (string) get_option( self::OPTION_API_URL, '' );

		return $url !== '' ? $url : self::DEFAULT_API_URL;
	}

	public function getApiVersion(): string {
		return self::DEFAULT_API_VERSION;
	}

	public function getWebhookSecret(): ?string {
		$secret = Secrets::reveal( get_option( self::OPTION_WEBHOOK_SECRET, '' ) );

		return $secret !== '' ? $secret : null;
	}

	public function isTestmode(): bool {
		$env = (string) get_option( self::OPTION_GATEWAY_ENVIRONMENT_KEY, 'sandbox' );

		if ( $env === 'sandbox' || $env === 'test' ) {
			return true;
		}

		// Explicit override.
		return (bool) get_option( self::OPTION_TESTMODE, false );
	}

	public function getDefaultRedirectUrlSuccess(): string {
		if ( function_exists( 'pmpro_url' ) ) {
			return (string) pmpro_url( 'confirmation' );
		}

		return home_url( '/' );
	}

	public function getDefaultRedirectUrlCanceled(): string {
		if ( function_exists( 'pmpro_url' ) ) {
			return (string) pmpro_url( 'levels' );
		}

		return home_url( '/' );
	}
}
