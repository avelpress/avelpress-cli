<?php

namespace AvelPress\Cli\Release\Support;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Reads release credentials from .env files.
 *
 * Credentials never live in the repository, so they are resolved from, in order
 * of precedence: the real environment, the project .env and the global
 * ~/.avelpress/.env. The global file is what makes a single credential set
 * serve every plugin on the machine.
 */
class Env {

	/**
	 * Absolute paths of the .env files already loaded.
	 *
	 * @var string[]
	 */
	private static $loadedFiles = [];

	/**
	 * Whether load() already ran.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Loads the .env files once per process.
	 *
	 * The global file is loaded FIRST and the project file last, because Dotenv
	 * only protects variables that came from the real environment — a value it
	 * loaded itself from an earlier file is overwritten by a later one. Loading
	 * the project last is therefore what makes it win over the global file, and
	 * the real environment still wins over both.
	 *
	 * Getting this order wrong is not a cosmetic bug: it points the release at
	 * whichever site the global file names, which may not be the one the project
	 * intended.
	 *
	 * @param string|null $projectDir Directory of the plugin being released.
	 */
	public static function load( string $projectDir = null ): void {
		if ( self::$loaded ) {
			return;
		}

		self::$loaded = true;

		$candidates = [];
		$home = self::homeDir();

		if ( $home ) {
			$candidates[] = $home . DIRECTORY_SEPARATOR . '.avelpress' . DIRECTORY_SEPARATOR . '.env';
		}

		if ( $projectDir ) {
			$candidates[] = rtrim( $projectDir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.env';
		}

		$dotenv = new Dotenv();

		foreach ( $candidates as $file ) {
			if ( is_file( $file ) && is_readable( $file ) ) {
				$dotenv->load( $file );
				self::$loadedFiles[] = $file;
			}
		}
	}

	/**
	 * Returns a value from the environment.
	 *
	 * @param string $key     Variable name.
	 * @param mixed  $default Value returned when the variable is absent or empty.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$value = getenv( $key );

		if ( $value === false || $value === '' ) {
			$value = isset( $_ENV[ $key ] ) ? $_ENV[ $key ] : null;
		}

		if ( $value === null || $value === '' ) {
			$value = isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : null;
		}

		return ( $value === null || $value === '' ) ? $default : $value;
	}

	/**
	 * Returns a required value.
	 *
	 * @param string $key Variable name.
	 * @return string
	 * @throws \RuntimeException When the variable is not set anywhere.
	 */
	public static function mustGet( string $key ): string {
		$value = self::get( $key );

		if ( $value === null ) {
			throw new \RuntimeException(
				"Missing $key. Set it in .env, in ~/.avelpress/.env or as an environment variable."
			);
		}

		return (string) $value;
	}

	/**
	 * Whether every given key has a value.
	 *
	 * @param string[] $keys Variable names.
	 * @return bool
	 */
	public static function has( array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( self::get( $key ) === null ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Paths of the .env files that were loaded, for diagnostics.
	 *
	 * @return string[]
	 */
	public static function loadedFiles(): array {
		return self::$loadedFiles;
	}

	/**
	 * Resolves the user home directory across platforms.
	 *
	 * @return string|null
	 */
	private static function homeDir() {
		$home = getenv( 'HOME' );

		if ( ! $home ) {
			$home = getenv( 'USERPROFILE' );
		}

		return $home ? $home : null;
	}
}
