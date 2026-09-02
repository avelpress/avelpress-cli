<?php

namespace AvelPress\Cli\Release;

use AvelPress\Cli\Release\Build\ConsoleBuilder;
use AvelPress\Cli\Release\Contracts\ArtifactStorage;
use AvelPress\Cli\Release\Store\SiteInventory;
use AvelPress\Cli\Release\Support\Git;
use AvelPress\Cli\Release\Support\PackageInspector;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a release from end to end.
 *
 * The order is deliberate: everything that can refuse the release is checked
 * before anything is written, the package is verified before it is uploaded,
 * and the previous state is saved to disk before the store is touched.
 */
class ReleaseService {

	/**
	 * Project being released.
	 *
	 * @var ReleaseContext
	 */
	private $context;

	/**
	 * Where the package is published.
	 *
	 * @var ArtifactStorage
	 */
	private $storage;

	/**
	 * Targets that point at the package.
	 *
	 * @var \AvelPress\Cli\Release\Contracts\ReleaseTarget[]
	 */
	private $targets;

	/**
	 * Runs the build command.
	 *
	 * @var ConsoleBuilder
	 */
	private $builder;

	/**
	 * Store inventory, used by the preflight checks.
	 *
	 * @var SiteInventory
	 */
	private $inventory;

	/**
	 * @param ReleaseContext  $context   Project being released.
	 * @param ArtifactStorage $storage   Where the package is published.
	 * @param array           $targets   Targets to update.
	 * @param ConsoleBuilder  $builder   Build runner.
	 * @param SiteInventory   $inventory Store inventory.
	 */
	public function __construct(
		ReleaseContext $context,
		ArtifactStorage $storage,
		array $targets,
		ConsoleBuilder $builder,
		SiteInventory $inventory
	) {
		$this->context = $context;
		$this->storage = $storage;
		$this->targets = $targets;
		$this->builder = $builder;
		$this->inventory = $inventory;
	}

	/**
	 * Executes the release.
	 *
	 * @param array           $options Options coming from the command line.
	 * @param OutputInterface $output  Console output.
	 * @return int Exit code.
	 */
	public function run( array $options, OutputInterface $output ): int {
		$dryRun = ! empty( $options['dry_run'] );

		$current = $this->context->version();

		if ( $current === null ) {
			$output->writeln( '<error>No "Version:" header in ' . $this->context->mainFile() . '.</error>' );

			return 1;
		}

		$version = $this->resolveVersion( $current, $options );

		$output->writeln( '<info>Plugin:</info> ' . $this->context->pluginId() . ' ' . $current
			. ( $version === $current ? '' : ' -> ' . $version ) );

		if ( $dryRun ) {
			$output->writeln( '<comment>Dry run: nothing will be written.</comment>' );
		}

		$this->checkGit( $output, $dryRun );
		$this->checkChangelog( $version );
		$this->checkNotPublished( $version, $options );

		$bumped = [];

		if ( $version !== $current ) {
			$bumped = $this->applyBump( $current, $version, $output, $dryRun );
		}

		if ( $dryRun ) {
			return $this->planOnly( $version, $output );
		}

		if ( empty( $options['skip_build'] ) ) {
			$this->builder->build( $output );
		}

		$zip = $this->context->zipPath( $version );

		if ( ! file_exists( $zip ) ) {
			throw new \RuntimeException( "Package not found: $zip. Run without --skip-build." );
		}

		$this->checkPackage( $zip, $version, $output );

		$artifacts = $this->publish( $zip, $output );

		$this->applyTargets( $artifacts, $version, $output );

		// Only now: a commit and a tag should describe a version that really
		// went out, so a release that fails earlier leaves nothing but edited
		// files the developer can throw away.
		$this->recordBump( $bumped, $version, $output );

		if ( ! empty( $options['prune'] ) ) {
			$this->prune( $artifacts, $output );
		}

		$output->writeln( '<info>Release completed.</info>' );

		return 0;
	}

	/**
	 * Works out which version is being released.
	 *
	 * @param string $current Version in the header.
	 * @param array  $options Command line options.
	 * @return string
	 */
	private function resolveVersion( string $current, array $options ): string {
		if ( ! empty( $options['version'] ) ) {
			return $options['version'];
		}

		if ( ! empty( $options['bump'] ) ) {
			return VersionManager::bump( $current, $options['bump'] );
		}

		return $current;
	}

	/**
	 * Refuses to release from a dirty working tree.
	 *
	 * @param OutputInterface $output Console output.
	 * @param bool            $dryRun Whether this is a dry run.
	 * @throws \RuntimeException When the tree is dirty.
	 */
	private function checkGit( OutputInterface $output, bool $dryRun ): void {
		if ( ! $this->context->requireCleanGit() ) {
			return;
		}

		$git = Git::inspect( $this->context->projectDir() );

		if ( $git['state'] === 'none' || $git['state'] === 'clean' ) {
			return;
		}

		$problem = $git['state'] === 'dirty'
			? 'The working tree has uncommitted changes.'
			: "Could not read the working tree: {$git['message']}";

		if ( $dryRun ) {
			$output->writeln( "<comment>$problem A real release would stop here.</comment>" );

			return;
		}

		throw new \RuntimeException(
			$problem . ' Commit the changes (or fix git), or set release.git.require_clean to false in avelpress.config.php.'
		);
	}

