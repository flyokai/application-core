<?php

namespace Flyokai\DbSchema\Introspection;

use Flyokai\DbSchema\Model\ColumnModel;
use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\IndexModel;
use Flyokai\DbSchema\Model\PrimaryKeyModel;
use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\DbSchema\Model\UniqueKeyModel;
use Laminas\Db\Adapter\Adapter;

/**
 * Reads live MySQL schema via INFORMATION_SCHEMA into the same TableModel
 * produced by SchemaDiscovery. Only inspects tables by name to keep the
 * round-trip focused on what the declarative side knows about.
 */
class SchemaIntrospector
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly MysqlTypeNormalizer $normalizer = new MysqlTypeNormalizer(),
    ) {}

    /**
     * @param string[] $tableNames
     */
    public function introspect(array $tableNames): SchemaModel
    {
        if (!$tableNames) {
            return new SchemaModel([]);
        }
        $schema = $this->adapter->getCurrentSchema();
        $existingTableNames = $this->loadExistingTableNames($schema, $tableNames);

        $tables = [];
        foreach ($tableNames as $name) {
            if (!in_array($name, $existingTableNames, true)) {
                continue;
            }
            $tables[$name] = $this->introspectTable($schema, $name);
        }
        return new SchemaModel($tables);
    }

    /**
     * @param string[] $tableNames
     * @return string[]
     */
    private function loadExistingTableNames(string $schema, array $tableNames): array
    {
        $p = $this->adapter->getPlatform();
        $placeholders = implode(', ', array_map(fn ($n) => $p->quoteTrustedValue($n), $tableNames));
        $sql = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES'
            . ' WHERE TABLE_SCHEMA = ' . $p->quoteTrustedValue($schema)
            . ' AND TABLE_TYPE = \'BASE TABLE\''
            . ' AND TABLE_NAME IN (' . $placeholders . ')';
        $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        $names = [];
        foreach ($result->toArray() as $row) {
            $names[] = $row['TABLE_NAME'];
        }
        return $names;
    }

    private function introspectTable(string $schema, string $tableName): TableModel
    {
        $tableOpts = $this->loadTableOptions($schema, $tableName);
        $columns = $this->loadColumns($schema, $tableName);
        [$primaryKey, $uniqueKeys, $indexes] = $this->loadIndexes($schema, $tableName);
        $foreignKeys = $this->loadForeignKeys($schema, $tableName);

        // MySQL automatically creates a secondary index named after each foreign key
        // constraint when the FK's columns aren't already covered by another index.
        // Those implicit indexes surface in INFORMATION_SCHEMA.STATISTICS and would
        // otherwise look like "declared has no such index" to the differ, triggering
        // spurious destructive DROP INDEX proposals. Drop them from the introspected
        // index set so only explicit indexes compete in the diff.
        foreach ($foreignKeys as $fk) {
            unset($indexes[$fk->name]);
        }

        return new TableModel(
            name: $tableName,
            columns: $columns,
            primaryKey: $primaryKey,
            uniqueKeys: $uniqueKeys,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            engine: $tableOpts['engine'] ?? 'InnoDB',
            charset: $tableOpts['charset'] ?? 'utf8mb4',
            collation: $tableOpts['collation'] ?? 'utf8mb4_unicode_ci',
            comment: $tableOpts['comment'] ?? null,
        );
    }

    /**
     * @return array{engine?: string, charset?: string, collation?: string, comment?: string}
     */
    private function loadTableOptions(string $schema, string $tableName): array
    {
        $p = $this->adapter->getPlatform();
        $sql = 'SELECT T.ENGINE, T.TABLE_COLLATION, T.TABLE_COMMENT, CCSA.CHARACTER_SET_NAME'
            . ' FROM INFORMATION_SCHEMA.TABLES T'
            . ' LEFT JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA'
            . '   ON CCSA.COLLATION_NAME = T.TABLE_COLLATION'
            . ' WHERE T.TABLE_SCHEMA = ' . $p->quoteTrustedValue($schema)
            . ' AND T.TABLE_NAME = ' . $p->quoteTrustedValue($tableName);
        $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        $row = $result->toArray()[0] ?? null;
        if ($row === null) {
            return [];
        }
        return [
            'engine' => $row['ENGINE'] ?? 'InnoDB',
            'charset' => $row['CHARACTER_SET_NAME'] ?? 'utf8mb4',
            'collation' => $row['TABLE_COLLATION'] ?? 'utf8mb4_unicode_ci',
            'comment' => ($row['TABLE_COMMENT'] ?? '') !== '' ? $row['TABLE_COMMENT'] : null,
        ];
    }

    /**
     * @return array<string, ColumnModel>
     */
    private function loadColumns(string $schema, string $tableName): array
    {
        $p = $this->adapter->getPlatform();
        $sql = 'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,'
            . ' CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,'
            . ' COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT, COLLATION_NAME'
            . ' FROM INFORMATION_SCHEMA.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ' . $p->quoteTrustedValue($schema)
            . ' AND TABLE_NAME = ' . $p->quoteTrustedValue($tableName)
            . ' ORDER BY ORDINAL_POSITION';
        $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);

        $columns = [];
        foreach ($result->toArray() as $row) {
            $dataType = $this->normalizer->mapDataType($row['DATA_TYPE']);
            $columnType = strtolower($row['COLUMN_TYPE'] ?? '');
            $unsigned = str_contains($columnType, 'unsigned');
            $autoInc = str_contains(strtolower($row['EXTRA'] ?? ''), 'auto_increment');
            $enumValues = null;
            if (in_array($dataType, ['enum', 'set'], true)) {
                $enumValues = $this->parseEnumValues($row['COLUMN_TYPE'] ?? '');
            }

            $length = $row['CHARACTER_MAXIMUM_LENGTH'];
            if ($length !== null) {
                $length = (int)$length;
            }

            $precision = $row['NUMERIC_PRECISION'];
            $scale = $row['NUMERIC_SCALE'];
            if ($dataType === 'decimal') {
                $precision = $precision !== null ? (int)$precision : null;
                $scale = $scale !== null ? (int)$scale : null;
            } elseif ($dataType === 'int' || in_array($dataType, ['tinyint','smallint','mediumint','bigint'], true)) {
                $precision = null;
                $scale = null;
            }

            $extra = strtolower($row['EXTRA'] ?? '');
            $hasDefault = array_key_exists('COLUMN_DEFAULT', $row);
            $default = $row['COLUMN_DEFAULT'];
            $defaultExpression = null;
            if ($default !== null && str_contains($extra, 'default_generated')) {
                $defaultExpression = strtoupper($default);
                $default = null;
                $hasDefault = false;
            }
            if ($default === null && !str_contains($extra, 'default_generated')) {
                // COLUMN_DEFAULT NULL is ambiguous: "no default" vs "default NULL". Treat
                // it as "no default" uniformly; nullable columns implicitly default to
                // NULL in MySQL anyway, so the canonicalized form is equivalent.
                $hasDefault = false;
            }

            $onUpdateExpression = null;
            if (preg_match('/on update (current_timestamp(?:\(\d*\))?)/i', $extra, $m)) {
                $onUpdateExpression = strtoupper($m[1]);
            }

            $col = new ColumnModel(
                name: $row['COLUMN_NAME'],
                type: $dataType,
                length: $length,
                precision: $precision,
                scale: $scale,
                unsigned: $unsigned,
                nullable: $row['IS_NULLABLE'] === 'YES',
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $defaultExpression,
                onUpdateExpression: $onUpdateExpression,
                autoIncrement: $autoInc,
                comment: ($row['COLUMN_COMMENT'] ?? '') !== '' ? $row['COLUMN_COMMENT'] : null,
                collation: $row['COLLATION_NAME'],
                enumValues: $enumValues,
            );
            $columns[$col->name] = $col;
        }
        return $columns;
    }

    /**
     * @return string[]
     */
    private function parseEnumValues(string $columnType): array
    {
        if (preg_match('/^(?:enum|set)\((.+)\)$/i', $columnType, $m)) {
            if (preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vm)) {
                return array_map(fn ($s) => str_replace("''", "'", $s), $vm[1]);
            }
        }
        return [];
    }

    /**
     * @return array{0: ?PrimaryKeyModel, 1: array<string, UniqueKeyModel>, 2: array<string, IndexModel>}
     */
    private function loadIndexes(string $schema, string $tableName): array
    {
        $p = $this->adapter->getPlatform();
        $sql = 'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE'
            . ' FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ' . $p->quoteTrustedValue($schema)
            . ' AND TABLE_NAME = ' . $p->quoteTrustedValue($tableName)
            . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX';
        $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);

        $grouped = [];
        foreach ($result->toArray() as $row) {
            $grouped[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $grouped[$row['INDEX_NAME']]['non_unique'] = (int)$row['NON_UNIQUE'];
            $grouped[$row['INDEX_NAME']]['type'] = strtoupper($row['INDEX_TYPE'] ?? 'BTREE');
        }

        $primaryKey = null;
        $uniqueKeys = [];
        $indexes = [];
        foreach ($grouped as $name => $info) {
            if ($name === 'PRIMARY') {
                $primaryKey = new PrimaryKeyModel($info['columns']);
                continue;
            }
            if ($info['non_unique'] === 0) {
                $uniqueKeys[$name] = new UniqueKeyModel($name, $info['columns']);
                continue;
            }
            $type = $info['type'] === 'FULLTEXT' ? IndexModel::TYPE_FULLTEXT : IndexModel::TYPE_BTREE;
            $indexes[$name] = new IndexModel($name, $info['columns'], $type);
        }
        return [$primaryKey, $uniqueKeys, $indexes];
    }

    /**
     * @return array<string, ForeignKeyModel>
     */
    private function loadForeignKeys(string $schema, string $tableName): array
    {
        $p = $this->adapter->getPlatform();
        $sql = 'SELECT KCU.CONSTRAINT_NAME, KCU.COLUMN_NAME, KCU.REFERENCED_TABLE_NAME,'
            . ' KCU.REFERENCED_COLUMN_NAME, KCU.ORDINAL_POSITION, RC.UPDATE_RULE, RC.DELETE_RULE'
            . ' FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE KCU'
            . ' JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS RC'
            . '   ON RC.CONSTRAINT_SCHEMA = KCU.TABLE_SCHEMA'
            . '  AND RC.CONSTRAINT_NAME = KCU.CONSTRAINT_NAME'
            . ' WHERE KCU.TABLE_SCHEMA = ' . $p->quoteTrustedValue($schema)
            . ' AND KCU.TABLE_NAME = ' . $p->quoteTrustedValue($tableName)
            . ' AND KCU.REFERENCED_TABLE_NAME IS NOT NULL'
            . ' ORDER BY KCU.CONSTRAINT_NAME, KCU.ORDINAL_POSITION';
        $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);

        $grouped = [];
        foreach ($result->toArray() as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $grouped[$name]['columns'][] = $row['COLUMN_NAME'];
            $grouped[$name]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
            $grouped[$name]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
            $grouped[$name]['update_rule'] = $row['UPDATE_RULE'];
            $grouped[$name]['delete_rule'] = $row['DELETE_RULE'];
        }

        $fks = [];
        foreach ($grouped as $name => $info) {
            $fks[$name] = new ForeignKeyModel(
                name: $name,
                columns: $info['columns'],
                referencedTable: $info['referenced_table'],
                referencedColumns: $info['referenced_columns'],
                onDelete: $this->normalizeFkRule($info['delete_rule']),
                onUpdate: $this->normalizeFkRule($info['update_rule']),
            );
        }
        return $fks;
    }

    // MariaDB exposes RESTRICT as NO ACTION in INFORMATION_SCHEMA (InnoDB
    // treats them as synonyms). Normalize so the differ doesn't churn FKs
    // forever when running against MariaDB.
    private function normalizeFkRule(string $rule): string
    {
        $rule = strtoupper($rule);
        return $rule === 'NO ACTION' ? 'RESTRICT' : $rule;
    }
}
