<?php

namespace AvelPress\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AvelPress\Cli\Helpers\NamespaceHelper;

class MakeModelCommand extends Command
{
	protected static $defaultName = 'make:model';

	protected function configure()
	{
		$this
			->setDescription('Creates a new model for Avelpress.')
			->addArgument('name', InputArgument::REQUIRED, 'Model name')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Model path', 'src/app/Models')
			->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module name (if set, model will be created in src/app/Modules/{module}/Models)')
			->addOption('table', null, InputOption::VALUE_REQUIRED, 'Table name for the model')
			->addOption('fillable', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of fillable attributes')
			->addOption('timestamps', null, InputOption::VALUE_NONE, 'Enable timestamps for the model')
			->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Table prefix for the model');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$name = $input->getArgument('name');
		$module = $input->getOption('module');
		$path = $input->getOption('path');
		$table = $input->getOption('table');
		$fillable = $input->getOption('fillable');
		$timestamps = $input->getOption('timestamps');
		$prefix = $input->getOption('prefix');

		// Ensure the model name follows PascalCase convention
		$className = $this->toPascalCase($name);

		if ($module) {
			$path = "src/app/Modules/{$module}/Models";
		}

		$filename = $path . '/' . $className . '.php';

		if (!is_dir($path)) {
			mkdir($path, 0777, true);
		}

		if (file_exists($filename)) {
			$output->writeln("<error>Model already exists:</error> $filename");
			return Command::FAILURE;
		}

		$content = $this->generateModelContent($className, $module, $table, $fillable, $timestamps, $prefix, $output);

		if ($content === null) {
			return Command::FAILURE;
		}

		file_put_contents($filename, $content);

		$output->writeln("<info>Model created:</info> $filename");

		return Command::SUCCESS;
	}

	private function generateModelContent(string $className, ?string $module = null, ?string $table = null, ?string $fillable = null, bool $timestamps = false, ?string $prefix = null, ?OutputInterface $output = null): ?string
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
			$namespace = NamespaceHelper::getModuleNamespace($packageNamespace, $module, 'Models');
		} else {
			$namespace = NamespaceHelper::getClassNamespace($packageNamespace, 'App\\Models');
		}

		$fillableArray = $fillable ? $this->parseFillableAttributes($fillable) : [];

		$template = "<?php\n\n";
		$template .= "namespace {$namespace};\n\n";
		$template .= "use AvelPress\\Database\\Eloquent\\Model;\n\n";
		$template .= "defined( 'ABSPATH' ) || exit;\n\n";
		$template .= "class {$className} extends Model {\n\n";

		// Table name
		if ($table) {
			$template .= "\t/**\n";
			$template .= "\t * The table associated with the model.\n";
			$template .= "\t */\n";
			$template .= "\tprotected \$table = '{$table}';\n\n";
		}

		// Table prefix
		if ($prefix) {
			$template .= "\t/**\n";
			$template .= "\t * The table prefix for the model.\n";
			$template .= "\t */\n";
			$template .= "\tprotected \$prefix = '{$prefix}';\n\n";
		}

		// Timestamps
		if ($timestamps) {
			$template .= "\t/**\n";
			$template .= "\t * Indicates if the model should be timestamped.\n";
			$template .= "\t */\n";
			$template .= "\tpublic \$timestamps = true;\n\n";
		}

		// Fillable attributes
		if (!empty($fillableArray)) {
			$template .= "\t/**\n";
			$template .= "\t * The attributes that are mass assignable.\n";
			$template .= "\t */\n";
			$template .= "\tprotected \$fillable = [\n";
			foreach ($fillableArray as $attribute) {
				$template .= "\t\t'{$attribute}',\n";
			}
			$template .= "\t];\n\n";
		}

		$template .= "}\n";

		return $template;
	}

	private function toPascalCase(string $name): string
	{
		return NamespaceHelper::toPascalCase($name);
	}

	private function tableNameFromClass(string $className): string
	{
		// Convert PascalCase to snake_case and pluralize
		$tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));

		// Simple pluralization (basic rules)
		if (substr($tableName, -1) === 'y') {
			$tableName = substr($tableName, 0, -1) . 'ies';
		} elseif (in_array(substr($tableName, -1), ['s', 'x', 'z']) || in_array(substr($tableName, -2), ['ch', 'sh'])) {
			$tableName .= 'es';
		} else {
			$tableName .= 's';
		}

		return $tableName;
	}

	private function parseFillableAttributes(string $fillable): array
	{
		$attributes = array_map('trim', explode(',', $fillable));
		return array_filter($attributes); // Remove empty values
	}
}