	/**
	 * Requires a changelog entry for the version being released.
	 *
	 * The entry is never written automatically: the changelog is store copy, and
	 * only a human should decide what it says.
	 *
	 * @param string $version Version being released.
	 * @throws \RuntimeException When the entry is missing.
	 */
	private function checkChangelog( string $version ): void {
		$readme = $this->context->projectDir() . DIRECTORY_SEPARATOR . 'readme.txt';

		if ( ! file_exists( $readme ) ) {
			return;
		}

		if ( ! VersionManager::hasChangelogEntry( $readme, $version ) ) {
			throw new \RuntimeException( "readme.txt has no changelog entry \"= $version =\". Add it before releasing." );
		}
	}

	/**
	 * Refuses to publish a version the store already serves.
	 *
	 * @param string $version Version being released.
	 * @param array  $options Command line options.
	 * @throws \RuntimeException When the version is already out there.
	 */
	private function checkNotPublished( string $version, array $options ): void {
		if ( ! empty( $options['force'] ) ) {
			return;
		}

		$matcher = $this->context->matcher();

		foreach ( $this->inventory->downloads() as $entry ) {
			if ( $matcher->matches( $entry['file'] ) && $matcher->versionOf( $entry['file'] ) === $version ) {
				throw new \RuntimeException(
					"The store already serves $version (product #{$entry['product_id']}). Bump the version, or use --force."
				);
			}
		}
	}

	/**
	 * Verifies the package really carries the version being released.
	 *
	 * @param string          $zip     Package path.
	 * @param string          $version Version being released.
	 * @param OutputInterface $output  Console output.
	 * @throws \RuntimeException When the package carries another version.
	 */
	private function checkPackage( string $zip, string $version, OutputInterface $output ): void {
		$packaged = PackageInspector::versionIn( $zip, $this->context->pluginId() );

		if ( $packaged === null ) {
			$output->writeln( '<comment>Could not read the version inside the package; skipping that check.</comment>' );

			return;
		}

		if ( $packaged !== $version ) {
			throw new \RuntimeException( "The package declares $packaged but $version is being released." );
		}
	}

	/**
	 * Writes the new version to the project files.
	 *
	 * @param string          $current Version being replaced.
	 * @param string          $version New version.
	 * @param OutputInterface $output  Console output.
	 * @param bool            $dryRun  Whether this is a dry run.
	 * @return string[] Files that changed.
	 */
	private function applyBump( string $current, string $version, OutputInterface $output, bool $dryRun ): array {
		if ( $dryRun ) {
			$output->writeln( '<comment>Would bump to ' . $version . ' in: '
				. implode( ', ', array_map( 'basename', $this->context->versionFiles() ) ) . '</comment>' );

			return [];
		}

		$changed = VersionManager::writeVersion( $this->context->versionFiles(), $current, $version );

		if ( ! $changed ) {
			throw new \RuntimeException( "No file carried version $current, so the bump would be silent." );
		}

		$output->writeln( '<info>Bumped to</info> ' . $version . ': ' . implode( ', ', array_map( 'basename', $changed ) ) );

		return $changed;
	}

	/**
	 * Commits and tags the bump once the release actually happened.
	 *
	 * @param string[]        $files   Files changed by the bump.
	 * @param string          $version Version that was released.
	 * @param OutputInterface $output  Console output.
	 */
	private function recordBump( array $files, string $version, OutputInterface $output ): void {
		if ( ! $files ) {
			return;
		}

		if ( ! Git::isUsable( $this->context->projectDir() ) ) {
			$output->writeln(
				'<comment>The bump was written to the files but not committed: git is not usable here. Commit it by hand.</comment>'
			);

			return;
		}

		Git::commit( $this->context->projectDir(), $files, 'Release ' . $version );
		$output->writeln( '<info>Committed</info> release ' . $version );

		if ( $this->context->shouldTag() && ! Git::hasTag( $this->context->projectDir(), 'v' . $version ) ) {
			Git::tag( $this->context->projectDir(), 'v' . $version );
			$output->writeln( '<info>Tagged</info> v' . $version );
		}
	}

	/**
	 * Shows what a real run would change in the targets.
	 *
	 * A dry run never uploads, so the plan is built against the URL the package
	 * would most likely get, which is enough to show which entries move.
	 *
	 * @param string          $version Version being released.
	 * @param OutputInterface $output  Console output.
	 * @return int Exit code.
	 */
	private function planOnly( string $version, OutputInterface $output ): int {
		$artifact = new ArtifactRef( '(url of the package that would be uploaded)' );

		foreach ( $this->targets as $target ) {
			$plan = $target->plan( $artifact, $version );

			$output->writeln( '<info>Target ' . $target->name() . ':</info> '
				. count( $plan ) . ' objects would be updated' );

			foreach ( $plan as $item ) {
				$output->writeln( '  ' . $item['label'] );

				foreach ( $item['changes'] as $change ) {
					$output->writeln( '    ' . substr( $change['download_id'], 0, 8 ) . '  '
						. basename( (string) parse_url( $change['from'], PHP_URL_PATH ) ) . '  ->  ' . $version );
				}
			}
		}

		return 0;
	}

