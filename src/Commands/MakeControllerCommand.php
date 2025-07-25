<?php

namespace AvelPress\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AvelPress\Cli\Helpers\NamespaceHelper;

class MakeControllerCommand extends Command
{
	protected static $defaultName = 'make:controller';

	protected function configure()
	{
		$this
			->setDescription('Creates a new controller for AvelPress.')
			->addArgument('name', InputArgument::REQUIRED, 'Controller name')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Controller path', 'src/app/Http/Controllers')
			->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module name (if set, controller will be created in src/app/Modules/{module}/Http/Controllers)')
			->addOption('resource', null, InputOption::VALUE_NONE, 'Generate a resource controller with CRUD methods');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$name = $input->getArgument('name');
		$module = $input->getOption('module');
		$path = $input->getOption('path');
		$resource = $input->getOption('resource');

		// Ensure the controller name follows PascalCase convention and ends with Controller
		$className = $this->toPascalCase($name);
		if (substr($className, -10) !== 'Controller') {
			$className .= 'Controller';
		}

		if ($module) {
			$path = "src/app/Modules/{$module}/Http/Controllers";
		}

		$filename = $path . '/' . $className . '.php';

		if (!is_dir($path)) {
			mkdir($path, 0777, true);
		}

		if (file_exists($filename)) {
			$output->writeln("<error>Controller already exists:</error> $filename");
			return Command::FAILURE;
		}

		$content = $this->generateControllerContent($className, $module, $resource, $output);

		if ($content === null) {
			return Command::FAILURE;
		}

		file_put_contents($filename, $content);

		$output->writeln("<info>Controller created:</info> $filename");

		return Command::SUCCESS;
	}

	private function generateControllerContent(string $className, ?string $module = null, bool $resource = false, ?OutputInterface $output = null): ?string
	{
		$packageNamespace = NamespaceHelper::detectPackageNamespace();

		if (!$packageNamespace) {
			if ($output) {
				$output->writeln("<error>Error:</error> You must be in a valid AvelPress project directory that contains a composer.json file.");
				$output->writeln("<comment>Please navigate to your AvelPress project root directory and try again.</comment>");
			}
			return null;
		}

		if ($module) {
			$namespace = NamespaceHelper::getModuleNamespace($packageNamespace, $module, 'Http\\Controllers');
		} else {
			$namespace = NamespaceHelper::getClassNamespace($packageNamespace, 'App\\Http\\Controllers');
		}

		$template = "<?php\n\n";
		$template .= "namespace {$namespace};\n\n";
		$template .= "use AvelPress\\Routing\\Controller;\n\n";
		$template .= "defined( 'ABSPATH' ) || exit;\n\n";
		$template .= "class {$className} extends Controller {\n\n";

		if ($resource) {
			$template .= $this->generateResourceMethods();
		}

		$template .= "}\n";

		return $template;
	}

	private function generateResourceMethods(): string
	{
		$methods = '';

		// Index method
		$methods .= "\t/**\n";
		$methods .= "\t * Display a listing of the resource.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function index() {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		// Create method
		$methods .= "\t/**\n";
		$methods .= "\t * Show the form for creating a new resource.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function create() {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		// Store method
		$methods .= "\t/**\n";
		$methods .= "\t * Store a newly created resource in storage.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function store(\$request) {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		// Show method
		$methods .= "\t/**\n";
		$methods .= "\t * Display the specified resource.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function show(\$request) {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		// Edit method
		$methods .= "\t/**\n";
		$methods .= "\t * Show the form for editing the specified resource.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function edit(\$request) {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		// Update method
		$methods .= "\t/**\n";
		$methods .= "\t * Update the specified resource in storage.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function update(\$request) {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		// Destroy method
		$methods .= "\t/**\n";
		$methods .= "\t * Remove the specified resource from storage.\n";
		$methods .= "\t */\n";
		$methods .= "\tpublic function destroy(\$request) {\n";
		$methods .= "\t\t//\n";
		$methods .= "\t}\n\n";

		return $methods;
	}

	private function toPascalCase(string $name): string
	{
		return NamespaceHelper::toPascalCase($name);
	}
}
