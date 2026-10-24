<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Admin;

use Netivo\ActionLoggerMU\Access\Guard;
use Netivo\ActionLoggerMU\Admin\Page\Status;
use Netivo\ActionLoggerMU\Config;
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
 * Class Admin
 *
 * Bootstraps the module's admin pages and hides this must-use plugin from the
 * "Must Use" plugins list for anyone outside Access\Guard's allowed domains.
 * A client site's own administrators keep full control over every other
 * plugin; only this internal diagnostics screen is restricted, since all of
 * its configuration is read-only and comes from wp-config.php constants.
 */
class Admin {

	/**
	 * Admin constructor.
	 *
	 * @param Config         $config  Module configuration.
	 * @param Queue          $queue   Local fallback queue.
	 * @param DeliveryStatus $status  Delivery status store.
	 * @param Client         $client  Ingestion API client.
	 * @param QueueWorker    $worker  Queue worker.
	 * @param Updater        $updater Self-updater.
	 */
	public function __construct( Config $config, Queue $queue, DeliveryStatus $status, Client $client, QueueWorker $worker, Updater $updater ) {
		add_filter( 'show_advanced_plugins', [ self::class, 'hide_from_plugins_list' ], 10, 2 );

		if ( ! Guard::current_user_is_privileged() ) {
			return;
		}

		add_action(
			'admin_init',
			static function () use ( $updater ): void {
				if ( self::is_relevant_screen() ) {
					$updater->check();
				}
			}
		);

		new Status( $config, $queue, $status, $client, $worker, $updater );
	}

	/**
	 * Whether the current admin request is the Plugins screen or this
	 * module's own status page, the two places an update check is cheap
	 * enough to run on every page load (results are cached by Updater::check()).
	 *
	 * @return bool
	 */
	private static function is_relevant_screen(): bool {
		global $pagenow;

		if ( 'plugins.php' === $pagenow ) {
			return true;
		}

		return 'options-general.php' === $pagenow
			&& isset( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, not a form submission.
			&& Status::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Hides the "Must Use" section of the Plugins screen from users outside the
	 * allowed domains. Drop-ins (the other $type this filter controls) are left
	 * untouched.
	 *
	 * @param bool   $show Whether to show the section.
	 * @param string $type `mustuse` or `dropins`.
	 *
	 * @return bool
	 */
	public static function hide_from_plugins_list( bool $show, string $type ): bool {
		if ( 'mustuse' !== $type ) {
			return $show;
		}

		return Guard::current_user_is_privileged() && $show;
	}
}
