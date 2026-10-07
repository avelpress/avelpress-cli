<?php

namespace AvelPress\Cli\Build;

/**
 * Keeps the self-hosted updater out of wordpress.org builds.
 *
 * wordpress.org delivers updates itself, and a plugin carrying code that hooks
 * the update check is rejected in review. A project declares
 * `'build' => ['wordpress_org' => true]` and the build refuses to produce a
 * package that requires avelpress/updater or still configures it.
 */
class WordPressOrgGuard {

	const UPDATER_PACKAGE = 'avelpress/updater';

	/**
	 * Whether the project is built for wordpress.org.
	 *
	 * @param array $config avelpress.config.php contents.
	 */
	public static function applies( array $config ): bool {
		return ! empty( $config['build']['wordpress_org'] );
	}

	/**
	 * Problems visible before installing anything.
	 *
	 * @param string $projectDir   Project root.
	 * @param array  $composerData Decoded composer.json, or [] when absent.
	 * @param string $pluginId     Plugin id; the main file is {plugin_id}.php.
	 * @return string[] One message per problem, empty when the project is clean.
	 */
	public function checkProject( string $projectDir, array $composerData, string $pluginId ): array {
		$problems = [];

		if ( isset( $composerData['require'][ self::UPDATER_PACKAGE ] ) ) {
			$problems[] = 'composer.json requires ' . self::UPDATER_PACKAGE . '.';
		}

		$mainFile = "$projectDir/$pluginId.php";

		if ( is_file( $mainFile ) && preg_match( '/[\'"]updater[\'"]\s*=>/', (string) file_get_contents( $mainFile ) ) ) {
			$problems[] = "$pluginId.php passes an 'updater' config to AvelPress::init().";
		}

		foreach ( $this->filesUsingUpdater( $projectDir, $pluginId ) as $file ) {
			$problems[] = "$file uses the AvelPress\\Update classes.";
		}

		return $problems;
	}

	/**
	 * Problems in the dependencies composer actually installed, which also
	 * catches the updater arriving through another package.
	 *
	 * @param string[] $installedPackages Package names from vendor/composer/installed.json.
	 * @return string[]
	 */
	public function checkInstalled( array $installedPackages ): array {
		if ( ! in_array( self::UPDATER_PACKAGE, $installedPackages, true ) ) {
			return [];
		}

		return [ self::UPDATER_PACKAGE . ' was installed as a dependency of another package.' ];
	}

	/**
	 * Formats the failure shown to the developer.
	 *
	 * @param string[] $problems Messages from the checks.
	 */
	public static function message( array $problems ): string {
		return "This plugin is built for wordpress.org ('build' => ['wordpress_org' => true]), "
			. "which delivers updates itself and rejects plugins that change the update source:\n  - "
			. implode( "\n  - ", $problems )
			. "\nRemove avelpress/updater and the 'updater' config, or drop 'wordpress_org' if this plugin is not distributed through wordpress.org.";
	}

	/**
	 * Project PHP files (main file and src/) that reference AvelPress\Update.
	 *
	 * @return string[] Paths relative to the project root.
	 */
	private function filesUsingUpdater( string $projectDir, string $pluginId ): array {
		$files = [];
		$candidates = [ "$projectDir/$pluginId.php" ];

		if ( is_dir( "$projectDir/src" ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( "$projectDir/src", \RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $item ) {
				if ( $item->isFile() && $item->getExtension() === 'php' ) {
					$candidates[] = $item->getPathname();
				}
			}
		}

		foreach ( $candidates as $file ) {
			if ( is_file( $file ) && strpos( (string) file_get_contents( $file ), 'AvelPress\\Update\\' ) !== false ) {
				$files[] = ltrim( substr( $file, strlen( $projectDir ) ), '/' );
			}
		}

		return $files;
	}
}
