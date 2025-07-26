<?php

namespace AvelPress\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

class BuildCommand extends Command
{
	protected static $defaultName = 'build';

	protected function configure()
	{
		$this
			->setDescription('Builds a distribution package of the AvelPress application.');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$currentDir = getcwd();
		$configFile = "$currentDir/avelpress.config.php";

		// Check if avelpress.config.php exists
		if (!file_exists($configFile)) {
			$output->writeln("<error>avelpress.config.php not found. Please run this command from the root of an AvelPress project.</error>");
			return Command::FAILURE;
		}

		// Load configuration
		$config = require $configFile;
		if (!isset($config['build']['prefixer']['namespace_prefix'])) {
			$output->writeln("<error>Configuration missing 'build.prefixer.namespace_prefix' setting.</error>");
			return Command::FAILURE;
		}

		// Get namespace prefix from configuration
		$namespacePrefix = rtrim($config['build']['prefixer']['namespace_prefix'], '\\');
		$packages = $config['build']['prefixer']['packages'] ?? null;

		// Get project name from current directory
		$projectName = basename($currentDir);
		$distDir = "$currentDir/dist";
		$buildDir = "$distDir/$projectName";

		$output->writeln("<info>Building distribution package for: $projectName</info>");
		$output->writeln("<info>Using namespace prefix: $namespacePrefix</info>");

		// Check if ZIP extension is available
		if (!extension_loaded('zip')) {
			$output->writeln("<warning>ZIP extension is not available. ZIP file creation will be skipped.</warning>");
			$output->writeln("<comment>To install ZIP extension:</comment>");
			$output->writeln("<comment>- Ubuntu/Debian: sudo apt-get install php-zip</comment>");
			$output->writeln("<comment>- CentOS/RHEL: sudo yum install php-zip</comment>");
			$output->writeln("<comment>- Windows: uncomment extension=zip in php.ini</comment>");
			$output->writeln("<comment>- Docker: RUN docker-php-ext-install zip</comment>");
		}

		try {
			// Create dist directory
			if (!is_dir($distDir)) {
				mkdir($distDir, 0755, true);
				$output->writeln("Created directory: dist/");
			} else {
				// Clean existing dist directory
				$this->removeDirectory($distDir);
				mkdir($distDir, 0755, true);
				$output->writeln("Cleaned and recreated directory: dist/");
			}

			// Create build directory
			mkdir($buildDir, 0755, true);
			$output->writeln("Created directory: dist/$projectName/");

			// Copy src directory with namespace replacement for vendor packages used
			if (is_dir("$currentDir/src")) {
				// We'll collect vendor namespaces after handling vendor packages
				$this->copyDirectoryWithNamespaceReplacement(
					"$currentDir/src",
					"$buildDir/src",
					$namespacePrefix,
					[], // Will be updated later
					'use'
				);
				$output->writeln("Copied and processed: src/");
			}

			// Handle vendor packages based on configuration
			$vendorNamespaces = [];
			if (empty($packages)) {
				// New logic: copy composer.json, remove require-dev, run composer install
				$output->writeln("<info>No packages specified in config. Using composer.json approach.</info>");

				// Copy and modify composer.json
				$composerFile = "$currentDir/composer.json";
				if (!file_exists($composerFile)) {
					$output->writeln("<error>composer.json not found in project root.</error>");
					return Command::FAILURE;
				}

				$composerData = json_decode(file_get_contents($composerFile), true);
				if ($composerData === null) {
					$output->writeln("<error>Invalid composer.json file.</error>");
					return Command::FAILURE;
				}

				// Remove require-dev section
				if (isset($composerData['require-dev'])) {
					unset($composerData['require-dev']);
					$output->writeln("Removed require-dev section from composer.json");
				}

				// Save modified composer.json to build directory
				$buildComposerFile = "$buildDir/composer.json";
				file_put_contents($buildComposerFile, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				$output->writeln("Copied modified composer.json to: $buildComposerFile");

				// Run composer install in build directory
				$output->writeln("<info>Running composer install in build directory...</info>");
				$composerCommand = "cd " . escapeshellarg($buildDir) . " && composer install --no-dev --ignore-platform-reqs --optimize-autoloader 2>&1";
				$composerOutput = shell_exec($composerCommand);

				if ($composerOutput === null) {
					$output->writeln("<error>Failed to execute composer install.</error>");
					return Command::FAILURE;
				}

				$output->writeln("<comment>Composer install output:</comment>");
				$output->writeln($composerOutput);

				// Check if vendor directory was created
				$buildVendorDir = "$buildDir/vendor";
				if (!is_dir($buildVendorDir)) {
					$output->writeln("<error>Vendor directory was not created after composer install.</error>");
					return Command::FAILURE;
				}

				// Get all installed packages from the build vendor directory
				$packages = $this->getInstalledPackages($buildVendorDir);
				$output->writeln("<info>Found " . count($packages) . " installed packages</info>");

				// Collect vendor namespaces from installed packages
				$vendorNamespaces = $this->collectVendorNamespaces($buildDir, $packages);

				// Apply namespace prefixing to vendor packages
				$this->applyNamespacePrefixingToVendor($buildVendorDir, $namespacePrefix, $vendorNamespaces, $output);

			} else {
				// Original logic: copy specific packages from existing vendor directory
				$output->writeln("<info>Using specified packages from config: " . implode(', ', $packages) . "</info>");

				$vendorDir = "$currentDir/vendor";
				if (is_dir($vendorDir)) {
					$copiedPackages = 0;
					$skippedPackages = 0;

					foreach ($packages as $package) {
						$packagePath = "$vendorDir/$package";
						if (is_dir($packagePath)) {
							$packageNamespaces = $this->getPackageNamespaces($packagePath);
							$packageDest = "$buildDir/vendor/$package";
							$this->copyDirectoryWithNamespaceReplacement(
								$packagePath,
								$packageDest,
								$namespacePrefix,
								$packageNamespaces,
								'namespace'
							);
							$output->writeln("Copied and processed vendor package: $package");
							$copiedPackages++;
						} else {
							$output->writeln("<warning>Vendor package not found: $package</warning>");
							$skippedPackages++;
						}
					}

					$output->writeln("<info>Vendor packages summary: {$copiedPackages} copied, {$skippedPackages} skipped</info>");

					// Collect vendor namespaces from specified packages
					$vendorNamespaces = $this->collectVendorNamespaces($currentDir, $packages);
				} else {
					$output->writeln("<warning>Vendor directory not found. Please run 'composer install' first.</warning>");
				}
			}

			// Copy Composer autoload files (if vendor directory exists)
			if (is_dir("$buildDir/vendor")) {
				$this->copyComposerAutoloadFiles("$buildDir/vendor", "$buildDir/vendor", $namespacePrefix, $vendorNamespaces);
				$output->writeln("Copied and processed Composer autoload files");
			}

			// Re-process src directory with collected vendor namespaces
			if (is_dir("$currentDir/src") && !empty($vendorNamespaces)) {
				$this->copyDirectoryWithNamespaceReplacement(
					"$currentDir/src",
					"$buildDir/src",
					$namespacePrefix,
					$vendorNamespaces,
					'use'
				);
				$output->writeln("Re-processed src/ with vendor namespaces");
			}

			// Copy assets directory if exists
			if (is_dir("$currentDir/assets")) {
				$this->copyDirectory("$currentDir/assets", "$buildDir/assets");
				$output->writeln("Copied: assets/");
			}

			// Copy main PHP file with namespace replacement
			$mainPhpFile = "$currentDir/$projectName.php";
			if (file_exists($mainPhpFile)) {
				$this->copyFileWithNamespaceReplacement(
					$mainPhpFile,
					"$buildDir/$projectName.php",
					$namespacePrefix,
					$vendorNamespaces
				);
				$output->writeln("Copied and processed: $projectName.php");
			} else {
				$output->writeln("<warning>Main PHP file not found: $projectName.php</warning>");
			}

			// Copy README.md if exists
			if (file_exists("$currentDir/README.md")) {
				copy("$currentDir/README.md", "$buildDir/README.md");
				$output->writeln("Copied: README.md");
			}

			// Create ZIP file (only if ZIP extension is available)
			if (extension_loaded('zip')) {
				$zipFile = "$distDir/$projectName.zip";
				$this->createZipArchive($buildDir, $zipFile, $projectName);
				$output->writeln("Created: $projectName.zip");
			}

			$output->writeln("<info>Build completed successfully!</info>");
			$output->writeln("<comment>Distribution files created in: dist/</comment>");
			$output->writeln("<comment>- Folder: dist/$projectName/</comment>");
			if (extension_loaded('zip')) {
				$output->writeln("<comment>- ZIP: dist/$projectName.zip</comment>");
			} else {
				$output->writeln("<comment>- ZIP: Skipped (ZIP extension not available)</comment>");
			}

			return Command::SUCCESS;
		} catch (\Exception $e) {
			$output->writeln("<error>Error building package: {$e->getMessage()}</error>");
			return Command::FAILURE;
		}
	}

	/**
	 * Get all installed packages from vendor directory
	 */
	private function getInstalledPackages(string $vendorDir): array
	{
		$packages = [];
		$installedFile = "$vendorDir/composer/installed.json";

		if (file_exists($installedFile)) {
			$installedData = json_decode(file_get_contents($installedFile), true);

			if (isset($installedData['packages'])) {
				// Composer 2.x format
				foreach ($installedData['packages'] as $package) {
					if (isset($package['name'])) {
						$packages[] = $package['name'];
					}
				}
			} elseif (is_array($installedData)) {
				// Composer 1.x format
				foreach ($installedData as $package) {
					if (isset($package['name'])) {
						$packages[] = $package['name'];
					}
				}
			}
		}

		// Fallback: scan vendor directory
		if (empty($packages) && is_dir($vendorDir)) {
			$vendorIterator = new \DirectoryIterator($vendorDir);
			foreach ($vendorIterator as $vendorItem) {
				if ($vendorItem->isDot() || $vendorItem->getFilename() === 'composer') {
					continue;
				}

				if ($vendorItem->isDir()) {
					$vendorName = $vendorItem->getFilename();
					$vendorPath = $vendorItem->getPathname();

					$packageIterator = new \DirectoryIterator($vendorPath);
					foreach ($packageIterator as $packageItem) {
						if ($packageItem->isDot()) {
							continue;
						}

						if ($packageItem->isDir()) {
							$packageName = $packageItem->getFilename();
							$packages[] = "$vendorName/$packageName";
						}
					}
				}
			}
		}

		return $packages;
	}

	/**
	 * Apply namespace prefixing to all vendor packages
	 */
	private function applyNamespacePrefixingToVendor(string $vendorDir, string $namespacePrefix, array $vendorNamespaces, OutputInterface $output): void
	{
		if (!is_dir($vendorDir)) {
			return;
		}

		$vendorIterator = new \DirectoryIterator($vendorDir);
		foreach ($vendorIterator as $vendorItem) {
			if ($vendorItem->isDot() || $vendorItem->getFilename() === 'composer') {
				continue;
			}

			if ($vendorItem->isDir()) {
				$vendorName = $vendorItem->getFilename();
				$vendorPath = $vendorItem->getPathname();

				$packageIterator = new \DirectoryIterator($vendorPath);
				foreach ($packageIterator as $packageItem) {
					if ($packageItem->isDot()) {
						continue;
					}

					if ($packageItem->isDir()) {
						$packageName = $packageItem->getFilename();
						$packagePath = $packageItem->getPathname();
						$fullPackageName = "$vendorName/$packageName";

						// Get package namespaces
						$packageNamespaces = $this->getPackageNamespaces($packagePath);

						// Apply namespace prefixing to this package
						$this->applyNamespacePrefixingToDirectory($packagePath, $namespacePrefix, $packageNamespaces);

						$output->writeln("Applied namespace prefixing to: $fullPackageName");
					}
				}
			}
		}
	}

	/**
	 * Apply namespace prefixing to a directory
	 */
	private function applyNamespacePrefixingToDirectory(string $dir, string $namespacePrefix, array $packageNamespaces): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isFile() && pathinfo($item->getPathname(), PATHINFO_EXTENSION) === 'php') {
				$content = file_get_contents($item->getPathname());
				$content = $this->replaceNamespaces($content, $namespacePrefix, $packageNamespaces, 'namespace');
				file_put_contents($item->getPathname(), $content);
			}
		}
	}

	/**
	 * Get production packages from composer.json and their dependencies
	 */
	private function getProductionPackagesFromComposer(string $currentDir): array
	{
		$composerFile = "$currentDir/composer.json";

		if (!file_exists($composerFile)) {
			return [];
		}

		$composerData = json_decode(file_get_contents($composerFile), true);

		if (!isset($composerData['require'])) {
			return [];
		}

		// Get all production dependencies (excluding PHP and extensions)
		$productionPackages = [];
		foreach ($composerData['require'] as $package => $version) {
			// Skip PHP and extensions
			if (strpos($package, 'php') === 0 || strpos($package, 'ext-') === 0) {
				continue;
			}
			$productionPackages[] = $package;
		}

		// Get all transitive dependencies
		$allPackages = $this->getAllDependencies($currentDir, $productionPackages);

		return array_unique($allPackages);
	}

	/**
	 * Get all dependencies including transitive ones
	 */
	private function getAllDependencies(string $currentDir, array $packages): array
	{
		$allDependencies = [];
		$processed = [];
		$vendorDir = "$currentDir/vendor";

		$toProcess = $packages;
		$maxDepth = 50; // Prevent infinite loops
		$currentDepth = 0;

		while (!empty($toProcess) && $currentDepth < $maxDepth) {
			$package = array_shift($toProcess);
			$currentDepth++;

			if (in_array($package, $processed)) {
				continue;
			}

			$processed[] = $package;
			$allDependencies[] = $package;

			$packagePath = "$vendorDir/$package";
			if (is_dir($packagePath)) {
				$packageDeps = $this->getPackageDependencies($packagePath);
				foreach ($packageDeps as $dep) {
					if (!in_array($dep, $processed) && !in_array($dep, $toProcess)) {
						$toProcess[] = $dep;
					}
				}
			}
		}

		return $allDependencies;
	}

	/**
	 * Get dependencies from a package's composer.json
	 */
	private function getPackageDependencies(string $packagePath): array
	{
		$composerFile = "$packagePath/composer.json";
		$dependencies = [];

		if (file_exists($composerFile)) {
			$composerData = json_decode(file_get_contents($composerFile), true);

			if (isset($composerData['require'])) {
				foreach ($composerData['require'] as $package => $version) {
					// Skip PHP and extensions
					if (strpos($package, 'php') === 0 || strpos($package, 'ext-') === 0) {
						continue;
					}
					$dependencies[] = $package;
				}
			}
		}

		return $dependencies;
	}

	/**
	 * Collect all vendor namespaces from specified packages
	 */
	private function collectVendorNamespaces(string $currentDir, array $packages): array
	{
		$vendorNamespaces = [];
		$vendorDir = "$currentDir/vendor";

		foreach ($packages as $package) {
			$packagePath = "$vendorDir/$package";
			if (is_dir($packagePath)) {
				$packageNamespaces = $this->getPackageNamespaces($packagePath);
				$vendorNamespaces = array_merge($vendorNamespaces, $packageNamespaces);
			}
		}

		return $vendorNamespaces;
	}

	/**
	 * Get package namespaces from composer.json
	 */
	private function getPackageNamespaces(string $packagePath): array
	{
		$composerFile = "$packagePath/composer.json";
		$packageNamespaces = [];

		if (file_exists($composerFile)) {
			$composerData = json_decode(file_get_contents($composerFile), true);
			if (isset($composerData['autoload']['psr-4'])) {
				$packageNamespaces = $composerData['autoload']['psr-4'];
			}
		}

		return $packageNamespaces;
	}

	/**
	 * Copy a directory recursively with namespace replacement in PHP files
	 */
	private function copyDirectoryWithNamespaceReplacement(string $source, string $dest, string $namespacePrefix, array $namespacesToReplace, string $replacementType): void
	{
		if (!is_dir($source)) {
			return;
		}

		if (!is_dir($dest)) {
			mkdir($dest, 0755, true);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			// Use getPathname() instead of getRealPath() to avoid resolving symlinks
			$sourcePath = $item->getPathname();
			$relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $sourcePath);
			$destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;

			if ($item->isDir()) {
				if (!is_dir($destPath)) {
					mkdir($destPath, 0755, true);
				}
			} else {
				// For files, we still need the real path for reading content
				$realSourcePath = $item->getRealPath();
				$this->copyFileWithNamespaceReplacement($realSourcePath, $destPath, $namespacePrefix, $namespacesToReplace, $replacementType);
			}
		}
	}

	/**
	 * Copy a single file with namespace replacement if it's a PHP file
	 */
	private function copyFileWithNamespaceReplacement(string $source, string $dest, string $namespacePrefix, array $namespacesToReplace, string $replacementType = null): void
	{
		if (pathinfo($source, PATHINFO_EXTENSION) === 'php') {
			$content = file_get_contents($source);
			$content = $this->replaceNamespaces($content, $namespacePrefix, $namespacesToReplace, $replacementType);
			file_put_contents($dest, $content);
		} else {
			// For non-PHP files, just copy directly
			copy($source, $dest);
		}
	}

	/**
	 * Replace namespaces in content based on replacement type
	 */
	private function replaceNamespaces(string $content, string $namespacePrefix, array $namespacesToReplace, string $replacementType = null): string
	{
		foreach ($namespacesToReplace as $namespace => $path) {
			$cleanNamespace = rtrim($namespace, '\\');
			$prefixedNamespace = "$namespacePrefix\\$cleanNamespace";

			// Only replace if the namespace doesn't already have the prefix
			if (strpos($content, $prefixedNamespace) === false) {
				if ($replacementType === 'namespace' || $replacementType === null) {
					// Replace namespace declarations for vendor packages (only if prefix is not already present)
					$content = preg_replace(
						'/^namespace\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"namespace $prefixedNamespace$1;",
						$content
					);

					// Replace use statements within vendor packages (only if prefix is not already present)
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"use $prefixedNamespace$1;",
						$content
					);

					// Replace use statements with aliases (only if prefix is not already present)
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^\\s]+)\s+as\s+([^;]+);/m',
						"use $prefixedNamespace$1 as $2;",
						$content
					);

					// Replace class references in code (only if prefix is not already present)
					$content = preg_replace(
						'/(\s|^|\s\\\\|new\s+|instanceof\s+|extends\s+|implements\s+)(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '\\\\/',
						"$1$prefixedNamespace\\",
						$content
					);
				} elseif ($replacementType === 'use') {
					// Replace use statements for src files (using vendor packages) (only if prefix is not already present)
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"use $prefixedNamespace$1;",
						$content
					);

					// Replace use statements with aliases (only if prefix is not already present)
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^\\s]+)\s+as\s+([^;]+);/m',
						"use $prefixedNamespace$1 as $2;",
						$content
					);
				} elseif ($replacementType === 'psr4') {
					// Replace PSR-4 namespace keys in array declarations (only if prefix is not already present)
					$content = preg_replace(
						'/([\'"])(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^\'"]*)([\'"])\s*=>/m',
						"$1" . str_replace('\\', '\\\\\\', $prefixedNamespace) . "$2$3 =>",
						$content
					);
				}
			}
		}

		return $content;
	}

	/**
	 * Copy Composer autoload files with namespace replacement
	 */
	private function copyComposerAutoloadFiles(string $sourceVendorDir, string $destVendorDir, string $namespacePrefix, array $vendorNamespaces): void
	{
		$composerDir = "$sourceVendorDir/composer";
		$destComposerDir = "$destVendorDir/composer";

		if (!is_dir($composerDir)) {
			return;
		}

		// Create composer directory in destination
		if (!is_dir($destComposerDir)) {
			mkdir($destComposerDir, 0755, true);
		}

		// Copy autoload.php (main autoloader file)
		if (file_exists("$sourceVendorDir/autoload.php")) {
			$this->copyFileWithNamespaceReplacement(
				"$sourceVendorDir/autoload.php",
				"$destVendorDir/autoload.php",
				$namespacePrefix,
				$vendorNamespaces,
				'use'
			);
		}

		// Files to copy directly (no namespace replacement needed)
		$filesToCopyDirectly = [
			'ClassLoader.php',
			'LICENSE',
			'platform_check.php'
		];

		foreach ($filesToCopyDirectly as $file) {
			if (file_exists("$composerDir/$file")) {
				copy("$composerDir/$file", "$destComposerDir/$file");
			}
		}

		// Files that need different types of namespace replacement
		$filesToProcessUse = [
			'autoload_real.php',
			'autoload_classmap.php',
			'autoload_namespaces.php',
			'autoload_files.php',
			'installed.php',
			'InstalledVersions.php'
		];

		$filesToProcessPsr4 = [
			'autoload_psr4.php',
			'autoload_static.php'
		];

		// Process files with 'use' replacement type
		foreach ($filesToProcessUse as $file) {
			if (file_exists("$composerDir/$file")) {
				$this->copyFileWithNamespaceReplacement(
					"$composerDir/$file",
					"$destComposerDir/$file",
					$namespacePrefix,
					$vendorNamespaces,
					'use'
				);
			}
		}

		// Process files with 'psr4' replacement type
		foreach ($filesToProcessPsr4 as $file) {
			if (file_exists("$composerDir/$file")) {
				$this->copyFileWithNamespaceReplacement(
					"$composerDir/$file",
					"$destComposerDir/$file",
					$namespacePrefix,
					$vendorNamespaces,
					'psr4'
				);
			}
		}
	}

	/**
	 * Copy a directory recursively
	 */
	private function copyDirectory(string $source, string $dest): void
	{
		if (!is_dir($source)) {
			return;
		}

		if (!is_dir($dest)) {
			mkdir($dest, 0755, true);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$sourcePath = $item->getPathname();
			$relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $sourcePath);
			$destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;

			if ($item->isDir()) {
				if (!is_dir($destPath)) {
					mkdir($destPath, 0755, true);
				}
			} else {
				copy($item->getRealPath(), $destPath);
			}
		}
	}

	/**
	 * Remove a directory recursively
	 */
	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir()) {
				rmdir($item->getRealPath());
			} else {
				unlink($item->getRealPath());
			}
		}

		rmdir($dir);
	}

	/**
	 * Create a ZIP archive from a directory
	 */
	private function createZipArchive(string $sourceDir, string $zipFile, string $projectName): void
	{
		$zip = new ZipArchive();
		$result = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		if ($result !== TRUE) {
			throw new \Exception("Cannot create ZIP file: $zipFile (Error code: $result)");
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$sourcePath = $item->getPathname();
			$relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $sourcePath);
			$zipPath = $projectName . DIRECTORY_SEPARATOR . $relativePath;

			if ($item->isDir()) {
				$zip->addEmptyDir($zipPath);
			} else {
				$zip->addFile($item->getRealPath(), $zipPath);
			}
		}

		$zip->close();
	}
}

