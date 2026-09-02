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

## Releases

`avel release` publishes a new version and repoints every store download at it:
it builds the package, uploads it to the WordPress media library and rewrites
the download URL of every product, variable product and variation that serves
this plugin — in every language — while keeping each download id untouched, so
purchases already made keep working.

```bash
php bin/avel release --dry-run          # show what would change
php bin/avel release --bump=patch       # bump, build, publish and sync
php bin/avel release:doctor             # audit only, writes nothing
php bin/avel release:doctor --site-wide # audit every plugin on the site
php bin/avel release:restore dist/release-backup-1.2.0-20260902-001651.json
```

Options: `--dry-run`, `--bump=major|minor|patch`, `--set-version=x.y.z`,
`--skip-build`, `--prune`, `--force`, `--site`.

### Credentials

Copy `.env.example` to `.env` in the project, or to `~/.avelpress/.env` to share
one set of credentials across every plugin. Real environment variables take
precedence over the project file, which takes precedence over the global one.
`AVELPRESS_WP_USER` plus `AVELPRESS_WP_APP_PASSWORD` (a WordPress application
password) is enough; a WooCommerce key can be used for the store routes instead.

### The update manifest

When `AVELPRESS_RELEASE_WEBHOOK` and `AVELPRESS_RELEASE_TOKEN` are set, the release
also posts a manifest (version, package URL, requirements and the changelog entry
of the version) to that endpoint, so plugins that update themselves and the store
can never disagree. Without them the release only updates the store.

The package is then published twice, because the two audiences need opposite
things. The store copy goes to WooCommerce's protected folder, where a direct
request answers 403 and the file is only served through a purchase. The manifest
instead points at a public copy, since the customer's WordPress fetches that URL
with no credentials at all when it installs an update.

### Project configuration

Everything is optional — a plugin with no `release` block uses its `plugin_id`
as the file name it owns and the defaults below.

```php
'release' => [
    'store' => [
        // File names this plugin owns in the store, when they differ from the
        // plugin id or the plugin was once published under another name.
        'match'   => [ 'infixs-checkout-fields', 'br-checkout-fields-for-woocommerce' ],
        // Sibling plugins whose file names start the same way and must not be
        // claimed, e.g. "-pro" and "-dokan" next to the free plugin.
        'exclude' => [ 'infixs-correios-automatico-pro' ],
    ],
    'version_files' => [ 'infixs-checkout-fields.php', 'readme.txt' ],
    'retention'     => [ 'keep' => 3 ],
    'git'           => [ 'require_clean' => true, 'tag' => true ],
],
```

### Before the first release

WooCommerce only accepts download files stored in an approved directory. Add the
uploads folder root once, in WooCommerce > Settings > Products > Downloadable
products > Approved directories:

```
https://your-site.com/wp-content/uploads/
```

Using the root covers every future month; approving one folder per month means
the first release of each month fails.

### What the release refuses to do

- Release from a dirty working tree (`release.git.require_clean`).
- Release a version the store already serves (use `--force` to override).
- Release when `readme.txt` has no `= x.y.z =` changelog entry for the version.
- Publish a package whose internal plugin header disagrees with the version.
- Delete a package still referenced by any product, even when pruning.
