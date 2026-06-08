<?php

namespace Flyokai\DbSchema\Model;

/**
 * Normalized column description. Produced by both SchemaDiscovery (declarative)
 * and SchemaIntrospector (live DB), consumed by SchemaDiffer.
 *
 * Type tokens are canonical: int, tinyint, smallint, mediumint, bigint,
 * varchar, char, text, tinytext, mediumtext, longtext, json,
 * date, datetime, time, timestamp, decimal, float, double, boolean,
 * binary, varbinary, blob, tinyblob, mediumblob, longblob, enum.
 */
class ColumnModel
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $unsigned = false,
        public readonly bool $nullable = false,
        public readonly bool $hasDefault = false,
        public readonly mixed $default = null,
        public readonly ?string $defaultExpression = null,
        public readonly ?string $onUpdateExpression = null,
        public readonly bool $autoIncrement = false,
        public readonly ?string $comment = null,
        public readonly ?string $collation = null,
        /** @var string[]|null ENUM values when type === 'enum' */
        public readonly ?array $enumValues = null,
        public readonly ?string $renamedFrom = null,
    ) {}
}
