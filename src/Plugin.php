<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU;

use Netivo\ActionLoggerMU\Admin\Admin;
use Netivo\ActionLoggerMU\Listeners\AbstractListener;
use Netivo\ActionLoggerMU\Listeners\AuthListener;
use Netivo\ActionLoggerMU\Listeners\ContentListener;
use Netivo\ActionLoggerMU\Listeners\SettingsListener;
use Netivo\ActionLoggerMU\Listeners\SystemListener;
use Netivo\ActionLoggerMU\Listeners\UserListener;
use Netivo\ActionLoggerMU\Listeners\WooCommerceListener;
use Netivo\ActionLoggerMU\Transport\Client;
use Netivo\ActionLoggerMU\Transport\Dispatcher;
use Netivo\ActionLoggerMU\Transport\EventSink;
use Netivo\ActionLoggerMU\Transport\Queue;
use Netivo\ActionLoggerMU\Transport\QueueWorker;
use Netivo\ActionLoggerMU\Transport\Status;
use Netivo\ActionLoggerMU\Update\ReleaseClient;
use Netivo\ActionLoggerMU\Update\Updater;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Plugin
 *
 * Entry point of the standalone Action Logger must-use plugin. Unlike
 * packages/wp-module, it does not depend on a Netivo theme or netivo/wp-core:
 * configuration is resolved on `plugins_loaded`, once every regular plugin
 * (including WooCommerce, if present) is available, so it works under any
 * theme. When the module is disabled or misconfigured nothing is captured.
 */
class Plugin {

	/**
	 * Module version, sent in the User-Agent of API requests and compared
	 * against the update repository's latest release tag by Update\Updater.
	 * Keep this in sync with the tag applied to the mirrored repository on
	 * every release that touches this package.
	 */
	public const VERSION = '0.2.1';

	/**
	 * Resolved configuration.
	 *
	 * @var Config|null
	 */
	private ?Config $config = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Local fallback queue.
	 *
	 * @var Queue|null
	 */
	private ?Queue $queue = null;

	/**
	 * Delivery status store.
	 *
	 * @var Status|null
	 */
	private ?Status $status = null;

	/**
	 * Ingestion API client.
	 *
	 * @var Client|null
	 */
	private ?Client $client = null;

	/**
	 * Queue worker.
	 *
	 * @var QueueWorker|null
	 */
	private ?QueueWorker $worker = null;

	/**
	 * Request-scoped event dispatcher.
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher = null;

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
		if ( did_action( 'plugins_loaded' ) ) {
			$this->boot();
		} else {
			add_action( 'plugins_loaded', [ $this, 'boot' ], 20 );
		}
	}

	/**
	 * Resolves the configuration and wires transport, listeners and admin. Never throws.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		try {
			$config       = Config::load();
			$this->config = $config;

			$this->queue  = new Queue();
			$this->status = new Status();
			$this->client = new Client( $config );
			$this->worker = new QueueWorker( $this->queue, $this->client, $this->status );

			$this->queue->install();
			$this->worker->register();

			if ( ! $config->is_active() ) {
				$this->worker->unschedule();
				$this->boot_admin();

				return;
			}

			$this->worker->schedule();
			$this->dispatcher = new Dispatcher( $config, $this->queue, $this->client, $this->status );

			foreach ( $this->listeners( $this->dispatcher, $config ) as $listener ) {
				$listener->register();
			}

			$this->boot_admin();
		} catch ( Throwable $e ) {
			// The host site must never break because of audit logging.
			unset( $e );
		}
	}

	/**
	 * Builds the listeners to register.
	 *
	 * @param EventSink $sink   Receives captured events.
	 * @param Config    $config Module configuration.
	 *
	 * @return AbstractListener[]
	 */
	protected function listeners( EventSink $sink, Config $config ): array {
		$listeners = [
			new AuthListener( $sink, $config ),
			new ContentListener( $sink, $config ),
			new SystemListener( $sink, $config ),
			new UserListener( $sink, $config ),
			new SettingsListener( $sink, $config ),
		];

		// By plugins_loaded, WooCommerce (a regular plugin) is detectable here.
		if ( class_exists( 'WooCommerce' ) ) {
			$listeners[] = new WooCommerceListener( $sink, $config );
		}

		return $listeners;
	}

	/**
	 * Attaches the admin status page.
	 *
	 * @return void
	 */
	protected function boot_admin(): void {
		if ( ! is_admin() || null === $this->config || null === $this->queue || null === $this->status || null === $this->client || null === $this->worker ) {
			return;
		}

		$updater = new Updater( $this->config, $this->client, new ReleaseClient( $this->config ) );

		new Admin( $this->config, $this->queue, $this->status, $this->client, $this->worker, $updater );
	}

	/**
	 * Resolved configuration, once booted.
	 *
	 * @return Config|null
	 */
	public function config(): ?Config {
		return $this->config;
	}

	/**
	 * Event dispatcher, when the module is active.
	 *
	 * @return Dispatcher|null
	 */
	public function dispatcher(): ?Dispatcher {
		return $this->dispatcher;
	}
}
