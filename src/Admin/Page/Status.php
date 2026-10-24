<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Admin\Page;

use Netivo\ActionLoggerMU\Config;
use Netivo\ActionLoggerMU\Payload\Actor;
use Netivo\ActionLoggerMU\Payload\EventPayload;
use Netivo\ActionLoggerMU\Plugin;
use Netivo\ActionLoggerMU\Transport\Client;
use Netivo\ActionLoggerMU\Transport\Queue;
use Netivo\ActionLoggerMU\Transport\QueueWorker;
use Netivo\ActionLoggerMU\Transport\Status as DeliveryStatus;
use Netivo\ActionLoggerMU\Update\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Status
 *
 * Read-only status page under Settings → Action Logger (MU): connection state,
 * endpoint, masked key, queue backlog and last delivery. Its only form actions
 * are a test ping and draining the queue; configuration is never editable here.
 * Only reachable by users Access\Guard allows; wp-admin menu registration
 * happens in Admin, which checks the guard before constructing this class.
 */
class Status {

	/**
	 * Menu slug of the page.
	 */
	public const SLUG = 'nt-action-logger-mu';

	/**
	 * Transient prefix for the last action result shown to a user.
	 */
	private const NOTICE_TRANSIENT = 'nt_mu_action_logger_notice_';

