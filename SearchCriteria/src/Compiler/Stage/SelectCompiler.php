<?php

namespace Flyokai\SearchCriteria\Compiler\Stage;

use Flyokai\SearchCriteria\Compiler\CompiledQuery;
use Flyokai\SearchCriteria\Compiler\JoinEntry;
use Flyokai\SearchCriteria\Compiler\JoinPlan;
use Flyokai\SearchCriteria\Criteria\AndGroup;
use Flyokai\SearchCriteria\Criteria\CriteriaNode;
use Flyokai\SearchCriteria\Criteria\FieldPredicate;
use Flyokai\SearchCriteria\Criteria\NotGroup;
use Flyokai\SearchCriteria\Criteria\Operator;
use Flyokai\SearchCriteria\Criteria\OrGroup;
use Flyokai\SearchCriteria\Criteria\SearchCriteria;
use Flyokai\SearchCriteria\Criteria\SortDirection;
use Flyokai\SearchCriteria\Operator\OperatorRegistry;
use Flyokai\SearchCriteria\Sql\NotPredicate;
use Laminas\Db\Sql\Predicate\Between;
use Laminas\Db\Sql\Predicate\Expression;
use Laminas\Db\Sql\Predicate\In;
use Laminas\Db\Sql\Predicate\IsNotNull;
use Laminas\Db\Sql\Predicate\IsNull;
use Laminas\Db\Sql\Predicate\Like;
use Laminas\Db\Sql\Predicate\NotBetween;
use Laminas\Db\Sql\Predicate\NotIn;
use Laminas\Db\Sql\Predicate\NotLike;
use Laminas\Db\Sql\Predicate\Operator as LaminasOperator;
use Laminas\Db\Sql\Predicate\PredicateInterface;
use Laminas\Db\Sql\Predicate\PredicateSet;
use Laminas\Db\Sql\Select;

/**
 * Emits a Laminas Select with joins, WHERE tree, ORDER BY, LIMIT/OFFSET.
 */
class SelectCompiler
{
    public function __construct(
        private readonly OperatorRegistry $operatorRegistry,
    ) {}

    public function compile(
        SearchCriteria $criteria,
        string $baseTableName,
        string $baseAlias,
        JoinPlan $joinPlan,
    ): CompiledQuery {
        $select = new Select([$baseAlias => $baseTableName]);
        $select->columns(['*']);

        // Add joins
        foreach ($joinPlan->joins as $join) {
            $onClause = $this->buildJoinCondition($join);
            $select->join(
                [$join->alias => $join->table->name],
                $onClause,
                [], // No columns from joined tables — filtering only
                Select::JOIN_INNER,
            );
        }

        // WHERE
        if ($criteria->filter !== null) {
            $predicate = $this->compilePredicate($criteria->filter, $baseAlias);
            if ($predicate !== null) {
                $select->where($predicate);
            }
        }

        // ORDER BY
        foreach ($criteria->sort as $sort) {
            $qualifiedField = $this->qualifyField($sort->field, $baseAlias);
            $direction = $sort->direction === SortDirection::Desc ? 'DESC' : 'ASC';
            $select->order("{$qualifiedField} {$direction}");
        }

        // LIMIT / OFFSET
        if ($criteria->limit !== null) {
            $select->limit($criteria->limit);
        }
        if ($criteria->offset !== null) {
            $select->offset($criteria->offset);
        }

        return new CompiledQuery(
            select: $select,
            joinPlan: $joinPlan,
            baseAlias: $baseAlias,
            baseTable: $baseTableName,
        );
    }

    private function buildJoinCondition(JoinEntry $join): string
    {
        $parts = [];
        foreach ($join->foreignKey->columns as $i => $localCol) {
            $refCol = $join->foreignKey->referencedColumns[$i];
            $parts[] = "{$join->baseAlias}.{$localCol} = {$join->alias}.{$refCol}";
        }
        return implode(' AND ', $parts);
    }

