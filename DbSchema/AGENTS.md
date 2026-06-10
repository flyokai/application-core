# flyokai/db-schema — agent knowledge

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

Declarative MySQL schema management for Flyokai. Solid DTOs tagged with `#[Flyokai\DbSchema\Attribute\Table]` define the schema; a Repeatable setup step reconciles the live database with the declared model on every `setup:install` and `setup:upgrade`.

This file is the deep reference for agents working on or with this package. For a one-screen overview see [CLAUDE.md](CLAUDE.md).

## Pipeline

```
Registry::modules()
   │
   ▼
SchemaDiscovery ──► SchemaModel(declared)
                      │
INFORMATION_SCHEMA ──► SchemaIntrospector ──► SchemaModel(existing)
                      │                      │
                      ▼                      ▼
                          SchemaDiffer
                              │
                              ▼
                          SchemaPlan (TableDiff[])
                              │
                              ▼
                          SchemaApplier ──► Laminas Ddl ──► Adapter::query
```

All four stages share the same `Flyokai\DbSchema\Model\TableModel` value object so the diff is a pure structural comparison. `MysqlTypeNormalizer` canonicalizes both sides (drops integer display widths, normalizes `CURRENT_TIMESTAMP()` vs `CURRENT_TIMESTAMP`, treats nullable + null default as "no default", etc.) so equivalent descriptions don't drift across runs.

## Attribute reference

All attributes live under `Flyokai\DbSchema\Attribute\*` except `#[Json]` which lives in `Flyokai\DataMate\Attribute\Json` because it serves both schema discovery and runtime hydration.

### Class-level

| Attribute | Purpose |
|-----------|---------|
| `#[Table(name, engine='InnoDB', charset='utf8mb4', collation='utf8mb4_unicode_ci', options=[], comment=null)]` | Marks a Solid DTO as a schema source. Absence = ignored. |
| `#[PrimaryKey(columns, autoIncrement=false)]` | Composite primary key. Single-column AI PKs prefer the parameter form. |
| `#[UniqueKey(name, columns)]` | Repeatable. |
| `#[Index(name, columns, type='BTREE')]` | Repeatable. `type` is `BTREE` or `FULLTEXT`. |
| `#[ForeignKey(name, columns, referencesDto, referencesColumns, onDelete='RESTRICT', onUpdate='RESTRICT')]` | `referencesDto` is the *target* Solid class (a class-string). The discovery resolves it to the target's `#[Table]` name in a second pass, so cross-package FKs work even if the target is registered in a different module. |

### Parameter-level

| Attribute | Purpose |
|-----------|---------|
| `#[Column(name?, type?, length?, precision?, scale?, unsigned?, nullable?, default?, defaultExpression?, onUpdateExpression?, comment?, collation?, renamedFrom?)]` | Override any subset of the inferred definition. `type` is the canonical token (`int`, `varchar`, `text`, `json`, `datetime`, `timestamp`, `decimal`, etc.). |
| `#[PrimaryKey(autoIncrement=false)]` | Shortcut for single-column PK on this property. |
| `#[Json(target?)]` | Forces JSON storage. With `target`, the runtime helper hydrates the JSON value into the target class via Valinor; without `target` Valinor uses the declared parameter type. |
| `#[Enum(values?)]` | Forces MySQL ENUM storage on a `BackedEnum`-typed parameter. Without this attribute, BackedEnum maps to `VARCHAR(64)` (string-backed) or `SMALLINT` (int-backed). MySQL ENUM is destructive on reorder — prefer the default. |
| `#[Unmanaged]` | The parameter exists in the DTO but the schema manager neither declares nor drops the corresponding column. Use for columns whose features can't be represented yet, or for columns managed by a legacy step. |

### PHP type → MySQL inference (no `#[Column]`)

