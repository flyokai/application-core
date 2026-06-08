<?php

namespace Flyokai\SearchCriteria\Compiler;

use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\TableModel;

final readonly class JoinPlan
{
    /**
     * @param array<string, JoinEntry> $joins Keyed by alias, sorted deterministically.
     */
    public function __construct(
        public array $joins = [],
    ) {}

    public function hasJoins(): bool
    {
        return $this->joins !== [];
    }
}
