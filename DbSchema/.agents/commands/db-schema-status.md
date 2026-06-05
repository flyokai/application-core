---
description: Inspect the current state of the flyokai/db-schema pipeline — discovered tables, live diff vs the configured database, last-run summary, and any pending destructive ops.
allowed-tools: Read, Glob, Grep, Bash
---

# db-schema status

You are tasked with reporting the current state of the `flyokai/db-schema` pipeline for this project. The user wants to know: what schema does the codebase declare, what does the live database actually look like, what would change if `setup:upgrade` ran right now, and what destructive operations are queued.

## What to gather

1. **Discovered tables** — every Solid DTO carrying `#[Flyokai\DbSchema\Attribute\Table]`. List the table name, source DTO class, column count, primary key, unique keys, indexes, and foreign keys.

2. **Live schema diff** — run discovery + introspection + diff against the configured database and summarize the resulting `SchemaPlan`: which tables are `create`, `alter`, or `noop`, and for `alter` tables which columns/indexes/constraints would be added, modified, renamed, or dropped.

3. **Last-run summary** — read `storage/setup/db-schema.last-run.php` if it exists. Report timestamp, applied operations, skipped destructive operations.

4. **Pending destructive operations** — read `storage/setup/db-schema.pending-drops.php` if it exists. These are drops that have been collected but require `FLYOK_DB_SCHEMA_ALLOW_DESTRUCTIVE=1` to apply.

## How to run discovery + diff

The pipeline is plain PHP and can be exercised standalone with the project's autoloader. Use this script (adjust paths if invoked from a different directory):

```bash
php -r '
chdir("/www/flyokai/flyokai");
require "vendor/composer/ClassLoader.php";
$cl = new \Composer\Autoload\ClassLoader("vendor");
foreach (require "vendor/composer/autoload_psr4.php" as $ns => $path) $cl->setPsr4($ns, $path);
if ($cm = require "vendor/composer/autoload_classmap.php") $cl->addClassMap($cm);
$cl->register();
foreach (glob("vendor/flyokai/*/bootstrap.php") as $f) require_once $f;
// Add private vendors as needed:
// foreach (glob("vendor/<your-org>/*/bootstrap.php") as $f) require_once $f;

$schema = (new \Flyokai\DbSchema\Discovery\SchemaDiscovery())->discover();
echo "Discovered " . count($schema->tables) . " tagged table(s):\n";
foreach ($schema->tables as $name => $t) {
    echo "  $name (" . count($t->columns) . " cols, "
        . ($t->primaryKey ? "PK=" . implode(",", $t->primaryKey->columns) : "no PK")
        . ", " . count($t->uniqueKeys) . " UK, "
        . count($t->indexes) . " IDX, "
        . count($t->foreignKeys) . " FK)\n";
}
'
```

For the live diff you also need a Laminas `Adapter` connected to the configured database. Either parse `storage/flyok.config.json` for the connection details or run `bin/flyok-setup upgrade` and capture the `ApplySchema` step's output (it prints discovered tables and either "live schema matches declared — nothing to do" or a list of `[applied]` / `[skipped destructive]` operations).

## Where to look for state files

- **`storage/flyok.config.json`** — the runtime config including `db.connection.default.{hostname,port,username,password,database}`
- **`storage/setup/db-schema.last-run.php`** — array with `timestamp`, `applied`, `skipped_destructive`, `tables`
- **`storage/setup/db-schema.pending-drops.php`** — array with `timestamp`, `operations`, `apply_by_setting`
- **`bin/flyok-setup`** — the generated wrapper that defines `FLYOK_CONFIG_FILE` and `FLYOK_STORAGE_DIR` so the step can find its output paths

## Output format

Produce four sections:

### 1. Declared schema
```
{N} tagged table(s):
  {table_name} ← {DTO::class}
    columns: {col1, col2, ...}
    PK: {col} (auto_increment)
    UK: {name}({cols}), ...
    FK: {name}({cols}) → {ref_table}({ref_cols}), ...
```

### 2. Live diff
```
SchemaPlan:
  {table_name}: {kind}
    + ADD COLUMN {name} {type}
    ~ MODIFY {name}: {old} → {new}
    - DROP {name}  (filtered, destructive)
```

### 3. Last run
```
Timestamp: {iso timestamp}
Applied ({n}): {first 5}
Skipped destructive ({n}): {first 5}
```
or "No prior run recorded" if the file doesn't exist.

### 4. Pending drops
```
{n} operation(s) waiting on FLYOK_DB_SCHEMA_ALLOW_DESTRUCTIVE=1:
  - ...
```
or "No pending destructive operations" if the file doesn't exist or is empty.

## Important

- **Don't apply anything**. This command is read-only. Never run `setup:install`, `setup:upgrade`, or any DDL.
- **Trust the live database, not the last-run summary**. The summary captures intent at run-time; the live `INFORMATION_SCHEMA` is the ground truth. If they disagree, mention it.
- **Look for the storage directory via `FLYOK_STORAGE_DIR`** in `storage/flyok-bootstrap.php` if the conventional `storage/setup/` path doesn't have files. The user may have a non-standard install layout.
- If the user passed a positional argument (e.g. a table name), narrow the report to that table only. Otherwise show everything.
