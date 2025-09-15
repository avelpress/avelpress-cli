<?php

namespace AvelPress\Cli;

use AvelPress\Cli\Commands\MigrateCommand;
use Symfony\Component\Console\Application;
use AvelPress\Cli\Commands\MakeMigrationCommand;
use AvelPress\Cli\Commands\MakeModelCommand;
use AvelPress\Cli\Commands\MakeControllerCommand;
use AvelPress\Cli\Commands\NewCommand;
use AvelPress\Cli\Commands\BuildCommand;
use AvelPress\Cli\Commands\MigrateFreshCommand;

class Kernel {
	public function run() {
		$app = new Application( 'Avelpress CLI', '1.0.0' );

		$app->add( new MakeMigrationCommand() );
		$app->add( new MigrateCommand() );
		$app->add( new MakeModelCommand() );
		$app->add( new MakeControllerCommand() );
		$app->add( new NewCommand() );
		$app->add( new MigrateFreshCommand() );
		$app->add( new BuildCommand() );

		$app->run();
	}
}
