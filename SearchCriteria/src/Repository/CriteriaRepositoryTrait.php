<?php

namespace Flyokai\SearchCriteria\Repository;

use Flyokai\DataMate\Dto;
use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\SearchCriteria\Compiler\SearchCompiler;
use Flyokai\SearchCriteria\Criteria\SearchCriteria;
use Flyokai\SearchCriteria\Exception\CriteriaException;
use Flyokai\SearchCriteria\Exception\EmptyCriteriaException;
use Flyokai\SearchCriteria\Sql\CriteriaMultiTableUpdate;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Sql;

/**
 * Provides getList() and massUpdate() for repositories extending AbstractRepository.
 *
 * Requires the host class to provide:
 *   - dtoClassName(bool $solid): string  (from AbstractRepository)
 *   - tableName(): string                (from AbstractRepository)
 *   - dbAdapter(): Adapter               (from AbstractRepository)
 *
 * Dependencies (inject via constructor):
 *   - SearchCompiler $searchCompiler
 *   - SchemaModel $schemaModel
 */
trait CriteriaRepositoryTrait
{
    abstract protected function searchCompiler(): SearchCompiler;
    abstract protected function schemaModel(): SchemaModel;

    public function getList(SearchCriteria|array $criteria): ListResult
    {
        if (is_array($criteria)) {
            $compiled = $this->searchCompiler()->compile(
                $this->dtoClassName(solid: true),
                $criteria
            );
            // Re-parse for the typed criteria (needed for withTotal check)
            $normalizer = new \Flyokai\SearchCriteria\Compiler\Stage\CriteriaJsonNormalizer();
            $mapper = new \Flyokai\SearchCriteria\Compiler\Stage\CriteriaMapper();
            $typedCriteria = $mapper->map($normalizer->normalize($criteria));
        } else {
            $compiled = $this->searchCompiler()->compileFromCriteria(
                $this->dtoClassName(solid: true),
                $criteria
            );
            $typedCriteria = $criteria;
        }

        $adapter = $this->dbAdapter();
        $sql = new Sql($adapter);

        // Execute main query
        $statement = $sql->prepareStatementForSqlObject($compiled->select);
        $resultSet = $statement->execute();

        $solidClass = $this->dtoClassName(solid: true);
        $items = [];
        foreach ($resultSet as $row) {
            $row = call_user_func([$solidClass, 'tuneDbRow'], $row);
            $items[] = call_user_func([$solidClass, 'fromArray'], $row);
        }

        // Optional total count
        $total = null;
        if ($typedCriteria->withTotal) {
            $total = $this->executeCountQuery($compiled->select, $adapter);
        }

        return new ListResult($items, $total);
    }

