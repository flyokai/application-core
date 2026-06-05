<?php

namespace Flyokai\DbSchema\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class ForeignKey
{
    public const RESTRICT = 'RESTRICT';
    public const CASCADE = 'CASCADE';
    public const SET_NULL = 'SET NULL';
    public const NO_ACTION = 'NO ACTION';

    /**
     * @param string $name Constraint name.
     * @param string[] $columns Local column names.
     * @param class-string $referencesDto Solid DTO class whose #[Table] is referenced.
     * @param string[] $referencesColumns Columns in the referenced table.
     * @param string $onDelete RESTRICT|CASCADE|SET NULL|NO ACTION.
     * @param string $onUpdate RESTRICT|CASCADE|SET NULL|NO ACTION.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $referencesDto,
        public readonly array $referencesColumns,
        public readonly string $onDelete = self::RESTRICT,
        public readonly string $onUpdate = self::RESTRICT,
    ) {}
}
