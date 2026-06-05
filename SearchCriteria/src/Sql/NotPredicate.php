<?php

namespace Flyokai\SearchCriteria\Sql;

use Laminas\Db\Sql\Predicate\PredicateInterface;
use Laminas\Db\Sql\Predicate\PredicateSet;

/**
 * Wraps a predicate with NOT.
 * Emits: NOT (inner_predicate)
 */
class NotPredicate extends PredicateSet
{
    public function __construct(
        private readonly PredicateInterface $inner,
    ) {
        parent::__construct([$inner]);
    }

    public function getExpressionData(): array
    {
        $innerData = $this->inner->getExpressionData();
        if ($innerData === []) {
            return [];
        }

        // Wrap: prepend NOT ( and append )
        $result = [];
        $result[] = ['NOT (', [], []];
        foreach ($innerData as $datum) {
            $result[] = $datum;
        }
        $result[] = [')', [], []];

        return $result;
    }
}
