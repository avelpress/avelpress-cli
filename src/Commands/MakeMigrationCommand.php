<?php

namespace AvelPressCli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeMigrationCommand extends Command
{
	protected static $defaultName = 'make:migration';

	protected function configure()
	{
		$this
			->setDescription('Creates a new migration for Avelpress.')
			->addArgument('name', InputArgument::REQUIRED, 'Migration name')
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Migration path', 'src/database/migrations')
			->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module name (if set, migration will be created in src/app/Modules/{module}/database/migrations)')
			->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'App ID prefix for table names');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$name = $input->getArgument('name');
		$module = $input->getOption('module');
		$path = $input->getOption('path');
		$appId = $input->getOption('app-id');

		if ($module) {
			$path = "src/app/Modules/{$module}/database/migrations";
		}

		$filename = $path . '/' . date('Y_m_d_His') . "_{$name}.php";

		if (!is_dir($path)) {
			mkdir($path, 0777, true);
		}

		$content = $this->generateMigrationContent($name, $appId);
		file_put_contents($filename, $content);

		$output->writeln("<info>Migration created:</info> $filename");

		return Command::SUCCESS;
	}

	private function generateMigrationContent(string $name, ?string $appId = null): string
	{
		$tableName = $this->extractTableName($name);
		$isCreateTable = $this->isCreateTableMigration($name);

		// Adicionar prefixo app-id se fornecido
		if ($appId) {
			$appId = preg_replace('/[-\s]/', '_', $appId);
			$tableName = $appId . '_' . $tableName;
		}

		$template = "<?php\n\n";
		$template .= "use AvelPress\Database\Migrations\Migration;\n";
		$template .= "use AvelPress\Database\Schema\Blueprint;\n";
		$template .= "use AvelPress\Database\Schema\Schema;\n\n";
		$template .= "defined( 'ABSPATH' ) || exit;\n\n";
		$template .= "return new class extends Migration {\n";
		$template .= "\t/**\n";
		$template .= "\t * Run the migrations.\n";
		$template .= "\t */\n";
		$template .= "\tpublic function up(): void {\n";

		if ($isCreateTable) {
			$template .= "\t\tSchema::create( '{$tableName}', function (Blueprint \$table) {\n";
			$template .= "\t\t\t// Add columns to the table\n";
			$template .= "\t\t} );\n";
		} else {
			$template .= "\t\tSchema::table( '{$tableName}', function (Blueprint \$table) {\n";
			$template .= "\t\t\t// Add/modify columns to the table\n";
			$template .= "\t\t} );\n";
		}

		$template .= "\t}\n\n";
		$template .= "\t/**\n";
		$template .= "\t * Reverse the migrations.\n";
		$template .= "\t */\n";
		$template .= "\tpublic function down(): void {\n";

		if ($isCreateTable) {
			$template .= "\t\tSchema::drop( '{$tableName}' );\n";
		} else {
			$template .= "\t\tSchema::table( '{$tableName}', function (Blueprint \$table) {\n";
			$template .= "\t\t\t// Remove the changes made in up() method\n";
			$template .= "\t\t} );\n";
		}

		$template .= "\t}\n";
		$template .= "};\n";

		return $template;
	}

	private function isCreateTableMigration(string $name): bool
	{
		return preg_match('/^create_(.+)_table$/', $name) === 1;
	}

	private function extractTableName(string $name): string
	{
		// Para migrations do tipo "create_{table_name}_table"
		if (preg_match('/^create_(.+)_table$/', $name, $matches)) {
			return $matches[1];
		}

		// Para migrations do tipo "add_{column_name}_to_{table_name}_table"
		if (preg_match('/^add_.+_to_(.+)_table$/', $name, $matches)) {
			return $matches[1];
		}

		// Para outros padrões, tentar extrair o nome da tabela removendo palavras comuns
		$cleaned = preg_replace('/^(create_|add_|remove_|modify_|drop_)/', '', $name);
		$cleaned = preg_replace('/_table$/', '', $cleaned);
		$cleaned = preg_replace('/^.+_to_/', '', $cleaned);

		return $cleaned ?: 'table_name';
	}
}
