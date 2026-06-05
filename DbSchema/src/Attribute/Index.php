<?php

namespace Flyokai\DbSchema\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Index
{
    public const TYPE_BTREE = 'BTREE';
    public const TYPE_FULLTEXT = 'FULLTEXT';

    /**
     * @param string $name Index name.
     * @param string[] $columns Column names in index order.
     * @param string $type BTREE|FULLTEXT.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $type = self::TYPE_BTREE,
    ) {}
}
