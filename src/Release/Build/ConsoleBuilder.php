<?php

namespace AvelPress\Cli\Release\Build;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the existing `build` command as a step of the release.
 *
 * The release never reimplements packaging: it delegates to the same command a
 * developer runs by hand, so both always produce the same ZIP.
 */
class ConsoleBuilder {

	/**
	 * Console application holding the build command.
	 *
	 * @var Application
	 */
	private $application;

	/**
	 * @param Application $application Console application.
	 */
	public function __construct( Application $application ) {
		$this->application = $application;
	}

	/**
	 * Builds the distribution package.
	 *
	 * @param OutputInterface $output Console output.
	 * @throws \RuntimeException When the build command fails.
	 */
	public function build( OutputInterface $output ): void {
		$exitCode = $this->application->find( 'build' )->run( new ArrayInput( [] ), $output );

		if ( $exitCode !== 0 ) {
			throw new \RuntimeException( 'Build failed, nothing was published.' );
		}
	}
}
