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
		$packages = $config['build']['prefixer']['packages'] ?? [];

		// Collect all vendor namespaces from packages
		$vendorNamespaces = $this->collectVendorNamespaces($currentDir, $packages);

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
				$this->copyDirectoryWithNamespaceReplacement(
					"$currentDir/src",
					"$buildDir/src",
					$namespacePrefix,
					$vendorNamespaces,
					'use'
				);
				$output->writeln("Copied and processed: src/");
			}

			// Copy vendor packages specified in configuration
			if (!empty($packages)) {
				$vendorDir = "$currentDir/vendor";
				if (is_dir($vendorDir)) {
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
						} else {
							$output->writeln("<warning>Vendor package not found: $package</warning>");
						}
					}
				} else {
					$output->writeln("<warning>Vendor directory not found</warning>");
				}
			}

			// Copy Composer autoload files
			if (!empty($packages)) {
				$vendorDir = "$currentDir/vendor";
				if (is_dir($vendorDir)) {
					$this->copyComposerAutoloadFiles($vendorDir, "$buildDir/vendor", $namespacePrefix, $vendorNamespaces);
					$output->writeln("Copied and processed Composer autoload files");
				}
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

		// Copy installed.json if exists (no processing needed)
		if (file_exists("$composerDir/installed.json")) {
			copy("$composerDir/installed.json", "$destComposerDir/installed.json");
		}
	}

	private function copyDirectory(string $source, string $destination): void
	{
		if (!is_dir($destination)) {
			mkdir($destination, 0755, true);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			// Use getPathname() instead of getRealPath() to avoid resolving symlinks
			$sourcePath = $item->getPathname();
			$relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $sourcePath);
			$destPath = $destination . DIRECTORY_SEPARATOR . $relativePath;

			if ($item->isDir()) {
				if (!is_dir($destPath)) {
					mkdir($destPath, 0755, true);
				}
			} else {
				// For files, we need the real path for copying
				$realSourcePath = $item->getRealPath();
				copy($realSourcePath, $destPath);
			}
		}
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $fileinfo) {
			if ($fileinfo->isDir()) {
				rmdir($fileinfo->getRealPath());
			} else {
				unlink($fileinfo->getRealPath());
			}
		}

		rmdir($dir);
	}

	private function createZipArchive(string $sourceDir, string $zipFile, string $projectName): void
	{
		// Double check that ZIP extension is available
		if (!extension_loaded('zip')) {
			throw new \Exception("ZIP extension is not available. Cannot create ZIP file.");
		}

		$zip = new ZipArchive();

		if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
			throw new \Exception("Cannot create ZIP file: $zipFile");
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			// Use getPathname() for relative path calculation
			$filePath = $file->getPathname();
			$relativePath = "$projectName/" . substr($filePath, strlen($sourceDir) + 1);

			if ($file->isDir()) {
				$zip->addEmptyDir($relativePath);
			} else {
				// Use getRealPath() for actual file content
				$realFilePath = $file->getRealPath();
				$zip->addFile($realFilePath, $relativePath);
			}
		}

		$zip->close();
	}
}
