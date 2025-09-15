<?php

namespace AvelPress\Cli\Commands;

use AvelPress\Cli\Helpers\AppHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateFreshCommand extends Command {
	protected static $defaultName = 'migrate:fresh';

	protected function configure() {
		$this
			->setDescription( 'Drops all tables and re-runs all migrations.' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$output->writeln( "<info>Runs fresh migration</info>" );

		$appId = AppHelper::getAppId();

		$cmd = "wp --allow-root eval '\AvelPress\AvelPress::app(  \"migrator\", \"$appId\" )->fresh();'";
		$result = shell_exec( $cmd );

		if ( $result === null ) {
			$output->writeln( '<error>Failed to execute WP-CLI command, check your installation.</error>' );
			return Command::FAILURE;
		}

		$output->writeln( $result );

		return Command::SUCCESS;
	}

}