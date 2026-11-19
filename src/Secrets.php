<?php

declare(strict_types=1);

namespace Vatly\PMPro;

/**
 * Encrypts the stored API keys and webhook secret.
 *
 * Values are sealed with libsodium's secretbox (WordPress ships a polyfill),
 * keyed from `VATLY_PMPRO_ENCRYPTION_KEY` when defined, else WordPress's
 * `wp_salt( 'logged_in' )`. A stored value without the prefix is a plain
 * value saved by an earlier build: {@see reveal()} returns it as is and
 * {@see encrypt_stored()} encrypts it in place.
 *
 * If the salts change, stored values can no longer be decrypted and read as
 * empty: re-enter them on the gateway settings screen.
 */
final class Secrets {

	public const OPTIONS = [
		Configuration::OPTION_API_KEY,
		Configuration::OPTION_API_KEY_TEST,
		Configuration::OPTION_WEBHOOK_SECRET,
	];

	private const PREFIX = 'vatly-enc:v1:';

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
	}

	/**
	 * The plain value of a stored secret, or '' when it cannot be decrypted.
	 *
	 * @param mixed $stored
	 */
	public static function reveal( $stored ): string {
		$stored = (string) $stored;

		if ( ! self::is_encrypted( $stored ) ) {
			return $stored;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encrypt().
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return $plain === false ? '' : $plain;
	}

	public static function is_encrypted( string $stored ): bool {
		return strpos( $stored, self::PREFIX ) === 0;
	}

	/**
	 * Store a secret encrypted, without autoloading it.
	 */
	public static function store( string $option, string $plain ): void {
		$plain = trim( $plain );

		update_option( $option, $plain === '' ? '' : self::encrypt( $plain ), false );
	}

	/**
	 * Encrypt values that earlier builds stored in plain text.
	 */
	public static function encrypt_stored(): void {
		foreach ( self::OPTIONS as $option ) {
			$stored = (string) get_option( $option, '' );

			if ( $stored !== '' && ! self::is_encrypted( $stored ) ) {
				self::store( $option, $stored );
			}
		}
	}

	private static function key(): string {
		$material = defined( 'VATLY_PMPRO_ENCRYPTION_KEY' ) && (string) constant( 'VATLY_PMPRO_ENCRYPTION_KEY' ) !== ''
			? (string) constant( 'VATLY_PMPRO_ENCRYPTION_KEY' )
			: wp_salt( 'logged_in' );

		return hash( 'sha256', 'vatly-pmpro-secrets|' . $material, true );
	}
}
