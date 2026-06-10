# Skill: annotate-dto-schema

Tag a Solid DTO with `flyokai/db-schema` attributes so a hand-written `Setup\Install\Db\*Tables` step can be retired and the DTO becomes the source of truth for its MySQL table.

## Prerequisites

Before starting, gather from the user (or read from the existing imperative step):

- **Target Solid DTO** — class FQCN, e.g. `Flyokai\User\Dto\UserSolid`
- **Existing imperative step file** (if any) — `vendor/{pkg}/Setup/Install/Db/*Tables.php` — read its `Ddl\CreateTable` so you can match the live schema column-for-column
- **Table name** — the literal MySQL name (e.g. `flyok_user`); usually defined in a `Setup\TableName` enum
- **Column types**, lengths, nullability, defaults — straight off the imperative step
- **Primary key** — single column or composite; auto-increment?
- **Unique keys, indexes, foreign keys** — including constraint names that already exist in production so they round-trip cleanly

## Where the attributes live

- `Flyokai\DbSchema\Attribute\{Table, Column, PrimaryKey, UniqueKey, Index, ForeignKey, Enum, Unmanaged}`
- `Flyokai\DataMate\Attribute\Json` — note this lives in **data-mate**, not db-schema, because it is dual-purpose (schema + runtime hydration)

Always import what you need explicitly with `use` statements at the top of the DTO file.

## Step-by-step

### 1. Map every constructor parameter to a column decision

Walk every promoted constructor parameter on the Solid class. For each one, decide:

- **Keep as a managed column** with `#[Column(...)]` overrides matching the existing schema, OR
- **Mark as `#[Unmanaged]`** if the column has features the current model can't represent (e.g. computed columns, generated columns, or anything you don't want this tool to touch)

If a parameter does NOT have a corresponding DB column, leave it un-promoted or move it out of the constructor — discovery iterates promoted constructor parameters only.

### 2. Add the class-level `#[Table]` and key declarations

```php
use Flyokai\DbSchema\Attribute\Table;
use Flyokai\DbSchema\Attribute\UniqueKey;
use Flyokai\DbSchema\Attribute\Index;
use Flyokai\DbSchema\Attribute\ForeignKey;

#[Table(name: 'flyok_user')]
#[UniqueKey(name: 'FLYOK_USER__EMAIL', columns: ['email'])]
#[UniqueKey(name: 'FLYOK_USER__USERNAME', columns: ['username'])]
#[ForeignKey(
    name: 'FLYOK_USER_ROLE_ID__FLYOK_ACL_ROLE_ID',
    columns: ['role_id'],
    referencesDto: \Flyokai\Application\Dto\Acl\RoleSolid::class,
    referencesColumns: ['role_id'],
    onDelete: ForeignKey::RESTRICT,
    onUpdate: ForeignKey::CASCADE,
)]
class UserSolid implements ... { ... }
```

**Critical**: `referencesDto` is the *target Solid class*, not the table name. The discovery resolves it to the target's `#[Table]` name in a second pass, so the target DTO must also be tagged with `#[Table]`. If the target lives in another package, that's fine — discovery walks all registered modules.

### 3. Annotate every column parameter

Common patterns from real Flyokai DTOs:

```php
public function __construct(
    // varchar with explicit length
    #[Column(length: 32)]
    public readonly string $username,

    // unsigned int (FK columns)
    #[Column(unsigned: true)]
    public readonly int $role_id,

    // single-column auto-increment primary key
    #[PrimaryKey(autoIncrement: true)]
    public readonly int $user_id,

    // timestamp with default current time
    #[Column(type: 'timestamp', defaultExpression: 'CURRENT_TIMESTAMP')]
    public readonly string $created,

    // timestamp with default + auto-update
    #[Column(type: 'timestamp', defaultExpression: 'CURRENT_TIMESTAMP', onUpdateExpression: 'CURRENT_TIMESTAMP')]
    public readonly string $updated,

    // BackedEnum maps to varchar — set length explicitly
    #[Column(length: 32)]
    public readonly GrantType $grant_type,

    // typed JSON column (object stored as JSON)
    #[Json(target: Resources::class)]
    public readonly Resources $resources,

    // nullable string column
    #[Column(length: 32)]
    public readonly ?string $firstname = null,

    // explicit type override
    #[Column(type: 'date')]
    public readonly string $usage_date,

    #[Column(type: 'datetime')]
    public readonly string $start_date,

    #[Column(type: 'smallint', unsigned: true)]
    public readonly ?bool $valid_domain = null,

    #[Column(type: 'text')]
    public readonly ?string $domains = null,
) {}
```

PHP defaults like `?string $firstname = null` or `string $description = ''` are NOT reflected in the schema — they exist for DTO ergonomics. To declare a column default, use `#[Column(default: 'whatever')]` or `#[Column(defaultExpression: 'CURRENT_TIMESTAMP')]` explicitly.

