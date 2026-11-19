<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks;

use Vatly\Fluent\Contracts\ConfigurationInterface;
use Vatly\Fluent\Exceptions\InvalidWebhookSignatureException;
use Vatly\Fluent\Webhooks\WebhookProcessor;
use Vatly\PMPro\Container;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public REST route Vatly POSTs webhook events to.
 *
 * Endpoint: `POST /wp-json/vatly/v1/webhook`
 *
 * Signature verification is handled by fluent's WebhookProcessor. We just need
 * to forward the raw body and the `Vatly-Signature` header. We respond 200 on
 * success, 400 on signature failure, 500 on unexpected exceptions, and 503
 * while no webhook secret is configured (Vatly retries until it is set).
 */
class RestRoute {

	public const NAMESPACE = 'vatly/v1';
	public const ROUTE     = '/webhook';

	public function __construct(
		private Container $container,
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( WP_REST_Request $request ) {
		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'vatly-signature' );

		if ( $body === '' ) {
			return new WP_Error(
				'vatly_webhook_empty_body',
				'Empty webhook body',
				[ 'status' => 400 ]
			);
		}

		// Refuse before building the processor while no secret is configured.
		$secret = $this->container->get( ConfigurationInterface::class )->getWebhookSecret();
		if ( $secret === null || trim( $secret ) === '' ) {
			return self::secret_not_configured();
		}

		try {
			/** @var WebhookProcessor $processor */
			$processor = $this->container->get( WebhookProcessor::class );
			$processor->handle( $body, $signature );
		} catch ( InvalidWebhookSignatureException $e ) {
			if ( $e->isSecretNotConfigured() ) {
				return self::secret_not_configured();
			}

			// Don't leak detail to the caller — invalid signature is the only
			// 400 we surface. Log for ops.
			error_log( '[vatly-pmpro] webhook signature rejected: ' . $e->getMessage() );

			return new WP_Error(
				'vatly_webhook_invalid_signature',
				'Invalid webhook signature',
				[ 'status' => 400 ]
			);
		} catch ( \Throwable $e ) {
			error_log( '[vatly-pmpro] webhook handler failure: ' . $e->getMessage() );

			return new WP_Error(
				'vatly_webhook_failed',
				'Webhook handler failed',
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	private static function secret_not_configured(): WP_Error {
		return new WP_Error(
			'webhook_secret_not_configured',
			'Webhook secret not configured',
			[ 'status' => 503 ]
		);
	}

	public static function url(): string {
		return rest_url( trailingslashit( self::NAMESPACE ) . ltrim( self::ROUTE, '/' ) );
	}
}
