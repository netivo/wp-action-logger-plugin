<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Update;

use Netivo\ActionLoggerMU\Config;
use Netivo\ActionLoggerMU\Payload\Actor;
use Netivo\ActionLoggerMU\Payload\EventPayload;
use Netivo\ActionLoggerMU\Plugin;
use Netivo\ActionLoggerMU\Transport\Client;
use RuntimeException;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Updater
 *
 * Checks the update repository for a newer release and, only when explicitly
 * asked to (never from a cron job or a background request), downloads its
 * release zip, extracts it, and swaps it in for the files currently running
 * in wp-content/mu-plugins/. The release zip is expected to be laid out to
 * match that directory exactly: a loader file plus the plugin directory.
 *
 * Every apply() call reports the outcome as an audit event, sent immediately
 * (like Admin\Page\Status::ping()) so the triggering admin sees right away
 * whether the event itself reached the API.
 *
 * phpcs:disable Generic.PHP.NoSilencedErrors.Forbidden, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- self-update intentionally replaces files directly on the local filesystem of wp-content/mu-plugins/ (never a VIP-hosted, FTP-abstracted install); WP_Filesystem() is used for the download/extract steps, and the atomic rename()/rmdir() swap below has explicit backup and rollback on failure.
 */
class Updater {

	/**
	 * Transient caching the last check result, so visiting an admin screen
	 * repeatedly does not call the GitHub API on every request.
	 */
	private const CHECK_TRANSIENT = 'nt_mu_action_logger_update_check';

	/**
	 * How long a cached check result is trusted before a fresh one is fetched.
	 */
	private const CHECK_INTERVAL = 12 * HOUR_IN_SECONDS;

	/**
	 * Updater constructor.
	 *
	 * @param Config        $config   Module configuration.
	 * @param Client        $client   Ingestion API client, used to report the outcome.
	 * @param ReleaseClient $releases Reads the latest release from the update repository.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Client $client,
		private readonly ReleaseClient $releases
	) {
	}

	/**
	 * Checks whether a newer release is available. Cheap and safe to call on
	 * every admin page load: results are cached for CHECK_INTERVAL unless
	 * `$force` is set (an explicit "Check for updates" click).
	 *
	 * @param bool $force Bypasses the cache.
	 *
	 * @return array<string, mixed> Keys: checked_at (int), current_version (string),
	 *               latest_version (string|null), update_available (bool),
	 *               release (array{version: string, tag: string, zip_url: string, html_url: string}|null).
	 */
	public function check( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CHECK_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$release = $this->releases->latest_release();

		$result = [
			'checked_at'        => time(),
			'current_version'  => Plugin::VERSION,
			'latest_version'   => null,
			'update_available' => false,
			'release'          => null,
		];

		if ( null !== $release ) {
			$result['latest_version']   = $release['version'];
			$result['update_available'] = version_compare( $release['version'], Plugin::VERSION, '>' );
			$result['release']          = $release;
		}

		set_transient( self::CHECK_TRANSIENT, $result, self::CHECK_INTERVAL );

		return $result;
	}

	/**
	 * Downloads, extracts and installs the latest release in place of the
	 * files currently running. Never throws; reports its outcome as an audit
	 * event and returns it for the admin UI to display.
	 *
	 * @return array{success: bool, old_version: string, new_version: string, error: string}
	 */
	public function apply(): array {
		$check   = $this->check( true );
		$release = $check['release'];

		if ( null === $release || ! $check['update_available'] ) {
			return $this->finish( false, Plugin::VERSION, (string) ( $check['latest_version'] ?? '' ), 'No newer release is available.' );
		}

		try {
			$zip = $this->download( $release['zip_url'] );
		} catch ( Throwable $e ) {
			return $this->finish( false, Plugin::VERSION, $release['version'], $e->getMessage() );
		}

		try {
			$extracted = $this->extract( $zip );
		} catch ( Throwable $e ) {
			wp_delete_file( $zip );

			return $this->finish( false, Plugin::VERSION, $release['version'], $e->getMessage() );
		}

		wp_delete_file( $zip );

		try {
			$this->swap( $extracted );
		} catch ( Throwable $e ) {
			$this->remove_directory( $extracted );

			return $this->finish( false, Plugin::VERSION, $release['version'], $e->getMessage() );
		}

		$this->remove_directory( $extracted );
		delete_transient( self::CHECK_TRANSIENT );

		return $this->finish( true, Plugin::VERSION, $release['version'], '' );
	}