| PHP type | MySQL | Notes |
|---|---|---|
| `int` | `INT` | `unsigned` only via `#[Column]`. Convention: a single int column named `id` or `*_id` becomes the PK. |
| `float` | `DECIMAL(12,4)` | Decimal by default to avoid silent money rounding. |
| `bool` | `TINYINT(1)` | Emitted as `BOOLEAN` by Laminas. |
| `string` | `VARCHAR(255)` | Length via `#[Column(length: …)]`. |
| `array` | `JSON` | |
| `BackedEnum` (string) | `VARCHAR(64)` | Or `ENUM(…)` with `#[Enum]`. |
| `BackedEnum` (int) | `SMALLINT` | |
| `DateTimeInterface` | `DATETIME` | UTC; conversion is the DTO's responsibility. |
| Class type | **error** | Must carry `#[Json(target: …)]`. |
| `mixed`/union/no type | **error** | Use explicit `#[Column(type: …)]`. |

### Defaults

PHP parameter defaults are **not** reflected in the schema — they exist for DTO construction ergonomics. To declare a column default, use `#[Column(default: …)]` for a literal or `#[Column(defaultExpression: 'CURRENT_TIMESTAMP')]` for raw SQL.

## Discovery internals

`SchemaDiscovery::collectTaggedDtoClasses()`:

1. Iterates `Flyokai\ApplicationCore\Bootstrap\Registry::modules()`.
2. For each registered `ModuleBootstrap`, walks ancestor directories until it finds a `composer.json` (`PackagePathResolver::resolvePackageRoot`).
3. Reads `autoload.psr-4` from that composer.json and walks each PSR-4 root.
4. For every `.php` file: cheap `str_contains` pre-filter for `Table` token → `class_exists()` to autoload → `ReflectionClass` → must implement `Flyokai\DataMate\Solid` AND carry `#[Table]`.
5. Builds `TableModel` in two passes (so cross-DTO FK references resolve regardless of registration order).

The pre-filter and the Solid+Table requirement keep the scan cheap even on a large vendor tree. Discovery is intentionally NOT cached at this layer — opcache makes class-loading near-zero on warm runs.

## Introspection internals

`SchemaIntrospector` queries `INFORMATION_SCHEMA.{COLUMNS,STATISTICS,KEY_COLUMN_USAGE,REFERENTIAL_CONSTRAINTS,TABLES}` directly via the Laminas `Adapter`. It does **not** use `Laminas\Db\Metadata\Source\MysqlMetadata` because that class doesn't surface `EXTRA` (auto-increment, default-generated, on-update) or column comments.

Important behaviors:
- `EXTRA` containing `auto_increment` → `ColumnModel::$autoIncrement = true`
- `EXTRA` containing `DEFAULT_GENERATED` → the value moves from `default` to `defaultExpression`
- `EXTRA` containing `on update CURRENT_TIMESTAMP` → `onUpdateExpression = 'CURRENT_TIMESTAMP'`
- MySQL auto-creates a secondary index on each FK column with the same name as the FK constraint. The introspector strips those from the index set so the differ doesn't propose to drop them.
- Nullable columns reported as `COLUMN_DEFAULT = NULL` are treated as "no default" — equivalent to `DEFAULT NULL` semantically.

## Diff & apply

`SchemaDiffer::diff(SchemaModel $declared, SchemaModel $existing): SchemaPlan` is a pure function. Per table:

- New table → `TableDiff(KIND_CREATE)` with the full declared model.
- Existing table → `TableDiff(KIND_ALTER)` with typed change sets:
  `columnsToAdd`, `columnsToChange`, `columnsRenamed`, `columnsToDrop`,
  `primaryKeyToAdd/Drop`, `uniqueKeysToAdd/Drop`, `indexesToAdd/Drop`, `foreignKeysToAdd/Drop`.
- Identical → `TableDiff(KIND_NOOP)`.

`SchemaApplier::apply(SchemaPlan $plan, bool $allowDestructive)` runs **two passes**:

1. Structure: `CREATE TABLE` (no FKs) and `ALTER TABLE` (add/modify columns, indexes, unique keys, primary key changes).
2. Foreign keys: `ALTER TABLE ... ADD CONSTRAINT` for FKs to add or `DROP FOREIGN KEY` for FKs to drop.

Two passes avoid ordering hell when tables form FK cycles. The applier emits Laminas `Ddl\CreateTable` / `Ddl\AlterTable` and runs them through `Sql\Sql::buildSqlString()` so the MySQL platform decorators add `UNSIGNED`, `AUTO_INCREMENT`, and timestamp `ON UPDATE` correctly.

