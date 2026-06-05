# flyokai/db-schema

> User docs → [`README.md`](README.md) · Agent quick-ref → [`CLAUDE.md`](CLAUDE.md) · Agent deep dive → [`AGENTS.md`](AGENTS.md)

Attribute-driven MySQL schema management. Solid DTOs tagged with `#[Table]` are the single source of truth for database schema; a Repeatable setup step diffs the declared model against the live DB and applies additive ALTER TABLE operations during `setup:install` / `setup:upgrade`.

See [AGENTS.md](AGENTS.md) for the pipeline architecture, attribute reference, and migration playbook.

## Quick Reference

- **Discovery**: `SchemaDiscovery` walks `Registry::modules()`, scans PSR-4 roots for `Solid` DTOs carrying `#[Table]`
- **Introspection**: `SchemaIntrospector` reads `INFORMATION_SCHEMA` (columns, indexes, FKs, EXTRA flags) into the same `TableModel`
- **Diff**: `SchemaDiffer` is a pure function — declared vs live → `SchemaPlan` of `TableDiff` ops
- **Apply**: `SchemaApplier` runs in two passes (structure → FKs); takes a Laminas `Adapter`
- **Step**: `Setup\Step\ApplySchema` (Repeatable) wires everything; runs after `db` in `install:root` and `upgrade:root`
- **Attributes** (`Flyokai\DbSchema\Attribute\*`): `Table`, `Column`, `PrimaryKey`, `UniqueKey`, `Index`, `ForeignKey`, `Enum`, `Unmanaged` — plus `Flyokai\DataMate\Attribute\Json` for JSON columns
- **Destructive policy**: drops are collected to `storage/setup/db-schema.pending-drops.php` but never applied unless `FLYOK_DB_SCHEMA_ALLOW_DESTRUCTIVE=1`
- **Run summary**: every step run writes `storage/setup/db-schema.last-run.php`
