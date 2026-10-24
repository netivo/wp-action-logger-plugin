<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class Config
 *
 * Immutable module configuration. Values are resolved from (lowest to highest
 * priority): the `netivo_action_logger_mu_config` filter, then
 * `NETIVO_ACTION_LOGGER_MU_*` constants defined in wp-config.php. There is
 * intentionally no writable admin form and no theme coupling: this package
 * must work on any WordPress site regardless of theme.
 */
final class Config {

	/**
	 * Deliver events at the end of the request, after the response was sent when possible.
	 */
	public const DELIVERY_SHUTDOWN = 'shutdown';

	/**
	 * Write every event to the local queue and let the cron worker deliver it.
	 */
	public const DELIVERY_QUEUE = 'queue';

	/**
	 * Default total HTTP timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 3;

	/**
	 * Site token format issued by the dashboard.
	 */
	private const KEY_PATTERN = '/^nt_live_[0-9a-f]{32}$/';

	/**
	 * `owner/repo` the self-updater checks for new releases.
	 */
	public const DEFAULT_UPDATE_REPO = 'netivo/wp-action-logger-plugin';

	/**
	 * Configuration key => constant name.
	 */
	private const CONSTANTS = [
		'api_url'         => 'NETIVO_ACTION_LOGGER_MU_API_URL',
		'api_key'         => 'NETIVO_ACTION_LOGGER_MU_API_KEY',
		'enabled'         => 'NETIVO_ACTION_LOGGER_MU_ENABLED',
		'delivery'        => 'NETIVO_ACTION_LOGGER_MU_DELIVERY',
		'timeout'         => 'NETIVO_ACTION_LOGGER_MU_TIMEOUT',
		'trusted_proxies' => 'NETIVO_ACTION_LOGGER_MU_TRUSTED_PROXIES',
		'update_repo'     => 'NETIVO_ACTION_LOGGER_MU_UPDATE_REPO',
		'update_token'    => 'NETIVO_ACTION_LOGGER_MU_UPDATE_TOKEN',
	];

	/**
	 * Config constructor.
	 *
	 * @param string   $api_url         Base URL of the Action Logger API.
	 * @param string   $api_key         Site token (`nt_live_...`).
	 * @param bool     $enabled         Whether logging is switched on.
	 * @param string   $delivery        One of the DELIVERY_* constants.
	 * @param int      $timeout         Total HTTP timeout in seconds.
	 * @param string[] $trusted_proxies Proxy IPs whose X-Forwarded-For header is trusted.
	 * @param string   $update_repo     `owner/repo` the self-updater checks for new releases.
	 * @param string   $update_token    Optional GitHub token, for a private update repo or a higher rate limit.
	 */
	public function __construct(
		private readonly string $api_url,
		private readonly string $api_key,
		private readonly bool $enabled,
		private readonly string $delivery,
		private readonly int $timeout,
		private readonly array $trusted_proxies,
		private readonly string $update_repo = self::DEFAULT_UPDATE_REPO,
		private readonly string $update_token = ''
	) {
	}

	/**
	 * Resolves the configuration from all supported sources.
	 *
	 * @return self
	 */
	public static function load(): self {
		$values = self::defaults();

		// Filters the configuration before wp-config constants are applied
		// (netivo_action_logger_mu_config); not documented inline with a
		// @param tag, since the result is validated below rather than trusted.
		$filtered = apply_filters( 'netivo_action_logger_mu_config', $values );
		if ( is_array( $filtered ) ) {
			$values = array_merge( $values, self::known( $filtered ) );
		}

		$values = array_merge( $values, self::from_constants() );

		return self::from_array( $values );
	}

	/**
	 * Builds a configuration from raw values, normalizing types.
	 *
	 * @param array<string, mixed> $values Raw configuration values.
	 *
	 * @return self
	 */
	public static function from_array( array $values ): self {
		$values = array_merge( self::defaults(), $values );

		$delivery = is_string( $values['delivery'] ) ? strtolower( trim( $values['delivery'] ) ) : '';
		if ( ! in_array( $delivery, [ self::DELIVERY_SHUTDOWN, self::DELIVERY_QUEUE ], true ) ) {
			$delivery = self::DELIVERY_SHUTDOWN;
		}

		$timeout = is_numeric( $values['timeout'] ) ? (int) $values['timeout'] : self::DEFAULT_TIMEOUT;

		$proxies = $values['trusted_proxies'];
		if ( is_string( $proxies ) ) {
			$proxies = explode( ',', $proxies );
		}
		$proxies = is_array( $proxies ) ? array_values( array_filter( array_map( 'trim', array_filter( $proxies, 'is_string' ) ) ) ) : [];

		return new self(
			is_string( $values['api_url'] ) ? trim( $values['api_url'] ) : '',
			is_string( $values['api_key'] ) ? trim( $values['api_key'] ) : '',
			self::to_bool( $values['enabled'] ),
			$delivery,
			max( 1, min( 10, $timeout ) ),
			$proxies,
			is_string( $values['update_repo'] ) ? trim( $values['update_repo'] ) : self::DEFAULT_UPDATE_REPO,
			is_string( $values['update_token'] ) ? trim( $values['update_token'] ) : ''
		);
	}

