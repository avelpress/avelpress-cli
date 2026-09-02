<?php

namespace AvelPress\Cli\Release\Support;

/**
 * Reads the plugin header from inside a built package.
 *
 * The point is to catch a stale dist/ folder before it reaches customers: the
 * ZIP that is about to be published must declare the version being released.
 * PHP's zip extension is optional, so the unzip binary is used as a fallback and
 * the check simply reports "unknown" when neither is available.
 */
class PackageInspector {

	/**
	 * Version declared inside the packaged plugin.
	 *
	 * @param string $zipFile  Path of the package.
	 * @param string $pluginId Plugin id, which is also the folder inside the ZIP.
	 * @return string|null Version, or null when it could not be read.
	 */
	public static function versionIn( string $zipFile, string $pluginId ) {
		$entry = $pluginId . '/' . $pluginId . '.php';
		$content = self::readEntry( $zipFile, $entry );

		if ( $content === null || ! preg_match( '/\*\s*Version:\s*(.+)/i', $content, $matches ) ) {
			return null;
		}

		return trim( $matches[1] );
	}

	/**
	 * Reads one file out of a ZIP.
	 *
	 * @param string $zipFile Path of the package.
	 * @param string $entry   Path inside the archive.
	 * @return string|null
	 */
	private static function readEntry( string $zipFile, string $entry ) {
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();

			if ( $zip->open( $zipFile ) !== true ) {
				return null;
			}

			$content = $zip->getFromName( $entry );
			$zip->close();

			return $content === false ? null : $content;
		}

		$output = [];
		$code = 0;
		exec( 'unzip -p ' . escapeshellarg( $zipFile ) . ' ' . escapeshellarg( $entry ) . ' 2>/dev/null', $output, $code );

		return $code === 0 && $output ? implode( "\n", $output ) : null;
	}
}
