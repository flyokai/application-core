<?php

namespace Flyokai\SearchCriteria\Compiler;

use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\TableModel;

final readonly class JoinEntry
{
    public function __construct(
        public string $alias,
        public TableModel $table,
        public ForeignKeyModel $foreignKey,
        public string $baseAlias,
    ) {}
}