	/**
	 * Publishes the package once for each visibility a target asks for.
	 *
	 * The store copy and the updater copy are the same ZIP with different
	 * reachability, so the upload happens once per visibility, not once per
	 * target.
	 *
	 * @param string          $zip    Package path.
	 * @param OutputInterface $output Console output.
	 * @return ArtifactRef[] Keyed by visibility.
	 */
	private function publish( string $zip, OutputInterface $output ): array {
		$artifacts = [];

		foreach ( $this->targets as $target ) {
			$visibility = $target->artifactVisibility();

			if ( isset( $artifacts[ $visibility ] ) ) {
				continue;
			}

			$output->writeln( '<info>Uploading</info> ' . basename( $zip ) . " ($visibility)" );

			$artifacts[ $visibility ] = $this->storage->put( $zip, $visibility );

			$output->writeln( '  ' . $artifacts[ $visibility ]->url() );
		}

		return $artifacts;
	}

	/**
	 * Backs up the current state and updates every target.
	 *
	 * @param ArtifactRef[]   $artifacts Published packages, keyed by visibility.
	 * @param string          $version   Version being released.
	 * @param OutputInterface $output    Console output.
	 * @return array Plans that were applied, keyed by target name.
	 */
	private function applyTargets( array $artifacts, string $version, OutputInterface $output ): array {
		$plans = [];

		foreach ( $this->targets as $target ) {
			$plans[ $target->name() ] = $target->plan( $artifacts[ $target->artifactVisibility() ], $version );
		}

		$backup = $this->writeBackup( $plans, $version );

		if ( $backup ) {
			$output->writeln( '<info>Backup:</info> ' . $backup );
		}

		foreach ( $this->targets as $target ) {
			$plan = $plans[ $target->name() ];

			if ( ! $plan ) {
				$output->writeln( '<comment>Target ' . $target->name() . ': already up to date.</comment>' );
				continue;
			}

			$target->apply( $plan );

			$output->writeln( '<info>Target ' . $target->name() . ':</info> ' . count( $plan ) . ' objects updated' );

			foreach ( $plan as $item ) {
				// Only the store plan describes individual download entries; a
				// target like the update manifest has a single thing to say.
				$changes = isset( $item['changes'] ) ? ' (' . count( $item['changes'] ) . ')' : '';

				$output->writeln( '  ' . $item['label'] . $changes );
			}
		}

		return $plans;
	}

	/**
	 * Saves the pre-release state so it can be restored.
	 *
	 * @param array  $plans   Plans keyed by target name.
	 * @param string $version Version being released.
	 * @return string|null Path of the backup file.
	 */
	private function writeBackup( array $plans, string $version ) {
		if ( ! array_filter( $plans ) ) {
			return null;
		}

		$dir = $this->context->distDir();

		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			return null;
		}

		$file = $dir . DIRECTORY_SEPARATOR . 'release-backup-' . $version . '-' . date( 'Ymd-His' ) . '.json';

		file_put_contents( $file, json_encode( [
			'plugin' => $this->context->pluginId(),
			'version' => $version,
			'site' => $this->inventory->baseUrl(),
			'generated_at' => date( 'c' ),
			'plans' => $plans,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		return $file;
	}

	/**
	 * Deletes published packages beyond the retention limit.
	 *
	 * A package still referenced by any download entry is never deleted, no
	 * matter how old it is.
	 *
	 * @param ArtifactRef[]   $artifacts Packages just published, keyed by visibility.
	 * @param OutputInterface $output    Console output.
	 */
	private function prune( array $artifacts, OutputInterface $output ): void {
		$keep = $this->context->keep();
		$matcher = $this->context->matcher();

		// The store was just rewritten; ask it again so a file that stopped being
		// referenced a minute ago is actually seen as free.
		$this->inventory->refresh();

		$referenced = [];

		foreach ( $this->inventory->downloads() as $entry ) {
			$referenced[ strtolower( basename( (string) parse_url( $entry['file'], PHP_URL_PATH ) ) ) ] = true;
		}

		// The updater copy is referenced by the manifest, not by any product, so
		// nothing else here would stop it from being deleted right after it was
		// published.
		foreach ( $artifacts as $artifact ) {
			$referenced[ strtolower( $artifact->fileName() ) ] = true;
		}

		$published = $this->storage->published( $matcher );
		$removed = 0;
		$kept = 0;

		foreach ( $published as $candidate ) {
			if ( isset( $referenced[ strtolower( $candidate->fileName() ) ] ) ) {
				continue;
			}

			if ( $kept < $keep ) {
				$kept++;
				continue;
			}

			$this->storage->remove( $candidate );
			$output->writeln( '  removed ' . $candidate->fileName() );
			$removed++;
		}

		$output->writeln( '<info>Prune:</info> removed ' . $removed . ', kept the ' . $keep . ' most recent.' );
	}
}
