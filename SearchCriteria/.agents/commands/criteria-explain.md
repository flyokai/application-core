---
description: Show the compiled SQL, join plan, and field resolution for a search criteria JSON against a given Solid DTO — without executing anything.
allowed-tools: Read, Glob, Grep, Bash
---

# criteria-explain

You are tasked with explaining what SQL a search criteria JSON would produce for a given Solid DTO class. This is a **read-only diagnostic** — never execute queries against the database.

## What to gather from the user

- **Target DTO class** — fully qualified Solid DTO class name (e.g. `Flyokai\User\Dto\UserSolid`)
- **Criteria JSON** — the raw JSON tree (e.g. `{"AND": [{"field": "status", "op": "eq", "value": "active"}]}`)
- Optionally: `sort`, `limit`, `offset`, `withTotal` parameters

If the user provides only a DTO class (no JSON), show the available aliases and joinable tables instead.

## How to run

The compile pipeline is pure PHP and can be exercised standalone. Use this script:

```bash
php -r '
chdir("/www/flyokai/flyokai");
require "vendor/composer/ClassLoader.php";
$cl = new \Composer\Autoload\ClassLoader("vendor");
foreach (require "vendor/composer/autoload_psr4.php" as $ns => $path) $cl->setPsr4($ns, $path);
if ($cm = require "vendor/composer/autoload_classmap.php") $cl->addClassMap($cm);
$cl->register();
foreach (glob("vendor/flyokai/*/bootstrap.php") as $f) require_once $f;

$schema = (new \Flyokai\DbSchema\Discovery\SchemaDiscovery())->discover();

// Show all registered aliases
echo "Registered table aliases:\n";
foreach ($schema->tablesByAlias as $alias => $table) {
    echo "  $alias => $table->name ($table->sourceDtoClass)\n";
}
'
```

To compile a specific criteria:

```bash
php -r '
chdir("/www/flyokai/flyokai");
require "vendor/composer/ClassLoader.php";
$cl = new \Composer\Autoload\ClassLoader("vendor");
foreach (require "vendor/composer/autoload_psr4.php" as $ns => $path) $cl->setPsr4($ns, $path);
if ($cm = require "vendor/composer/autoload_classmap.php") $cl->addClassMap($cm);
$cl->register();
foreach (glob("vendor/flyokai/*/bootstrap.php") as $f) require_once $f;

use Flyokai\SearchCriteria\Compiler\Stage\{CriteriaJsonNormalizer, CriteriaMapper, FieldCollector, BaseColumnValidator, JoinResolver, SelectCompiler};
use Flyokai\SearchCriteria\Compiler\SearchCompiler;
use Flyokai\SearchCriteria\Operator\OperatorRegistry;

$schema = (new \Flyokai\DbSchema\Discovery\SchemaDiscovery())->discover();
$compiler = new SearchCompiler(
    new CriteriaJsonNormalizer(),
    new CriteriaMapper(),
    new FieldCollector(),
    new BaseColumnValidator(),
    new JoinResolver($schema),
    new SelectCompiler(new OperatorRegistry()),
    $schema,
);

$dtoClass = "REPLACE_DTO_CLASS";
$json = json_decode('\''REPLACE_JSON'\'', true);

try {
    $compiled = $compiler->compile($dtoClass, $json);
    $adapter = new \Laminas\Db\Adapter\Adapter(["driver" => "Pdo_Mysql", "hostname" => "localhost"]);
    $sql = new \Laminas\Db\Sql\Sql($adapter);
    echo "Generated SQL:\n" . $sql->buildSqlString($compiled->select) . "\n\n";
    echo "Base table: $compiled->baseTable (alias: $compiled->baseAlias)\n";
    echo "Joins:\n";
    foreach ($compiled->joinPlan->joins as $alias => $join) {
        echo "  INNER JOIN $join->table->name AS $alias ON ";
        $parts = [];
        foreach ($join->foreignKey->columns as $i => $col) {
            $parts[] = "$join->baseAlias.$col = $alias.{$join->foreignKey->referencedColumns[$i]}";
        }
        echo implode(" AND ", $parts) . "\n";
    }
} catch (\Throwable $e) {
    echo "Compile error: " . $e->getMessage() . "\n";
}
'
```

Replace `REPLACE_DTO_CLASS` and `REPLACE_JSON` with the user's input.

**Note**: The Laminas adapter is needed only for SQL string rendering (platform quoting). No database connection is actually opened — the `Pdo_Mysql` driver with a dummy hostname is enough for `buildSqlString()`.

## Output format

### Compiled query

```
Base table: flyok_user (alias: user)
Base DTO: Flyokai\User\Dto\UserSolid

Joins:
  INNER JOIN flyok_acl_role AS role ON user.role_id = role.role_id
  INNER JOIN flyok_user_extra AS user_extra ON user.user_id = user_extra.user_id

Generated SQL:
  SELECT `user`.*
  FROM `flyok_user` AS `user`
  INNER JOIN `flyok_acl_role` AS `role` ON `user`.`role_id` = `role`.`role_id`
  WHERE `user`.`status` = 'active'
  ORDER BY `user`.`user_id` DESC
  LIMIT 20 OFFSET 0
```

### Field resolution

```
Fields referenced:
  status        → user.status (base table column: varchar(32))
  role.role     → role.role (joined via FK FLYOK_USER_ROLE_ID__FLYOK_ACL_ROLE_ID)
```

### If compilation fails

Show the full exception message and suggest how to fix it:
- `InvalidFieldException` → field typo or missing column; list available columns on the table
- `AmbiguousForeignKeyException` → multiple FKs to the same table; list them and suggest #[Relation] (v2)
- `CriteriaDepthException` → criteria too deep; show current maxDepth
- `CriteriaException` → generic; show the error message

## Important

- **Don't execute queries**. This command only compiles and renders SQL.
- **Don't modify any files**. Pure read-only operation.
- If a user passes a criteria argument, parse it as JSON. If it's not valid JSON, tell them.
- If the DTO class doesn't exist or isn't tagged with `#[Table]`, show the error and list available DTOs.