	/**
	 * Downloads a release asset to a temporary file.
	 *
	 * @param string $url Asset URL.
	 *
	 * @return string Path to the downloaded file.
	 *
	 * @throws RuntimeException When the download fails.
	 */
	protected function download( string $url ): string {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $url, 30 );
		if ( $file instanceof WP_Error ) {
			throw new RuntimeException( $file->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered.
		}

		return $file;
	}

	/**
	 * Extracts a downloaded zip to a fresh temporary directory.
	 *
	 * @param string $zip_file Path to the downloaded zip.
	 *
	 * @return string Path to the directory it was extracted into.
	 *
	 * @throws RuntimeException When extraction fails.
	 */
	protected function extract( string $zip_file ): string {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$destination = trailingslashit( get_temp_dir() ) . 'netivo-action-logger-mu-update-' . wp_generate_password( 12, false, false );
		wp_mkdir_p( $destination );

		$result = unzip_file( $zip_file, $destination );
		if ( $result instanceof WP_Error ) {
			throw new RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered.
		}

		return $destination;
	}

	/**
	 * Replaces the currently running loader file and plugin directory with
	 * the extracted release, backing up the originals first and rolling back
	 * if any step fails.
	 *
	 * @param string $extracted Directory the release was extracted into.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the release is malformed or the swap fails.
	 */
	private function swap( string $extracted ): void {
		$plugin_dir  = NETIVO_ACTION_LOGGER_MU_DIR;
		$loader_file = NETIVO_ACTION_LOGGER_MU_LOADER_FILE;

		$new_plugin_dir  = $extracted . '/netivo-action-logger-mu';
		$new_loader_file = $extracted . '/netivo-action-logger-mu-loader.php';

		if ( ! is_dir( $new_plugin_dir ) || ! is_file( $new_loader_file ) ) {
			throw new RuntimeException( 'The downloaded release is missing expected files.' );
		}

		$backup_dir    = $plugin_dir . '.bak-' . time();
		$backup_loader = $loader_file . '.bak-' . time();

		if ( ! @rename( $plugin_dir, $backup_dir ) ) {
			throw new RuntimeException( 'Could not back up the current plugin directory.' );
		}

		if ( ! @rename( $loader_file, $backup_loader ) ) {
			@rename( $backup_dir, $plugin_dir );
			throw new RuntimeException( 'Could not back up the current loader file.' );
		}

		if ( ! @rename( $new_plugin_dir, $plugin_dir ) || ! @rename( $new_loader_file, $loader_file ) ) {
			$this->remove_directory( $plugin_dir );
			@rename( $backup_dir, $plugin_dir );
			@rename( $backup_loader, $loader_file );
			throw new RuntimeException( 'Could not install the new release; rolled back to the previous version.' );
		}

		$this->remove_directory( $backup_dir );
		wp_delete_file( $backup_loader );
	}

	/**
	 * Recursively removes a directory (or a single file, if given one).
	 *
	 * @param string $path Path to remove.
	 *
	 * @return void
	 */
	private function remove_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}

			return;
		}

		$items = scandir( $path );
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$this->remove_directory( $path . '/' . $item );
		}

		@rmdir( $path );
	}

	/**
	 * Reports an apply() outcome as an audit event, sent immediately so the
	 * triggering admin sees right away whether it reached the API.
	 *
	 * @param bool   $success     Whether the update succeeded.
	 * @param string $old_version Version before the attempt.
	 * @param string $new_version Version the attempt targeted.
	 * @param string $error       Failure reason, empty on success.
	 *
	 * @return array{success: bool, old_version: string, new_version: string, error: string}
	 */
	private function finish( bool $success, string $old_version, string $new_version, string $error ): array {
		try {
			$event = EventPayload::create(
				'system',
				$success ? 'mu_plugin_updated' : 'mu_plugin_update_failed',
				Actor::current( $this->config->trusted_proxies() ),
				[
					'type'  => 'plugin',
					'title' => 'Netivo Action Logger (MU)',
					'slug'  => 'netivo-action-logger-mu',
				],
				[
					'version' => [
						'old' => '' === $old_version ? null : $old_version,
						'new' => '' === $new_version ? null : $new_version,
					],
				],
				array_filter( [ 'error' => $error ] ),
				$success ? 'warning' : 'critical'
			);

			$this->client->send( [ $event->to_array() ] );
		} catch ( Throwable $e ) {
			unset( $e );
		}

		return [
			'success'     => $success,
			'old_version' => $old_version,
			'new_version' => $new_version,
			'error'       => $error,
		];
	}
}
