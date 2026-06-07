<?php

namespace Flyokai\DbSchema\Attribute;

/**
 * Marks a constructor property as "exists in the DTO, but the schema manager
 * must not touch the corresponding DB column": it is neither declared nor
 * dropped. Use for columns whose DB features (e.g. ON UPDATE CURRENT_TIMESTAMP,
 * generated columns, spatial types) cannot be represented by the current
 * attribute model, or for columns managed by a legacy imperative setup step.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Unmanaged
{
}
