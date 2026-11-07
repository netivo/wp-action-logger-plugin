<?php

declare( strict_types=1 );

namespace Netivo\ActionLoggerMU\Tests\Update;

use Brain\Monkey\Functions;
use Netivo\ActionLoggerMU\Config;
use Netivo\ActionLoggerMU\Tests\TestCase;
use Netivo\ActionLoggerMU\Update\ReleaseClient;
use WP_Error;

final class ReleaseClientTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
	}

	public function test_returns_null_without_a_configured_url_or_repo(): void {
		$config = Config::from_array( [ 'update_url' => '', 'update_repo' => '' ] );
		self::assertNull( ( new ReleaseClient( $config ) )->latest_release() );
	}

	public function test_returns_latest_release_from_cloudflare_manifest(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				[
					'slug'     => 'netivo-action-logger-mu',
					'latest'   => '0.7.0',
					'homepage' => 'https://netivo.pl',
					'versions' => [
						'0.7.0' => [
							'version'      => '0.7.0',
							'tag'          => 'v0.7.0',
							'download_url' => 'https://github.com/netivo/wp-action-logger-plugin/releases/download/v0.7.0/netivo-action-logger-mu-v0.7.0.zip',
							'checksum'     => [
								'type'  => 'sha256',
								'value' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
								'url'   => 'https://github.com/netivo/wp-action-logger-plugin/releases/download/v0.7.0/netivo-action-logger-mu-v0.7.0.zip.sha256',
							],
							'requires_php' => '8.2',
							'requires_wp'  => '6.3',
							'tested_wp'    => '6.7',
							'changelog'    => 'Release v0.7.0',
						],
					],
				]
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( array $response ): string => $response['body']
		);

		$client  = new ReleaseClient( Config::from_array( [ 'update_url' => 'https://updates.netivo.dev' ] ) );
		$release = $client->latest_release();

		self::assertNotNull( $release );
		self::assertSame( '0.7.0', $release['version'] );
		self::assertSame( 'v0.7.0', $release['tag'] );
		self::assertSame( 'https://github.com/netivo/wp-action-logger-plugin/releases/download/v0.7.0/netivo-action-logger-mu-v0.7.0.zip', $release['zip_url'] );
		self::assertSame( 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $release['sha256'] );
		self::assertSame( 'https://github.com/netivo/wp-action-logger-plugin/releases/download/v0.7.0/netivo-action-logger-mu-v0.7.0.zip.sha256', $release['checksum_url'] );
		self::assertSame( '8.2', $release['requires_php'] );
		self::assertSame( '6.3', $release['requires_wp'] );
	}

	public function test_returns_latest_release_from_legacy_github_with_a_zip_asset(): void {
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

		$release = ( new ReleaseClient( Config::from_array( [ 'update_url' => '', 'update_repo' => 'netivo/wp-action-logger-plugin' ] ) ) )->latest_release();

		self::assertNotNull( $release );
		self::assertSame( '1.2.3', $release['version'] );
		self::assertSame( 'v1.2.3', $release['tag'] );
		self::assertSame( 'https://example.com/netivo-action-logger-mu-v1.2.3.zip', $release['zip_url'] );
		self::assertSame( 'https://github.com/netivo/wp-action-logger-plugin/releases/tag/v1.2.3', $release['html_url'] );
		self::assertNull( $release['checksum_url'] );
		self::assertNull( $release['sha256'] );
	}

	public function test_returns_checksum_url_when_a_matching_sha256_asset_is_published_on_github(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				[
					'tag_name' => 'v1.2.3',
					'assets'   => [
						[
							'name'                 => 'netivo-action-logger-mu-v1.2.3.zip',
							'browser_download_url' => 'https://example.com/netivo-action-logger-mu-v1.2.3.zip',
						],
						[
							'name'                 => 'netivo-action-logger-mu-v1.2.3.zip.sha256',
							'browser_download_url' => 'https://example.com/netivo-action-logger-mu-v1.2.3.zip.sha256',
						],
					],
				]
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );

		$release = ( new ReleaseClient( Config::from_array( [ 'update_url' => '', 'update_repo' => 'netivo/wp-action-logger-plugin' ] ) ) )->latest_release();

		self::assertNotNull( $release );
		self::assertSame( 'https://example.com/netivo-action-logger-mu-v1.2.3.zip.sha256', $release['checksum_url'] );
	}

	public function test_returns_null_checksum_url_when_no_matching_sha256_asset_exists_on_github(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response(
				200,
				[
					'tag_name' => 'v1.2.3',
					'assets'   => [
						[
							'name'                 => 'netivo-action-logger-mu-v1.2.3.zip',
							'browser_download_url' => 'https://example.com/netivo-action-logger-mu-v1.2.3.zip',
						],
						// A checksum for a different, unrelated asset must not be matched.
						[
							'name'                 => 'other-file.zip.sha256',
							'browser_download_url' => 'https://example.com/other-file.zip.sha256',
						],
					],
				]
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );

		$release = ( new ReleaseClient( Config::from_array( [ 'update_url' => '', 'update_repo' => 'netivo/wp-action-logger-plugin' ] ) ) )->latest_release();

		self::assertNotNull( $release );
		self::assertNull( $release['checksum_url'] );
	}

	public function test_returns_null_when_no_asset_is_a_zip_on_github(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			$this->response( 200, [ 'tag_name' => 'v1.0.0', 'assets' => [ [ 'browser_download_url' => 'https://example.com/source.tar.gz' ] ] ] )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );

		self::assertNull( ( new ReleaseClient( Config::from_array( [ 'update_url' => '', 'update_repo' => 'netivo/x' ] ) ) )->latest_release() );
	}

	public function test_returns_null_on_non_2xx_response(): void {
		Functions\when( 'wp_remote_get' )->justReturn( $this->response( 404, [] ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		self::assertNull( ( new ReleaseClient( Config::from_array( [ 'update_url' => '', 'update_repo' => 'netivo/x' ] ) ) )->latest_release() );
	}

	public function test_returns_null_on_transport_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		self::assertNull( ( new ReleaseClient( Config::from_array( [ 'update_url' => '', 'update_repo' => 'netivo/x' ] ) ) )->latest_release() );
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
