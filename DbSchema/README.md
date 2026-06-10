# flyokai/db-schema

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

> Attribute-driven MySQL schema for Flyokai. Tag your Solid DTOs and the framework keeps your database in sync.

`db-schema` makes your Solid DTOs the single source of truth for the database. Tag a DTO with `#[Table]` and matching column attributes; on every `setup:install` and `setup:upgrade` a Repeatable step diffs the declared model against `INFORMATION_SCHEMA` and applies additive `ALTER TABLE` operations. Drops are opt-in.

## Features

- **Declarative schemas** — write your DTO once, get the table for free
- **Pure-function diff** — declared vs. live → typed `SchemaPlan` of `TableDiff` ops
- **Two-pass apply** — structure first, then foreign keys (avoids FK ordering hell on cycles)
- **Cross-package FKs** — `referencesDto` is a class-string, resolved during a second discovery pass
- **Repeatable setup step** — drops into both `install:root` and `upgrade:root`
- **Audit trail** — every run writes `storage/setup/db-schema.last-run.php`
- **Destructive policy** — drops are collected and *not* applied unless `FLYOK_DB_SCHEMA_ALLOW_DESTRUCTIVE=1`

## Installation

```bash
composer require flyokai/db-schema
```

The package boots itself via Composer autoload (`bootstrap.php`).

## Quick start

Tag a `Solid` DTO and your table is created on the next setup run:

```php
use Flyokai\DataMate\Dto;
use Flyokai\DataMate\DtoTrait;
use Flyokai\DataMate\Solid;
use Flyokai\DbSchema\Attribute\{Table, Column, PrimaryKey, UniqueKey, Index, ForeignKey};

#[Table(name: 'flyok_user', alias: 'user')]
#[UniqueKey('idx_user_username', ['username'])]
#[Index('idx_user_role', ['role_id'])]
#[ForeignKey(
    name: 'fk_user_role',
    columns: ['role_id'],
    referencesDto: RoleSolid::class,
    referencesColumns: ['role_id'],
    onDelete: 'RESTRICT',
)]
final class UserSolid implements Dto, Solid
{
    use DtoTrait;

    public function __construct(
        #[PrimaryKey(autoIncrement: true)]
        public readonly int $userId,

        #[Column(length: 64)]
        public readonly string $username,

        #[Column(length: 255)]
        public readonly string $email,

        public readonly int $roleId,

        #[Column(defaultExpression: 'CURRENT_TIMESTAMP')]
        public readonly \DateTimeImmutable $created,
    ) {}
}
```

Run `bin/flyok-setup install` (or `upgrade`) and the table appears.

## How it works

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

Both sides flow through `MysqlTypeNormalizer` (drops display widths, normalises `CURRENT_TIMESTAMP()` vs `CURRENT_TIMESTAMP`, treats `nullable + null default` as "no default", etc.) so equivalent definitions don't endlessly drift.

## Attribute reference

### Class-level

| Attribute | Purpose |
|-----------|---------|
| `#[Table(name, engine='InnoDB', charset='utf8mb4', collation='utf8mb4_unicode_ci', options=[], comment=null)]` | Marks a Solid DTO as a schema source. Without it the DTO is invisible to discovery. |
| `#[PrimaryKey(columns, autoIncrement=false)]` | Composite PKs |
| `#[UniqueKey(name, columns)]` | Repeatable |
| `#[Index(name, columns, type='BTREE')]` | Repeatable. `type` ∈ {`BTREE`, `FULLTEXT`} |
| `#[ForeignKey(name, columns, referencesDto, referencesColumns, onDelete='RESTRICT', onUpdate='RESTRICT')]` | `referencesDto` is the *target Solid class* — resolved to a table name in a second pass |

### Parameter-level

| Attribute | Purpose |
|-----------|---------|
| `#[Column(name?, type?, length?, precision?, scale?, unsigned?, nullable?, default?, defaultExpression?, onUpdateExpression?, comment?, collation?, renamedFrom?)]` | Override any subset of the inferred definition |
| `#[PrimaryKey(autoIncrement=false)]` | Single-column PK shortcut |
| `#[Json(target?)]` | Force JSON storage; with `target`, runtime hydrates JSON via Valinor into that class |
| `#[Enum(values?)]` | Force MySQL `ENUM` on a `BackedEnum` parameter (otherwise → `VARCHAR(64)` or `SMALLINT`) |
| `#[Unmanaged]` | Tells the schema manager to neither declare nor drop the column |

