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
		$configFile = $currentDir . '/avelpress.config.php';

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

		// Get project name from current directory
		$projectName = basename($currentDir);
		$distDir = $currentDir . '/dist';
		$buildDir = $distDir . '/' . $projectName;

		$output->writeln("<info>Building distribution package for: {$projectName}</info>");
		$output->writeln("<info>Using namespace prefix: {$namespacePrefix}</info>");

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
			$output->writeln("Created directory: dist/{$projectName}/");

			// Copy src directory with namespace replacement
			if (is_dir($currentDir . '/src')) {
				$this->copyDirectoryWithNamespaceReplacement($currentDir . '/src', $buildDir . '/src', $namespacePrefix);
				$output->writeln("Copied and processed: src/");
			}

			// Copy vendor packages specified in configuration
			if (!empty($packages)) {
				$vendorDir = $currentDir . '/vendor';
				if (is_dir($vendorDir)) {
					foreach ($packages as $package) {
						$packagePath = $vendorDir . '/' . $package;
						if (is_dir($packagePath)) {
							$packageDest = $buildDir . '/vendor/' . $package;
							$this->copyVendorPackageWithNamespaceReplacement($packagePath, $packageDest, $namespacePrefix);
							$output->writeln("Copied and processed vendor package: {$package}");
						} else {
							$output->writeln("<warning>Vendor package not found: {$package}</warning>");
						}
					}
				} else {
					$output->writeln("<warning>Vendor directory not found</warning>");
				}
			}

			// Copy assets directory if exists
			if (is_dir($currentDir . '/assets')) {
				$this->copyDirectory($currentDir . '/assets', $buildDir . '/assets');
				$output->writeln("Copied: assets/");
			}

			// Copy main PHP file with namespace replacement
			$mainPhpFile = $currentDir . '/' . $projectName . '.php';
			if (file_exists($mainPhpFile)) {
				$this->copyFileWithNamespaceReplacement($mainPhpFile, $buildDir . '/' . $projectName . '.php', $namespacePrefix);
				$output->writeln("Copied and processed: {$projectName}.php");
			} else {
				$output->writeln("<warning>Main PHP file not found: {$projectName}.php</warning>");
			}

			// Copy README.md if exists
			if (file_exists($currentDir . '/README.md')) {
				copy($currentDir . '/README.md', $buildDir . '/README.md');
				$output->writeln("Copied: README.md");
			}

			// Create ZIP file
			$zipFile = $distDir . '/' . $projectName . '.zip';
			$this->createZipArchive($buildDir, $zipFile, $projectName);
			$output->writeln("Created: {$projectName}.zip");

			$output->writeln("<info>Build completed successfully!</info>");
			$output->writeln("<comment>Distribution files created in: dist/</comment>");
			$output->writeln("<comment>- Folder: dist/{$projectName}/</comment>");
			$output->writeln("<comment>- ZIP: dist/{$projectName}.zip</comment>");

			return Command::SUCCESS;
		} catch (\Exception $e) {
			$output->writeln("<error>Error building package: {$e->getMessage()}</error>");
			return Command::FAILURE;
		}
	}

	/**
	 * Copy a vendor package with namespace replacement based on its composer.json
	 */
	private function copyVendorPackageWithNamespaceReplacement($source, $dest, $namespacePrefix)
	{
		if (!is_dir($source)) {
			return;
		}

		if (!is_dir($dest)) {
			mkdir($dest, 0755, true);
		}

		// Read package's composer.json to get PSR-4 namespaces
		$composerFile = $source . '/composer.json';
		$packageNamespaces = [];
		
		if (file_exists($composerFile)) {
			$composerData = json_decode(file_get_contents($composerFile), true);
			if (isset($composerData['autoload']['psr-4'])) {
				$packageNamespaces = $composerData['autoload']['psr-4'];
			}
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$sourcePath = $item->getRealPath();
			$relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $sourcePath);
			$destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;

			if ($item->isDir()) {
				if (!is_dir($destPath)) {
					mkdir($destPath, 0755, true);
				}
			} else {
				$this->copyVendorFileWithNamespaceReplacement($sourcePath, $destPath, $packageNamespaces, $namespacePrefix);
			}
		}
	}

	/**
	 * Copy a vendor file with namespace replacement if it's a PHP file
	 */
	private function copyVendorFileWithNamespaceReplacement($source, $dest, $packageNamespaces, $namespacePrefix)
	{
		if (pathinfo($source, PATHINFO_EXTENSION) === 'php') {
			$content = file_get_contents($source);
			
			// Replace package namespaces with prefixed versions
			foreach ($packageNamespaces as $namespace => $path) {
				$cleanNamespace = rtrim($namespace, '\\');
				
				// Only replace if the namespace doesn't already have the prefix
				if (strpos($content, $namespacePrefix . '\\' . $cleanNamespace) === false) {
					// Replace namespace declarations - only exact matches
					$content = preg_replace(
						'/^namespace\s+' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"namespace {$namespacePrefix}\\{$cleanNamespace}$1;",
						$content
					);
					
					// Replace use statements - only exact matches at start of use
					$content = preg_replace(
						'/^use\s+' . preg_quote($cleanNamespace, '/') . '(\\\\[^;]*)?;/m',
						"use {$namespacePrefix}\\{$cleanNamespace}$1;",
						$content
					);
					
					// Replace use statements with aliases
					$content = preg_replace(
						'/^use\s+' . preg_quote($cleanNamespace, '/') . '(\\\\[^\\s]+)\s+as\s+([^;]+);/m',
						"use {$namespacePrefix}\\{$cleanNamespace}$1 as $2;",
						$content
					);
					
					// Replace class references in code - be very careful here
					$content = preg_replace(
						'/(\s|^|\\\\|new\s+|instanceof\s+|extends\s+|implements\s+)' . preg_quote($cleanNamespace, '/') . '\\\\/',
						"$1{$namespacePrefix}\\{$cleanNamespace}\\",
						$content
					);
				}
			}

			file_put_contents($dest, $content);
		} else {
			// For non-PHP files, just copy directly
			copy($source, $dest);
		}
	}

	/**
	 * Copy a directory recursively with namespace replacement in PHP files
	 */
	private function copyDirectoryWithNamespaceReplacement($source, $dest, $namespacePrefix)
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
			$sourcePath = $item->getRealPath();
			$relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $sourcePath);
			$destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;

			if ($item->isDir()) {
				if (!is_dir($destPath)) {
					mkdir($destPath, 0755, true);
				}
			} else {
				$this->copyFileWithNamespaceReplacement($sourcePath, $destPath, $namespacePrefix);
			}
		}
	}

	/**
	 * Copy a single file with namespace replacement if it's a PHP file
	 */
	private function copyFileWithNamespaceReplacement($source, $dest, $namespacePrefix)
	{
		if (pathinfo($source, PATHINFO_EXTENSION) === 'php') {
			$content = file_get_contents($source);
			
			// Only replace if the namespace doesn't already have the prefix
			if (strpos($content, $namespacePrefix . '\\AvelPress') === false) {
				// Replace "use AvelPress" with "use {NamespacePrefix}\AvelPress" - only exact matches
				$content = preg_replace(
					'/^use\s+AvelPress(\\\\[^;]*)?;/m',
					"use {$namespacePrefix}\\AvelPress$1;",
					$content
				);
				
				// Replace "use AvelPress" with aliases
				$content = preg_replace(
					'/^use\s+AvelPress(\\\\[^\\s]+)\s+as\s+([^;]+);/m',
					"use {$namespacePrefix}\\AvelPress$1 as $2;",
					$content
				);
			}

			file_put_contents($dest, $content);
		} else {
			// For non-PHP files, just copy directly
			copy($source, $dest);
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
			$sourcePath = $item->getRealPath();
			$relativePath = str_replace($source . DIRECTORY_SEPARATOR, '', $sourcePath);
			$destPath = $destination . DIRECTORY_SEPARATOR . $relativePath;
			
			if ($item->isDir()) {
				if (!is_dir($destPath)) {
					mkdir($destPath, 0755, true);
				}
			} else {
				copy($sourcePath, $destPath);
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
		$zip = new ZipArchive();
		
		if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
			throw new \Exception("Cannot create ZIP file: {$zipFile}");
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $file) {
			$filePath = $file->getRealPath();
			$relativePath = $projectName . '/' . substr($filePath, strlen($sourceDir) + 1);

			if ($file->isDir()) {
				$zip->addEmptyDir($relativePath);
			} else {
				$zip->addFile($filePath, $relativePath);
			}
		}

		$zip->close();
	}
}
