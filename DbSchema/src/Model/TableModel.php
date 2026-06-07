<?php

namespace Flyokai\DbSchema\Model;

class TableModel
{
    public function __construct(
        public readonly string $name,
        /** @var array<string, ColumnModel> keyed by column name in declared order */
        public readonly array $columns,
        public readonly ?PrimaryKeyModel $primaryKey = null,
        /** @var array<string, UniqueKeyModel> keyed by constraint name */
        public readonly array $uniqueKeys = [],
        /** @var array<string, IndexModel> keyed by index name */
        public readonly array $indexes = [],
        /** @var array<string, ForeignKeyModel> keyed by constraint name */
        public readonly array $foreignKeys = [],
        public readonly string $engine = 'InnoDB',
        public readonly string $charset = 'utf8mb4',
        public readonly string $collation = 'utf8mb4_unicode_ci',
        public readonly ?string $comment = null,
        /** @var class-string|null */
        public readonly ?string $sourceDtoClass = null,
        /** @var string[] DB column names declared on the DTO but intentionally excluded from schema management */
        public readonly array $unmanagedColumns = [],
        public readonly ?string $alias = null,
    ) {}
}