### PHP type → MySQL inference (no `#[Column]`)

| PHP type | MySQL | Notes |
|---|---|---|
| `int` | `INT` | A single int column named `id` or `*_id` becomes the PK |
| `float` | `DECIMAL(12,4)` | Decimal by default — no silent money rounding |
| `bool` | `TINYINT(1)` | Emitted as `BOOLEAN` |
| `string` | `VARCHAR(255)` | Length via `#[Column(length: …)]` |
| `array` | `JSON` |  |
| `BackedEnum` (string) | `VARCHAR(64)` | Or `ENUM(…)` with `#[Enum]` |
| `BackedEnum` (int) | `SMALLINT` |  |
| `DateTimeInterface` | `DATETIME` | UTC |
| Class type | **error** | Must carry `#[Json(target: …)]` |
| `mixed`/union/no type | **error** | Use explicit `#[Column(type: …)]` |

PHP defaults are **not** reflected in the schema — they are for DTO ergonomics. Use `#[Column(default: …)]` for a literal or `#[Column(defaultExpression: 'CURRENT_TIMESTAMP')]` for raw SQL.

## Renaming a column

Rename via reflection alone is indistinguishable from drop+add. Tag the rename explicitly:

```php
public function __construct(
    #[Column(name: 'email_address', renamedFrom: 'email')]
    public readonly string $emailAddress,
) {}
```

The differ emits `CHANGE COLUMN email email_address …` instead of refusing the destructive drop.

## Destructive policy

Drops are **never executed by default**. Set the env var:

```bash
FLYOK_DB_SCHEMA_ALLOW_DESTRUCTIVE=1 bin/flyok-setup upgrade
```

Skipped operations are written to `storage/setup/db-schema.pending-drops.php` so you can audit before flipping the flag. The file is automatically deleted when a clean run reports no destructive ops.

## Setup integration

`Flyokai\DbSchema\Setup\Step\ApplySchema` is `Repeatable` and is composed into both `install:root` and `upgrade:root` with `after: ['db'], before: ['finalize']`, so it runs after any per-package legacy `install:db:*` steps.

Every run writes:

- `storage/setup/db-schema.last-run.php` — execution summary
- `storage/setup/db-schema.pending-drops.php` — pending destructive ops (deleted when none)

## Migration playbook

Retiring an imperative `Setup\Install\Db\*Tables` step in favour of attributes:

1. Tag the corresponding Solid DTO(s) with `#[Table]`, matching the existing column types/lengths/nullability.
2. Run discovery standalone (or via the `flyokai-annotate-dto-schema` skill) to verify the produced `TableModel` matches the imperative DDL.
3. Run `setup:install` against a throwaway database; compare `SHOW CREATE TABLE` output with the imperative step.
4. Delete the legacy step file and its `diconfig_setup.php` registration.
5. Run `setup:upgrade` — `ApplySchema` should report *live schema matches declared — nothing to do*.

## Files

- `src/Discovery/SchemaDiscovery.php` — main entry; tagging rules, type inference, FK resolution
- `src/Discovery/PackagePathResolver.php` — composer.json `autoload-psr4` reader
- `src/Introspection/SchemaIntrospector.php` — `INFORMATION_SCHEMA` queries
- `src/Introspection/MysqlTypeNormalizer.php` — canonicalisation
- `src/Diff/SchemaDiffer.php` — pure diff function
- `src/Diff/{TableDiff,SchemaPlan}.php` — diff result types
- `src/Apply/SchemaApplier.php` — two-pass DDL execution
- `src/Setup/Step/ApplySchema.php` — the Repeatable step

## Known limitations

- No `MEDIUMINT` / non-1 `TINYINT` builders. Add a custom Laminas `Column` class if you need them.
- No native `ENUM` emission yet — use string-backed enums and the default `VARCHAR` mapping.
- `ApplySchema` does not drop tables. Removing a `#[Table]` from a DTO leaves the live table intact.
- Identity metadata (`$idKey`, `$altIdKeys`, `$type`) is still declared on each DTO trait. A future revision will unify these with `#[Table]`.

## See also

- [`flyokai/data-mate`](../data-mate/README.md) — Solid DTOs are the input to discovery
- [`flyokai/search-criteria`](../search-criteria/README.md) — uses `#[Table(alias)]` and `#[ForeignKey]` for join auto-discovery
- [`flyokai/application`](../application/README.md) — registers the setup step

## License

MIT
