<?php

namespace Flyokai\SearchCriteria\Operator;

use Laminas\Db\Sql\Predicate\PredicateInterface;

interface PredicateFactory
{
    /**
     * @param string $qualifiedField Fully qualified "alias.column" identifier.
     * @param mixed $value The predicate value from the criteria JSON.
     */
    public function create(string $qualifiedField, mixed $value): PredicateInterface;
}
