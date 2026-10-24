<?php
/**
 * PHPUnit bootstrap: defines ABSPATH, loads Composer and minimal WordPress
 * class stubs. WordPress functions are mocked per test with Brain Monkey.
 */

// Ensure ABSPATH is defined before loading any code that checks it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// WordPress defines these in wp-includes/default-constants.php before any
// mu-plugin loads; class constants that use them (e.g. Updater::CHECK_INTERVAL)
// need them defined before the class declaration is parsed.
foreach (
	[
		'MINUTE_IN_SECONDS' => 60,
		'HOUR_IN_SECONDS'   => 60 * 60,
		'DAY_IN_SECONDS'    => 60 * 60 * 24,
		'WEEK_IN_SECONDS'   => 60 * 60 * 24 * 7,
		'YEAR_IN_SECONDS'   => 60 * 60 * 24 * 365,
	] as $nt_time_constant => $nt_time_value
) {
	if ( ! defined( $nt_time_constant ) ) {
		define( $nt_time_constant, $nt_time_value );
	}
}
unset( $nt_time_constant, $nt_time_value );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/wordpress.php';
