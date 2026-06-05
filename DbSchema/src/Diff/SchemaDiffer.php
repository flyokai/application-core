<?php

namespace Flyokai\DbSchema\Diff;

use Flyokai\DbSchema\Introspection\MysqlTypeNormalizer;
use Flyokai\DbSchema\Model\ColumnModel;
use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\IndexModel;
use Flyokai\DbSchema\Model\PrimaryKeyModel;
use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\DbSchema\Model\UniqueKeyModel;

/**
 * Pure function: declared vs live SchemaModel -> SchemaPlan. Canonicalizes both
 * sides through MysqlTypeNormalizer so equivalent descriptions don't drift
 * across repeated runs.
 */
class SchemaDiffer
{
    public function __construct(
        private readonly MysqlTypeNormalizer $normalizer = new MysqlTypeNormalizer(),
    ) {}

    public function diff(SchemaModel $declared, SchemaModel $existing): SchemaPlan
    {
        $tables = [];
        foreach ($declared->tables as $name => $declaredTable) {
            $existingTable = $existing->tables[$name] ?? null;
            if ($existingTable === null) {
                $tables[$name] = new TableDiff(
                    kind: TableDiff::KIND_CREATE,
                    declared: $declaredTable,
                );
                continue;
            }
            $tables[$name] = $this->diffTable($declaredTable, $existingTable);
        }
        return new SchemaPlan($tables);
    }

    private function diffTable(TableModel $declared, TableModel $existing): TableDiff
    {
        $columnsToAdd = [];
        $columnsToChange = [];
        $columnsRenamed = [];
        $columnsToDrop = [];

        $existingByName = $existing->columns;
        $consumedExisting = [];

        foreach ($declared->columns as $name => $declaredCol) {
            if ($declaredCol->renamedFrom !== null && isset($existingByName[$declaredCol->renamedFrom])) {
                $oldCol = $existingByName[$declaredCol->renamedFrom];
                $consumedExisting[$declaredCol->renamedFrom] = true;
                $columnsRenamed[] = [$oldCol, $declaredCol];
                continue;
            }
            if (!isset($existingByName[$name])) {
                $columnsToAdd[] = $declaredCol;
                continue;
            }
            $consumedExisting[$name] = true;
            $oldCol = $existingByName[$name];
            if (!$this->columnsEqual($declaredCol, $oldCol)) {
                $columnsToChange[] = [$oldCol, $declaredCol];
            }
        }
        $unmanaged = array_flip($declared->unmanagedColumns);
        foreach ($existingByName as $name => $existingCol) {
            if (isset($consumedExisting[$name])) continue;
            if (isset($unmanaged[$name])) continue;
            $columnsToDrop[] = $existingCol;
        }

        $primaryKeyToAdd = null;
        $primaryKeyToDrop = null;
        $primaryKeyChanged = false;
        if (!$this->primaryKeysEqual($declared->primaryKey, $existing->primaryKey)) {
            $primaryKeyChanged = true;
            if ($existing->primaryKey !== null) {
                $primaryKeyToDrop = $existing->primaryKey;
            }
            if ($declared->primaryKey !== null) {
                $primaryKeyToAdd = $declared->primaryKey;
            }
        }

        [$uniqueAdd, $uniqueDrop] = $this->diffUniqueKeys($declared->uniqueKeys, $existing->uniqueKeys);
        [$indexAdd, $indexDrop] = $this->diffIndexes($declared->indexes, $existing->indexes);
        [$fkAdd, $fkDrop] = $this->diffForeignKeys($declared->foreignKeys, $existing->foreignKeys);

        $hasChanges = $columnsToAdd || $columnsToChange || $columnsRenamed || $columnsToDrop
            || $primaryKeyChanged
            || $uniqueAdd || $uniqueDrop
            || $indexAdd || $indexDrop
            || $fkAdd || $fkDrop;

        return new TableDiff(
            kind: $hasChanges ? TableDiff::KIND_ALTER : TableDiff::KIND_NOOP,
            declared: $declared,
            columnsToAdd: $columnsToAdd,
            columnsToChange: $columnsToChange,
            columnsRenamed: $columnsRenamed,
            columnsToDrop: $columnsToDrop,
            primaryKeyChanged: $primaryKeyChanged,
            primaryKeyToAdd: $primaryKeyToAdd,
            primaryKeyToDrop: $primaryKeyToDrop,
            uniqueKeysToAdd: $uniqueAdd,
            uniqueKeysToDrop: $uniqueDrop,
            indexesToAdd: $indexAdd,
            indexesToDrop: $indexDrop,
            foreignKeysToAdd: $fkAdd,
            foreignKeysToDrop: $fkDrop,
        );
    }

