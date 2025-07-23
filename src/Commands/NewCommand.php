<?php

namespace AvelPressCli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewCommand extends Command
{
    protected static $defaultName = 'new';

    protected function configure()
    {
        $this
            ->setDescription('Creates a new AvelPress application.')
            ->addArgument('name', InputArgument::REQUIRED, 'Application name in format <vendor>/<name> (e.g., company/my-plugin)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Application type (plugin or theme)', 'plugin');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $type = $input->getOption('type');

        if (!preg_match('/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+$/', $name)) {
            $output->writeln("<error>Invalid name format. Must be '<vendor>/<name>' (e.g., 'company/my-plugin').</error>");
            return Command::FAILURE;
        }

        [$vendor, $packageName] = explode('/', $name);
        $fullName = $vendor . '-' . $packageName;

        if (!in_array($type, ['plugin', 'theme'])) {
            $output->writeln("<error>Invalid type. Must be 'plugin' or 'theme'.</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Creating new Avelpress {$type}: {$fullName}</info>");

        try {
            $this->createApplicationStructure($vendor, $packageName, $fullName, $type, $output);
            $output->writeln("<info>Application '{$fullName}' created successfully!</info>");

            $output->writeln("<comment>To finish setup, run the following commands:</comment>");
            $output->writeln("<info>  cd {$fullName}</info>");
            $output->writeln("<info>  composer install</info>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error creating application: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function createApplicationStructure(string $vendor, string $packageName, string $fullName, string $type, OutputInterface $output): void
    {
        $basePath = getcwd() . '/' . $fullName;

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
            $output->writeln("Created directory: {$fullName}/");
        }

        $this->createDirectoryStructure($basePath, $output);

        $this->createApplicationFiles($basePath, $vendor, $packageName, $fullName, $type, $output);
    }

    private function createDirectoryStructure(string $basePath, OutputInterface $output): void
    {
        $directories = [
            'src',
            'assets',
            'src/app',
            'src/bootstrap',
            'src/config',
            'src/database',
            'src/resources',
            'src/routes',
            'src/app/Controllers',
            'src/app/Http',
            'src/app/Modules',
            'src/app/Providers',
            'src/app/Services',
            'src/app/Models',
            'src/database/migrations',
            'src/resources/views'
        ];

        foreach ($directories as $dir) {
            $fullPath = $basePath . '/' . $dir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
                $output->writeln("Created directory: {$dir}/");
            }
        }
    }

    private function createApplicationFiles(string $basePath, string $vendor, string $packageName, string $fullName, string $type, OutputInterface $output): void
    {
        $this->createComposerJson($basePath, $vendor, $packageName, $fullName, $type, $output);

        $this->createMainFile($basePath, $vendor, $packageName, $fullName, $type, $output);

        $this->createProvidersFile($basePath, $vendor, $packageName, $output);

        $this->createAppServiceProvider($basePath, $vendor, $packageName, $output);

        $this->createAppConfigFile($basePath, $fullName, $output);

        $this->createApiRoutesFile($basePath, $output);
    }


    private function createComposerJson(string $basePath, string $vendor, string $packageName, string $fullName, string $type, OutputInterface $output): void
    {
        $composer = [
            'name' => $vendor . '/' . strtolower($packageName),
            'description' => "A new AvelPress {$type}: {$fullName}",
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
            'autoload' => [
                'psr-4' => [
                    ucfirst($vendor) . '\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $packageName))) . '\\' => 'src/'
                ]
            ]
        ];

        $filename = $basePath . '/composer.json';
        $content = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($filename, $content);
        $output->writeln("Created file: composer.json");
    }

    private function createMainFile(string $basePath, string $vendor, string $packageName, string $fullName, string $type, OutputInterface $output): void
    {
        $filename = $basePath . '/' . $fullName . '.php';

        $content = "<?php\n";
        $content .= "/**\n";

        if ($type === 'plugin') {
            $content .= " * Plugin Name: " . ucfirst($vendor) . ' ' . ucfirst($packageName) . "\n";
            $content .= " * Description: A new AvelPress plugin.\n";
            $content .= " * Version: 1.0.0\n";
            $content .= " * Requires at least: 6.0\n";
            $content .= " * Requires PHP: 7.4\n";
            $content .= " * Author: Your Name\n";
            $content .= " * Text Domain: " . strtolower($fullName) . "\n";
            $content .= " * License: GPLv2 or later\n";
            $content .= " * License URI: http://www.gnu.org/licenses/gpl-2.0.txt\n";
            $content .= " */\n\n";
        } else {
            $content .= " * Theme Name: " . ucfirst($vendor) . ' ' . ucfirst($packageName) . "\n";
            $content .= " * Description: A new AvelPress theme.\n";
            $content .= " * Version: 1.0.0\n";
            $content .= " * Author: Your Name\n";
            $content .= " * Text Domain: " . strtolower($fullName) . "\n";
            $content .= " */\n\n";
        }

        $content .= "use AvelPress\Avelpress;\n\n";
        $content .= "defined( 'ABSPATH' ) || exit;\n\n";

        $nameWithUnderscore = str_replace('-', '_', $fullName);
        $constantName = strtoupper($nameWithUnderscore) . '_PLUGIN_PATH';

        $content .= "define( '{$constantName}', plugin_dir_path( __FILE__ ) );\n\n";
        $content .= "require {$constantName} . 'vendor/autoload.php';\n\n";

        $content .= "Avelpress::init( '{$fullName}', [\n";
        $content .= "\t'base_path' => {$constantName} . 'src',\n";
        $content .= "] );\n";

        file_put_contents($filename, $content);
        $output->writeln("Created file: {$fullName}.php");
    }

    private function createProvidersFile(string $basePath, string $vendor, string $packageName, OutputInterface $output): void
    {
        $filename = $basePath . '/src/bootstrap/providers.php';

        $vendorPascalCase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $vendor)));
        $packagePascalCase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $packageName)));

        $content = "<?php\n\n";
        $content .= "defined( 'ABSPATH' ) || exit;\n\n";
        $content .= "return [\n";
        $content .= "\t{$vendorPascalCase}\\{$packagePascalCase}\\App\\Providers\\AppServiceProvider::class,\n";
        $content .= "];\n";

        file_put_contents($filename, $content);
        $output->writeln("Created file: src/bootstrap/providers.php");
    }

    private function createAppServiceProvider(string $basePath, string $vendor, string $packageName, OutputInterface $output): void
    {
        $filename = $basePath . '/src/app/Providers/AppServiceProvider.php';

        $vendorPascalCase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $vendor)));
        $packagePascalCase = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $packageName)));

        $content = "<?php\n\n";
        $content .= "namespace {$vendorPascalCase}\\{$packagePascalCase}\\App\\Providers;\n\n";
        $content .= "use AvelPress\\Support\\ServiceProvider;\n\n";
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
        $content .= "\t\t//\n";
        $content .= "\t}\n";
        $content .= "}\n";

        file_put_contents($filename, $content);
        $output->writeln("Created file: src/app/Providers/AppServiceProvider.php");
    }

    private function createAppConfigFile(string $basePath, string $fullName, OutputInterface $output): void
    {
        $filename = $basePath . '/src/config/app.php';

        $content = "<?php\n\n";
        $content .= "defined( 'ABSPATH' ) || exit;\n\n";
        $content .= "return [\n";
        $content .= "\t'name' => '" . ucfirst($fullName) . "',\n";
        $content .= "\t'version' => '1.0.0',\n";
        $content .= "\t'debug' => defined('WP_DEBUG') ? WP_DEBUG : false,\n";
        $content .= "\t'providers' => [\n";
        $content .= "\t\t// Register your service providers here\n";
        $content .= "\t],\n";
        $content .= "];\n";

        file_put_contents($filename, $content);
        $output->writeln("Created file: src/config/app.php");
    }

    private function createApiRoutesFile(string $basePath, OutputInterface $output): void
    {
        $filename = $basePath . '/src/routes/api.php';

        $content = "<?php\n\n";
        $content .= "use AvelPress\\Facades\\Route;\n\n";
        $content .= "defined('ABSPATH') || exit;\n\n";
        $content .= "//Route::prefix('acme-plugin-example/v1')->guards(['edit_posts'])->group(function () {\n";
        $content .= "//\tRoute::get('/route-example', [MyController::class, 'my-function']);\n";
        $content .= "//});\n";

        file_put_contents($filename, $content);
        $output->writeln("Created file: src/routes/api.php");
    }
}
