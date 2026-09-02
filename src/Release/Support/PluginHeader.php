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
	 * @return array{name: string, requires: string, requires_php: string, tested: string}
	 */
	public static function read( string $mainFile, $readme = null ): array {
		$plugin = file_exists( $mainFile ) ? (string) file_get_contents( $mainFile ) : '';
		$readmeContent = ( $readme && file_exists( $readme ) ) ? (string) file_get_contents( $readme ) : '';

		return [
			'name' => self::field( $plugin, 'Plugin Name' ),
			'requires' => self::field( $plugin, 'Requires at least' ),
			'requires_php' => self::field( $plugin, 'Requires PHP' ),
			'tested' => self::field( $readmeContent, 'Tested up to' ),
		];
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
