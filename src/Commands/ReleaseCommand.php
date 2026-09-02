<?php

namespace AvelPress\Cli\Commands;

use AvelPress\Cli\Release\Build\ConsoleBuilder;
use AvelPress\Cli\Release\Http\HttpClient;
use AvelPress\Cli\Release\Http\WpRestClient;
use AvelPress\Cli\Release\ReleaseContext;
use AvelPress\Cli\Release\ReleaseService;
use AvelPress\Cli\Release\Storage\WordPressMediaStorage;
use AvelPress\Cli\Release\Store\SiteInventory;
use AvelPress\Cli\Release\Support\Env;
use AvelPress\Cli\Release\Targets\WebhookTarget;
use AvelPress\Cli\Release\Targets\WooCommerceTarget;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Publishes a new version of the plugin and repoints the store at it.
 */
class ReleaseCommand extends Command {
	protected static $defaultName = 'release';

	protected function configure() {
		$this
			->setDescription( 'Builds, publishes and points every store download at the new package.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything' )
			->addOption( 'bump', null, InputOption::VALUE_REQUIRED, 'Bump the version: major, minor or patch' )
			->addOption( 'set-version', null, InputOption::VALUE_REQUIRED, 'Release this exact version' )
			->addOption( 'skip-build', null, InputOption::VALUE_NONE, 'Reuse the package already in the output directory' )
			->addOption( 'prune', null, InputOption::VALUE_NONE, 'Delete published packages beyond the retention limit' )
			->addOption( 'force', null, InputOption::VALUE_NONE, 'Publish even when the store already serves this version' )
			->addOption( 'site', null, InputOption::VALUE_REQUIRED, 'Site URL (defaults to AVELPRESS_SITE_URL)' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$projectDir = getcwd();

		Env::load( $projectDir );

		try {
			$context = ReleaseContext::fromProject( $projectDir );
			$site = $input->getOption( 'site' ) ? $input->getOption( 'site' ) : Env::mustGet( 'AVELPRESS_SITE_URL' );
			$wordpress = $this->wordpressClient( $site );
			$store = $this->storeClient( $site, $wordpress );

			$inventory = new SiteInventory( $store, $wordpress );

			$targets = [ new WooCommerceTarget( $store, $inventory, $context->matcher() ) ];

			$webhook = $context->manifestWebhook();

			if ( $webhook ) {
				$targets[] = new WebhookTarget( new HttpClient(), $webhook, $this->manifestAuth(), $context );
			}

			$service = new ReleaseService(
				$context,
				new WordPressMediaStorage( $wordpress ),
				$targets,
				new ConsoleBuilder( $this->getApplication() ),
				$inventory
			);

			$output->writeln( '<info>Store:</info> ' . $site );

			// Which file the target came from: a release that silently points at
			// the wrong site is the worst failure this command has.
			if ( Env::loadedFiles() ) {
				$output->writeln( '<comment>Credentials from: ' . implode( ', ', Env::loadedFiles() ) . ' (the last one wins)</comment>' );
			}

			return $service->run( [
				'dry_run' => (bool) $input->getOption( 'dry-run' ),
				'bump' => $input->getOption( 'bump' ),
				'version' => $input->getOption( 'set-version' ),
				'skip_build' => (bool) $input->getOption( 'skip-build' ),
				'prune' => (bool) $input->getOption( 'prune' ),
				'force' => (bool) $input->getOption( 'force' ),
			], $output );
		} catch (\RuntimeException $e) {
			$output->writeln( "<error>{$e->getMessage()}</error>" );

			return Command::FAILURE;
		}
	}

	/**
	 * Authorization the manifest endpoint expects.
	 *
	 * A bearer token when one is configured — an endpoint outside WordPress has
	 * no user to authenticate. Otherwise the same application password already
	 * used to upload the package: when the manifest lives on the store itself,
	 * inventing a second credential buys nothing and adds one more secret to
	 * rotate.
	 *
	 * @return string
	 */
	private function manifestAuth(): string {
		$token = Env::get( 'AVELPRESS_RELEASE_TOKEN' );

		if ( $token ) {
			return 'Bearer ' . $token;
		}

		return 'Basic ' . base64_encode(
			Env::mustGet( 'AVELPRESS_WP_USER' ) . ':' . Env::mustGet( 'AVELPRESS_WP_APP_PASSWORD' )
		);
	}

	/**
	 * Client authenticated with the WordPress application password.
	 *
	 * @param string $site Site URL.
	 * @return WpRestClient
	 * @throws \RuntimeException When the application password is missing.
	 */
	private function wordpressClient( string $site ): WpRestClient {
		if ( ! Env::has( [ 'AVELPRESS_WP_USER', 'AVELPRESS_WP_APP_PASSWORD' ] ) ) {
			throw new \RuntimeException(
				'Publishing needs AVELPRESS_WP_USER and AVELPRESS_WP_APP_PASSWORD (a WordPress application password) in .env or ~/.avelpress/.env.'
			);
		}

		return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WP_USER' ), Env::mustGet( 'AVELPRESS_WP_APP_PASSWORD' ), 120 );
	}

	/**
	 * Client used for the WooCommerce routes.
	 *
	 * @param string       $site      Site URL.
	 * @param WpRestClient $fallback  Client used when no WooCommerce key is set.
	 * @return WpRestClient
	 */
	private function storeClient( string $site, WpRestClient $fallback ): WpRestClient {
		if ( Env::has( [ 'AVELPRESS_WC_KEY', 'AVELPRESS_WC_SECRET' ] ) ) {
			return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WC_KEY' ), Env::mustGet( 'AVELPRESS_WC_SECRET' ) );
		}

		return $fallback;
	}
}
