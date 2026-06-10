<?php

namespace Flyokai\DbSchema\Model;

class IndexModel
{
    public const TYPE_BTREE = 'BTREE';
    public const TYPE_FULLTEXT = 'FULLTEXT';

    public function __construct(
        public readonly string $name,
        /** @var string[] */
        public readonly array $columns,
        public readonly string $type = self::TYPE_BTREE,
    ) {}
}
