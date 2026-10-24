<?php
/**
 * Plugin Name: Netivo Action Logger (MU)
 * Description: Streams admin user actions to Netivo Action Logger telemetry. Read-only configuration via wp-config.php constants; see the bundled README for installation.
 * Version: 0.2.1
 * Author: Netivo
 * Author URI: https://netivo.pl
 * License: Proprietary
 *
 * This file is the real bootstrap of the standalone Action Logger must-use
 * plugin. It is not itself placed directly in wp-content/mu-plugins/: that
 * directory only auto-loads top-level .php files, so this whole package
 * directory is copied into wp-content/mu-plugins/netivo-action-logger-mu/
 * and loaded through the tiny netivo-action-logger-mu-loader.php stub that
 * sits at the mu-plugins root. See README.md for exact steps.
 *
 * @package Netivo\ActionLoggerMU
 */

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

// The self-updater (src/Update/Updater.php) replaces this directory and the
// loader file in place, so it needs their exact paths rather than a relative
// one computed at update time.
if ( ! defined( 'NETIVO_ACTION_LOGGER_MU_DIR' ) ) {
	define( 'NETIVO_ACTION_LOGGER_MU_DIR', __DIR__ );
}
if ( ! defined( 'NETIVO_ACTION_LOGGER_MU_LOADER_FILE' ) ) {
	define( 'NETIVO_ACTION_LOGGER_MU_LOADER_FILE', dirname( __DIR__ ) . '/netivo-action-logger-mu-loader.php' );
}

// No Composer autoloader is assumed on the host site: every class is loaded
// explicitly so this package works as a plain drop-in on any WordPress
// install, Composer-managed or not. Base classes are required before the
// classes that extend or implement them.
require_once __DIR__ . '/src/Config.php';

require_once __DIR__ . '/src/Payload/Redactor.php';
require_once __DIR__ . '/src/Payload/Actor.php';
require_once __DIR__ . '/src/Payload/EventPayload.php';

require_once __DIR__ . '/src/Transport/EventSink.php';
require_once __DIR__ . '/src/Transport/DeliveryResult.php';
require_once __DIR__ . '/src/Transport/Status.php';
require_once __DIR__ . '/src/Transport/Queue.php';
require_once __DIR__ . '/src/Transport/Client.php';
require_once __DIR__ . '/src/Transport/QueueWorker.php';
require_once __DIR__ . '/src/Transport/Dispatcher.php';

require_once __DIR__ . '/src/Listeners/AbstractListener.php';
require_once __DIR__ . '/src/Listeners/AuthListener.php';
require_once __DIR__ . '/src/Listeners/ContentListener.php';
require_once __DIR__ . '/src/Listeners/SettingsListener.php';
require_once __DIR__ . '/src/Listeners/SystemListener.php';
require_once __DIR__ . '/src/Listeners/UserListener.php';
require_once __DIR__ . '/src/Listeners/WooCommerceListener.php';

require_once __DIR__ . '/src/Update/ReleaseClient.php';
require_once __DIR__ . '/src/Update/Updater.php';

require_once __DIR__ . '/src/Access/Guard.php';
require_once __DIR__ . '/src/Admin/Page/Status.php';
require_once __DIR__ . '/src/Admin/Admin.php';

require_once __DIR__ . '/src/Plugin.php';

new \Netivo\ActionLoggerMU\Plugin();
