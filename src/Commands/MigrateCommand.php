<?php

namespace AvelPress\Cli\Commands;

use AvelPress\Cli\Helpers\AppHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command {
	protected static $defaultName = 'migrate';

	protected function configure() {
		$this
			->setDescription( 'Runs pending database migrations.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$output->writeln( "<info>Runs migration</info>" );

		$appId = AppHelper::getAppId();

		$cmd = "wp --allow-root eval '\AvelPress\AvelPress::app( 'migrator' )->run();'";
		$result = shell_exec( $cmd );

		if ( $result === null ) {
			$output->writeln( '<error>Failed to execute WP-CLI command, check your installation.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( $result );

		return Command::SUCCESS;
	}
}