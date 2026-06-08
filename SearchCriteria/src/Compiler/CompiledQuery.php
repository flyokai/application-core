<?php

namespace Flyokai\SearchCriteria\Compiler;

use Laminas\Db\Sql\Select;

final readonly class CompiledQuery
{
    public function __construct(
        public Select $select,
        public JoinPlan $joinPlan,
        public string $baseAlias,
        public string $baseTable,
    ) {}
}
