<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Transport;

use Netivo\ActionLoggerMU\Payload\ErrorPayload;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Interface ErrorSink
 *
 * Receives error events captured by the runtime interceptor.
 */
interface ErrorSink {

	/**
	 * Accepts an error event for delivery.
	 *
	 * @param ErrorPayload $error Captured error.
	 *
	 * @return void
	 */
	public function dispatch( ErrorPayload $error ): void;
}
