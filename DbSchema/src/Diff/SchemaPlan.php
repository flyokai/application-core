<?php

namespace Flyokai\DbSchema\Diff;

class SchemaPlan
{
    public function __construct(
        /** @var array<string, TableDiff> keyed by table name */
        public readonly array $tables,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->tables as $diff) {
            if ($diff->kind !== TableDiff::KIND_NOOP) {
                return false;
            }
        }
        return true;
    }
}
