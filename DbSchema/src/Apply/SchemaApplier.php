<?php

namespace Flyokai\DbSchema\Apply;

use Flyokai\DbSchema\Diff\SchemaPlan;
use Flyokai\DbSchema\Diff\TableDiff;
use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\PrimaryKeyModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\DbSchema\Model\UniqueKeyModel;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Ddl;
use Laminas\Db\Sql\Ddl\Constraint;
use Laminas\Db\Sql\Sql;

/**
 * Applies a SchemaPlan in two passes:
 *  1. CREATE TABLE (without foreign keys) and ALTER TABLE (column/index/unique changes)
 *  2. ADD / DROP FOREIGN KEYs
 *
 * Two passes avoid ordering issues when tables reference each other and match
 * the pattern used by Magento declarative schema. Destructive operations are
 * filtered out unless $allowDestructive is true.
 */
class SchemaApplier
{
    private readonly Sql $sql;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly ColumnFactory $columnFactory = new ColumnFactory(),
    ) {
        $this->sql = new Sql($adapter);
    }

    public function apply(SchemaPlan $plan, bool $allowDestructive): ApplyResult
    {
        $applied = [];
        $skipped = [];

        // Pass 1: structure changes (no FKs).
        foreach ($plan->tables as $diff) {
            if ($diff->kind === TableDiff::KIND_NOOP) {
                continue;
            }
            if ($diff->kind === TableDiff::KIND_CREATE) {
                $this->runCreateTable($diff->declared);
                $applied[] = sprintf('CREATE TABLE %s', $diff->declared->name);
                continue;
            }
            // KIND_ALTER
            $this->runAlterStructural($diff, $allowDestructive, $applied, $skipped);
        }

        // Pass 2: FK additions (for all tables, including just-created ones).
        foreach ($plan->tables as $diff) {
            $this->runForeignKeyChanges($diff, $allowDestructive, $applied, $skipped);
        }

        return new ApplyResult($applied, $skipped);
    }

    private function runCreateTable(TableModel $t): void
    {
        $create = new Ddl\CreateTable($t->name);
        foreach ($t->columns as $col) {
            $create->addColumn($this->columnFactory->build($col));
        }
        if ($t->primaryKey !== null) {
            $create->addConstraint(new Constraint\PrimaryKey($t->primaryKey->columns));
        }
        foreach ($t->uniqueKeys as $uk) {
            $create->addConstraint(new Constraint\UniqueKey($uk->columns, $uk->name));
        }
        foreach ($t->indexes as $idx) {
            $create->addConstraint(new Ddl\Index\Index($idx->columns, $idx->name));
        }
        // Foreign keys are added in pass 2.
        $this->exec($this->sql->buildSqlString($create));
    }

    private function runAlterStructural(
        TableDiff $diff,
        bool $allowDestructive,
        array &$applied,
        array &$skipped,
    ): void {
        $alter = new Ddl\AlterTable($diff->declared->name);
        $haveAdditive = false;
        $haveDestructive = false;

        foreach ($diff->columnsToAdd as $col) {
            $alter->addColumn($this->columnFactory->build($col));
            $applied[] = sprintf('%s: ADD COLUMN %s', $diff->declared->name, $col->name);
            $haveAdditive = true;
        }
        foreach ($diff->columnsToChange as [$old, $new]) {
            $alter->changeColumn($old->name, $this->columnFactory->build($new));
            $applied[] = sprintf('%s: MODIFY COLUMN %s', $diff->declared->name, $new->name);
            $haveAdditive = true;
        }
        foreach ($diff->columnsRenamed as [$old, $new]) {
            $alter->changeColumn($old->name, $this->columnFactory->build($new));
            $applied[] = sprintf('%s: CHANGE COLUMN %s -> %s', $diff->declared->name, $old->name, $new->name);
            $haveAdditive = true;
        }

        if ($diff->primaryKeyToDrop !== null && $allowDestructive) {
            $this->exec(sprintf('ALTER TABLE %s DROP PRIMARY KEY', $this->quote($diff->declared->name)));
            $applied[] = sprintf('%s: DROP PRIMARY KEY', $diff->declared->name);
        } elseif ($diff->primaryKeyToDrop !== null) {
            $skipped[] = sprintf('%s: DROP PRIMARY KEY', $diff->declared->name);
            $haveDestructive = true;
        }

        if ($diff->primaryKeyToAdd !== null) {
            $alter->addConstraint(new Constraint\PrimaryKey($diff->primaryKeyToAdd->columns));
            $applied[] = sprintf('%s: ADD PRIMARY KEY (%s)', $diff->declared->name, implode(',', $diff->primaryKeyToAdd->columns));
            $haveAdditive = true;
        }

        /** @var UniqueKeyModel $uk */
        foreach ($diff->uniqueKeysToDrop as $uk) {
            if ($allowDestructive) {
                $alter->dropIndex($uk->name);
                $applied[] = sprintf('%s: DROP UNIQUE %s', $diff->declared->name, $uk->name);
                $haveAdditive = true;
            } else {
                $skipped[] = sprintf('%s: DROP UNIQUE %s', $diff->declared->name, $uk->name);
                $haveDestructive = true;
            }
        }
        foreach ($diff->uniqueKeysToAdd as $uk) {
            $alter->addConstraint(new Constraint\UniqueKey($uk->columns, $uk->name));
            $applied[] = sprintf('%s: ADD UNIQUE %s (%s)', $diff->declared->name, $uk->name, implode(',', $uk->columns));
            $haveAdditive = true;
        }

        foreach ($diff->indexesToDrop as $idx) {
            if ($allowDestructive) {
                $alter->dropIndex($idx->name);
                $applied[] = sprintf('%s: DROP INDEX %s', $diff->declared->name, $idx->name);
                $haveAdditive = true;
            } else {
                $skipped[] = sprintf('%s: DROP INDEX %s', $diff->declared->name, $idx->name);
                $haveDestructive = true;
            }
        }
        foreach ($diff->indexesToAdd as $idx) {
            $alter->addConstraint(new Ddl\Index\Index($idx->columns, $idx->name));
            $applied[] = sprintf('%s: ADD INDEX %s (%s)', $diff->declared->name, $idx->name, implode(',', $idx->columns));
            $haveAdditive = true;
        }

        foreach ($diff->columnsToDrop as $col) {
            if ($allowDestructive) {
                $alter->dropColumn($col->name);
                $applied[] = sprintf('%s: DROP COLUMN %s', $diff->declared->name, $col->name);
                $haveAdditive = true;
            } else {
                $skipped[] = sprintf('%s: DROP COLUMN %s', $diff->declared->name, $col->name);
                $haveDestructive = true;
            }
        }

        if ($haveAdditive) {
            $this->exec($this->sql->buildSqlString($alter));
        }
    }

    private function runForeignKeyChanges(
        TableDiff $diff,
        bool $allowDestructive,
        array &$applied,
        array &$skipped,
    ): void {
        $tableName = $diff->declared->name;

        // On a fresh CREATE, the declared table's FKs all need to be added.
        $toAdd = $diff->foreignKeysToAdd;
        if ($diff->kind === TableDiff::KIND_CREATE) {
            $toAdd = array_values($diff->declared->foreignKeys);
        }

        foreach ($diff->foreignKeysToDrop as $fk) {
            if ($allowDestructive) {
                $this->exec(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->quote($tableName),
                    $this->quote($fk->name)
                ));
                $applied[] = sprintf('%s: DROP FOREIGN KEY %s', $tableName, $fk->name);
            } else {
                $skipped[] = sprintf('%s: DROP FOREIGN KEY %s', $tableName, $fk->name);
            }
        }

        if (!$toAdd) {
            return;
        }

        $alter = new Ddl\AlterTable($tableName);
        foreach ($toAdd as $fk) {
            /** @var ForeignKeyModel $fk */
            $alter->addConstraint(new Constraint\ForeignKey(
                $fk->name,
                $fk->columns,
                $fk->referencedTable,
                $fk->referencedColumns,
                $fk->onDelete,
                $fk->onUpdate,
            ));
            $applied[] = sprintf(
                '%s: ADD FOREIGN KEY %s (%s) REFERENCES %s(%s)',
                $tableName,
                $fk->name,
                implode(',', $fk->columns),
                $fk->referencedTable,
                implode(',', $fk->referencedColumns),
            );
        }
        $this->exec($this->sql->buildSqlString($alter));
    }

    private function exec(string $sql): void
    {
        $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
    }

    private function quote(string $identifier): string
    {
        return $this->adapter->getPlatform()->quoteIdentifier($identifier);
    }
}
