<?php

namespace AvelPress\Cli\Release\Support;

/**
 * The few git operations a release needs.
 *
 * Reading git here can fail for a reason that has nothing to do with the code:
 * the CLI is commonly run inside a container as root over a bind mount owned by
 * the host user, and git refuses such repositories ("dubious ownership"). That
 * case is reported, never swallowed — a guard that quietly stops guarding is
 * worse than no guard, because the release looks checked when it was not.
 */
class Git {

	/**
	 * Inspects the working tree.
	 *
	 * @param string $dir Project root.
	 * @return array{state: string, message: string} State is one of "none" (not
	 *         a repository), "clean", "dirty" or "error".
	 */
	public static function inspect( string $dir ): array {
		$status = self::run( $dir, 'status --porcelain' );

		if ( $status['code'] === 0 ) {
			return [
				'state' => trim( $status['output'] ) === '' ? 'clean' : 'dirty',
				'message' => trim( $status['output'] ),
			];
		}

		if ( ! file_exists( rtrim( $dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.git' ) ) {
			return [ 'state' => 'none', 'message' => '' ];
		}

		return [ 'state' => 'error', 'message' => trim( $status['output'] ) ];
	}

	/**
	 * Whether the directory is a usable git repository.
	 *
	 * @param string $dir Project root.
	 * @return bool
	 */
	public static function isUsable( string $dir ): bool {
		$state = self::inspect( $dir )['state'];

		return $state === 'clean' || $state === 'dirty';
	}

	/**
	 * Commits the given files.
	 *
	 * @param string   $dir     Project root.
	 * @param string[] $files   Absolute paths to stage.
	 * @param string   $message Commit message.
	 * @throws \RuntimeException When git fails.
	 */
	public static function commit( string $dir, array $files, string $message ): void {
		foreach ( $files as $file ) {
			self::mustRun( $dir, 'add ' . escapeshellarg( $file ) );
		}

		self::mustRun( $dir, 'commit -m ' . escapeshellarg( $message ) );
	}

	/**
	 * Creates an annotated tag.
	 *
	 * @param string $dir  Project root.
	 * @param string $name Tag name.
	 * @throws \RuntimeException When git fails.
	 */
	public static function tag( string $dir, string $name ): void {
		self::mustRun( $dir, 'tag -a ' . escapeshellarg( $name ) . ' -m ' . escapeshellarg( $name ) );
	}

	/**
	 * Whether a tag already exists.
	 *
	 * @param string $dir  Project root.
	 * @param string $name Tag name.
	 * @return bool
	 */
	public static function hasTag( string $dir, string $name ): bool {
		return trim( self::run( $dir, 'tag --list ' . escapeshellarg( $name ) )['output'] ) !== '';
	}

	/**
	 * Runs a git command and fails loudly.
	 *
	 * @param string $dir       Project root.
	 * @param string $arguments Command arguments.
	 * @throws \RuntimeException When git returns a non zero code.
	 */
	private static function mustRun( string $dir, string $arguments ): void {
		$result = self::run( $dir, $arguments );

		if ( $result['code'] !== 0 ) {
			throw new \RuntimeException( "git $arguments failed: " . trim( $result['output'] ) );
		}
	}

	/**
	 * Runs a git command inside the project.
	 *
	 * safe.directory is passed on the command line as a courtesy: git only
	 * honours it there from 2.36 on, which is why a failure still has to be
	 * handled rather than assumed away.
	 *
	 * @param string $dir       Project root.
	 * @param string $arguments Command arguments.
	 * @return array{code: int, output: string}
	 */
	private static function run( string $dir, string $arguments ): array {
		$command = 'git -c safe.directory=' . escapeshellarg( $dir )
			. ' -C ' . escapeshellarg( $dir ) . ' ' . $arguments . ' 2>&1';

		$output = [];
		$code = 0;
		exec( $command, $output, $code );

		return [ 'code' => $code, 'output' => implode( "\n", $output ) ];
	}
}