    private function columnsEqual(ColumnModel $a, ColumnModel $b): bool
    {
        $ca = $this->normalizer->canonicalize($a);
        $cb = $this->normalizer->canonicalize($b);
        return $ca->type === $cb->type
            && $ca->length === $cb->length
            && $ca->precision === $cb->precision
            && $ca->scale === $cb->scale
            && $ca->unsigned === $cb->unsigned
            && $ca->nullable === $cb->nullable
            && $ca->hasDefault === $cb->hasDefault
            && (!$ca->hasDefault || $ca->default === $cb->default)
            && $ca->defaultExpression === $cb->defaultExpression
            && $ca->onUpdateExpression === $cb->onUpdateExpression
            && $ca->autoIncrement === $cb->autoIncrement
            && $this->nullableStringEqual($ca->comment, $cb->comment)
            && $this->enumValuesEqual($ca->enumValues, $cb->enumValues);
    }

    private function nullableStringEqual(?string $a, ?string $b): bool
    {
        return ($a ?? '') === ($b ?? '');
    }

    /**
     * @param string[]|null $a
     * @param string[]|null $b
     */
    private function enumValuesEqual(?array $a, ?array $b): bool
    {
        if ($a === null && $b === null) return true;
        if ($a === null || $b === null) return false;
        return $a === $b;
    }

    private function primaryKeysEqual(?PrimaryKeyModel $a, ?PrimaryKeyModel $b): bool
    {
        if ($a === null && $b === null) return true;
        if ($a === null || $b === null) return false;
        return $a->columns === $b->columns;
    }

    /**
     * @param array<string, UniqueKeyModel> $declared
     * @param array<string, UniqueKeyModel> $existing
     * @return array{0: UniqueKeyModel[], 1: UniqueKeyModel[]}
     */
    private function diffUniqueKeys(array $declared, array $existing): array
    {
        $add = [];
        $drop = [];
        foreach ($declared as $name => $u) {
            if (!isset($existing[$name]) || $existing[$name]->columns !== $u->columns) {
                $add[] = $u;
            }
        }
        foreach ($existing as $name => $u) {
            if (!isset($declared[$name]) || $declared[$name]->columns !== $u->columns) {
                $drop[] = $u;
            }
        }
        return [$add, $drop];
    }

    /**
     * @param array<string, IndexModel> $declared
     * @param array<string, IndexModel> $existing
     * @return array{0: IndexModel[], 1: IndexModel[]}
     */
    private function diffIndexes(array $declared, array $existing): array
    {
        $add = [];
        $drop = [];
        foreach ($declared as $name => $i) {
            if (!isset($existing[$name])
                || $existing[$name]->columns !== $i->columns
                || $existing[$name]->type !== $i->type
            ) {
                $add[] = $i;
            }
        }
        foreach ($existing as $name => $i) {
            if (!isset($declared[$name])
                || $declared[$name]->columns !== $i->columns
                || $declared[$name]->type !== $i->type
            ) {
                $drop[] = $i;
            }
        }
        return [$add, $drop];
    }

    /**
     * @param array<string, ForeignKeyModel> $declared
     * @param array<string, ForeignKeyModel> $existing
     * @return array{0: ForeignKeyModel[], 1: ForeignKeyModel[]}
     */
    private function diffForeignKeys(array $declared, array $existing): array
    {
        $add = [];
        $drop = [];
        foreach ($declared as $name => $f) {
            $e = $existing[$name] ?? null;
            if ($e === null
                || $e->columns !== $f->columns
                || $e->referencedTable !== $f->referencedTable
                || $e->referencedColumns !== $f->referencedColumns
                || $e->onDelete !== $f->onDelete
                || $e->onUpdate !== $f->onUpdate
            ) {
                $add[] = $f;
            }
        }
        foreach ($existing as $name => $f) {
            $d = $declared[$name] ?? null;
            if ($d === null
                || $d->columns !== $f->columns
                || $d->referencedTable !== $f->referencedTable
                || $d->referencedColumns !== $f->referencedColumns
                || $d->onDelete !== $f->onDelete
                || $d->onUpdate !== $f->onUpdate
            ) {
                $drop[] = $f;
            }
        }
        return [$add, $drop];
    }
}
