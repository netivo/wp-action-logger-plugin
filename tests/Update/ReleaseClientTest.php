<?php

declare( strict_types=1 );

namespace Netivo\ActionLoggerMU\Tests\Update;

use Brain\Monkey\Functions;
use Netivo\ActionLoggerMU\Config;
use Netivo\ActionLoggerMU\Tests\TestCase;
use Netivo\ActionLoggerMU\Update\ReleaseClient;
use WP_Error;

final class ReleaseClientTest extends TestCase {
	public function test_returns_null_without_a_configured_repo(): void {
		$config = Config::from_array( [ 'update_repo' => '' ] );
		self::assertNull( ( new ReleaseClient( $config ) )->latest_release() );
	}

	public function test_returns_latest_release_with_a_zip_asset(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				[
					'tag_name'  => 'v1.2.3',
					'html_url'  => 'https://github.com/netivo/wp-action-logger-plugin/releases/tag/v1.2.3',
					'assets'    => [
						[ 'browser_download_url' => 'https://example.com/other.tar.gz' ],
						[ 'browser_download_url' => 'https://example.com/netivo-action-logger-mu-v1.2.3.zip' ],
					],
				]
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( array $response ): string => $response['body']
		);

		$release = ( new ReleaseClient( Config::from_array( [ 'update_repo' => 'netivo/wp-action-logger-plugin' ] ) ) )->latest_release();

		self::assertNotNull( $release );
		self::assertSame( '1.2.3', $release['version'] );
		self::assertSame( 'v1.2.3', $release['tag'] );
		self::assertSame( 'https://example.com/netivo-action-logger-mu-v1.2.3.zip', $release['zip_url'] );
		self::assertSame( 'https://github.com/netivo/wp-action-logger-plugin/releases/tag/v1.2.3', $release['html_url'] );
	}

	public function test_returns_null_when_no_asset_is_a_zip(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response( 200, [ 'tag_name' => 'v1.0.0', 'assets' => [ [ 'browser_download_url' => 'https://example.com/source.tar.gz' ] ] ] )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );

		self::assertNull( ( new ReleaseClient( Config::from_array( [ 'update_repo' => 'netivo/x' ] ) ) )->latest_release() );
	}

	public function test_returns_null_on_non_2xx_response(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->response( 404, [] ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		self::assertNull( ( new ReleaseClient( Config::from_array( [ 'update_repo' => 'netivo/x' ] ) ) )->latest_release() );
	}

	public function test_returns_null_on_transport_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		self::assertNull( ( new ReleaseClient( Config::from_array( [ 'update_repo' => 'netivo/x' ] ) ) )->latest_release() );
	}

	/**
	 * @param array<string, mixed> $body_data
	 *
	 * @return array{body: string}
	 */
	private function response( int $code, array $body_data ): array {
		unset( $code );

		return [ 'body' => (string) json_encode( $body_data ) ];
	}
}
