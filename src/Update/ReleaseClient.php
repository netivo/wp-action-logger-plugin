<?php
/**
 * Created by Netivo for action-logger-mu
 *
 * @package Netivo\ActionLoggerMU
 */

namespace Netivo\ActionLoggerMU\Update;

use Netivo\ActionLoggerMU\Config;
use Netivo\ActionLoggerMU\Plugin;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.0 403 Forbidden' );
	exit;
}

/**
 * Class ReleaseClient
 *
 * Reads the latest published release from the Netivo Cloudflare repository
 * (`Config::update_url()`), falling back to GitHub Releases if an external
 * GitHub repository is configured directly.
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
	 * Fetches the latest release, or null when it cannot be determined.
	 *
	 * @return array{
	 *     version: string,
	 *     tag: string,
	 *     zip_url: string,
	 *     html_url: string,
	 *     checksum_url: string|null,
	 *     sha256: string|null,
	 *     requires_php?: string,
	 *     requires_wp?: string,
	 *     tested_wp?: string,
	 *     changelog?: string
	 * }|null
	 */
	public function latest_release(): ?array {
		$url = trim( $this->config->update_url() );
		if ( '' !== $url ) {
			$manifest_release = $this->latest_from_manifest( $url, $this->config->update_slug() );
			if ( null !== $manifest_release ) {
				return $manifest_release;
			}
		}

		$repo = trim( $this->config->update_repo() );
		if ( '' !== $repo ) {
			return $this->latest_from_github( $repo );
		}

		return null;
	}

	/**
	 * Fetches release information from the Cloudflare repository JSON manifest.
	 *
	 * @param string $base_url Repository base URL.
	 * @param string $slug     Plugin slug.
	 *
	 * @return array{
	 *     version: string,
	 *     tag: string,
	 *     zip_url: string,
	 *     html_url: string,
	 *     checksum_url: string|null,
	 *     sha256: string|null,
	 *     requires_php: string,
	 *     requires_wp: string,
	 *     tested_wp: string,
	 *     changelog: string
	 * }|null
	 */
	private function latest_from_manifest( string $base_url, string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}

		$manifest_url = sprintf( '%s/%s/info.json', rtrim( $base_url, '/' ), $slug );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- read-only update check against Cloudflare CDN.
		$response = wp_remote_get(
			$manifest_url,
			[
				'headers' => [
					'Accept'     => 'application/json',
					'User-Agent' => 'netivo-action-logger-mu/' . Plugin::VERSION . '; ' . ( function_exists( 'home_url' ) ? home_url() : '' ),
				],
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

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['latest'], $data['versions'] ) || ! is_array( $data['versions'] ) ) {
			return null;
		}

		$latest_version = (string) $data['latest'];
		$version_data   = $data['versions'][ $latest_version ] ?? null;

		if ( ! is_array( $version_data ) || ! isset( $version_data['download_url'] ) || ! is_string( $version_data['download_url'] ) ) {
			return null;
		}

		$tag          = isset( $version_data['tag'] ) && is_string( $version_data['tag'] ) ? $version_data['tag'] : 'v' . $latest_version;
		$checksum_url = isset( $version_data['checksum']['url'] ) && is_string( $version_data['checksum']['url'] ) ? $version_data['checksum']['url'] : null;
		$sha256       = isset( $version_data['checksum']['value'] ) && is_string( $version_data['checksum']['value'] ) ? strtolower( trim( $version_data['checksum']['value'] ) ) : null;

		return [
			'version'      => ltrim( $latest_version, 'v' ),
			'tag'          => $tag,
			'zip_url'      => $version_data['download_url'],
			'html_url'     => isset( $data['homepage'] ) && is_string( $data['homepage'] ) ? $data['homepage'] : '',
			'checksum_url' => $checksum_url,
			'sha256'       => $sha256,
			'requires_php' => isset( $version_data['requires_php'] ) && is_string( $version_data['requires_php'] ) ? $version_data['requires_php'] : '8.2',
			'requires_wp'  => isset( $version_data['requires_wp'] ) && is_string( $version_data['requires_wp'] ) ? $version_data['requires_wp'] : '6.3',
			'tested_wp'    => isset( $version_data['tested_wp'] ) && is_string( $version_data['tested_wp'] ) ? $version_data['tested_wp'] : '6.7',
			'changelog'    => isset( $version_data['changelog'] ) && is_string( $version_data['changelog'] ) ? $version_data['changelog'] : '',
		];
	}

	/**
	 * Legacy GitHub Releases client for fallback or backward compatibility.
	 *
	 * @param string $repo Repository owner/name.
	 *
	 * @return array{
	 *     version: string,
	 *     tag: string,
	 *     zip_url: string,
	 *     html_url: string,
	 *     checksum_url: string|null,
	 *     sha256: null
	 * }|null
	 */
	private function latest_from_github( string $repo ): ?array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'netivo-action-logger-mu',
		];
		$token   = $this->config->update_token();
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
			'sha256'       => null,
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
