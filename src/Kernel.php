<?php

namespace AvelPress\Cli;

use Symfony\Component\Console\Application;
use AvelPress\Cli\Commands\MakeMigrationCommand;
use AvelPress\Cli\Commands\MakeModelCommand;
use AvelPress\Cli\Commands\MakeControllerCommand;
use AvelPress\Cli\Commands\NewCommand;
use AvelPress\Cli\Commands\BuildCommand;

class Kernel {
	public function run() {
		$app = new Application( 'Avelpress CLI', '1.0.0' );

		$app->add( new MakeMigrationCommand() );
		$app->add( new MakeModelCommand() );
		$app->add( new MakeControllerCommand() );
		$app->add( new NewCommand() );
		$app->add( new BuildCommand() );

		$app->run();
	}
}
