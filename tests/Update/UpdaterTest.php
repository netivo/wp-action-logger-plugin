<?php

declare( strict_types=1 );

namespace Netivo\ActionLoggerMU\Tests\Update;

use Brain\Monkey\Functions;
use Mockery;
use Netivo\ActionLoggerMU\Config;
use Netivo\ActionLoggerMU\Plugin;
use Netivo\ActionLoggerMU\Tests\TestCase;
use Netivo\ActionLoggerMU\Transport\Client;
use Netivo\ActionLoggerMU\Transport\DeliveryResult;
use Netivo\ActionLoggerMU\Update\ReleaseClient;
use Netivo\ActionLoggerMU\Update\Updater;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class UpdaterTest extends TestCase {
	/**
	 * Directories created on disk for a test, removed again in tearDown().
	 *
	 * @var string[]
	 */
	private array $temp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->delete_tree( $dir );
		}
		$this->temp_dirs = [];
		parent::tearDown();
	}

	public function test_check_uses_the_cache_unless_forced(): void {
		$config   = Config::from_array( [] );
		$client   = Mockery::mock( Client::class );
		$releases = Mockery::mock( ReleaseClient::class );
		$releases->shouldReceive( 'latest_release' )->once()->andReturn(
			[ 'version' => '9.9.9', 'tag' => 'v9.9.9', 'zip_url' => 'https://example.com/a.zip', 'html_url' => '' ]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$updater = new Updater( $config, $client, $releases );

		$first  = $updater->check();
		Functions\when( 'get_transient' )->justReturn( $first );
		$second = $updater->check();

		self::assertTrue( $first['update_available'] );
		self::assertSame( '9.9.9', $second['latest_version'] );
	}

	public function test_check_reports_no_update_when_already_current(): void {
		$config   = Config::from_array( [] );
		$client   = Mockery::mock( Client::class );
		$releases = Mockery::mock( ReleaseClient::class );
		$releases->shouldReceive( 'latest_release' )->once()->andReturn(
			[ 'version' => Plugin::VERSION, 'tag' => 'v' . Plugin::VERSION, 'zip_url' => 'https://example.com/a.zip', 'html_url' => '' ]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = ( new Updater( $config, $client, $releases ) )->check( true );

		self::assertFalse( $result['update_available'] );
	}

	public function test_apply_reports_failure_when_no_update_is_available(): void {
		$config   = Config::from_array( [] );
		$releases = Mockery::mock( ReleaseClient::class );
		$releases->shouldReceive( 'latest_release' )->once()->andReturn(
			[ 'version' => Plugin::VERSION, 'tag' => 'v' . Plugin::VERSION, 'zip_url' => 'https://example.com/a.zip', 'html_url' => '' ]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User() );

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'send' )->once()->withArgs(
			static function ( array $events ): bool {
				return 'mu_plugin_update_failed' === $events[0]['action'];
			}
		)->andReturn( new DeliveryResult( DeliveryResult::DELIVERED ) );

		$result = ( new Updater( $config, $client, $releases ) )->apply();

		self::assertFalse( $result['success'] );
		self::assertSame( 'No newer release is available.', $result['error'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_apply_swaps_the_plugin_directory_and_loader_file(): void {
		$current_dir    = $this->make_temp_dir( 'current' );
		$plugin_dir     = $current_dir . '/netivo-action-logger-mu';
		$loader_file    = $current_dir . '/netivo-action-logger-mu-loader.php';
		mkdir( $plugin_dir );
		file_put_contents( $plugin_dir . '/netivo-action-logger-mu.php', 'old-bootstrap' );
		file_put_contents( $loader_file, 'old-loader' );

		define( 'NETIVO_ACTION_LOGGER_MU_DIR', $plugin_dir );
		define( 'NETIVO_ACTION_LOGGER_MU_LOADER_FILE', $loader_file );

		$extracted = $this->make_temp_dir( 'extracted' );
		mkdir( $extracted . '/netivo-action-logger-mu' );
		file_put_contents( $extracted . '/netivo-action-logger-mu/netivo-action-logger-mu.php', 'new-bootstrap' );
		file_put_contents( $extracted . '/netivo-action-logger-mu-loader.php', 'new-loader' );

		$config   = Config::from_array( [] );
		$releases = Mockery::mock( ReleaseClient::class );
		$releases->shouldReceive( 'latest_release' )->once()->andReturn(
			[ 'version' => '9.9.9', 'tag' => 'v9.9.9', 'zip_url' => 'https://example.com/a.zip', 'html_url' => 'https://example.com/releases/v9.9.9' ]
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User() );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);

		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'send' )->once()->withArgs(
			static function ( array $events ): bool {
				return 'mu_plugin_updated' === $events[0]['action']
					&& 'warning' === $events[0]['severity']
					&& '9.9.9' === $events[0]['changes']['version']['new'];
			}
		)->andReturn( new DeliveryResult( DeliveryResult::DELIVERED ) );

		$updater = new UpdaterUsingFixedExtraction( $config, $client, $releases, $extracted );
		$result  = $updater->apply();

		self::assertTrue( $result['success'] );
		self::assertSame( '9.9.9', $result['new_version'] );
		self::assertSame( 'new-bootstrap', file_get_contents( $plugin_dir . '/netivo-action-logger-mu.php' ) );
		self::assertSame( 'new-loader', file_get_contents( $loader_file ) );
		self::assertFileDoesNotExist( $extracted );
	}

	/**
	 * Creates a fresh temporary directory, tracked for removal in tearDown().
	 *
	 * @param string $suffix Distinguishes directories within one test.
	 *
	 * @return string
	 */
	private function make_temp_dir( string $suffix ): string {
		$dir = sys_get_temp_dir() . '/nt-al-mu-test-' . $suffix . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		$this->temp_dirs[] = $dir;

		return $dir;
	}

	/**
	 * Recursively removes a directory, if it still exists.
	 *
	 * @param string $dir Directory to remove.
	 *
	 * @return void
	 */
	private function delete_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $item ) {
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->delete_tree( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}

/**
 * Test double that skips the real download/unzip and hands apply() a
 * ready-made extracted directory instead, so the swap/rollback logic runs
 * against real files on disk without touching the network or WP_Filesystem.
 */
final class UpdaterUsingFixedExtraction extends Updater {
	/**
	 * @param Config        $config              Module configuration.
	 * @param Client        $client              Ingestion API client.
	 * @param ReleaseClient $releases             Release lookup.
	 * @param string        $fixed_extracted_dir Directory apply() should treat as the unzip result.
	 */
	public function __construct(
		Config $config,
		Client $client,
		ReleaseClient $releases,
		private readonly string $fixed_extracted_dir
	) {
		parent::__construct( $config, $client, $releases );
	}

	protected function download( string $url ): string {
		unset( $url );

		return $this->fixed_extracted_dir . '/unused.zip';
	}

	protected function extract( string $zip_file ): string {
		unset( $zip_file );

		return $this->fixed_extracted_dir;
	}
}
