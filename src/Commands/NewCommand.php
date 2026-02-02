<?php

namespace AvelPress\Cli\Commands;

use AvelPress\Cli\Helpers\NamespaceHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class NewCommand extends Command {
	protected static $defaultName = 'new';

	protected function configure() {
		$this
			->setDescription( 'Creates a new AvelPress application.' )
			->addArgument( 'name', InputArgument::REQUIRED, 'Application name in format <vendor>/<name> (e.g., company/my-plugin)' )
			->addOption( 'type', null, InputOption::VALUE_REQUIRED, 'Application type (plugin or theme)', 'plugin' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$name = $input->getArgument( 'name' );
		$type = $input->getOption( 'type' );

		if ( ! preg_match( '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $name ) ) {
			$output->writeln( "<error>Invalid name format. Must be '<vendor>/<name>' (e.g., 'company/my-plugin').</error>" );
			return Command::FAILURE;
		}

		[ $vendor, $packageName ] = explode( '/', $name );
		$fullName = $vendor . '-' . $packageName;
		$appId = strtolower( $fullName );

		if ( ! in_array( $type, [ 'plugin', 'theme' ] ) ) {
			$output->writeln( "<error>Invalid type. Must be 'plugin' or 'theme'.</error>" );
			return Command::FAILURE;
		}

		// Get additional information for plugin
		$displayName = '';
		$shortDescription = '';

		if ( $type === 'plugin' ) {
			$helper = $this->getHelper( 'question' );

			// Ask for display name
			$displayNameQuestion = new Question( '<question>Enter the display name for the plugin (max 80 characters): </question>' );
			$displayNameQuestion->setValidator( function ( $answer ) {
				if ( empty( $answer ) || strlen( $answer ) > 80 ) {
					throw new \RuntimeException( 'Display name is required and must be 80 characters or less.' );
				}
				return $answer;
			} );
			$displayName = $helper->ask( $input, $output, $displayNameQuestion );

			// Ask for short description
			$shortDescQuestion = new Question( '<question>Enter a short description for the plugin (max 150 characters): </question>' );
			$shortDescQuestion->setValidator( function ( $answer ) {
				if ( empty( $answer ) || strlen( $answer ) > 150 ) {
					throw new \RuntimeException( 'Short description is required and must be 150 characters or less.' );
				}
				return $answer;
			} );
			$shortDescription = $helper->ask( $input, $output, $shortDescQuestion );
		}

		$output->writeln( "<info>Creating new Avelpress {$type}: {$fullName}</info>" );

		try {
			$this->createApplicationStructure( $vendor, $packageName, $fullName, $type, $displayName, $shortDescription, $appId, $output );
			$output->writeln( "<info>Application '{$fullName}' created successfully!</info>" );

			$output->writeln( "<comment>To finish setup, run the following commands:</comment>" );
			$output->writeln( "<info>  cd {$fullName}</info>" );
			$output->writeln( "<info>  composer install</info>" );

			return Command::SUCCESS;
		} catch (\Exception $e) {
			$output->writeln( "<error>Error creating application: {$e->getMessage()}</error>" );
			return Command::FAILURE;
		}
	}

	private function createApplicationStructure( string $vendor, string $packageName, string $fullName, string $type, string $displayName, string $shortDescription, string $appId, OutputInterface $output ): void {
		$basePath = getcwd() . '/' . $fullName;

		if ( ! is_dir( $basePath ) ) {
			mkdir( $basePath, 0755, true );
			$output->writeln( "Created directory: {$fullName}/" );
		}

		$this->createDirectoryStructure( $basePath, $output );

		$this->createApplicationFiles( $basePath, $vendor, $packageName, $fullName, $type, $displayName, $shortDescription, $appId, $output );
	}

	private function createDirectoryStructure( string $basePath, OutputInterface $output ): void {
		$directories = [
			'src',
			'assets',
			'languages',
			'src/app',
			'src/bootstrap',
			'src/config',
			'src/database',
			'src/resources',
			'src/routes',
			'src/app/Http',
			'src/app/Http/Controllers',
			'src/app/Modules',
			'src/app/Providers',
			'src/app/Services',
			'src/app/Models',
			'src/database/migrations',
			'src/resources/views'
		];

		foreach ( $directories as $dir ) {
			$fullPath = $basePath . '/' . $dir;
			if ( ! is_dir( $fullPath ) ) {
				mkdir( $fullPath, 0755, true );
				$output->writeln( "Created directory: {$dir}/" );
			}
		}
	}

	private function createApplicationFiles( string $basePath, string $vendor, string $packageName, string $fullName, string $type, string $displayName, string $shortDescription, string $appId, OutputInterface $output ): void {
		$packageNamespace = NamespaceHelper::getPackageNamespace( $vendor, $packageName );

		$this->createComposerJson( $basePath, $vendor, $packageName, $fullName, $type, $packageNamespace, $shortDescription, $output );

		$this->createMainFile( $basePath, $vendor, $packageName, $fullName, $type, $packageNamespace, $displayName, $output );

		$this->createProvidersFile( $basePath, $packageNamespace, $output );

		$this->createAppServiceProvider( $basePath, $packageNamespace, $output );

		$this->createAppConfigFile( $basePath, $fullName, $output );

		$this->createAdminSetupFile( $basePath, $packageNamespace, $output );

		$this->createAdminMenuFile( $basePath, $packageNamespace, $displayName, $appId, $output );

		$this->createApiRoutesFile( $basePath, $output );

		$this->createAdminRoutesFile( $basePath, $output );

		$this->createAvelPressConfigFile( $basePath, $packageNamespace, $output, $fullName );

		if ( $type === 'plugin' ) {
			$this->createReadmeTxtFile( $basePath, $vendor, $displayName, $shortDescription, $output );
		}

		$this->createGitignoreFile( $basePath, $output );
	}


	private function createComposerJson( string $basePath, string $vendor, string $packageName, string $fullName, string $type, string $composerNamespace, string $shortDescription, OutputInterface $output ): void {
		$composer = [
			'name' => $vendor . '/' . strtolower( $packageName ),
			'description' => $shortDescription ?: "A new AvelPress {$type}: {$fullName}",
			'version' => '1.0.0',
			'type' => $type === 'plugin' ? 'wordpress-plugin' : 'wordpress-theme',
			'license' => 'GPL-2.0+',
			'authors' => [
				[
					'name' => 'Your Name',
					'email' => 'your@email.com'
				]
			],
			'require' => [
				'avelpress/avelpress' => '^1.0'
			],
			'require-dev' => [
				'php-stubs/wordpress-stubs' => '^6.8',
				'php-stubs/woocommerce-stubs' => '^9.9'
			],
			'autoload' => [
				'psr-4' => [
					$composerNamespace . "\\" => 'src/',
					$composerNamespace . "\\App\\" => 'src/app'
				]
			]
		];

		$filename = $basePath . '/composer.json';
		$content = json_encode( $composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		file_put_contents( $filename, $content );
		$output->writeln( "Created file: composer.json" );
	}

	private function createMainFile( string $basePath, string $vendor, string $packageName, string $fullName, string $type, string $packageNamespace, string $displayName, OutputInterface $output ): void {
		$filename = $basePath . '/' . $fullName . '.php';

		$vendorDisplay = ucfirst( $vendor );
		$packageDisplay = ucfirst( $packageName );

		$content = "<?php\n";
		$content .= "/**\n";
		$content .= " * This plugin uses AvelPress Framework (https://avelpress.com)\n\n";

		if ( $type === 'plugin' ) {
			$pluginName = $displayName ?: "{$vendorDisplay} {$packageDisplay}";
			$content .= " * Plugin Name: {$pluginName}\n";
			$content .= " * Description: A new AvelPress plugin.\n";
			$content .= " * Version: 1.0.0\n";
			$content .= " * Requires at least: 6.0\n";
			$content .= " * Requires PHP: 7.4\n";
			$content .= " * Author: Your Name\n";
			$content .= " * Text Domain: " . strtolower( $fullName ) . "\n";
			$content .= " * License: GPLv2 or later\n";
			$content .= " * License URI: http://www.gnu.org/licenses/gpl-2.0.txt\n";
			$content .= " */\n\n";
		} else {
			$content .= " * Theme Name: {$vendorDisplay} {$packageDisplay}\n";
			$content .= " * Description: A new AvelPress theme.\n";
			$content .= " * Version: 1.0.0\n";
			$content .= " * Author: Your Name\n";
			$content .= " * Text Domain: " . strtolower( $fullName ) . "\n";
			$content .= " */\n\n";
		}

		$content .= "use AvelPress\\Avelpress;\n\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n\n";

		$nameWithUnderscore = str_replace( '-', '_', $fullName );
		$constantName = strtoupper( $nameWithUnderscore ) . '_PLUGIN_PATH';

		$content .= "define( '{$constantName}', plugin_dir_path( __FILE__ ) );\n\n";
		$content .= "require {$constantName} . 'vendor/autoload.php';\n\n";

		$content .= "Avelpress::init( '{$fullName}', [\n";
		$content .= "\t'base_path' => {$constantName} . 'src',\n";
		$content .= "] );\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: {$fullName}.php" );
	}

	private function createProvidersFile( string $basePath, string $packageNamespace, OutputInterface $output ): void {
		$filename = $basePath . '/src/bootstrap/providers.php';

		$content = "<?php\n\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n\n";
		$content .= "return [\n";
		$content .= "\t{$packageNamespace}\\App\\Providers\\AppServiceProvider::class,\n";
		$content .= "];\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: src/bootstrap/providers.php" );
	}

	private function createAppServiceProvider( string $basePath, string $packageNamespace, OutputInterface $output ): void {
		$filename = $basePath . '/src/app/Providers/AppServiceProvider.php';

		$content = "<?php\n\n";
		$content .= "namespace {$packageNamespace}\\App\\Providers;\n\n";
		$content .= "use AvelPress\\Support\\ServiceProvider;\n\n";
		$content .= "use {$packageNamespace}\\App\\Admin\\Setup;\n\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n\n";
		$content .= "class AppServiceProvider extends ServiceProvider {\n";
		$content .= "\t/**\n";
		$content .= "\t * Register any application services.\n";
		$content .= "\t */\n";
		$content .= "\tpublic function register(): void {\n";
		$content .= "\t\t//\n";
		$content .= "\t}\n\n";
		$content .= "\t/**\n";
		$content .= "\t * Bootstrap any application services.\n";
		$content .= "\t */\n";
		$content .= "\tpublic function boot(): void {\n";
		$content .= "\t\tadd_action( 'init', [ \$this, 'admin_setup' ] );\n";
		$content .= "\t}\n";
		$content .= "\t/**\n";
		$content .= "\t * Admin setup method.\n";
		$content .= "\t */\n";
		$content .= "\tpublic function admin_setup(): void {\n";
		$content .= "\t\tnew Setup();\n";
		$content .= "\t}\n";
		$content .= "}\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: src/app/Providers/AppServiceProvider.php" );
	}

	private function createAppConfigFile( string $basePath, string $fullName, OutputInterface $output ): void {
		$filename = $basePath . '/src/config/app.php';

		$content = "<?php\n\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n\n";
		$content .= "return [\n";
		$content .= "\t// Admin Classes\n";
		$content .= "\t'admin_menu_class' => '\\Infixs\\AutomationForWhatsapp\\App\\Admin\\Menu::class',\n";
		$content .= "\t'admin_setup_class' => '\\Infixs\\AutomationForWhatsapp\\App\\Admin\\Setup::class',\n";
		$content .= "];\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: src/config/app.php" );
	}


	private function createAdminSetupFile( string $basePath, string $packageNamespace, OutputInterface $output ): void {
		$filename = $basePath . '/src/app/Admin/Setup.php';

		$content = "<?php\n\n";
		$content .= "namespace {$packageNamespace}\\App\\Admin;\n\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n\n";
		$content .= "class Setup {\n";
		$content .= "\tpublic function __construct() {\n";
		$content .= "\t\t// Add admin setup code here\n";
		$content .= "\t}\n";
		$content .= "}\n";

		file_put_contents( $filename, $content );

		$output->writeln( "Created file: src/app/Admin/Setup.php" );
	}

	private function createAdminMenuFile( string $basePath, string $packageNamespace, string $displayName, string $appId, OutputInterface $output ): void {
		$filename = $basePath . '/src/app/Admin/Menu.php';

		$content = "<?php\n\n";
		$content .= "namespace {$packageNamespace}\\App\\Admin;\n\n";
		$content .= "use AvelPress\\Admin\\Menu\\MenuBuilder;\n\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n\n";
		$content .= "class Menu extends MenuBuilder {\n";
		$content .= "\tpublic function register() {\n";
		$content .= "\t\t\$menu = \$this\n";
		$content .= "\t\t\t->add( '{$appId}', __( '{$displayName}', '{$appId}' ) )\n";
		$content .= "\t\t\t->icon( 'dashicons-lightbulb' )\n";
		$content .= "\t\t\t->capability( 'manage_options' );\n";
		$content .= "\t}\n";
		$content .= "}\n";

		file_put_contents( $filename, $content );

		$output->writeln( "Created file: src/app/Admin/Menu.php" );
	}

	private function createApiRoutesFile( string $basePath, OutputInterface $output ): void {
		$filename = $basePath . '/src/routes/api.php';

		$content = "<?php\n\n";
		$content .= "use AvelPress\\Facades\\Route;\n\n";
		$content .= "defined('ABSPATH') || exit;\n\n";
		$content .= "//Route::prefix('acme-plugin-example/v1')->guards(['edit_posts'])->group(function () {\n";
		$content .= "//\tRoute::get('/route-example', [MyController::class, 'my-function']);\n";
		$content .= "//});\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: src/routes/api.php" );
	}

	private function createAdminRoutesFile( string $basePath, OutputInterface $output ): void {
		$filename = $basePath . '/src/routes/admin.php';

		$content = "<?php\n\n";
		$content .= "use AvelPress\\Facades\\Route;\n\n";
		$content .= "defined('ABSPATH') || exit;\n\n";
		$content .= "//You can access this example route with: /wp-admin/admin.php?page=my-page-id&path=/path-example\n";
		$content .= "//Route::page('my-page-id')->guards(['edit_posts'])->group(function () {\n";
		$content .= "//\tRoute::get('/path-example', [MyController::class, 'my-function']);\n";
		$content .= "//});\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: src/routes/admin.php" );
	}

	private function createAvelPressConfigFile( string $basePath, string $packageNamespace, OutputInterface $output, string $fullName ): void {
		$filename = $basePath . '/avelpress.config.php';

		$content = "<?php\n\n";
		$content .= "return [\n";
		$content .= "\t'plugin_id' => '$fullName',\n";
		$content .= "\t'build' => [\n";
		$content .= "\t\t'output_dir' => 'dist',\n";
		$content .= "\t\t'copy' => [],\n";
		$content .= "\t\t'prefixer' => [\n";
		$content .= "\t\t\t'enabled' => true,\n";
		$content .= "\t\t\t'namespace_prefix' => '" . NamespaceHelper::escapeNamespace( $packageNamespace ) . "\\\\',\n";
		$content .= "\t\t]\n";
		$content .= "\t]\n";
		$content .= "];\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: avelpress.config.php" );
	}

	private function createReadmeTxtFile( string $basePath, string $vendor, string $displayName, string $shortDescription, OutputInterface $output ): void {
		$filename = $basePath . '/readme.txt';

		$vendorName = ucfirst( $vendor );

		$content = "=== {$displayName} ===\n";
		$content .= "Contributors: {$vendorName}\n";
		$content .= "Tags: wordpress, plugin, avelpress, composer, php\n";
		$content .= "Requires at least: 6.0\n";
		$content .= "Requires PHP: 7.4\n";
		$content .= "Tested up to: 6.8\n";
		$content .= "Stable tag: 1.0.0\n";
		$content .= "License: GPLv2 or later\n";
		$content .= "License URI: http://www.gnu.org/licenses/gpl-2.0.html\n";
		$content .= "{$shortDescription}\n\n";
		$content .= "== Description ==\n\n";
		$content .= "{$shortDescription}\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: readme.txt" );
	}

	private function createGitignoreFile( string $basePath, OutputInterface $output ): void {
		$filename = $basePath . '/.gitignore';

		$content = "/vendor\n";
		$content .= "/dist\n";

		file_put_contents( $filename, $content );
		$output->writeln( "Created file: .gitignore" );
	}
}
