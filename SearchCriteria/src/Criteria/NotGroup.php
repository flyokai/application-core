<?php

namespace Flyokai\SearchCriteria\Criteria;

final readonly class NotGroup implements CriteriaNode
{
    public function __construct(
        public CriteriaNode $child,
    ) {}
}