    public function massUpdate(SearchCriteria|array $criteria, Dto $partial): int
    {
        if (is_array($criteria)) {
            $compiled = $this->searchCompiler()->compile(
                $this->dtoClassName(solid: true),
                $criteria
            );
            // Check for empty filter
            $normalizer = new \Flyokai\SearchCriteria\Compiler\Stage\CriteriaJsonNormalizer();
            $mapper = new \Flyokai\SearchCriteria\Compiler\Stage\CriteriaMapper();
            $typedCriteria = $mapper->map($normalizer->normalize($criteria));
        } else {
            $compiled = $this->searchCompiler()->compileFromCriteria(
                $this->dtoClassName(solid: true),
                $criteria
            );
            $typedCriteria = $criteria;
        }

        // Guard: refuse empty criteria
        if ($typedCriteria->filter === null) {
            throw new EmptyCriteriaException(
                'massUpdate() requires a non-empty filter to prevent accidental full-table writes. '
                . 'Use massUpdateAll() if this is intentional.'
            );
        }

        // Reject limit/sort with multi-table UPDATE (MySQL constraint)
        if ($compiled->joinPlan->hasJoins()) {
            if ($typedCriteria->limit !== null) {
                throw new CriteriaException(
                    'MySQL multi-table UPDATE does not support LIMIT. '
                    . 'Remove limit from criteria when filtering across joins.'
                );
            }
            if ($typedCriteria->sort !== []) {
                throw new CriteriaException(
                    'MySQL multi-table UPDATE does not support ORDER BY. '
                    . 'Remove sort from criteria when filtering across joins.'
                );
            }
        }

        // Build SET clause from partial DTO
        $setClauses = $this->buildSetClauses($partial);
        if ($setClauses === []) {
            return 0;
        }

        $adapter = $this->dbAdapter();

        if ($compiled->joinPlan->hasJoins()) {
            // Multi-table UPDATE via custom builder
            $builder = new CriteriaMultiTableUpdate();
            return $builder->execute($compiled, $setClauses, $adapter);
        }

        // Simple single-table UPDATE
        $sql = new Sql($adapter);
        $update = $sql->update([$compiled->baseAlias => $compiled->baseTable]);

        $update->set($setClauses);

        // Apply WHERE from the compiled Select
        $selectSqlString = $sql->buildSqlString($compiled->select);
        $wherePos = stripos($selectSqlString, ' WHERE ');
        if ($wherePos !== false) {
            $wherePart = substr($selectSqlString, $wherePos + 7);
            // Strip ORDER BY, LIMIT, OFFSET
            foreach (['ORDER BY', 'LIMIT', 'OFFSET'] as $keyword) {
                $kwPos = stripos($wherePart, $keyword);
                if ($kwPos !== false) {
                    $wherePart = substr($wherePart, 0, $kwPos);
                }
            }
            $update->where(new \Laminas\Db\Sql\Predicate\Expression(trim($wherePart)));
        }

        $statement = $sql->prepareStatementForSqlObject($update);
        $result = $statement->execute();

        return $result->getAffectedRows();
    }

    /**
     * Build a COUNT query from the compiled Select (strips limit/offset/order, wraps as subquery).
     */
    private function executeCountQuery(Select $select, Adapter $adapter): int
    {
        $countSelect = clone $select;
        $countSelect->reset(Select::LIMIT);
        $countSelect->reset(Select::OFFSET);
        $countSelect->reset(Select::ORDER);
        $countSelect->reset(Select::COLUMNS);
        $countSelect->columns(['__criteria_count' => new \Laminas\Db\Sql\Expression('COUNT(*)')]);

        $sql = new Sql($adapter);
        $statement = $sql->prepareStatementForSqlObject($countSelect);
        $result = $statement->execute();
        $row = $result->current();

        return (int)($row['__criteria_count'] ?? 0);
    }

    /**
     * Extract SET clause key-value pairs from a partial DTO.
     * Filters: only base-table columns, excludes PK columns, excludes undefined Draft values.
     *
     * @return array<string, mixed>
     */
    private function buildSetClauses(Dto $partial): array
    {
        $dbRow = $partial->toDbRow();

        // Filter out undefined values (Draft DTOs track undefined via isUndefined())
        if (method_exists($partial, 'isUndefined')) {
            $rc = new \ReflectionClass($partial);
            $ctor = $rc->getConstructor();
            if ($ctor) {
                foreach ($ctor->getParameters() as $param) {
                    if ($param->isPromoted() && $partial->isUndefined($param->getName())) {
                        unset($dbRow[$param->getName()]);
                    }
                }
            }
        }

        // Validate against base table columns and strip PK columns
        $solidClass = $this->dtoClassName(solid: true);
        $tableName = $this->schemaModel()->dtoClassToTableName[$solidClass] ?? null;
        if ($tableName && isset($this->schemaModel()->tables[$tableName])) {
            $tableModel = $this->schemaModel()->tables[$tableName];
            $pkColumns = $tableModel->primaryKey?->columns ?? [];

            $filtered = [];
            foreach ($dbRow as $col => $value) {
                if (in_array($col, $pkColumns, true)) {
                    continue; // Never update PK columns
                }
                if (!isset($tableModel->columns[$col])) {
                    continue; // Skip columns not in the table model
                }
                $filtered[$col] = $value;
            }
            return $filtered;
        }

        return $dbRow;
    }
}
