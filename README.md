# AvelPress CLI

Command Line Interface for projects based on the AvelPress framework.

## Installation

### Global Installation

You can install avelpress-cli globally using Composer:

```bash
composer global require avelpress/avelpress-cli
```

After that, the `avel` command will be available globally (make sure your Composer global bin directory is in your PATH).

### Local (per-project) Installation

Add to your project:

```bash
composer require avelpress/avelpress-cli --dev
```

And run via vendor/bin:

```bash
php vendor/bin/avel <command>
```

Or, if using the project binary:

```bash
php bin/avel <command>
```

## Available Commands

- `build` — Builds a distribution package for your AvelPress project.
  - `--ignore-platform-reqs` — Ignore platform requirements when running composer install during build.
- `make:controller` — Create a new controller.
- `make:model` — Create a new model.
- `make:migration` — Create a new migration.
- `migrate` — Run pending migrations.
- `new` — Create a new AvelPress project.

See all options with:

```bash
php bin/avel list
```

## Example: Build Usage

```bash
php bin/avel build --ignore-platform-reqs
```

## Requirements

- PHP 7.4+
- Composer
