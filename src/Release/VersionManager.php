<?php

namespace AvelPress\Cli\Release;

/**
 * Reads and compares plugin versions.
 *
 * The version of an AvelPress plugin lives in the "Version:" header of its main
 * file, which is also what the build uses to name the ZIP. Keeping the parsing
 * here means the build and the release always read the same number.
 */
class VersionManager {

	/**
	 * Resolves the main plugin file for a project.
	 *
	 * @param string $projectDir Project root.
	 * @param string $pluginId   Plugin id from avelpress.config.php.
	 * @return string Absolute path, even when the file does not exist.
	 */
	public static function mainFile( string $projectDir, string $pluginId ): string {
		return rtrim( $projectDir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $pluginId . '.php';
	}

	/**
	 * Reads the version declared in a plugin header.
	 *
	 * @param string $file Path to the main plugin file.
	 * @return string|null Version, or null when the file or the header is absent.
	 */
	public static function readVersion( string $file ) {
		if ( ! file_exists( $file ) ) {
			return null;
		}

		$content = file_get_contents( $file );

		if ( $content === false || ! preg_match( '/\*\s*Version:\s*(.+)/i', $content, $matches ) ) {
			return null;
		}

		$version = trim( $matches[1] );

		return $version === '' ? null : $version;
	}

	/**
	 * Compares two versions the same way WordPress does.
	 *
	 * @param string $a First version.
	 * @param string $b Second version.
	 * @return int -1 when $a is older, 0 when equal, 1 when $a is newer.
	 */
	public static function compare( string $a, string $b ): int {
		return version_compare( $a, $b );
	}

	/**
	 * Increments one part of a version.
	 *
	 * @param string $version Current version.
	 * @param string $part    "major", "minor" or "patch".
	 * @return string
	 * @throws \RuntimeException When the version or the part is not understood.
	 */
	public static function bump( string $version, string $part ): string {
		if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
			throw new \RuntimeException( "Cannot bump \"$version\": it is not a plain numeric version." );
		}

		$numbers = array_map( 'intval', explode( '.', $version ) );
		$numbers = array_pad( $numbers, 3, 0 );

		$index = [ 'major' => 0, 'minor' => 1, 'patch' => 2 ];

		if ( ! isset( $index[ $part ] ) ) {
			throw new \RuntimeException( "Unknown bump \"$part\". Use major, minor or patch." );
		}

		$position = $index[ $part ];
		$numbers[ $position ]++;

		for ( $i = $position + 1; $i < count( $numbers ); $i++ ) {
			$numbers[ $i ] = 0;
		}

		return implode( '.', $numbers );
	}

	/**
	 * Rewrites the version in the files that declare it.
	 *
	 * Only the lines that are meant to carry a version are touched — the plugin
	 * header, the readme stable tag and version constants — so a version number
	 * that happens to appear elsewhere in the file is left alone.
	 *
	 * @param string[] $files Absolute paths.
	 * @param string   $old   Version being replaced.
	 * @param string   $new   New version.
	 * @return string[] Files that changed.
	 */
	public static function writeVersion( array $files, string $old, string $new ): array {
		$quoted = preg_quote( $old, '/' );

		$patterns = [
			'/(\*\s*Version:\s*)' . $quoted . '(\s*$)/mi',
			'/(^Stable tag:\s*)' . $quoted . '(\s*$)/mi',
			'/(define\(\s*[\'"][A-Z0-9_]*VERSION[\'"]\s*,\s*[\'"])' . $quoted . '([\'"])/',
		];

		$changed = [];

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			if ( $content === false ) {
				continue;
			}

			$updated = preg_replace( $patterns, '${1}' . $new . '${2}', $content );

			if ( $updated !== null && $updated !== $content ) {
				file_put_contents( $file, $updated );
				$changed[] = $file;
			}
		}

		return $changed;
	}

	/**
	 * Whether a readme declares a changelog section for a version.
	 *
	 * @param string $readme  Path to readme.txt.
	 * @param string $version Version being released.
	 * @return bool
	 */
	public static function hasChangelogEntry( string $readme, string $version ): bool {
		if ( ! file_exists( $readme ) ) {
			return false;
		}

		$content = file_get_contents( $readme );

		return $content !== false && preg_match( '/^=\s*' . preg_quote( $version, '/' ) . '\s*(\S[^=]*)?=/mi', $content ) === 1;
	}
}
