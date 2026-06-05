<?php

namespace Flyokai\DbSchema\Attribute;

/**
 * Opt-in ENUM storage for a BackedEnum-typed parameter. Without this attribute,
 * a string-backed enum is stored as VARCHAR(64). MySQL ENUM reordering is destructive,
 * so use sparingly.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Enum
{
    /**
     * @param string[]|null $values Explicit enum value list. When null, the runtime
     *     reads $enum::cases() and uses ->value for each case.
     */
    public function __construct(
        public readonly ?array $values = null,
    ) {}
}
