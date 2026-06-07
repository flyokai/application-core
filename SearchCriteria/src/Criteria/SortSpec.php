<?php

namespace Flyokai\SearchCriteria\Criteria;

final readonly class SortSpec
{
    public function __construct(
        public string $field,
        public SortDirection $direction = SortDirection::Asc,
    ) {}
}
