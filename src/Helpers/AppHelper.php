<?php

namespace AvelPress\Cli\Helpers;

class AppHelper {
	public static function getAppId() {
		$configFile = './avelpress.config.php';
		if ( ! file_exists( $configFile ) ) {
			throw new \RuntimeException( 'avelpress.config.php not found' );
		}
		$config = include $configFile;
		return isset( $config['plugin_id'] ) ? $config['plugin_id'] : null;
	}

}