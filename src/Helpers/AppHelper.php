<?php

namespace AvelPress\Cli\Helpers;

class AppHelper {
	/**
	 * Loads avelpress.config.php from the current project.
	 *
	 * @return array
	 * @throws \RuntimeException When the file is missing.
	 */
	public static function getConfig(): array {
		$configFile = './avelpress.config.php';
		if ( ! file_exists( $configFile ) ) {
			throw new \RuntimeException( 'avelpress.config.php not found' );
		}
		$config = include $configFile;
		return is_array( $config ) ? $config : [];
	}

	public static function getAppId() {
		$config = self::getConfig();
		return isset( $config['plugin_id'] ) ? $config['plugin_id'] : null;
	}

}
