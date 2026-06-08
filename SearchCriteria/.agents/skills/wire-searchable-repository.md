# Skill: wire-searchable-repository

Add `getList()` and `massUpdate()` search criteria support to an existing repository by wiring the `SearchableRepository` interface and `CriteriaRepositoryTrait`.

## Prerequisites

Before starting, gather from the user:

- **Target repository** — the concrete implementation class (e.g. `Flyokai\User\User\UserRepositoryImpl`) and its interface (e.g. `Flyokai\User\User\UserRepository`)
- **Solid DTO class** — the DTO this repository manages (e.g. `Flyokai\User\Dto\UserSolid`). Must already carry `#[Table]` and `#[ForeignKey]` attributes.
- **Table alias** — confirm the DTO's `#[Table]` has an alias set, or verify the auto-derived alias is acceptable (lowercased short class name minus "Solid")
- **Package** — the package that owns the repository (e.g. `flyokai/user`)

## Architecture overview

```
Repository (existing)                      Search criteria (new)
┌─────────────────────┐                   ┌──────────────────────────┐
│ AbstractRepository   │                   │ SearchableRepository     │
│  - get()            │                   │  - getList()             │
│  - save()           │                   │  - massUpdate()          │
│  - delete()         │                   └──────────────────────────┘
│  - bulkSave()       │                              ▲
└─────────────────────┘                   ┌──────────┴──────────┐
         ▲                                │CriteriaRepositoryTrait│
         │                                │  - searchCompiler()  │◄── abstract
         │                                │  - schemaModel()     │◄── abstract
┌────────┴──────────────────┐             └─────────────────────┘
│ YourRepositoryImpl        │◄──── uses trait
│  implements YourRepository│
│  implements SearchableRepository
└───────────────────────────┘
```

The trait requires two abstract methods that the implementing class must provide: `searchCompiler()` and `schemaModel()`. Both are DI-injected singletons.

## Files to create/modify

1. **Repository interface** — add `SearchableRepository` extension
2. **Repository implementation** — add trait, constructor params, abstract method implementations
3. **DI config** — ensure `SearchCompiler` and `SchemaModel` are available as constructor args

## Step-by-step

### 1. Update the repository interface

Add `SearchableRepository` to the interface extension list:

```php
<?php

namespace Flyokai\User\User;

use Flyokai\DataMate\HasIdDto;
use Flyokai\SearchCriteria\Repository\SearchableRepository;

interface UserRepository extends SearchableRepository
{
    public function get(HasIdDto|int|string|array $entityId, mixed ...$tweaks): HasIdDto;
    public function save(HasIdDto $user, mixed ...$tweaks): static;
    public function delete(HasIdDto|int|string $user, mixed ...$tweaks): static;
    // getList() and massUpdate() are inherited from SearchableRepository
}
```

### 2. Update the repository implementation

Add `CriteriaRepositoryTrait`, inject `SearchCompiler` and `SchemaModel`, implement the abstract methods:

```php
<?php

namespace Flyokai\User\User;

use Amp\Injector\Meta\ParameterAttribute\ServiceParameter;
use Flyokai\ApplicationCore\Base\AbstractRepository;
use Flyokai\ApplicationCore\DB\ConnectionPool;
use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\SearchCriteria\Compiler\SearchCompiler;
use Flyokai\SearchCriteria\Repository\CriteriaRepositoryTrait;
use Flyokai\User\Dto\UserSolid;
use Flyokai\User\Dto\User as UserDto;

class UserRepositoryImpl extends AbstractRepository implements UserRepository
{
    use CriteriaRepositoryTrait;

    public function __construct(
        #[ServiceParameter] ConnectionPool $connectionPool,
        private readonly SearchCompiler $searchCompiler,
        private readonly SchemaModel $schemaModel,
    ) {
        parent::__construct($connectionPool);
    }

    protected function searchCompiler(): SearchCompiler
    {
        return $this->searchCompiler;
    }

    protected function schemaModel(): SchemaModel
    {
        return $this->schemaModel;
    }

    public function entityType(): string
    {
        return UserDto::entityType();
    }

    public function tableName(): string
    {
        return 'flyok_user';
    }

    public function dtoClassName(bool $solid = false): string
    {
        return $solid ? UserSolid::class : UserDto::class;
    }

    // ... existing methods (get, save, delete, etc.) remain unchanged
}
```

