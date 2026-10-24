<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Access;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Guard
 *
 * Restricts visibility of this module's admin UI to staff of the agencies that
 * operate it. The site's own administrators keep full control over every other
 * plugin; only this internal diagnostics screen (and the module's entry in the
 * "Must Use" plugins list) is limited to known staff email domains, since there
 * is nothing here for a client to configure — settings are read-only and come
 * from wp-config.php.
 */
final class Guard {

	/**
	 * Email domains (lowercase, no leading @) whose users may see the admin UI.
	 */
	private const ALLOWED_DOMAINS = [ 'netivo.pl', 'fehu-web.dev' ];

	/**
	 * Filter used to customize the allowed domains.
	 */
	private const FILTER = 'netivo_action_logger_mu_allowed_domains';

	/**
	 * Whether the signed-in user belongs to an allowed domain.
	 *
	 * @return bool
	 */
	public static function current_user_is_privileged(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return self::email_is_privileged( wp_get_current_user()->user_email );
	}

	/**
	 * Whether an email address belongs to an allowed domain.
	 *
	 * @param string $email Email address to check.
	 *
	 * @return bool
	 */
	public static function email_is_privileged( string $email ): bool {
		$domain = self::domain_of( $email );
		if ( '' === $domain ) {
			return false;
		}

		return in_array( $domain, self::allowed_domains(), true );
	}

	/**
	 * Allowed domains, lowercase and filterable.
	 *
	 * @return string[]
	 */
	public static function allowed_domains(): array {
		// Filters the email domains allowed to see the Action Logger MU admin UI
		// (netivo_action_logger_mu_allowed_domains); not documented inline with
		// a @param tag, since the result is validated below rather than trusted.
		$domains = apply_filters( self::FILTER, self::ALLOWED_DOMAINS );
		if ( ! is_array( $domains ) ) {
			return self::ALLOWED_DOMAINS;
		}

		return array_values(
			array_unique(
				array_map(
					static fn ( string $domain ): string => strtolower( trim( $domain ) ),
					array_filter( $domains, 'is_string' )
				)
			)
		);
	}

	/**
	 * Lowercase domain part of an email address, or an empty string when it has none.
	 *
	 * @param string $email Email address.
	 *
	 * @return string
	 */
	private static function domain_of( string $email ): string {
		$at = strrchr( $email, '@' );
		if ( false === $at ) {
			return '';
		}

		return strtolower( substr( $at, 1 ) );
	}
}
