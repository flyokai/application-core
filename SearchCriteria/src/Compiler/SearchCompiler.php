<?php

namespace Flyokai\SearchCriteria\Compiler;

use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\SearchCriteria\Compiler\Stage\BaseColumnValidator;
use Flyokai\SearchCriteria\Compiler\Stage\CriteriaJsonNormalizer;
use Flyokai\SearchCriteria\Compiler\Stage\CriteriaMapper;
use Flyokai\SearchCriteria\Compiler\Stage\FieldCollector;
use Flyokai\SearchCriteria\Compiler\Stage\JoinResolver;
use Flyokai\SearchCriteria\Compiler\Stage\SelectCompiler;
use Flyokai\SearchCriteria\Criteria\AndGroup;
use Flyokai\SearchCriteria\Criteria\CriteriaNode;
use Flyokai\SearchCriteria\Criteria\FieldPredicate;
use Flyokai\SearchCriteria\Criteria\NotGroup;
use Flyokai\SearchCriteria\Criteria\Operator;
use Flyokai\SearchCriteria\Criteria\OrGroup;
use Flyokai\SearchCriteria\Criteria\SearchCriteria;
use Flyokai\SearchCriteria\Exception\CriteriaException;
use Flyokai\SearchCriteria\Exception\InvalidFieldException;

/**
 * Orchestrates the six-stage compile pipeline:
 *
 *  1. CriteriaJsonNormalizer — raw JSON array → canonical array
 *  2. CriteriaMapper — canonical array → SearchCriteria typed tree
 *  3. FieldCollector — walk tree → base fields + prefixed fields
 *  4. BaseColumnValidator — validate base fields against TableModel
 *  5. JoinResolver — resolve prefixed aliases → JoinPlan
 *  6. SelectCompiler — emit Laminas Select with joins + Where
 */
class SearchCompiler
{
    public function __construct(
        private readonly CriteriaJsonNormalizer $normalizer,
        private readonly CriteriaMapper $mapper,
        private readonly FieldCollector $fieldCollector,
        private readonly BaseColumnValidator $baseColumnValidator,
        private readonly JoinResolver $joinResolver,
        private readonly SelectCompiler $selectCompiler,
        private readonly SchemaModel $schema,
    ) {}

    /**
     * Compile a raw JSON criteria array for a given DTO class.
     *
     * @param class-string $baseDtoClass The Solid DTO class tagged with #[Table].
     * @param array $json Raw JSON criteria (external format).
     */
    public function compile(string $baseDtoClass, array $json): CompiledQuery
    {
        $baseTable = $this->resolveBaseTable($baseDtoClass);
        $baseAlias = $baseTable->alias
            ?? throw new CriteriaException(
                "DTO '{$baseDtoClass}' has no alias defined on its #[Table] attribute"
            );

        // Stage 1: Normalize JSON
        $normalized = $this->normalizer->normalize($json);

        // Stage 2: Map to typed SearchCriteria
        $criteria = $this->mapper->map($normalized);

        // Stage 3: Collect referenced fields
        $fields = $this->fieldCollector->collect($criteria);

        // Handle base-alias prefixed fields: treat "baseAlias.col" as base-table fields
        if (isset($fields['prefixed'][$baseAlias])) {
            $fields['base'] = array_unique(array_merge(
                $fields['base'],
                $fields['prefixed'][$baseAlias]
            ));
            unset($fields['prefixed'][$baseAlias]);
        }

        // Stage 4: Validate base-table columns
        $this->baseColumnValidator->validate($fields['base'], $baseTable, $baseAlias);

        // Stage 4b: Reject JSON columns used with non-null operators
        if ($criteria->filter !== null) {
            $this->validateJsonColumnUsage($criteria->filter, $baseTable, $baseAlias);
        }

        // Stage 5: Resolve joins
        $joinPlan = $this->joinResolver->resolve($fields['prefixed'], $baseTable, $baseAlias);

        // Stage 6: Compile to Laminas Select
        return $this->selectCompiler->compile($criteria, $baseTable->name, $baseAlias, $joinPlan);
    }

    /**
     * Compile from an already-typed SearchCriteria (for programmatic use).
     *
     * @param class-string $baseDtoClass The Solid DTO class tagged with #[Table].
     */
    public function compileFromCriteria(string $baseDtoClass, SearchCriteria $criteria): CompiledQuery
    {
        $baseTable = $this->resolveBaseTable($baseDtoClass);
        $baseAlias = $baseTable->alias
            ?? throw new CriteriaException(
                "DTO '{$baseDtoClass}' has no alias defined on its #[Table] attribute"
            );

        $fields = $this->fieldCollector->collect($criteria);

        if (isset($fields['prefixed'][$baseAlias])) {
            $fields['base'] = array_unique(array_merge(
                $fields['base'],
                $fields['prefixed'][$baseAlias]
            ));
            unset($fields['prefixed'][$baseAlias]);
        }

        $this->baseColumnValidator->validate($fields['base'], $baseTable, $baseAlias);

        if ($criteria->filter !== null) {
            $this->validateJsonColumnUsage($criteria->filter, $baseTable, $baseAlias);
        }

        $joinPlan = $this->joinResolver->resolve($fields['prefixed'], $baseTable, $baseAlias);

        return $this->selectCompiler->compile($criteria, $baseTable->name, $baseAlias, $joinPlan);
    }

    /**
     * Walk filter tree and reject JSON columns used with operators other than isNull/isNotNull.
     */
    private function validateJsonColumnUsage(CriteriaNode $node, TableModel $baseTable, string $baseAlias): void
    {
        if ($node instanceof FieldPredicate) {
            $field = $node->field;
            $dot = strpos($field, '.');
            if ($dot === false || substr($field, 0, $dot) === $baseAlias) {
                // Base table field
                $colName = $dot === false ? $field : substr($field, $dot + 1);
                if (isset($baseTable->columns[$colName]) && $baseTable->columns[$colName]->type === 'json') {
                    if ($node->op !== Operator::IsNull && $node->op !== Operator::IsNotNull) {
                        throw new InvalidFieldException(
                            "Field '{$field}' is a JSON column on '{$baseTable->name}'. "
                            . "Only isNull/isNotNull operators are allowed on JSON columns."
                        );
                    }
                }
            }
            // Joined-table JSON columns are already rejected by JoinResolver
            return;
        }

        if ($node instanceof AndGroup || $node instanceof OrGroup) {
            foreach ($node->children as $child) {
                $this->validateJsonColumnUsage($child, $baseTable, $baseAlias);
            }
            return;
        }

        if ($node instanceof NotGroup) {
            $this->validateJsonColumnUsage($node->child, $baseTable, $baseAlias);
        }
    }

    private function resolveBaseTable(string $baseDtoClass): TableModel
    {
        $tableName = $this->schema->dtoClassToTableName[$baseDtoClass]
            ?? throw new CriteriaException(
                "DTO class '{$baseDtoClass}' is not registered in SchemaModel. "
                . "Ensure it implements Solid and carries #[Table]."
            );

        return $this->schema->tables[$tableName]
            ?? throw new CriteriaException(
                "Table '{$tableName}' for DTO '{$baseDtoClass}' not found in SchemaModel"
            );
    }
}