### 4. Verify the declared model matches the imperative step

Run a one-shot discovery against the real codebase to confirm there are no errors and the produced `TableModel` looks right:

```bash
php -r '
require "vendor/composer/ClassLoader.php";
$cl = new \Composer\Autoload\ClassLoader("vendor");
foreach (require "vendor/composer/autoload_psr4.php" as $ns => $path) $cl->setPsr4($ns, $path);
if ($cm = require "vendor/composer/autoload_classmap.php") $cl->addClassMap($cm);
$cl->register();
foreach (glob("vendor/flyokai/*/bootstrap.php") as $f) require_once $f;
// Add private vendors as needed:
// foreach (glob("vendor/<your-org>/*/bootstrap.php") as $f) require_once $f;
$schema = (new \Flyokai\DbSchema\Discovery\SchemaDiscovery())->discover();
foreach ($schema->tables[$tableName = "flyok_user"]->columns ?? [] as $c) {
    echo "$c->name: $c->type" . ($c->length ? "($c->length)" : "") . " "
        . ($c->nullable ? "NULL" : "NOT NULL")
        . ($c->autoIncrement ? " AI" : "")
        . ($c->unsigned ? " UNSIGNED" : "")
        . ($c->defaultExpression ? " DEF=$c->defaultExpression" : "")
        . ($c->onUpdateExpression ? " ON UPDATE $c->onUpdateExpression" : "")
        . "\n";
}
'
```

If discovery throws a `LogicException`, fix the offending parameter:
- *"has union/intersection/no type"* → add `#[Column(type: '...')]`
- *"unsupported type"* → use `#[Json(target: ...)]` for objects, or `#[Column(type: '...')]` for everything else
- *"references unknown column"* → check class-level `#[PrimaryKey(columns: [...])]` matches a real parameter name
- *"references DTO ... which is not tagged with #[Table]"* → tag the FK target DTO first

### 5. Smoke-test against a throwaway DB

For high-stakes tables, seed a fresh local MySQL database with the imperative step's exact DDL, then run discovery → introspection → diff → apply against it. The diff should report `noop`. If it reports a `MODIFY`, the declared model doesn't match the existing schema and you need to tighten the `#[Column]` overrides.

### 6. Retire the imperative step

Once discovery + diff is clean:

1. Delete the step file: `rm vendor/{pkg}/Setup/Install/Db/{Tables}.php`
2. In the package's `config/diconfig_setup.php`:
   - Remove the `use ... as Install...` import line
   - Remove the `->addDefinition('install:db:$currentPackage:tables', object(...))` line
   - Remove the `compositionItem` entry under `"install:db:$currentPackage" => ['steps' => [...]]`
   - If the `'steps'` array becomes empty, remove the whole `"install:db:$currentPackage"` block
3. Run `bin/flyok-setup upgrade` and confirm `db-schema: live schema matches declared — nothing to do`

### 7. Re-run regression tests

If the package has tests, run them. The schema is unchanged, so existing test fixtures and inserts should keep working.

## Reference DTOs

- **Single PK + FK**: `vendor/flyokai/user/Dto/UserSolid.php` (FK to `RoleSolid`, two unique keys, two timestamp columns)
- **Three tables in one OAuth pair**: `vendor/flyokai/oauth-server/src/Dto/{ClientSolid,TokenSolid,RefreshTokenSolid}.php` (cross-table FKs, indexes, MySQL reserved word `key`)
- **Composite unique key**: any DTO declaring `#[UniqueKey(name: 'idx_x_y_z', columns: ['col_a', 'col_b', 'col_c'])]` at the class level.
- **Typed JSON column**: `vendor/flyokai/application/Dto/Acl/RoleSolid.php` (`#[Json(target: Resources::class)]`)
- **Backed enum column**: `vendor/flyokai/indexer/src/Dto/IndexerSolid.php` (`IndexerStatus` enum → `varchar(32)`)

## Common gotchas

- **`type: 'timestamp'` vs `type: 'datetime'`** — match what the imperative step used. They're not interchangeable in MySQL.
- **`onUpdateExpression`** — only `CURRENT_TIMESTAMP` is supported by MySQL itself. The attribute accepts a string for forward compatibility but anything else is silently ignored by the applier.
- **MySQL reserved words as column names** (`key`, `order`, etc.) — work fine; the applier quotes everything with backticks. No special escaping needed.
- **Identity traits** — leave `static $idKey` / `$altIdKeys` / `$type` on the shared DTO trait alone. `AbstractRepository` and Draft DTOs depend on them. Phase 2 of db-schema will unify these with the new attributes.
- **`composer dump-autoload`** — only needed if you create new files outside an already-known PSR-4 root. Adding attributes to existing DTOs needs no autoload regen.