    private function compilePredicate(CriteriaNode $node, string $baseAlias): ?PredicateInterface
    {
        if ($node instanceof AndGroup) {
            return $this->compileGroup($node->children, PredicateSet::OP_AND, $baseAlias);
        }

        if ($node instanceof OrGroup) {
            return $this->compileGroup($node->children, PredicateSet::OP_OR, $baseAlias);
        }

        if ($node instanceof NotGroup) {
            $inner = $this->compilePredicate($node->child, $baseAlias);
            if ($inner === null) {
                return null;
            }
            return new NotPredicate($inner);
        }

        if ($node instanceof FieldPredicate) {
            return $this->compileFieldPredicate($node, $baseAlias);
        }

        return null;
    }

    /**
     * @param list<CriteriaNode> $children
     */
    private function compileGroup(array $children, string $combination, string $baseAlias): ?PredicateInterface
    {
        $predicates = [];
        foreach ($children as $child) {
            $p = $this->compilePredicate($child, $baseAlias);
            if ($p !== null) {
                $predicates[] = $p;
            }
        }

        if ($predicates === []) {
            return null;
        }

        if (count($predicates) === 1) {
            return $predicates[0];
        }

        return new PredicateSet($predicates, $combination);
    }

    private function compileFieldPredicate(FieldPredicate $node, string $baseAlias): PredicateInterface
    {
        // Check custom operator registry first
        $factory = $this->operatorRegistry->getFactory($node->op);
        if ($factory !== null) {
            $qualifiedField = $this->qualifyField($node->field, $baseAlias);
            return $factory->create($qualifiedField, $node->value);
        }

        $qualifiedField = $this->qualifyField($node->field, $baseAlias);

        return match ($node->op) {
            Operator::Eq => new LaminasOperator($qualifiedField, LaminasOperator::OP_EQ, $node->value),
            Operator::Neq => new LaminasOperator($qualifiedField, LaminasOperator::OP_NE, $node->value),
            Operator::Gt => new LaminasOperator($qualifiedField, LaminasOperator::OP_GT, $node->value),
            Operator::Gte => new LaminasOperator($qualifiedField, LaminasOperator::OP_GTE, $node->value),
            Operator::Lt => new LaminasOperator($qualifiedField, LaminasOperator::OP_LT, $node->value),
            Operator::Lte => new LaminasOperator($qualifiedField, LaminasOperator::OP_LTE, $node->value),
            Operator::In => $this->compileIn($qualifiedField, $node->value),
            Operator::NotIn => $this->compileNotIn($qualifiedField, $node->value),
            Operator::Like => new Like($qualifiedField, $node->value),
            Operator::NotLike => new NotLike($qualifiedField, $node->value),
            Operator::IsNull => new IsNull($qualifiedField),
            Operator::IsNotNull => new IsNotNull($qualifiedField),
            Operator::Between => new Between(
                $qualifiedField,
                $node->value[0] ?? throw new \InvalidArgumentException("Between requires a two-element array"),
                $node->value[1] ?? throw new \InvalidArgumentException("Between requires a two-element array"),
            ),
        };
    }

    private function compileIn(string $field, mixed $value): PredicateInterface
    {
        if (!is_array($value) || $value === []) {
            // Empty IN → always false
            return new Expression('1 = 0');
        }
        return new In($field, $value);
    }

    private function compileNotIn(string $field, mixed $value): PredicateInterface
    {
        if (!is_array($value) || $value === []) {
            // Empty NOT IN → always true
            return new Expression('1 = 1');
        }
        return new NotIn($field, $value);
    }

    /**
     * Qualify a field name with the appropriate alias.
     * "status" → "base_alias.status"
     * "role.role" → "role.role"
     */
    private function qualifyField(string $field, string $baseAlias): string
    {
        if (str_contains($field, '.')) {
            return $field;
        }
        return "{$baseAlias}.{$field}";
    }
}
