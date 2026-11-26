<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit;

use Brain\Monkey\Functions;
use Vatly\Fluent\Vatly;
use Vatly\PMPro\Container;
use Vatly\PMPro\Tests\TestCase;

/**
 * Sends a real request to a PHP built-in web server on 127.0.0.1 that echoes
 * the User-Agent it received (see tests/Fixtures/user-agent-echo-server.php).
 */
class UserAgentTest extends TestCase {

	/** @var resource|null */
	private static $server;

	/** @var array<int, resource> */
	private static array $pipes = [];

	private static string $echo_server_url;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
		$port   = (int) substr( (string) strrchr( (string) stream_socket_get_name( $socket, false ), ':' ), 1 );
		fclose( $socket );

		self::$server          = proc_open(
			[ PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname( __DIR__ ) . '/Fixtures/user-agent-echo-server.php' ],
			// Pipes, not files: Patchwork's stream wrapper hides the file descriptor.
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			self::$pipes
		);
		self::$echo_server_url = "http://127.0.0.1:{$port}";

		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$connection = @fsockopen( '127.0.0.1', $port );

			if ( $connection !== false ) {
				fclose( $connection );

				return;
			}

			usleep( 50000 );
		}

		self::fail( 'The PHP built-in web server did not start.' );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$server !== null ) {
			proc_terminate( self::$server );
			array_map( 'fclose', self::$pipes );
			proc_close( self::$server );
			self::$server = null;
		}

		parent::tearDownAfterClass();
	}

	public function test_the_user_agent_ends_with_the_plugins_package_name_and_version(): void {
		$url = self::$echo_server_url;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = '' ) use ( $url ) {
				$opts = [
					'vatly_pmpro_api_key_test'  => \Vatly\PMPro\Secrets::encrypt( 'test_localdevkeyXXXXXXXXX' ),
					'vatly_pmpro_api_url'       => $url,
					'pmpro_gateway_environment' => 'sandbox',
				];

				return $opts[ $key ] ?? $default;
			}
		);

		$client     = ( new Container() )->get( Vatly::class )->getApiClient();
		$user_agent = $client->performHttpCall( 'GET', 'user-agent' )->userAgent;

		self::assertSame( implode( ' ', $client->getVersionStrings() ), $user_agent );
		self::assertStringEndsWith( ' vatly/vatly-pmpro/' . VATLY_PMPRO_VERSION, $user_agent );
		self::assertSame( 1, substr_count( $user_agent, 'vatly/vatly-pmpro/' ) );
	}
}
