<?php

namespace Flyokai\DbSchema\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class UniqueKey
{
    /**
     * @param string $name Unique-constraint name.
     * @param string[] $columns Column names composing the unique tuple.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
    ) {}
}
