<?php

namespace AvelPress\Cli\Release;

use AvelPress\Cli\Helpers\AppHelper;
use AvelPress\Cli\Release\Support\DownloadMatcher;

/**
 * Everything the release needs to know about the project it is publishing.
 *
 * Reads avelpress.config.php once and answers with defaults, so a plugin that
 * never configured a `release` block still works: the slug is the plugin id and
 * the version files are the ones an AvelPress plugin always has.
 */
class ReleaseContext {

	/**
	 * Project root.
	 *
	 * @var string
	 */
	private $projectDir;

	/**
	 * Contents of avelpress.config.php.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * @param string $projectDir Project root.
	 * @param array  $config     Contents of avelpress.config.php.
	 * @throws \RuntimeException When the config has no plugin id.
	 */
	public function __construct( string $projectDir, array $config ) {
		if ( empty( $config['plugin_id'] ) ) {
			throw new \RuntimeException( 'plugin_id is not set in avelpress.config.php.' );
		}

		$this->projectDir = rtrim( $projectDir, DIRECTORY_SEPARATOR );
		$this->config = $config;
	}

	/**
	 * Builds the context from the current directory.
	 *
	 * @param string $projectDir Project root.
	 * @return self
	 */
	public static function fromProject( string $projectDir ): self {
		return new self( $projectDir, AppHelper::getConfig() );
	}

	/**
	 * Plugin id.
	 *
	 * @return string
	 */
	public function pluginId(): string {
		return $this->config['plugin_id'];
	}

	/**
	 * Project root.
	 *
	 * @return string
	 */
	public function projectDir(): string {
		return $this->projectDir;
	}

	/**
	 * File names this plugin owns in the store.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		$match = $this->release( 'store.match' );

		return $match ? $match : [ $this->pluginId() ];
	}

	/**
	 * Sibling file names that must not be claimed.
	 *
	 * @return string[]
	 */
	public function excludes(): array {
		$exclude = $this->release( 'store.exclude' );

		return $exclude ? $exclude : [];
	}

	/**
	 * Matcher for this plugin's packages.
	 *
	 * @return DownloadMatcher
	 */
	public function matcher(): DownloadMatcher {
		return new DownloadMatcher( $this->slugs(), $this->excludes() );
	}

	/**
	 * Main plugin file.
	 *
	 * @return string
	 */
	public function mainFile(): string {
		return VersionManager::mainFile( $this->projectDir, $this->pluginId() );
	}

	/**
	 * Version currently declared in the plugin header.
	 *
	 * @return string|null
	 */
	public function version() {
		return VersionManager::readVersion( $this->mainFile() );
	}

	/**
	 * Directory where the build writes the package.
	 *
	 * @return string
	 */
	public function distDir(): string {
		$dir = isset( $this->config['build']['output_dir'] ) && $this->config['build']['output_dir']
			? $this->config['build']['output_dir']
			: 'dist';

		$isAbsolute = strpos( $dir, DIRECTORY_SEPARATOR ) === 0 || preg_match( '/^[A-Za-z]:/', $dir );

		return $isAbsolute ? rtrim( $dir, DIRECTORY_SEPARATOR ) : $this->projectDir . DIRECTORY_SEPARATOR . $dir;
	}

	/**
	 * Path of the package the build produces for a version.
	 *
	 * @param string $version Version being released.
	 * @return string
	 */
	public function zipPath( string $version ): string {
		return $this->distDir() . DIRECTORY_SEPARATOR . $this->pluginId() . '-' . $version . '.zip';
	}

	/**
	 * Files that carry the version number.
	 *
	 * @return string[] Absolute paths of files that exist.
	 */
	public function versionFiles(): array {
		$configured = $this->release( 'version_files' );

		if ( ! $configured ) {
			$configured = [ $this->pluginId() . '.php', 'readme.txt' ];
		}

		$files = [];

		foreach ( $configured as $file ) {
			$path = $this->projectDir . DIRECTORY_SEPARATOR . ltrim( $file, DIRECTORY_SEPARATOR );

			if ( file_exists( $path ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/**
	 * URL that serves the published update manifest, when there is one.
	 *
	 * Lets the audit compare what the plugin declares today against what the
	 * manifest is actually enforcing — the two drift whenever a release is
	 * pending or a requirement was raised in the code but never published.
	 *
	 * @return string|null
	 */
	public function manifestUrl() {
		$url = $this->release( 'manifest.check_url' );

		if ( ! $url ) {
			return null;
		}

		return str_replace( '{slug}', $this->pluginId(), $url );
	}

	/**
	 * How many published packages to keep when pruning.
	 *
	 * @return int
	 */
	public function keep(): int {
		$keep = $this->release( 'retention.keep' );

		return $keep === null ? 3 : (int) $keep;
	}

	/**
	 * Whether the release refuses to run with uncommitted changes.
	 *
	 * @return bool
	 */
	public function requireCleanGit(): bool {
		return $this->release( 'git.require_clean' ) !== false;
	}

	/**
	 * Whether a bump also creates a git tag.
	 *
	 * @return bool
	 */
	public function shouldTag(): bool {
		return (bool) $this->release( 'git.tag' );
	}

	/**
	 * Reads a dotted key from the release config.
	 *
	 * @param string $path Dotted key, e.g. "store.match".
	 * @return mixed|null
	 */
	private function release( string $path ) {
		$value = isset( $this->config['release'] ) ? $this->config['release'] : [];

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}
}
