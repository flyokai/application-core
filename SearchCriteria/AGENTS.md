# flyokai/search-criteria — agent knowledge

Declarative search criteria with auto-join discovery for Flyokai repositories. Accepts nested JSON filter trees, validates fields against the schema, auto-discovers joins from `#[ForeignKey]` declarations, and emits parameterised Laminas SQL.

This file is the deep reference for agents working on or with this package. For a one-screen overview see [CLAUDE.md](CLAUDE.md).

## Pipeline

```
raw JSON array
   │
   ▼
CriteriaJsonNormalizer ──► canonical array (type-tagged nodes)
   │
   ▼
CriteriaMapper ──► SearchCriteria (typed object tree)
   │
   ▼
FieldCollector ──► { base: string[], prefixed: {alias: string[]} }
   │
   ▼
BaseColumnValidator ──► validates base-table columns exist
   │
   ▼                     SchemaModel
SearchCompiler ──────────► validateJsonColumnUsage (isNull/isNotNull only for JSON cols)
   │
   ▼
JoinResolver ──► JoinPlan { alias → JoinEntry(table, foreignKey) }
   │
   ▼
SelectCompiler ──► CompiledQuery { Select, JoinPlan, baseAlias, baseTable }
```

All six stages are DI-registered singletons. `SearchCompiler` orchestrates them in sequence. Entry points:

- `SearchCompiler::compile(string $dtoClass, array $json)` — from raw JSON
- `SearchCompiler::compileFromCriteria(string $dtoClass, SearchCriteria $criteria)` — from typed objects

## Criteria JSON format

External (user-facing):
```json
{
  "AND": [
    {"field": "status", "op": "eq", "value": "active"},
    {"OR": [
      {"field": "role.name", "op": "eq", "value": "admin"},
      {"field": "role.name", "op": "eq", "value": "editor"}
    ]}
  ],
  "sort": [{"field": "created", "direction": "desc"}],
  "limit": 20,
  "offset": 0,
  "withTotal": true
}
```

The normalizer also accepts shorthand sort syntax: `["+field", "-field", "field"]` where `+` = asc (default), `-` = desc.

A bare filter without the `filter` wrapper is supported — if the top-level keys are `AND`/`OR`/`NOT`/`field`, the whole object is treated as the filter.

## Criteria object tree

```
SearchCriteria
├── filter: ?CriteriaNode
├── sort:   SortSpec[]
├── limit:  ?int
├── offset: ?int
└── withTotal: bool

CriteriaNode (interface)
├── AndGroup   { children: CriteriaNode[] }
├── OrGroup    { children: CriteriaNode[] }
├── NotGroup   { child: CriteriaNode }
└── FieldPredicate { field: string, op: Operator, value: mixed }

Operator (enum): eq, neq, gt, gte, lt, lte, in, notIn, like, notLike, isNull, isNotNull, between
SortDirection (enum): asc, desc
```

`CriteriaMapper` is a manual recursive mapper (not Valinor) because `AndGroup` and `OrGroup` have identical constructor shapes, making Valinor discriminated-union mapping impossible.

## Field resolution rules

| Pattern | Resolved to | Example |
|---------|-------------|---------|
| `"field"` | `baseAlias.field` | `"status"` → `user.status` |
| `"alias.field"` where alias = base alias | `baseAlias.field` | `"user.status"` → `user.status` |
| `"alias.field"` where alias != base alias | joined table | `"role.name"` → auto-discovered join |

Dotted paths deeper than `alias.col` are rejected in v1.

## Table aliases

Declared via `#[Table(name: 'flyok_user', alias: 'user')]` in `flyokai/db-schema`. If `alias` is omitted, defaults to the lowercased short class name minus "Solid" suffix, with `CamelCase` → `snake_case` conversion:

- `UserSolid` → `user`
- `RoleSolid` → `role`
- `OAuthClientSolid` → `o_auth_client`

Aliases must be unique across all discovered DTOs. `SchemaModel` throws `LogicException` on collision.

## Join auto-discovery

`JoinResolver` finds joins by matching the referenced alias to a `TableModel` via `SchemaModel::$tablesByAlias`, then finding exactly one `#[ForeignKey]` on the base table whose `$referencedTable` matches the target.

**Single FK rule**: if zero FKs match → `InvalidFieldException`. If multiple FKs match (e.g. `created_by_user_id` and `updated_by_user_id` both → `flyok_user`) → `AmbiguousForeignKeyException`. Disambiguation via a `#[Relation]` attribute is planned for v2.

Joins are emitted as `INNER JOIN` (not LEFT) because they're driven by filter predicates. All joins are sorted by alias for deterministic SQL output.

## Operator → Laminas predicate mapping

| Operator | Laminas class | Notes |
|----------|--------------|-------|
| `eq`/`neq`/`gt`/`gte`/`lt`/`lte` | `Operator` | Standard comparison |
| `in`/`notIn` | `In`/`NotIn` | Empty array → `1=0`/`1=1` |
| `like`/`notLike` | `Like`/`NotLike` | |
| `isNull`/`isNotNull` | `IsNull`/`IsNotNull` | Only operators allowed on JSON columns |
| `between` | `Between` | Value must be `[min, max]` array |
| AND group | `PredicateSet(OP_AND)` | |
| OR group | `PredicateSet(OP_OR)` | |
| NOT group | `NotPredicate` (custom) | Wraps inner predicate with `NOT (...)` |

