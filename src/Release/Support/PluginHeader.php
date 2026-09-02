<?php

namespace AvelPress\Cli\Release\Support;

/**
 * Reads the metadata WordPress itself reads.
 *
 * The update manifest served to installed plugins has to repeat what the plugin
 * header and the readme already declare — requirements, tested version and the
 * changelog of the release — so those values are read from the source of truth
 * instead of being configured a second time.
 */
class PluginHeader {

	/**
	 * Reads the headers of a plugin.
	 *
	 * @param string      $mainFile Main plugin file.
	 * @param string|null $readme   readme.txt, when the plugin has one.
	 * @return array{name: string, requires: string, requires_php: string, tested: string, requires_plugins: array}
	 */
	public static function read( string $mainFile, $readme = null ): array {
		$plugin = file_exists( $mainFile ) ? (string) file_get_contents( $mainFile ) : '';
		$readmeContent = ( $readme && file_exists( $readme ) ) ? (string) file_get_contents( $readme ) : '';

		return [
			'name' => self::field( $plugin, 'Plugin Name' ),
			'requires' => self::field( $plugin, 'Requires at least' ),
			'requires_php' => self::field( $plugin, 'Requires PHP' ),
			'tested' => self::field( $readmeContent, 'Tested up to' ),
			'requires_plugins' => self::requiredPlugins( $plugin ),
		];
	}

	/**
	 * Minimum version of other plugins this version depends on.
	 *
	 * WordPress has a "Requires Plugins" header but it carries no version, and a
	 * PRO release often needs a feature added to the free plugin. Declaring it
	 * here — next to the version, in the same file — means the requirement is
	 * raised in the same commit that writes the code needing it, instead of in a
	 * remote record somebody has to remember afterwards.
	 *
	 * Format: `Requires Plugins Versions: plugin-slug:1.9.0, other-slug:2.0`.
	 *
	 * @param string $content Main plugin file contents.
	 * @return array<string, string> Slug to minimum version.
	 */
	private static function requiredPlugins( string $content ): array {
		$declared = self::field( $content, 'Requires Plugins Versions' );

		if ( $declared === '' ) {
			return [];
		}

		$required = [];

		foreach ( explode( ',', $declared ) as $pair ) {
			$parts = explode( ':', trim( $pair ), 2 );

			if ( count( $parts ) !== 2 ) {
				continue;
			}

			$slug = trim( $parts[0] );
			$version = trim( $parts[1] );

			if ( $slug !== '' && $version !== '' ) {
				$required[ $slug ] = $version;
			}
		}

		return $required;
	}

	/**
	 * Extracts the changelog block of one version from a readme.
	 *
	 * @param string $readme  Path to readme.txt.
	 * @param string $version Version being released.
	 * @return string Empty string when the readme has no entry for it.
	 */
	public static function changelogFor( string $readme, string $version ): string {
		if ( ! file_exists( $readme ) ) {
			return '';
		}

		$content = (string) file_get_contents( $readme );
		$quoted = preg_quote( $version, '/' );

		if ( ! preg_match( '/^=\s*' . $quoted . '\s*(?:\S[^=\n]*)?=\s*$(.*?)(?=^=|\z)/ms', $content, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	/**
	 * Reads one header field.
	 *
	 * @param string $content File contents.
	 * @param string $field   Field name.
	 * @return string
	 */
	private static function field( string $content, string $field ): string {
		if ( ! preg_match( '/^[\s*#]*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $content, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}
}
