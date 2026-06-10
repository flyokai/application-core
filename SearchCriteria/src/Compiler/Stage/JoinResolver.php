<?php

namespace Flyokai\SearchCriteria\Compiler\Stage;

use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\SearchCriteria\Compiler\JoinEntry;
use Flyokai\SearchCriteria\Compiler\JoinPlan;
use Flyokai\SearchCriteria\Exception\AmbiguousForeignKeyException;
use Flyokai\SearchCriteria\Exception\InvalidFieldException;

/**
 * Resolves prefixed "alias.column" references into a JoinPlan.
 *
 * For each referenced alias:
 *  1. Looks up the target TableModel by alias in SchemaModel.
 *  2. Finds exactly one ForeignKey on the base table that references the target table.
 *     Throws on zero or multiple matches.
 *  3. Validates that the referenced columns exist on the target table.
 *  4. Rejects JSON columns on joined tables.
 */
class JoinResolver
{
    public function __construct(
        private readonly SchemaModel $schema,
    ) {}

    /**
     * @param array<string, string[]> $prefixedFields alias => [col, col, ...]
     * @param string $baseAlias alias of the base DTO
     */
    public function resolve(
        array $prefixedFields,
        TableModel $baseTable,
        string $baseAlias,
    ): JoinPlan {
        $joins = [];

        foreach ($prefixedFields as $alias => $columns) {
            // Prefix equal to the base alias is treated as base-table (already validated)
            if ($alias === $baseAlias) {
                continue;
            }

            $targetTable = $this->schema->tablesByAlias[$alias]
                ?? throw new InvalidFieldException(
                    "Unknown table alias '{$alias}' referenced in criteria. "
                    . "Available aliases: " . implode(', ', array_keys($this->schema->tablesByAlias))
                );

            // Find exactly one FK on base table that references the target table
            $matchingFks = [];
            foreach ($baseTable->foreignKeys as $fk) {
                if ($fk->referencedTable === $targetTable->name) {
                    $matchingFks[] = $fk;
                }
            }

            if (count($matchingFks) === 0) {
                throw new InvalidFieldException(
                    "No foreign key on table '{$baseTable->name}' (alias '{$baseAlias}') references "
                    . "table '{$targetTable->name}' (alias '{$alias}')"
                );
            }

            if (count($matchingFks) > 1) {
                $fkNames = array_map(fn($fk) => $fk->name, $matchingFks);
                throw new AmbiguousForeignKeyException(
                    "Multiple foreign keys on table '{$baseTable->name}' reference "
                    . "table '{$targetTable->name}' (alias '{$alias}'): "
                    . implode(', ', $fkNames)
                    . ". Disambiguation via #[Relation] is not yet supported."
                );
            }

            // Validate that all referenced columns exist on the target table
            foreach ($columns as $col) {
                if (!isset($targetTable->columns[$col])) {
                    throw new InvalidFieldException(
                        "Column '{$col}' does not exist on table '{$targetTable->name}' (alias '{$alias}')"
                    );
                }
                if ($targetTable->columns[$col]->type === 'json') {
                    throw new InvalidFieldException(
                        "Column '{$alias}.{$col}' is a JSON column on '{$targetTable->name}' "
                        . "and cannot be used in search criteria"
                    );
                }
            }

            $joins[$alias] = new JoinEntry(
                alias: $alias,
                table: $targetTable,
                foreignKey: $matchingFks[0],
                baseAlias: $baseAlias,
            );
        }

        // Sort by alias for deterministic SQL generation
        ksort($joins);

        return new JoinPlan($joins);
    }
}
