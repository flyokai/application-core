<?php

namespace Flyokai\SearchCriteria\Criteria;

final readonly class OrGroup implements CriteriaNode
{
    /**
     * @param list<CriteriaNode> $children
     */
    public function __construct(
        public array $children,
    ) {}
}
