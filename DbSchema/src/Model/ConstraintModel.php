<?php

namespace Flyokai\DbSchema\Model;

/**
 * Base for PrimaryKeyModel, UniqueKeyModel, ForeignKeyModel. PHP has no sealed
 * classes; treat this as an effective sum type — only the three subclasses are
 * produced by discovery/introspection and consumed by the differ.
 */
abstract class ConstraintModel
{
    public function __construct(
        public readonly string $name,
    ) {}
}
