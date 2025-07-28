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
			->setDescription('Builds a distribution package of the AvelPress application.')
			->addOption(
				'ignore-platform-reqs',
				null,
				\Symfony\Component\Console\Input\InputOption::VALUE_NONE,
				'Ignore platform requirements when running composer install'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$ignorePlatformReqs = $input->getOption('ignore-platform-reqs');

		$currentDir = getcwd();
		$configFile = "$currentDir/avelpress.config.php";

		// Check if avelpress.config.php exists
		if (!file_exists($configFile)) {
			$output->writeln("<error>avelpress.config.php not found. Please run this command from the root of an AvelPress project.</error>");
			return Command::FAILURE;
		}

		// Load configuration
		$config = require $configFile;

		// Check if namespace prefixing is enabled
		$shouldPrefixNamespaces =
			isset($config['build']['prefixer']['namespace_prefix']) &&
			!empty($config['build']['prefixer']['namespace_prefix']) &&
			(
				(isset($config['build']['prefixer']['enabled']) && $config['build']['prefixer']['enabled'] === true)
				|| !isset($config['build']['prefixer']['enabled'])
			);

		$composerCleanup = !isset($config['build']['composer_cleanup']) || $config['build']['composer_cleanup'] !== false;

		$includePackages = [];
		if (isset($config['build']['prefixer']['include_packages']) && is_array($config['build']['prefixer']['include_packages'])) {
			$includePackages = $config['build']['prefixer']['include_packages'];
		}

		$namespacePrefix = '';
		if ($shouldPrefixNamespaces) {
			$namespacePrefix = rtrim($config['build']['prefixer']['namespace_prefix'], '\\');
		}

		// Get plugin_id from config
		if (!isset($config['plugin_id']) || empty($config['plugin_id'])) {
			$output->writeln("<error>Missing 'plugin_id' in avelpress.config.php. Please set 'plugin_id' in your configuration file. Build cancelled.</error>");
			return Command::FAILURE;
		}
		$pluginId = $config['plugin_id'];

		// Allow custom output directory via config (absolute or relative)
		$outputDir = isset($config['build']['output_dir']) && !empty($config['build']['output_dir'])
			? $config['build']['output_dir']
			: 'dist';

		$isAbsolute = (strpos($outputDir, '/') === 0 || preg_match('/^[A-Za-z]:[\\/]/', $outputDir));
		$distDir = $isAbsolute ? $outputDir : rtrim($currentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $outputDir;
		$buildDir = $distDir . DIRECTORY_SEPARATOR . $pluginId;

		$outputDirDisplay = $outputDir;
		$output->writeln("<info>Building distribution package for: $pluginId</info>");
		if ($shouldPrefixNamespaces) {
			$output->writeln("<info>Using namespace prefix: $namespacePrefix</info>");
		} else {
			$output->writeln("<info>Namespace prefixing disabled</info>");
		}

		// Check if ZIP extension is available
		if (!extension_loaded('zip')) {
			$output->writeln("<comment>ZIP extension for PHP (php-zip) is not available. ZIP file creation will be skipped.</comment>");
		}

		try {
			// Create dist directory if it doesn't exist
			if (!is_dir($distDir)) {
				mkdir($distDir, 0755, true);
				$output->writeln("Created directory: $outputDirDisplay/");
			}

			// Clean or create build directory (plugin_id)
			if (is_dir($buildDir)) {
				$this->removeDirectory($buildDir);
				$output->writeln("Cleaned and recreated directory: $outputDirDisplay/$pluginId/");
			}
			mkdir($buildDir, 0755, true);
			$output->writeln("Created directory: $outputDirDisplay/$pluginId/");

			// Copy and install dependencies
			$vendorNamespaces = $this->handleDependencies($currentDir, $buildDir, $ignorePlatformReqs, $composerCleanup, $output, $includePackages);

			// Copy src directory
			if (is_dir("$currentDir/src")) {
				if ($shouldPrefixNamespaces) {
					$this->copyDirectoryWithNamespaceReplacement(
						"$currentDir/src",
						"$buildDir/src",
						$namespacePrefix,
						$vendorNamespaces
					);
					$output->writeln("Copied and processed: src/");
				} else {
					$this->copyDirectory("$currentDir/src", "$buildDir/src");
					$output->writeln("Copied: src/");
				}
			}

			// Apply namespace prefixing to vendor packages if enabled
			if ($shouldPrefixNamespaces && is_dir("$buildDir/vendor")) {
				if (!empty($includePackages)) {
					$this->applyNamespacePrefixingToVendor("$buildDir/vendor", $namespacePrefix, $output, $includePackages);
				} else {
					$this->applyNamespacePrefixingToVendor("$buildDir/vendor", $namespacePrefix, $output);
				}

				// Process Composer autoload files for namespace replacement
				$composerDir = "$buildDir/vendor/composer";
				if (is_dir($composerDir)) {
					$this->replaceNamespacesInComposerAutoloadFiles($composerDir, $namespacePrefix, $vendorNamespaces);
					$output->writeln("Processed Composer autoload files with namespace replacement");
				}
			}

			// Copy assets directory if exists
			if (is_dir("$currentDir/assets")) {
				$this->copyDirectory("$currentDir/assets", "$buildDir/assets");
				$output->writeln("Copied: assets/");
			}

			// Copy main PHP file
			$mainPhpFile = "$currentDir/$pluginId.php";
			if (file_exists($mainPhpFile)) {
				if ($shouldPrefixNamespaces) {
					$this->copyFileWithNamespaceReplacement(
						$mainPhpFile,
						"$buildDir/$pluginId.php",
						$namespacePrefix,
						$vendorNamespaces
					);
					$output->writeln("Copied and processed: $pluginId.php");
				} else {
					copy($mainPhpFile, "$buildDir/$pluginId.php");
					$output->writeln("Copied: $pluginId.php");
				}
			} else {
				$output->writeln("<comment>Main PHP file not found: $pluginId.php</comment>");
			}

			// Copy README.md if exists
			if (file_exists("$currentDir/README.md")) {
				copy("$currentDir/README.md", "$buildDir/README.md");
				$output->writeln("Copied: README.md");
			}

			// Create ZIP file (only if ZIP extension is available)
			if (extension_loaded('zip')) {
				$zipFile = "$distDir/$pluginId.zip";
				$this->createZipArchive($buildDir, $zipFile, $pluginId);
				$output->writeln("Created: $pluginId.zip");
			}

			$output->writeln("<info>Build completed successfully!</info>");
			$output->writeln("<comment>Distribution files created in: $outputDirDisplay/</comment>");
			$output->writeln("<comment>- Folder: $outputDirDisplay/$pluginId/</comment>");
			if (extension_loaded('zip')) {
				$output->writeln("<comment>- ZIP: $outputDirDisplay/$pluginId.zip</comment>");
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
	 * Handle dependencies installation and return vendor namespaces
	 */
	private function handleDependencies(string $currentDir, string $buildDir, bool $ignorePlatformReqs, bool $composerCleanup, OutputInterface $output, array $includePackages = []): array
	{
		$vendorNamespaces = [];

		// Copy and modify composer.json
		$composerFile = "$currentDir/composer.json";
		if (!file_exists($composerFile)) {
			$output->writeln("<comment>composer.json not found in project root. Skipping dependencies.</comment>");
			return $vendorNamespaces;
		}

		$composerData = json_decode(file_get_contents($composerFile), true);
		if ($composerData === null) {
			$output->writeln("<error>Invalid composer.json file.</error>");
			return $vendorNamespaces;
		}

		// Remove require-dev section
		if (isset($composerData['require-dev'])) {
			unset($composerData['require-dev']);
			$output->writeln("Removed require-dev section from composer.json");
		}

		// If repositories section exists, set options['symlink'] = false for each repository
		if (isset($composerData['repositories']) && is_array($composerData['repositories'])) {
			foreach ($composerData['repositories'] as &$repo) {
				if (is_array($repo)) {
					if (!isset($repo['options']) || !is_array($repo['options'])) {
						$repo['options'] = [];
					}
					$repo['options']['symlink'] = false;
				}
			}
			unset($repo); // break reference
			$output->writeln("Set options['symlink'] = false for all repositories in composer.json");
		}

		// Save modified composer.json to build directory
		$buildComposerFile = "$buildDir/composer.json";
		file_put_contents($buildComposerFile, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$output->writeln("Copied modified composer.json to: $buildComposerFile");

		// Run composer install in build directory
		$output->writeln("<info>Running composer install in build directory...</info>");
		$composerInstallCmd = "composer install --no-dev --optimize-autoloader";
		if ($ignorePlatformReqs) {
			$composerInstallCmd .= " --ignore-platform-reqs";
		}
		$composerCommand = "cd " . escapeshellarg($buildDir) . " && $composerInstallCmd 2>&1";
		$composerOutput = shell_exec($composerCommand);

		if ($composerOutput === null) {
			$output->writeln("<error>Failed to execute composer install.</error>");
			return $vendorNamespaces;
		}

		$output->writeln("<comment>Composer install output:</comment>");
		$output->writeln($composerOutput);

		// Check if vendor directory was created
		$buildVendorDir = "$buildDir/vendor";
		if (!is_dir($buildVendorDir)) {
			$output->writeln("<comment>Vendor directory was not created after composer install.</comment>");
			return $vendorNamespaces;
		}

		// Get all installed packages from the build vendor directory
		$packages = $this->getInstalledPackages($buildVendorDir);
		$output->writeln("<info>Found " . count($packages) . " installed packages</info>");

		// Collect vendor namespaces from installed packages
		$vendorNamespaces = $this->collectVendorNamespaces($buildDir, empty($includePackages) ? $packages : $includePackages);

		// Remove composer.json and composer.lock from build directory
		if ($composerCleanup && file_exists("$buildDir/composer.json")) {
			unlink("$buildDir/composer.json");
			$output->writeln("Removed composer.json from build directory.");
		}
		if ($composerCleanup && file_exists("$buildDir/composer.lock")) {
			unlink("$buildDir/composer.lock");
			$output->writeln("Removed composer.lock from build directory.");
		}

		return $vendorNamespaces;
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
	private function applyNamespacePrefixingToVendor(string $vendorDir, string $namespacePrefix, OutputInterface $output, array $includePackages = []): void
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
						$packageNamespaces = [];

						if (empty($includePackages) || in_array($fullPackageName, $includePackages, true)) {
							$packageNamespaces = $this->getPackageNamespaces($packagePath);
						}

						$dependencies = $this->getPackageDependencies($packagePath);

						foreach ($dependencies as $dependency) {
							if (!empty($includePackages) && !in_array($dependency, $includePackages, true)) {
								continue;
							}

							if (is_dir("$vendorDir/$dependency")) {
								$dependencyNamespaces = $this->getPackageNamespaces("$vendorDir/$dependency");
								$packageNamespaces = array_merge($packageNamespaces, $dependencyNamespaces);
							}
						}

						// Apply namespace prefixing to this package
						$this->applyNamespacePrefixingToDirectory($packagePath, $namespacePrefix, $packageNamespaces);

						$output->writeln("Applied namespace prefixing to: $fullPackageName");
					}
				}
			}
		}
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
	 * Copy a directory recursively with namespace replacement in PHP files
	 */
	private function copyDirectoryWithNamespaceReplacement(string $source, string $dest, string $namespacePrefix, array $namespacesToReplace, $replacementType = null): void
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
				$realSourcePath = $item->getRealPath();
				$this->copyFileWithNamespaceReplacement($realSourcePath, $destPath, $namespacePrefix, $namespacesToReplace, $replacementType);
			}
		}
	}

	/**
	 * Copy a single file with namespace replacement if it's a PHP file
	 */
	private function copyFileWithNamespaceReplacement(string $source, string $dest, string $namespacePrefix, array $namespacesToReplace, $replacementType = null): void
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
	private function replaceNamespaces(string $content, string $namespacePrefix, array $namespacesToReplace, $replacementType = null): string
	{
		foreach ($namespacesToReplace as $namespace => $path) {
			$cleanNamespace = rtrim($namespace, '\\');
			$prefixedNamespace = "$namespacePrefix\\$cleanNamespace";

			// Only replace if the namespace doesn't already have the prefix
			if (strpos($content, $prefixedNamespace) === false) {
				if ($replacementType === 'namespace' || $replacementType === null) {
					// Replace namespace declarations for vendor packages
					$content = preg_replace(
						'/^namespace\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"namespace $prefixedNamespace$1;",
						$content
					);

					// Replace use statements within vendor packages
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"use $prefixedNamespace$1;",
						$content
					);

					// Replace use statements with aliases
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^\\s]+)\s+as\s+([^;]+);/m',
						"use $prefixedNamespace$1 as $2;",
						$content
					);

					// Replace class references in code
					$content = preg_replace(
						'/(\s|^|\s\\\\|new\s+|instanceof\s+|extends\s+|implements\s+)(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '\\\\/',
						"$1$prefixedNamespace\\",
						$content
					);
				} elseif ($replacementType === 'use') {
					// Replace use statements for src files (using vendor packages)
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"use $prefixedNamespace$1;",
						$content
					);

					// Replace use statements with aliases
					$content = preg_replace(
						'/^use\s+(?!' . preg_quote($namespacePrefix, '/') . '\\\\)' . preg_quote($cleanNamespace, '/') . '(\\\\[^\\s]+)\s+as\s+([^;]+);/m',
						"use $prefixedNamespace$1 as $2;",
						$content
					);
				} elseif ($replacementType === 'psr4') {
					// Replace PSR-4 namespace keys in array declarations
					$content = preg_replace(
						'/([\'"])(?!' . str_replace('\\', '\\\\\\\\', $namespacePrefix) . '\\\\)' . str_replace('\\', '\\\\\\\\', $cleanNamespace) . '(\\\\[^\'"]*)([\'"])\s*=>/m',
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
	private function replaceNamespacesInComposerAutoloadFiles(string $composerDir, string $namespacePrefix, array $vendorNamespaces): void
	{
		if (!is_dir($composerDir)) {
			return;
		}

		// Files that need different types of namespace replacement
		$filesToProcessUse = [
			'autoload_real.php',
			'autoload_namespaces.php',
			'autoload_files.php',
			'installed.php',
			'InstalledVersions.php'
		];

		$filesToProcessPsr4 = [
			'autoload_psr4.php',
			'autoload_classmap.php',
			'autoload_static.php'
		];

		// Process files with 'use' replacement type
		foreach ($filesToProcessUse as $file) {
			if (file_exists("$composerDir/$file")) {
				$this->copyFileWithNamespaceReplacement(
					"$composerDir/$file",
					"$composerDir/$file",
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
					"$composerDir/$file",
					$namespacePrefix,
					$vendorNamespaces,
					'psr4'
				);
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