	/**
	 * Default values used when no source provides a setting.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return [
			'api_url'         => '',
			'api_key'         => '',
			'enabled'         => true,
			'delivery'        => self::DELIVERY_SHUTDOWN,
			'timeout'         => self::DEFAULT_TIMEOUT,
			'trusted_proxies' => [],
			'update_repo'     => self::DEFAULT_UPDATE_REPO,
			'update_token'    => '',
		];
	}

	/**
	 * Keeps only recognized, non-null configuration keys.
	 *
	 * @param array<mixed> $values Values from an external source.
	 *
	 * @return array<string, mixed>
	 */
	private static function known( array $values ): array {
		$result = [];
		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( array_key_exists( $key, $values ) && null !== $values[ $key ] && '' !== $values[ $key ] ) {
				$result[ $key ] = $values[ $key ];
			}
		}

		return $result;
	}

	/**
	 * Reads `NETIVO_ACTION_LOGGER_MU_*` constants.
	 *
	 * @return array<string, mixed>
	 */
	private static function from_constants(): array {
		$values = [];
		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$values[ $key ] = constant( $constant );
			}
		}

		return self::known( $values );
	}

	/**
	 * Interprets booleans written as strings or numbers in config files.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	private static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) || is_int( $value ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}

		return (bool) $value;
	}

	/**
	 * Returns the configured API base URL.
	 *
	 * @return string
	 */
	public function api_url(): string {
		return $this->api_url;
	}

	/**
	 * Returns the configured site token.
	 *
	 * @return string
	 */
	public function api_key(): string {
		return $this->api_key;
	}

	/**
	 * Whether logging is switched on (independent of validity).
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Returns the delivery mode (one of the DELIVERY_* constants).
	 *
	 * @return string
	 */
	public function delivery(): string {
		return $this->delivery;
	}

	/**
	 * Returns the total HTTP timeout in seconds (1-10).
	 *
	 * @return int
	 */
	public function timeout(): int {
		return $this->timeout;
	}

	/**
	 * Returns proxy IPs whose forwarded-for header is trusted.
	 *
	 * @return string[]
	 */
	public function trusted_proxies(): array {
		return $this->trusted_proxies;
	}

	/**
	 * `owner/repo` the self-updater checks for new releases.
	 *
	 * @return string
	 */
	public function update_repo(): string {
		return $this->update_repo;
	}

	/**
	 * Optional GitHub token sent as a Bearer header to the releases API, for a
	 * private update repo or to raise the unauthenticated rate limit.
	 *
	 * @return string
	 */
	public function update_token(): string {
		return $this->update_token;
	}

	/**
	 * Full URL of the ingestion endpoint.
	 *
	 * @return string
	 */
	public function endpoint(): string {
		$base = rtrim( $this->api_url, '/' );
		if ( ! str_ends_with( $base, '/api/v1' ) ) {
			$base .= '/api/v1';
		}

		return $base . '/ingest';
	}

	/**
	 * Lists configuration problems that prevent delivery.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$errors = [];

		if ( '' === $this->api_url ) {
			$errors[] = 'API URL is not configured (NETIVO_ACTION_LOGGER_MU_API_URL).';
		} elseif ( ! self::is_valid_url( $this->api_url ) ) {
			$errors[] = 'API URL must be an absolute http(s) URL.';
		}

		if ( '' === $this->api_key ) {
			$errors[] = 'API key is not configured (NETIVO_ACTION_LOGGER_MU_API_KEY).';
		} elseif ( 1 !== preg_match( self::KEY_PATTERN, $this->api_key ) ) {
			$errors[] = 'API key must have the format nt_live_ followed by 32 hex characters.';
		}

		return $errors;
	}

	/**
	 * Whether the module should capture and deliver events.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->enabled && [] === $this->errors();
	}

	/**
	 * Site token with the middle part hidden, safe to display.
	 *
	 * @return string
	 */
	public function masked_key(): string {
		if ( strlen( $this->api_key ) <= 16 ) {
			return '' === $this->api_key ? '' : str_repeat( '•', 8 );
		}

		return substr( $this->api_key, 0, 12 ) . '…' . substr( $this->api_key, -4 );
	}

	/**
	 * Checks for an absolute http(s) URL with a host.
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool
	 */
	private static function is_valid_url( string $url ): bool {
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, [ 'http', 'https' ], true ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
	}
}
