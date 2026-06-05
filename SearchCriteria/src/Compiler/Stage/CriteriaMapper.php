<?php

namespace Flyokai\SearchCriteria\Compiler\Stage;

use Flyokai\SearchCriteria\Criteria\AndGroup;
use Flyokai\SearchCriteria\Criteria\CriteriaNode;
use Flyokai\SearchCriteria\Criteria\FieldPredicate;
use Flyokai\SearchCriteria\Criteria\NotGroup;
use Flyokai\SearchCriteria\Criteria\Operator;
use Flyokai\SearchCriteria\Criteria\OrGroup;
use Flyokai\SearchCriteria\Criteria\SearchCriteria;
use Flyokai\SearchCriteria\Criteria\SortDirection;
use Flyokai\SearchCriteria\Criteria\SortSpec;
use Flyokai\SearchCriteria\Exception\CriteriaException;

/**
 * Maps normalised array (output of CriteriaJsonNormalizer) into typed SearchCriteria.
 *
 * Manual mapper rather than Valinor because the node tree is polymorphic
 * (AND/OR have identical shapes) and Valinor can't discriminate without
 * constructor-shape differences.
 */
class CriteriaMapper
{
    public function map(array $normalized): SearchCriteria
    {
        $filter = null;
        if (isset($normalized['filter']) && $normalized['filter'] !== null) {
            $filter = $this->mapNode($normalized['filter']);
        }

        $sort = array_map(
            fn(array $s) => new SortSpec(
                field: $s['field'],
                direction: SortDirection::from($s['direction']),
            ),
            $normalized['sort'] ?? []
        );

        return new SearchCriteria(
            filter: $filter,
            sort: $sort,
            limit: isset($normalized['limit']) ? (int)$normalized['limit'] : null,
            offset: isset($normalized['offset']) ? (int)$normalized['offset'] : null,
            withTotal: (bool)($normalized['withTotal'] ?? false),
        );
    }

    private function mapNode(array $node): CriteriaNode
    {
        return match ($node['type']) {
            'and' => new AndGroup(
                array_map(fn(array $c) => $this->mapNode($c), $node['children'])
            ),
            'or' => new OrGroup(
                array_map(fn(array $c) => $this->mapNode($c), $node['children'])
            ),
            'not' => new NotGroup(
                $this->mapNode($node['child'])
            ),
            'field' => new FieldPredicate(
                field: $node['field'],
                op: Operator::from($node['op']),
                value: $node['value'] ?? null,
            ),
            default => throw new CriteriaException("Unknown node type: {$node['type']}"),
        };
    }
}
