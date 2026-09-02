<?php

namespace AvelPress\Cli\Commands;

use AvelPress\Cli\Release\Http\HttpClient;
use AvelPress\Cli\Release\Http\WpRestClient;
use AvelPress\Cli\Release\ReleaseContext;
use AvelPress\Cli\Release\Store\SiteInventory;
use AvelPress\Cli\Release\Support\DownloadMatcher;
use AvelPress\Cli\Release\Support\Env;
use AvelPress\Cli\Release\Support\PluginHeader;
use AvelPress\Cli\Release\VersionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Audits what the store is serving, without changing anything.
 *
 * A release only fixes the future; this command shows the present, which is
 * where a manual publishing routine leaves its mistakes: a variation still
 * pointing at an older ZIP than its parent, a bundle that never got updated, a
 * file served from a legacy domain.
 */
class ReleaseDoctorCommand extends Command {
	protected static $defaultName = 'release:doctor';

	protected function configure() {
		$this
			->setDescription( 'Audits the store download links against the local plugin version.' )
			->addOption(
				'site-wide',
				null,
				InputOption::VALUE_NONE,
				'Audit every download of the site instead of only the current plugin'
			)
			->addOption(
				'site',
				null,
				InputOption::VALUE_REQUIRED,
				'Site URL (defaults to AVELPRESS_SITE_URL)'
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$projectDir = getcwd();

		Env::load( $projectDir );

		try {
			$site = $input->getOption( 'site' ) ? $input->getOption( 'site' ) : Env::mustGet( 'AVELPRESS_SITE_URL' );
			$storeClient = $this->storeClient( $site );
		} catch (\RuntimeException $e) {
			$output->writeln( "<error>{$e->getMessage()}</error>" );

			return Command::FAILURE;
		}

		$inventory = new SiteInventory( $storeClient, $this->mediaClient( $site ) );

		$output->writeln( "<info>Store:</info> $site" );

		if ( Env::loadedFiles() ) {
			$output->writeln( '<comment>Credentials from: ' . implode( ', ', Env::loadedFiles() ) . ' (the last one wins)</comment>' );
		}

		try {
			$entries = $inventory->downloads();
		} catch (\RuntimeException $e) {
			$output->writeln( "<error>{$e->getMessage()}</error>" );

			return Command::FAILURE;
		}

		$output->writeln(
			sprintf( '<comment>Swept %d products, %d download entries.</comment>', $inventory->productCount(), count( $entries ) )
		);
		$output->writeln( '' );

		if ( $input->getOption( 'site-wide' ) ) {
			return $this->auditSite( $inventory, $entries, $output );
		}

		return $this->auditPlugin( $entries, $projectDir, $output );
	}

	/**
	 * Audits the downloads that belong to the plugin in the current directory.
	 *
	 * @param array           $entries    Every download entry of the store.
	 * @param string          $projectDir Project root.
	 * @param OutputInterface $output     Console output.
	 * @return int
	 */
	private function auditPlugin( array $entries, string $projectDir, OutputInterface $output ): int {
		try {
			$context = ReleaseContext::fromProject( $projectDir );
		} catch (\RuntimeException $e) {
			$output->writeln( "<error>{$e->getMessage()} Run this from a plugin project, or use --site-wide.</error>" );

			return Command::FAILURE;
		}

		$pluginId = $context->pluginId();
		$slugs = $context->slugs();
		$matcher = $context->matcher();
		$localVersion = $context->version();

		$output->writeln( "<info>Plugin:</info> $pluginId " . ( $localVersion ? $localVersion : '(no version in header)' ) );
		$output->writeln( '<info>File names claimed:</info> ' . implode( ', ', $slugs ) );
		$output->writeln( '' );

		$matched = [];

		foreach ( $entries as $entry ) {
			if ( $matcher->matches( $entry['file'] ) ) {
				$entry['version'] = $matcher->versionOf( $entry['file'] );
				$matched[] = $entry;
			}
		}

		if ( ! $matched ) {
			$output->writeln( '<comment>No store download points to this plugin.</comment>' );

			return Command::SUCCESS;
		}

		$table = new Table( $output );
		$table->setHeaders( [ 'Product', 'Type', 'Lang', 'Download id', 'File', 'Version', 'Status' ] );

		foreach ( $matched as $entry ) {
			$table->addRow( [
				'#' . $entry['product_id'] . ( $entry['parent_id'] ? ' (of #' . $entry['parent_id'] . ')' : '' ),
				$entry['type'],
				$entry['lang'],
				substr( $entry['download_id'], 0, 8 ),
				DownloadMatcher::fileName( $entry['file'] ),
				$entry['version'] === null ? '-' : $entry['version'],
				$this->versionStatus( $entry['version'], $localVersion ),
			] );
		}

		$table->render();
		$output->writeln( '' );

		$findings = array_merge(
			$this->pluginFindings( $matched, $localVersion ),
			$this->manifestFindings( $context, $localVersion, $output )
		);

		return $this->report( $findings, $output );
	}

	/**
	 * Compares the published update manifest with what the plugin declares.
	 *
	 * The manifest gates who is offered the update — which version is current and
	 * which minimum version of a dependency is required. Both live in the plugin
	 * source now, but the manifest only catches up when a release publishes it,
	 * so a difference here means installed copies are being told something the
	 * code no longer says.
	 *
	 * @param ReleaseContext  $context      Project being audited.
	 * @param string|null     $localVersion Version in the plugin header.
	 * @param OutputInterface $output       Console output.
	 * @return string[]
	 */
	private function manifestFindings( ReleaseContext $context, $localVersion, OutputInterface $output ): array {
		$url = $context->manifestUrl();

		if ( ! $url ) {
			return [];
		}

		try {
			$response = ( new HttpClient() )->request( 'GET', $url, null, [ 'Accept: application/json' ], 20 );
			$manifest = json_decode( $response['body'], true );
		} catch (\RuntimeException $e) {
			$output->writeln( "<comment>Could not read the update manifest: {$e->getMessage()}</comment>" );

			return [];
		}

		if ( ! is_array( $manifest ) || empty( $manifest['new_version'] ) ) {
			$output->writeln( '<comment>The update manifest did not answer with a version.</comment>' );

			return [];
		}

		$output->writeln( '<info>Update manifest:</info> version ' . $manifest['new_version']
			. $this->describeRequirements( isset( $manifest['requires_plugins'] ) ? $manifest['requires_plugins'] : [] ) );

		$declared = PluginHeader::read( $context->mainFile() )['requires_plugins'];
		$published = isset( $manifest['requires_plugins'] ) && is_array( $manifest['requires_plugins'] )
			? $manifest['requires_plugins']
			: [];

		$findings = [];

		if ( $localVersion !== null && VersionManager::compare( (string) $manifest['new_version'], $localVersion ) < 0 ) {
			$findings[] = 'The update manifest still offers ' . $manifest['new_version']
				. ', older than the local ' . $localVersion . '. Installed copies are not being offered this version yet.';
		}

		// An endpoint that does not report requirements at all may still be
		// enforcing them; claiming otherwise would be a false alarm.
		if ( ! array_key_exists( 'requires_plugins', $manifest ) ) {
			if ( $declared ) {
				$output->writeln( '<comment>This manifest endpoint does not report plugin requirements, so the declared ones could not be checked.</comment>' );
			}

			$output->writeln( '' );

			return $findings;
		}

		foreach ( $declared as $slug => $version ) {
			$current = isset( $published[ $slug ] ) ? $published[ $slug ] : null;

			if ( $current === $version ) {
				continue;
			}

			$findings[] = sprintf(
				'The plugin declares it needs %s >= %s, but the manifest enforces %s. Publish a release to sync it.',
				$slug,
				$version,
				$current === null ? 'nothing' : $current
			);
		}

		foreach ( $published as $slug => $version ) {
			if ( ! isset( $declared[ $slug ] ) ) {
				$findings[] = sprintf(
					'The manifest requires %s >= %s but the plugin no longer declares it. Add it back to "Requires Plugins Versions" or it will look unintentional.',
					$slug,
					$version
				);
			}
		}

		$output->writeln( '' );

		return $findings;
	}

	/**
	 * Formats the dependency requirements for the report line.
	 *
	 * @param mixed $requirements Requirements read from the manifest.
	 * @return string
	 */
	private function describeRequirements( $requirements ): string {
		if ( ! is_array( $requirements ) || ! $requirements ) {
			return '';
		}

		$parts = [];

		foreach ( $requirements as $slug => $version ) {
			$parts[] = "$slug >= $version";
		}

		return ', requires ' . implode( ', ', $parts );
	}

	/**
	 * Audits every download of the store, including plugins with no local project.
	 *
	 * @param SiteInventory   $inventory Store inventory.
	 * @param array           $entries   Every download entry of the store.
	 * @param OutputInterface $output    Console output.
	 * @return int
	 */
	private function auditSite( SiteInventory $inventory, array $entries, OutputInterface $output ): int {
		$groups = [];
		$skipped = 0;

		foreach ( $entries as $entry ) {
			// Only packages carry a version worth comparing; anything else the
			// store sells (images, PDFs) would be noise in this report.
			if ( ! preg_match( '/\.zip$/i', DownloadMatcher::fileName( $entry['file'] ) ) ) {
				$skipped++;
				continue;
			}

			$described = DownloadMatcher::describe( $entry['file'] );
			$groups[ $described['slug'] ][] = $described['version'];
		}

		ksort( $groups );

		if ( $skipped ) {
			$output->writeln( "<comment>$skipped entries are not ZIP packages and were left out of the version audit.</comment>" );
		}

		$table = new Table( $output );
		$table->setHeaders( [ 'Plugin', 'Entries', 'Versions served' ] );

		foreach ( $groups as $slug => $versions ) {
			$distinct = array_values( array_unique( array_map( function ($version) {
				return $version === null ? '-' : $version;
			}, $versions ) ) );

			$table->addRow( [
				$slug,
				count( $versions ),
				implode( ', ', $distinct ) . ( count( $distinct ) > 1 ? '  <- diverges' : '' ),
			] );
		}

		$table->render();
		$output->writeln( '' );

		$findings = [];

		foreach ( $groups as $slug => $versions ) {
			$distinct = array_unique( array_filter( $versions, function ($version) {
				return $version !== null;
			} ) );

			if ( count( $distinct ) > 1 ) {
				$findings[] = "$slug is served in more than one version at the same time: " . implode( ', ', $distinct );
			}
		}

		$findings = array_merge( $findings, $this->hostFindings( $entries, $inventory ) );
		$findings = array_merge( $findings, $this->orphanFindings( $entries, $inventory, $output ) );

		return $this->report( $findings, $output );
	}

	/**
	 * Findings for a single plugin.
	 *
	 * @param array       $matched      Entries claimed by the plugin.
	 * @param string|null $localVersion Version in the plugin header.
	 * @return string[]
	 */
	private function pluginFindings( array $matched, $localVersion ): array {
		$findings = [];
		$versions = [];
		$missing = 0;

		foreach ( $matched as $entry ) {
			if ( $entry['version'] === null ) {
				$missing++;
				continue;
			}

			$versions[ $entry['version'] ][] = '#' . $entry['product_id'];
		}

		if ( count( $versions ) > 1 ) {
			$parts = [];

			foreach ( $versions as $version => $products ) {
				$parts[] = $version . ' (' . implode( ', ', array_slice( $products, 0, 4 ) ) . ( count( $products ) > 4 ? ', ...' : '' ) . ')';
			}

			$findings[] = 'The store serves more than one version of this plugin: ' . implode( ' | ', $parts );
		}

		if ( $localVersion !== null ) {
			foreach ( $versions as $version => $products ) {
				if ( VersionManager::compare( (string) $version, $localVersion ) < 0 ) {
					$findings[] = sprintf(
						'%s still serve %s, older than the local %s.',
						$this->plural( count( $products ), 'entry', 'entries' ),
						$version,
						$localVersion
					);
				}
			}
		}

		if ( $missing ) {
			$findings[] = $this->plural( $missing, 'entry has', 'entries have' )
				. ' no version in the file name, so they cannot be audited by version.';
		}

		return $findings;
	}

	/**
	 * Findings about files served from another host.
	 *
	 * WooCommerce treats a file on another host as remote and falls back to the
	 * redirect download method, which bypasses the forced download.
	 *
	 * @param array         $entries   Every download entry.
	 * @param SiteInventory $inventory Store inventory, for the canonical host.
	 * @return string[]
	 */
	private function hostFindings( array $entries, SiteInventory $inventory ): array {
		$expected = parse_url( $inventory->baseUrl(), PHP_URL_HOST );
		$offenders = [];

		foreach ( $entries as $entry ) {
			$host = parse_url( $entry['file'], PHP_URL_HOST );

			if ( $host && $expected && strcasecmp( $host, $expected ) !== 0 ) {
				$offenders[ $host ][] = '#' . $entry['product_id'];
			}
		}

		$findings = [];

		foreach ( $offenders as $host => $products ) {
			$findings[] = sprintf(
				'%s served from %s instead of %s, so WooCommerce treats them as remote files and skips the forced download.',
				$this->plural( count( $products ), 'entry is', 'entries are' ),
				$host,
				$expected
			);
		}

		return $findings;
	}

	/**
	 * Findings about packages no product points to.
	 *
	 * @param array           $entries   Every download entry.
	 * @param SiteInventory   $inventory Store inventory.
	 * @param OutputInterface $output    Console output.
	 * @return string[]
	 */
	private function orphanFindings( array $entries, SiteInventory $inventory, OutputInterface $output ): array {
		if ( ! $inventory->hasMediaAccess() ) {
			$output->writeln( '<comment>Skipping orphan packages: set AVELPRESS_WP_USER and AVELPRESS_WP_APP_PASSWORD to read the media library.</comment>' );

			return [];
		}

		$used = [];

		foreach ( $entries as $entry ) {
			$used[ strtolower( DownloadMatcher::fileName( $entry['file'] ) ) ] = true;
		}

		$orphans = [];

		try {
			foreach ( $inventory->mediaZips() as $media ) {
				if ( ! isset( $used[ strtolower( DownloadMatcher::fileName( $media['url'] ) ) ] ) ) {
					$orphans[] = $media;
				}
			}
		} catch (\RuntimeException $e) {
			$output->writeln( "<comment>Could not read the media library: {$e->getMessage()}</comment>" );

			return [];
		}

		if ( ! $orphans ) {
			return [];
		}

		$output->writeln( '<comment>Packages in the media library with no product pointing to them:</comment>' );

		foreach ( $orphans as $orphan ) {
			$output->writeln( '  ' . $orphan['date'] . '  ' . DownloadMatcher::fileName( $orphan['url'] ) );
		}

		$output->writeln( '' );

		return [
			$this->plural( count( $orphans ), 'package in the media library is', 'packages in the media library are' )
			. ' no longer referenced by any product (files uploaded outside the media library are not visible here).',
		];
	}

	/**
	 * Formats a count with the right noun form.
	 *
	 * @param int    $count    Number of items.
	 * @param string $singular Text used for one item.
	 * @param string $plural   Text used for any other amount.
	 * @return string
	 */
	private function plural( int $count, string $singular, string $plural ): string {
		return $count . ' ' . ( $count === 1 ? $singular : $plural );
	}

	/**
	 * Prints the findings and turns them into an exit code.
	 *
	 * @param string[]        $findings Findings collected by the audit.
	 * @param OutputInterface $output   Console output.
	 * @return int
	 */
	private function report( array $findings, OutputInterface $output ): int {
		if ( ! $findings ) {
			$output->writeln( '<info>No divergence found.</info>' );

			return Command::SUCCESS;
		}

		$output->writeln( '<comment>Findings:</comment>' );

		foreach ( $findings as $finding ) {
			$output->writeln( "  - $finding" );
		}

		return Command::FAILURE;
	}

	/**
	 * Compares one entry version against the local one.
	 *
	 * @param string|null $version      Version read from the file name.
	 * @param string|null $localVersion Version in the plugin header.
	 * @return string
	 */
	private function versionStatus( $version, $localVersion ): string {
		if ( $version === null ) {
			return 'no version';
		}

		if ( $localVersion === null ) {
			return '';
		}

		$comparison = VersionManager::compare( $version, $localVersion );

		if ( $comparison === 0 ) {
			return 'current';
		}

		return $comparison < 0 ? 'OUTDATED' : 'ahead of local';
	}

	/**
	 * Client used for the WooCommerce routes.
	 *
	 * The WooCommerce key is preferred when present; otherwise the WordPress
	 * application password is used, which authenticates wc/v3 just as well.
	 *
	 * @param string $site Site URL.
	 * @return WpRestClient
	 * @throws \RuntimeException When no usable credentials are configured.
	 */
	private function storeClient( string $site ): WpRestClient {
		if ( Env::has( [ 'AVELPRESS_WC_KEY', 'AVELPRESS_WC_SECRET' ] ) ) {
			return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WC_KEY' ), Env::mustGet( 'AVELPRESS_WC_SECRET' ) );
		}

		if ( Env::has( [ 'AVELPRESS_WP_USER', 'AVELPRESS_WP_APP_PASSWORD' ] ) ) {
			return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WP_USER' ), Env::mustGet( 'AVELPRESS_WP_APP_PASSWORD' ) );
		}

		throw new \RuntimeException(
			'No credentials found. Set AVELPRESS_WC_KEY and AVELPRESS_WC_SECRET, or AVELPRESS_WP_USER and AVELPRESS_WP_APP_PASSWORD, in .env or ~/.avelpress/.env.'
		);
	}

	/**
	 * Client used for the WordPress routes, when an application password exists.
	 *
	 * @param string $site Site URL.
	 * @return WpRestClient|null
	 */
	private function mediaClient( string $site ) {
		if ( ! Env::has( [ 'AVELPRESS_WP_USER', 'AVELPRESS_WP_APP_PASSWORD' ] ) ) {
			return null;
		}

		return new WpRestClient( $site, Env::mustGet( 'AVELPRESS_WP_USER' ), Env::mustGet( 'AVELPRESS_WP_APP_PASSWORD' ) );
	}
}
