<?php

namespace Flyokai\SearchCriteria\Criteria;

final readonly class SearchCriteria
{
    /**
     * @param CriteriaNode|null $filter Root filter node (AND/OR/NOT/field predicate tree).
     * @param list<SortSpec> $sort Order-by specifications.
     * @param int|null $limit Max rows to return.
     * @param int|null $offset Number of rows to skip.
     * @param bool $withTotal Whether to execute a COUNT query alongside the main query.
     */
    public function __construct(
        public ?CriteriaNode $filter = null,
        public array $sort = [],
        public ?int $limit = null,
        public ?int $offset = null,
        public bool $withTotal = false,
    ) {}
}
