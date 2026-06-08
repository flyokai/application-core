<?php

namespace Flyokai\DbSchema\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Column
{
    public const NO_DEFAULT = "\0__no_default__\0";

    /**
     * Overrides any subset of the defaults inferred from the parameter's PHP type.
     * Every field is optional; unset fields fall back to inference.
     *
     * @param string|null $name DB column name; defaults to the constructor parameter name.
     * @param string|null $type Canonical type token (int|bigint|smallint|tinyint|varchar|char|text|mediumtext|longtext|json|date|datetime|time|timestamp|decimal|float|double|boolean|binary|varbinary|blob).
     * @param int|null $length Length for varchar/char/binary/integer display widths.
     * @param int|null $precision Decimal precision.
     * @param int|null $scale Decimal scale.
     * @param bool $unsigned Unsigned modifier for integer types.
     * @param bool|null $nullable Overrides the PHP type's nullability.
     * @param mixed $default Literal default value. Leave as NO_DEFAULT to use the PHP default (if any) or emit no default.
     * @param string|null $defaultExpression Raw SQL expression for the default (e.g. CURRENT_TIMESTAMP) — bypasses quoting.
     * @param string|null $onUpdateExpression Raw SQL for ON UPDATE clause (MySQL only supports CURRENT_TIMESTAMP here).
     * @param string|null $comment Column COMMENT clause.
     * @param string|null $collation Per-column collation override.
     * @param string|null $renamedFrom Previous column name used to emit CHANGE COLUMN instead of drop+add.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $unsigned = false,
        public readonly ?bool $nullable = null,
        public readonly mixed $default = self::NO_DEFAULT,
        public readonly ?string $defaultExpression = null,
        public readonly ?string $onUpdateExpression = null,
        public readonly ?string $comment = null,
        public readonly ?string $collation = null,
        public readonly ?string $renamedFrom = null,
    ) {}
}
