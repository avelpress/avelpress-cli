<?php

namespace AvelPressCli;

use Symfony\Component\Console\Application;
use AvelPressCli\Commands\MakeMigrationCommand;
use AvelPressCli\Commands\NewCommand;

class Kernel {
	public function run() {
		$app = new Application( 'Avelpress CLI', '1.0.0' );

		$app->add( new MakeMigrationCommand() );
		$app->add( new NewCommand() );

		$app->run();
	}
}