	/**
	 * Status constructor.
	 *
	 * @param Config         $config  Module configuration.
	 * @param Queue          $queue   Local fallback queue.
	 * @param DeliveryStatus $status  Delivery status store.
	 * @param Client         $client  Ingestion API client.
	 * @param QueueWorker    $worker  Queue worker.
	 * @param Updater        $updater Self-updater.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly Queue $queue,
		private readonly DeliveryStatus $status,
		private readonly Client $client,
		private readonly QueueWorker $worker,
		private readonly Updater $updater
	) {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_submit' ] );
	}

	/**
	 * Registers the submenu page under Settings.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Action Logger (MU)', 'netivo' ),
			__( 'Action Logger (MU)', 'netivo' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Handles the test ping and queue drain buttons, then redirects back.
	 *
	 * @return void
	 */
	public function handle_submit(): void {
		if ( ! isset( $_POST[ 'save_' . self::SLUG ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'netivo' ), 403 );
		}
		check_admin_referer( 'save_' . self::SLUG );

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';

		$notice = match ( $operation ) {
			'ping' => $this->ping(),
			'flush' => $this->flush_queue(),
			'check_update' => $this->check_update(),
			'apply_update' => $this->apply_update(),
			default => null,
		};

		if ( null !== $notice ) {
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'netivo' ), 403 );
		}

		$view = dirname( __DIR__, 3 ) . '/views/admin/pages/action-logger-mu-status.phtml';
		( static function ( array $al_data, string $al_view ): void {
			extract( $al_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- fixed, known keys from Status::report() below.
			require $al_view; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- fixed path built from dirname(__DIR__) above, not user input.
		} )( $this->report(), $view );
	}

	/**
	 * Collects everything the view displays.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$status = $this->status->all();

		if ( ! $this->config->is_enabled() ) {
			$state = 'disabled';
		} elseif ( [] !== $this->config->errors() ) {
			$state = 'misconfigured';
		} elseif ( $this->status->is_auth_blocked() ) {
			$state = 'unauthorized';
		} elseif ( ! empty( $status['last_error_at'] ) && (int) $status['last_error_at'] > (int) ( $status['last_success_at'] ?? 0 ) ) {
			$state = 'offline';
		} elseif ( ! empty( $status['last_success_at'] ) ) {
			$state = 'connected';
		} else {
			$state = 'waiting';
		}

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		if ( false !== $notice ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		}

		$update = $this->updater->check();

		return [
			'state'               => $state,
			'errors'              => $this->config->errors(),
			'endpoint'            => '' === $this->config->api_url() ? '' : $this->config->endpoint(),
			'masked_key'          => $this->config->masked_key(),
			'delivery'            => $this->config->delivery(),
			'backlog'             => $this->queue->count(),
			'oldest_queued'       => $this->queue->oldest(),
			'last_success_at'     => isset( $status['last_success_at'] ) ? (int) $status['last_success_at'] : null,
			'last_error'          => isset( $status['last_error'] ) ? (string) $status['last_error'] : '',
			'last_error_at'       => isset( $status['last_error_at'] ) ? (int) $status['last_error_at'] : null,
			'dropped_total'       => (int) ( $status['dropped_total'] ?? 0 ),
			'next_run'            => wp_next_scheduled( QueueWorker::HOOK ),
			'version'             => Plugin::VERSION,
			'notice'              => is_array( $notice ) ? $notice : null,
			'can_send'            => $this->config->is_active(),
			'update_available'    => $update['update_available'],
			'latest_version'      => $update['latest_version'],
			'update_checked_at'   => $update['checked_at'],
			'update_release_url'  => $update['release']['html_url'] ?? '',
		];
	}

	/**
	 * Sends a synthetic event straight to the API and reports the round trip.
	 *
	 * @return array{type: string, message: string}
	 */
	private function ping(): array {
		if ( ! $this->config->is_active() ) {
			return [
				'type'    => 'error',
				'message' => __( 'The module is not configured, so no ping was sent.', 'netivo' ),
			];
		}

		$event = EventPayload::create(
			'system',
			'test_ping',
			Actor::current( $this->config->trusted_proxies() ),
			[
				'type'  => 'site',
				'title' => (string) get_bloginfo( 'name' ),
			],
			null,
			[
				'module_version' => Plugin::VERSION,
				'wp_version'     => (string) get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
			]
		);

		$started = microtime( true );
		$result  = $this->client->send( [ $event->to_array() ] );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( $result->is_delivered() ) {
			$this->status->record_success();

			return [
				'type'    => 'success',
				/* translators: %d: round trip in milliseconds. */
				'message' => sprintf( __( 'Ping delivered in %d ms. The event appears in the dashboard as "test_ping".', 'netivo' ), $elapsed ),
			];
		}

		$this->status->record_failure( $result );

		return [
			'type'    => 'error',
			/* translators: %s: failure reason. */
			'message' => sprintf( __( 'Ping failed: %s', 'netivo' ), $result->error() ),
		];
	}

	/**
	 * Runs the queue worker immediately.
	 *
	 * @return array{type: string, message: string}
	 */
	private function flush_queue(): array {
		$delivered = $this->worker->drain();
		$remaining = $this->queue->count();

		return [
			'type'    => 0 === $remaining ? 'success' : 'warning',
			/* translators: 1: delivered events, 2: events still queued. */
			'message' => sprintf( __( 'Delivered %1$d queued event(s); %2$d remaining.', 'netivo' ), $delivered, $remaining ),
		];
	}

	/**
	 * Forces a fresh update check, bypassing the cache.
	 *
	 * @return array{type: string, message: string}
	 */
	private function check_update(): array {
		$result = $this->updater->check( true );

		if ( $result['update_available'] ) {
			return [
				'type'    => 'warning',
				/* translators: 1: latest version, 2: currently running version. */
				'message' => sprintf( __( 'Update available: version %1$s (currently running %2$s).', 'netivo' ), $result['latest_version'], $result['current_version'] ),
			];
		}

		return [
			'type'    => 'success',
			'message' => __( 'No update available; already on the latest version.', 'netivo' ),
		];
	}

	/**
	 * Downloads and installs the latest release.
	 *
	 * @return array{type: string, message: string}
	 */
	private function apply_update(): array {
		$result = $this->updater->apply();

		if ( $result['success'] ) {
			return [
				'type'    => 'success',
				/* translators: %s: new version. */
				'message' => sprintf( __( 'Updated to version %s. Reload this page to confirm.', 'netivo' ), $result['new_version'] ),
			];
		}

		return [
			'type'    => 'error',
			/* translators: %s: failure reason. */
			'message' => sprintf( __( 'Update failed: %s', 'netivo' ), $result['error'] ),
		];
	}
}
