<?php

namespace Flyokai\SearchCriteria\Criteria;

final readonly class AndGroup implements CriteriaNode
{
    /**
     * @param list<CriteriaNode> $children
     */
    public function __construct(
        public array $children,
    ) {}
}
