<?php

namespace Flyokai\SearchCriteria\Compiler\Stage;

use Flyokai\DbSchema\Model\TableModel;
use Flyokai\SearchCriteria\Exception\InvalidFieldException;

/**
 * Validates that unprefixed field names exist as columns on the base table.
 *
 * JSON column restriction (isNull/isNotNull only) is enforced at the SelectCompiler
 * level where the operator is known.
 */
class BaseColumnValidator
{
    /**
     * @param string[] $baseFields
     * @param string $baseAlias The base table's alias (prefixed fields matching this are treated as base fields)
     */
    public function validate(array $baseFields, TableModel $baseTable, string $baseAlias): void
    {
        foreach ($baseFields as $field) {
            if (!isset($baseTable->columns[$field])) {
                throw new InvalidFieldException(
                    "Field '{$field}' does not exist on table '{$baseTable->name}' (alias '{$baseAlias}')"
                );
            }
        }
    }
}
