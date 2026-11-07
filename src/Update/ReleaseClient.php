<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Update;

use Netivo\ActionLoggerMU\Config;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ReleaseClient
 *
 * Reads the latest published GitHub release of the update repository
 * (`Config::update_repo()`), which is expected to carry a `.zip` asset laid
 * out to match `wp-content/mu-plugins/` exactly (the loader file plus the
 * plugin directory), built by that repository's own release workflow.
 */
class ReleaseClient {

	/**
	 * ReleaseClient constructor.
	 *
	 * @param Config $config Module configuration.
	 */
	public function __construct( private readonly Config $config ) {
	}

	/**
	 * Fetches the latest release, or null when it cannot be determined (no
	 * repo configured, a transport error, or a release without a zip asset).
	 *
	 * @return array{version: string, tag: string, zip_url: string, html_url: string, checksum_url: string|null}|null
	 */
	public function latest_release(): ?array {
		$repo = trim( $this->config->update_repo() );
		if ( '' === $repo ) {
			return null;
		}

		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'netivo-action-logger-mu',
		];
		$token = $this->config->update_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- read-only GitHub API call, never run on a VIP-hosted platform; result is cached by Updater::check().
		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo ),
			[
				'headers' => $headers,
				'timeout' => 3,
			]
		);

		if ( $response instanceof WP_Error ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || ! isset( $release['tag_name'] ) || ! is_string( $release['tag_name'] ) ) {
			return null;
		}

		$zip_url = self::zip_asset_url( $release );
		if ( null === $zip_url ) {
			return null;
		}

		return [
			'version'      => ltrim( $release['tag_name'], 'v' ),
			'tag'          => $release['tag_name'],
			'zip_url'      => $zip_url,
			'html_url'     => is_string( $release['html_url'] ?? null ) ? $release['html_url'] : '',
			'checksum_url' => self::checksum_asset_url( $release, $zip_url ),
		];
	}

	/**
	 * Finds the first `.zip` asset attached to a release.
	 *
	 * @param array<string, mixed> $release Decoded GitHub release.
	 *
	 * @return string|null
	 */
	private static function zip_asset_url( array $release ): ?string {
		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : [];

		foreach ( $assets as $asset ) {
			if (
				is_array( $asset )
				&& isset( $asset['browser_download_url'] )
				&& is_string( $asset['browser_download_url'] )
				&& str_ends_with( $asset['browser_download_url'], '.zip' )
			) {
				return $asset['browser_download_url'];
			}
		}

		return null;
	}

	/**
	 * Finds a `<zip-filename>.sha256` asset published alongside the zip, if
	 * any. This is a corruption/truncation check only, not independent
	 * artifact authentication: the digest is fetched from the same GitHub
	 * release response as the zip itself, so it does not protect against a
	 * compromised release pipeline or repository, only against a damaged or
	 * incomplete download. Older releases published before this file existed
	 * simply have no such asset, which `Updater` treats as "nothing to check"
	 * rather than a failure.
	 *
	 * @param array<string, mixed> $release Decoded GitHub release.
	 * @param string                $zip_url Resolved zip asset URL, to derive the expected sibling filename.
	 *
	 * @return string|null
	 */
	private static function checksum_asset_url( array $release, string $zip_url ): ?string {
		$assets        = is_array( $release['assets'] ?? null ) ? $release['assets'] : [];
		$zip_basename  = basename( (string) wp_parse_url( $zip_url, PHP_URL_PATH ) );
		$expected_name = $zip_basename . '.sha256';

		foreach ( $assets as $asset ) {
			if (
				is_array( $asset )
				&& isset( $asset['name'], $asset['browser_download_url'] )
				&& is_string( $asset['name'] )
				&& is_string( $asset['browser_download_url'] )
				&& $asset['name'] === $expected_name
			) {
				return $asset['browser_download_url'];
			}
		}

		return null;
	}
}
