# flyokai/search-criteria

Declarative search criteria with auto-join discovery for Flyokai repositories.

See [AGENTS.md](AGENTS.md) for the full pipeline architecture, operator mapping, guardrail table, and development skills.

## Architecture

**Criteria JSON** (external format) flows through a 6-stage compile pipeline:

1. `CriteriaJsonNormalizer` — transforms `{"AND": [...]}` / `{"OR": [...]}` / `{"NOT": {...}}` / `{"field":...}` into canonical `{"type": "and|or|not|field", ...}` form
2. `CriteriaMapper` — canonical array → typed `SearchCriteria` object tree (manual mapper, not Valinor, because node types are polymorphic with identical shapes)
3. `FieldCollector` — walks tree → base fields + prefixed `alias.col` fields
4. `BaseColumnValidator` — validates base fields exist in `TableModel`; JSON columns rejected for non-null operators
5. `JoinResolver` — resolves `alias.col` → `JoinPlan` via `#[ForeignKey]` graph. Requires exactly one FK match; throws on zero/ambiguous. Rejects JSON columns on joined tables entirely.
6. `SelectCompiler` — emits `Laminas\Db\Sql\Select` with INNER JOINs, WHERE tree, ORDER BY, LIMIT/OFFSET

**Key classes:**
- `SearchCompiler` — orchestrates the pipeline. Entry points: `compile(dtoClass, jsonArray)` and `compileFromCriteria(dtoClass, SearchCriteria)`
- `OperatorRegistry` — extension point for custom operators (e.g. `findInSet`). Standard ops handled directly by `SelectCompiler`
- `CriteriaRepositoryTrait` — adds `getList()` and `massUpdate()` to repositories. Requires `SearchableRepository` interface. Host must provide `searchCompiler()` and `schemaModel()` abstract methods.

## Field resolution rules

- `"username"` → base table column
- `"role.role"` → alias `role`, column `role`. Auto-discovered via `#[ForeignKey]` on base DTO.
- Prefix matching base alias → treated as base table (e.g., `user.username` when base is `user`)
- Dotted paths deeper than `alias.col` → rejected (v1 limitation)

## Table aliases

Declared via `#[Table(name: 'flyok_user', alias: 'user')]` in `flyokai/db-schema`. Defaults to lowercased short class name minus "Solid" suffix. Must be unique across schema.

## Guardrails

- `maxDepth=16`, `maxNodes=512` on the normalizer (DoS protection)
- `EmptyCriteriaException` on `massUpdate()` with null filter
- MySQL multi-table UPDATE rejects LIMIT/ORDER BY
- Empty `IN([])` → `1=0`, empty `NOT IN([])` → `1=1`
- JSON columns: only `isNull`/`isNotNull` allowed
- PK columns stripped from `massUpdate` SET clause
