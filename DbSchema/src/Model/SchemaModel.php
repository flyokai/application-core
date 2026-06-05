<?php

namespace Flyokai\DbSchema\Model;

class SchemaModel
{
    /** @var array<string, TableModel> keyed by alias */
    public readonly array $tablesByAlias;

    /** @var array<class-string, string> DTO class → alias */
    public readonly array $dtoClassToAlias;

    public function __construct(
        /** @var array<string, TableModel> keyed by table name */
        public readonly array $tables,
        /** @var array<class-string, string> DTO class → table name */
        public readonly array $dtoClassToTableName = [],
    ) {
        $byAlias = [];
        $classToAlias = [];
        foreach ($this->tables as $table) {
            if ($table->alias !== null) {
                if (isset($byAlias[$table->alias])) {
                    throw new \LogicException(sprintf(
                        'Duplicate table alias "%s" declared by "%s" and "%s"',
                        $table->alias,
                        $byAlias[$table->alias]->name,
                        $table->name,
                    ));
                }
                $byAlias[$table->alias] = $table;
            }
            if ($table->sourceDtoClass !== null && $table->alias !== null) {
                $classToAlias[$table->sourceDtoClass] = $table->alias;
            }
        }
        $this->tablesByAlias = $byAlias;
        $this->dtoClassToAlias = $classToAlias;
    }
}
