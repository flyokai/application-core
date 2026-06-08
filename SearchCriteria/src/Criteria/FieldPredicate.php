<?php

namespace Flyokai\SearchCriteria\Criteria;

final readonly class FieldPredicate implements CriteriaNode
{
    public function __construct(
        public string $field,
        public Operator $op,
        public mixed $value = null,
    ) {}
}
