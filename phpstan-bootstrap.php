<?php
/**
 * Declares, for PHPStan only, the two constants that netivo-action-logger-mu.php
 * defines at runtime before requiring src/Update/Updater.php. Not shipped with
 * the plugin itself (see .gitattributes export-ignore).
 */

if ( ! defined( 'NETIVO_ACTION_LOGGER_MU_DIR' ) ) {
	define( 'NETIVO_ACTION_LOGGER_MU_DIR', __DIR__ . '/src' );
}
if ( ! defined( 'NETIVO_ACTION_LOGGER_MU_LOADER_FILE' ) ) {
	define( 'NETIVO_ACTION_LOGGER_MU_LOADER_FILE', __DIR__ . '/netivo-action-logger-mu-loader.php' );
}