Custom operators can be registered via `OperatorRegistry::register(string $opValue, PredicateFactory $factory)`. The registry is checked before the built-in operator switch.

## Repository integration

`SearchableRepository` interface + `CriteriaRepositoryTrait` — opt-in per repository.

```php
interface SearchableRepository {
    public function getList(SearchCriteria|array $criteria): ListResult;
    public function massUpdate(SearchCriteria|array $criteria, Dto $partial): int;
}
```

`ListResult` holds `items: Solid[]` and `total: ?int` (null when `withTotal=false`).

The trait requires two abstract methods from the host class:
- `searchCompiler(): SearchCompiler`
- `schemaModel(): SchemaModel`

Both are typically DI-injected constructor parameters.

### getList() flow

1. Compile criteria → `CompiledQuery`
2. Execute Select via `Sql::prepareStatementForSqlObject()`
3. Map rows → Solid DTOs via `tuneDbRow()` + `fromArray()`
4. If `withTotal`: clone Select, strip limit/offset/order, wrap as `COUNT(*)`, execute

Only base-table columns are selected (`columns(['*'])` on base, `columns([])` on joins).

### massUpdate() flow

1. Compile criteria → `CompiledQuery`
2. Guard: null filter → `EmptyCriteriaException`
3. Guard: joins + limit/sort → `CriteriaException` (MySQL multi-table UPDATE constraint)
4. Build SET from `$partial->toDbRow()`, filter out undefined (Draft), strip PK columns
5. If joins: `CriteriaMultiTableUpdate::execute()` (custom multi-table UPDATE builder)
6. If no joins: standard Laminas `Update`
7. Return affected row count

## Guardrails

| Guard | Where | Behavior |
|-------|-------|----------|
| Depth limit (16) | `CriteriaJsonNormalizer` | `CriteriaDepthException` |
| Node limit (512) | `CriteriaJsonNormalizer` | `CriteriaException` |
| Unknown field | `BaseColumnValidator` | `InvalidFieldException` |
| Unknown alias | `JoinResolver` | `InvalidFieldException` |
| Ambiguous FK | `JoinResolver` | `AmbiguousForeignKeyException` |
| JSON column + non-null op | `SearchCompiler` | `InvalidFieldException` |
| Empty criteria on massUpdate | `CriteriaRepositoryTrait` | `EmptyCriteriaException` |
| Multi-table UPDATE + LIMIT/ORDER | `CriteriaRepositoryTrait` | `CriteriaException` |
| Empty IN array | `SelectCompiler` | Silently rewritten to `1=0` |
| Empty NOT IN array | `SelectCompiler` | Silently rewritten to `1=1` |

## DI wiring

All services registered in `config/diconfig.php` as singletons:

- `CriteriaJsonNormalizer`
- `CriteriaMapper`
- `FieldCollector`
- `BaseColumnValidator`
- `JoinResolver` (depends on `SchemaModel`)
- `SelectCompiler` (depends on `OperatorRegistry`)
- `OperatorRegistry`
- `SearchCompiler` (composes all above + `SchemaModel`)

`SchemaModel` is provided by `flyokai/db-schema`. If it's not already a DI singleton, it must be registered (e.g. via `injectableFactory` wrapping `SchemaDiscovery::discover()`).

## Files to know

- `src/Compiler/SearchCompiler.php` — pipeline orchestrator
- `src/Compiler/Stage/*.php` — the six stages
- `src/Compiler/CompiledQuery.php` — pipeline output
- `src/Compiler/JoinPlan.php` + `JoinEntry.php` — join resolution output
- `src/Criteria/*.php` — the typed criteria object tree
- `src/Operator/OperatorRegistry.php` — custom operator extension point
- `src/Repository/CriteriaRepositoryTrait.php` — getList/massUpdate implementation
- `src/Repository/SearchableRepository.php` — the opt-in interface
- `src/Sql/NotPredicate.php` — NOT wrapper for Laminas predicates
- `src/Sql/CriteriaMultiTableUpdate.php` — MySQL multi-table UPDATE builder
- `config/diconfig.php` — DI service registration

## Development skills

| Skill | Purpose |
|-------|---------|
| [wire-searchable-repository](.agents/skills/wire-searchable-repository.md) | Add search criteria support to an existing repository |

## Known limitations (v1)

- **No transitive joins**: `a.b.c` (alias → alias → column) is rejected. Only single-hop `alias.column` supported.
- **No `#[Relation]` disambiguation**: when multiple FKs reference the same target table, the compiler throws `AmbiguousForeignKeyException`. Must be disambiguated via schema design (separate DTOs per role) or wait for v2's `#[Relation]` attribute.
- **No LEFT JOIN support**: all joins are INNER. LEFT JOIN would require explicit opt-in via a future `#[Relation(type: JoinType::LEFT)]` attribute.
- **No JSON subpath queries**: filters on `#[Json]` columns are limited to `isNull`/`isNotNull`. A `$`-prefixed syntax for JSON path queries is reserved for v2.
- **No computed/virtual columns**: e.g. `CONCAT(first, ' ', last)` as a filterable field. Planned for v2 via `#[VirtualColumn]`.
- **Row duplication on one-to-many joins**: when a FK points to the "many" side (e.g. filtering users by their orders), INNER JOIN can multiply base rows. v1 does not auto-detect this or apply DISTINCT. Callers must be aware.
- **SchemaModel must be a DI singleton**: `SchemaDiscovery::discover()` is reflection-heavy. If called per-request rather than once at bootstrap, performance will degrade.
