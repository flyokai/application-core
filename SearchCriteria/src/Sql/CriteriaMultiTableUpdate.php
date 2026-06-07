<?php

namespace Flyokai\SearchCriteria\Sql;

use Flyokai\SearchCriteria\Compiler\CompiledQuery;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

/**
 * Builds and executes MySQL multi-table UPDATE statements.
 *
 * MySQL multi-table UPDATE syntax:
 *   UPDATE base AS alias
 *   INNER JOIN other AS other_alias ON ...
 *   SET alias.col = ?, ...
 *   WHERE ...
 *
 * Laminas\Db\Sql\Update does not support multi-table updates,
 * so this assembles the SQL manually using the adapter's platform for quoting.
 */
class CriteriaMultiTableUpdate
{
    /**
     * @param array<string, mixed> $setClauses Column => value pairs for the base table.
     * @return int Affected row count.
     */
    public function execute(
        CompiledQuery $compiled,
        array $setClauses,
        Adapter $adapter,
    ): int {
        $platform = $adapter->getPlatform();
        $driver = $adapter->getDriver();
        $baseAlias = $compiled->baseAlias;

        // Build UPDATE ... JOIN portion from the Select
        $sql = new Sql($adapter);
        $select = $compiled->select;

        // We need the FROM and JOIN clauses from the Select.
        // Get the full SELECT SQL and extract the FROM...WHERE parts.
        // Instead, we build the UPDATE SQL manually.

        $parts = [];
        $parts[] = 'UPDATE ' . $platform->quoteIdentifier($compiled->baseTable)
            . ' AS ' . $platform->quoteIdentifier($baseAlias);

        // JOIN clauses
        foreach ($compiled->joinPlan->joins as $join) {
            $onParts = [];
            foreach ($join->foreignKey->columns as $i => $localCol) {
                $refCol = $join->foreignKey->referencedColumns[$i];
                $onParts[] = $platform->quoteIdentifier($join->baseAlias) . '.'
                    . $platform->quoteIdentifier($localCol)
                    . ' = '
                    . $platform->quoteIdentifier($join->alias) . '.'
                    . $platform->quoteIdentifier($refCol);
            }
            $parts[] = 'INNER JOIN ' . $platform->quoteIdentifier($join->table->name)
                . ' AS ' . $platform->quoteIdentifier($join->alias)
                . ' ON ' . implode(' AND ', $onParts);
        }

        // SET clause
        $setExprs = [];
        $params = [];
        foreach ($setClauses as $col => $value) {
            $setExprs[] = $platform->quoteIdentifier($baseAlias) . '.'
                . $platform->quoteIdentifier($col) . ' = ?';
            $params[] = $value;
        }
        $parts[] = 'SET ' . implode(', ', $setExprs);

        // WHERE clause — extract from the compiled Select
        $selectSql = $sql->buildSqlString($select);
        $wherePos = stripos($selectSql, ' WHERE ');
        if ($wherePos !== false) {
            // Extract WHERE clause and parameters
            $whereSql = substr($selectSql, $wherePos + 1);
            // Remove ORDER BY, LIMIT, OFFSET from the WHERE portion
            foreach (['ORDER BY', 'LIMIT', 'OFFSET'] as $keyword) {
                $kwPos = stripos($whereSql, $keyword);
                if ($kwPos !== false) {
                    $whereSql = substr($whereSql, 0, $kwPos);
                }
            }
            $parts[] = trim($whereSql);
        }

        $updateSql = implode(' ', $parts);

        // Execute using a prepared statement.
        // We need to extract parameter values from the compiled Select.
        // Since we're using buildSqlString which inlines values, the params
        // from SET are our only positional params when using this approach.
        // However, the WHERE clause from buildSqlString has values inlined.
        $statement = $driver->createStatement($updateSql);
        $result = $statement->execute($params);

        return $result->getAffectedRows();
    }
}
