<?php

namespace Flyokai\DbSchema\Attribute;

/**
 * On a class: declare the table's primary key, optionally composite.
 * On a parameter: promote that single property to the primary key column.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER)]
class PrimaryKey
{
    /**
     * @param string|string[]|null $columns Column name(s). Ignored when the attribute is placed on a parameter.
     * @param bool $autoIncrement Auto-increment the single column. Composite PKs cannot auto-increment.
     */
    public function __construct(
        public readonly string|array|null $columns = null,
        public readonly bool $autoIncrement = false,
    ) {}
}