**Key points:**
- `SearchCompiler` and `SchemaModel` are DI-injected singletons (registered by `flyokai/search-criteria` and `flyokai/db-schema` respectively)
- The trait's `getList()` and `massUpdate()` call `$this->dtoClassName(solid: true)` to get the Solid class, and `$this->dbAdapter()` to get the Laminas adapter — both inherited from `AbstractRepository`
- No changes needed to the DI config if `SearchCompiler` and `SchemaModel` are already registered as singletons (they are by the search-criteria package's `diconfig.php`)

### 3. Verify DI config (usually no changes needed)

The `flyokai/search-criteria` package registers `SearchCompiler` as a singleton in its `config/diconfig.php`. The `SchemaModel` must also be registered. If `SchemaModel` is not yet a DI singleton, add it to the db-schema or application diconfig:

```php
// Only needed if SchemaModel is not already a DI singleton
use Flyokai\DbSchema\Discovery\SchemaDiscovery;
use Flyokai\DbSchema\Model\SchemaModel;
use function Amp\Injector\{singleton, injectableFactory};

$diConfig->addService(SchemaModel::class, singleton(
    injectableFactory(fn(SchemaDiscovery $discovery) => $discovery->discover())
));
```

The amphp-injector will automatically resolve `SearchCompiler` and `SchemaModel` as constructor arguments to your repository since they are already registered services.

### 4. Verify the DTO has proper schema attributes

Ensure the Solid DTO has:

```php
#[Table(name: 'flyok_user', alias: 'user')]
#[ForeignKey(
    name: 'FLYOK_USER_ROLE_ID__FLYOK_ACL_ROLE_ID',
    columns: ['role_id'],
    referencesDto: RoleSolid::class,
    referencesColumns: ['role_id'],
)]
class UserSolid implements HasIdDto, HasAltId, Solid {
    // ...
}
```

The `alias` parameter is optional — if omitted, it defaults to the lowercased short class name minus "Solid" (e.g. `UserSolid` → `user`).

### 5. Test the wiring

Call `getList()` with a simple criteria:

```php
// Simple: get first 10 active users
$result = $userRepository->getList(new SearchCriteria(
    filter: new AndGroup([
        new FieldPredicate('status', Operator::Eq, 'active'),
    ]),
    limit: 10,
    withTotal: true,
));

// JSON form (from an API request):
$result = $userRepository->getList([
    'AND' => [
        ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
    ],
    'limit' => 10,
    'withTotal' => true,
]);

echo count($result->items); // up to 10
echo $result->total;        // total matching rows
```

Call `massUpdate()` to bulk-update:

```php
use Flyokai\User\Dto\UserDraft;

// Deactivate all users in role 3
$affected = $userRepository->massUpdate(
    new SearchCriteria(
        filter: new FieldPredicate('role_id', Operator::Eq, 3),
    ),
    UserDraft::fromArgs(status: 'inactive'),
);
```

### 6. Test with joined criteria

```php
// Users whose role name is "editor"
$result = $userRepository->getList([
    'AND' => [
        ['field' => 'role.name', 'op' => 'eq', 'value' => 'editor'],
    ],
]);
```

This auto-discovers the join from `UserSolid`'s `#[ForeignKey(referencesDto: RoleSolid::class)]`.

## Conventions & gotchas

- **`SearchableRepository` is opt-in** — only repositories that explicitly implement the interface and use the trait gain `getList()`/`massUpdate()`. `AbstractRepository` is untouched.
- **`getList()` accepts both `SearchCriteria` objects and raw arrays** — use objects for server-side programmatic calls, arrays for API-received JSON.
- **`massUpdate()` requires a non-null filter** — passing `null` or empty criteria throws `EmptyCriteriaException` as a guard against accidental full-table writes.
- **`massUpdate()` uses Draft DTOs** — the partial DTO should be a Draft with `isUndefined()` support so only explicitly set fields are included in the SET clause. Using a Solid DTO will attempt to SET every column to its default value.
- **Joined tables are for filtering only** — `getList()` selects `base.*` and `columns([])` on joined tables. Hydration always produces the base Solid DTO only.
- **`withTotal` defaults to `false`** — set it explicitly to `true` when you need pagination totals. The extra COUNT query roughly doubles the cost.
- **Sort fields** can reference joined columns (e.g. `role.name`) just like filter fields.
- **MySQL multi-table UPDATE** does not support LIMIT or ORDER BY. `massUpdate()` throws if you pass `limit` or `sort` with criteria that require joins.

## Reference examples

- **Repository pattern**: `vendor/flyokai/application/Acl/RoleRepositoryImpl.php`
- **Solid DTO with schema**: `vendor/flyokai/user/Dto/UserSolid.php`
- **Foreign keys**: `vendor/flyokai/user/Dto/UserSolid.php` → `RoleSolid`
- **CriteriaRepositoryTrait**: `vendor/flyokai/search-criteria/src/Repository/CriteriaRepositoryTrait.php`
- **SearchCriteria object**: `vendor/flyokai/search-criteria/src/Criteria/SearchCriteria.php`
