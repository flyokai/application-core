<?php

namespace Flyokai\DbSchema\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Table
{
    /**
     * @param string $name MySQL table name.
     * @param string|null $alias Logical alias for search criteria. Defaults to lowercased short class name minus "Solid" suffix.
     * @param string $engine Storage engine (InnoDB default).
     * @param string $charset Default charset for the table.
     * @param string $collation Default collation; propagates to columns unless overridden.
     * @param array<string, mixed> $options Extra Ddl\CreateTable options merged verbatim.
     * @param string|null $comment Optional table COMMENT clause.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $alias = null,
        public readonly string $engine = 'InnoDB',
        public readonly string $charset = 'utf8mb4',
        public readonly string $collation = 'utf8mb4_unicode_ci',
        public readonly array $options = [],
        public readonly ?string $comment = null,
    ) {}
}
