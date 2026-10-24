<?php
/**
 * Plugin Name: Netivo Action Logger (MU) Loader
 * Description: Loads the Netivo Action Logger must-use plugin from its own directory. Copy this file to wp-content/mu-plugins/ alongside the netivo-action-logger-mu/ directory; see that package's README.
 * Version: 0.2.1
 * Author: Netivo
 * Author URI: https://netivo.pl
 * License: Proprietary
 *
 * wp-content/mu-plugins/ only auto-loads .php files directly inside it, not
 * subdirectories, so this stub is the one file that belongs at its root.
 * The actual plugin code lives in the sibling netivo-action-logger-mu/
 * directory and is loaded from there.
 *
 * @package Netivo\ActionLoggerMU
 */

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

$netivo_action_logger_mu_bootstrap = __DIR__ . '/netivo-action-logger-mu/netivo-action-logger-mu.php';

if ( is_readable( $netivo_action_logger_mu_bootstrap ) ) {
	require_once $netivo_action_logger_mu_bootstrap;
}

unset( $netivo_action_logger_mu_bootstrap );