### Destructive policy

Drops are **never executed by default**. Set the env var `FLYOK_DB_SCHEMA_ALLOW_DESTRUCTIVE=1` (any non-empty, non-`0` value) to opt in. Skipped operations are written to `storage/setup/db-schema.pending-drops.php` so you can audit before flipping the flag. The file is automatically deleted when a clean run reports no destructive ops.

Column **renames** are indistinguishable from drop+add via reflection alone. Use `#[Column(name: 'new_name', renamedFrom: 'old_name')]` so the differ emits `CHANGE COLUMN` instead of refusing the destructive drop.

## Setup step

`Flyokai\DbSchema\Setup\Step\ApplySchema implements AbstractStep, Repeatable`:

1. `$context->getDbHelper()->getAdapter()` — borrows the adapter from the existing `DbStepHelper` (no new DI wiring).
2. Discover → introspect (only the tables the discovery knows about) → diff → apply.
3. Always writes `storage/setup/db-schema.last-run.php` and updates/clears `db-schema.pending-drops.php`, even on no-op runs.

DI registration is in `vendor/flyokai/db-schema/config/diconfig_setup.php`. The step is composed into both `install:root` and `upgrade:root` with `after: ['db'], before: ['finalize']`, so it runs once any per-package legacy `install:db:*` steps have finished.

## Migration playbook

When retiring an imperative `Setup\Install\Db\*Tables` step in favour of attributes:

1. **Tag the corresponding Solid DTO(s)** with `#[Table]`, `#[Column]` overrides matching the existing column lengths/types/nullability, `#[PrimaryKey]`, `#[UniqueKey]`, `#[Index]`, `#[ForeignKey]`. Use `#[Unmanaged]` only for columns the model can't represent yet.
2. **Run discovery** standalone (`php -r` against the autoloader) or via the `flyokai-annotate-dto-schema` skill to verify the produced `TableModel` matches the imperative DDL.
3. **Run `setup:install` against a throwaway database** to confirm the declared schema produces the same `SHOW CREATE TABLE` output as the imperative step.
4. **Delete the step file** (`vendor/{pkg}/Setup/Install/Db/{Tables}.php`) and its registration in the package's `diconfig_setup.php` (`install:db:$currentPackage` `steps` entry plus the `addDefinition` line).
5. **Run `setup:upgrade`** — `ApplySchema` should report `live schema matches declared — nothing to do`.

## Files to know

- `src/Discovery/SchemaDiscovery.php` — main entry; tagging rules, type inference, FK resolution
- `src/Discovery/PackagePathResolver.php` — composer.json autoload-psr4 reader
- `src/Introspection/SchemaIntrospector.php` — INFORMATION_SCHEMA queries
- `src/Introspection/MysqlTypeNormalizer.php` — canonicalization rules
- `src/Diff/SchemaDiffer.php` — pure diff function
- `src/Diff/{TableDiff,SchemaPlan}.php` — diff result types
- `src/Apply/SchemaApplier.php` — two-pass DDL execution
- `src/Apply/ColumnFactory.php` — `ColumnModel` → Laminas `Ddl\Column` mapping
- `src/Setup/Step/ApplySchema.php` — the Repeatable step
- `config/diconfig_setup.php` — DI wiring + composition into `install:root`/`upgrade:root`

## Known limitations

- **No `MEDIUMINT` / `TINYINT` builders**: tinyint maps to `Boolean` (`TINYINT(1)`); other widths throw. Add a custom Laminas Column class if needed.
- **No native ENUM emission**: `#[Enum]` throws at apply time. Use string-backed enums with the default `VARCHAR` mapping.
- **Drop-table is not yet supported by `ApplySchema`**: removing a `#[Table]` from a DTO does NOT drop the live table (the differ only inspects tables the discovery still knows about). Drop the table by hand if you really mean it.
- **Identity metadata duplication**: each DTO trait still declares `static $idKey` / `$altIdKeys` / `$type` for `AbstractRepository`. A future revision will unify these with the schema attributes via a `SOLID_CLASS` constant on the shared trait.
