<?php

namespace Flyokai\DbSchema\Diff;

use Flyokai\DbSchema\Model\ColumnModel;
use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\IndexModel;
use Flyokai\DbSchema\Model\PrimaryKeyModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\DbSchema\Model\UniqueKeyModel;

/**
 * Typed diff for a single table. `kind` distinguishes a brand-new table from
 * an incremental alter. `declared` carries the target TableModel so the
 * applier can emit a full CREATE TABLE when kind === KIND_CREATE.
 *
 * Field semantics:
 *  - columnsToChange — entries are [oldColumn, newColumn] pairs.
 *  - columnsRenamed  — entries are [oldColumn, newColumn] pairs; applier uses CHANGE COLUMN.
 *  - primaryKeyChanged — true when declared PK differs from existing PK (drop+add).
 */
class TableDiff
{
    public const KIND_CREATE = 'create';
    public const KIND_ALTER = 'alter';
    public const KIND_NOOP = 'noop';

    public function __construct(
        public readonly string $kind,
        public readonly TableModel $declared,
        /** @var ColumnModel[] */
        public readonly array $columnsToAdd = [],
        /** @var array<int, array{0: ColumnModel, 1: ColumnModel}> */
        public readonly array $columnsToChange = [],
        /** @var array<int, array{0: ColumnModel, 1: ColumnModel}> */
        public readonly array $columnsRenamed = [],
        /** @var ColumnModel[] */
        public readonly array $columnsToDrop = [],
        public readonly bool $primaryKeyChanged = false,
        public readonly ?PrimaryKeyModel $primaryKeyToAdd = null,
        public readonly ?PrimaryKeyModel $primaryKeyToDrop = null,
        /** @var UniqueKeyModel[] */
        public readonly array $uniqueKeysToAdd = [],
        /** @var UniqueKeyModel[] */
        public readonly array $uniqueKeysToDrop = [],
        /** @var IndexModel[] */
        public readonly array $indexesToAdd = [],
        /** @var IndexModel[] */
        public readonly array $indexesToDrop = [],
        /** @var ForeignKeyModel[] */
        public readonly array $foreignKeysToAdd = [],
        /** @var ForeignKeyModel[] */
        public readonly array $foreignKeysToDrop = [],
    ) {}

    public function hasNonDestructiveChanges(): bool
    {
        return $this->kind === self::KIND_CREATE
            || $this->columnsToAdd
            || $this->columnsToChange
            || $this->columnsRenamed
            || $this->primaryKeyToAdd
            || $this->uniqueKeysToAdd
            || $this->indexesToAdd
            || $this->foreignKeysToAdd;
    }

    public function hasDestructiveChanges(): bool
    {
        return $this->columnsToDrop
            || $this->uniqueKeysToDrop
            || $this->indexesToDrop
            || $this->foreignKeysToDrop
            || $this->primaryKeyToDrop;
    }
}
