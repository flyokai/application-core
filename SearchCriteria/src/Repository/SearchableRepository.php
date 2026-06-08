<?php

namespace Flyokai\SearchCriteria\Repository;

use Flyokai\DataMate\Dto;
use Flyokai\SearchCriteria\Criteria\SearchCriteria;

interface SearchableRepository
{
    public function getList(SearchCriteria|array $criteria): ListResult;

    /**
     * Bulk UPDATE on base table filtered by criteria.
     * Criteria may reference joined columns for filtering but SET targets base-table columns only.
     *
     * @param SearchCriteria|array $criteria Filter criteria.
     * @param Dto $partial Draft DTO with only the fields to update set (undefined fields are skipped).
     * @return int Number of affected rows.
     */
    public function massUpdate(SearchCriteria|array $criteria, Dto $partial): int;
}
