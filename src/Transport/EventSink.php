<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Transport;

use Netivo\ActionLoggerMU\Payload\EventPayload;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Interface EventSink
 *
 * Receives events captured by listeners.
 */
interface EventSink {

	/**
	 * Accepts an event for delivery.
	 *
	 * @param EventPayload $event Captured event.
	 *
	 * @return void
	 */
	public function dispatch( EventPayload $event ): void;
}
