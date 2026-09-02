<?php

namespace AvelPress\Cli\Commands;

use AvelPress\Cli\Release\Http\WpRestClient;
use AvelPress\Cli\Release\ReleaseContext;
use AvelPress\Cli\Release\Store\SiteInventory;
use AvelPress\Cli\Release\Support\Env;
use AvelPress\Cli\Release\Targets\WooCommerceTarget;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts the store download links back to what a backup file recorded.
 */
class ReleaseRestoreCommand extends Command {
	protected static $defaultName = 'release:restore';

	protected function configure() {
		$this
			->setDescription( 'Restores store download links from a release backup file.' )
			->addArgument( 'file', InputArgument::REQUIRED, 'Backup file written by the release' )
			->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Site URL (defaults to AVELPRESS_SITE_URL)' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$projectDir = getcwd();

		Env::load( $projectDir );

		$file = $input->getArgument( 'file' );

		if ( ! file_exists( $file ) ) {
			$output->writeln( "<error>Backup file not found: $file</error>" );

			return Command::FAILURE;
		}

		$backup = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $backup ) || empty( $backup['plans']['store'] ) ) {
			$output->writeln( '<error>This file has no store plan to restore.</error>' );

			return Command::FAILURE;
		}

		try {
			$context = ReleaseContext::fromProject( $projectDir );
			$site = $input->getOption( 'site' ) ? $input->getOption( 'site' ) : Env::mustGet( 'AVELPRESS_SITE_URL' );
			$client = $this->client( $site );

			$target = new WooCommerceTarget( $client, new SiteInventory( $client ), $context->matcher() );

			$output->writeln( '<info>Restoring</info> ' . count( $backup['plans']['store'] ) . ' objects on ' . $site );

			$target->revert( $backup['plans']['store'] );

			foreach ( $backup['plans']['store'] as $item ) {
				$output->writeln( '  ' . $item['label'] );
			}

			$output->writeln( '<info>Restore completed.</info>' );

			return Command::SUCCESS;
		} catch (\RuntimeException $e) {
			$output->writeln( "<error>{$e->getMessage()}</error>" );

			return Command::FAILURE;
		}
	}

	/**
	 * Builds the REST client from the environment.
	 *
	 * @param string $site Site URL.
	 * @return WpRestClient
	 * @throws \RuntimeException When no credentials are configured.
	 */
	private function client( string $site ): WpRestClient {
		if ( Env::has( [ 'AVELPRESS_WC_KEY', 'AVELPRESS_WC_SECRET' ] ) ) {
			return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WC_KEY' ), Env::mustGet( 'AVELPRESS_WC_SECRET' ) );
		}

		return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WP_USER' ), Env::mustGet( 'AVELPRESS_WP_APP_PASSWORD' ) );
	}
}
