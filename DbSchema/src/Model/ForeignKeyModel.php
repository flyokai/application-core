<?php

namespace Flyokai\DbSchema\Model;

class ForeignKeyModel extends ConstraintModel
{
    public const RESTRICT = 'RESTRICT';
    public const CASCADE = 'CASCADE';
    public const SET_NULL = 'SET NULL';
    public const NO_ACTION = 'NO ACTION';

    public function __construct(
        string $name,
        /** @var string[] */
        public readonly array $columns,
        public readonly string $referencedTable,
        /** @var string[] */
        public readonly array $referencedColumns,
        public readonly string $onDelete = self::RESTRICT,
        public readonly string $onUpdate = self::RESTRICT,
    ) {
        parent::__construct($name);
    }
}
