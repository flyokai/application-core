<?php

namespace Flyokai\SearchCriteria\Compiler\Stage;

use Flyokai\SearchCriteria\Criteria\AndGroup;
use Flyokai\SearchCriteria\Criteria\CriteriaNode;
use Flyokai\SearchCriteria\Criteria\FieldPredicate;
use Flyokai\SearchCriteria\Criteria\NotGroup;
use Flyokai\SearchCriteria\Criteria\OrGroup;
use Flyokai\SearchCriteria\Criteria\SearchCriteria;

/**
 * Walks the criteria tree and collects all referenced field strings,
 * split into base-table fields and prefixed (alias.col) fields.
 */
class FieldCollector
{
    /**
     * @return array{base: string[], prefixed: array<string, string[]>}
     *   base: fields without a dot (e.g. "status", "age")
     *   prefixed: keyed by alias, values are column names (e.g. ["role" => ["role"], "user_extra" => ["is_certified"]])
     */
    public function collect(SearchCriteria $criteria): array
    {
        $base = [];
        $prefixed = [];

        if ($criteria->filter !== null) {
            $this->walk($criteria->filter, $base, $prefixed);
        }

        foreach ($criteria->sort as $sort) {
            $this->addField($sort->field, $base, $prefixed);
        }

        return ['base' => array_unique($base), 'prefixed' => $prefixed];
    }

    private function walk(CriteriaNode $node, array &$base, array &$prefixed): void
    {
        if ($node instanceof FieldPredicate) {
            $this->addField($node->field, $base, $prefixed);
            return;
        }

        if ($node instanceof AndGroup || $node instanceof OrGroup) {
            foreach ($node->children as $child) {
                $this->walk($child, $base, $prefixed);
            }
            return;
        }

        if ($node instanceof NotGroup) {
            $this->walk($node->child, $base, $prefixed);
        }
    }

    private function addField(string $field, array &$base, array &$prefixed): void
    {
        $dot = strpos($field, '.');
        if ($dot === false) {
            $base[] = $field;
        } else {
            $alias = substr($field, 0, $dot);
            $col = substr($field, $dot + 1);
            if (!isset($prefixed[$alias])) {
                $prefixed[$alias] = [];
            }
            if (!in_array($col, $prefixed[$alias], true)) {
                $prefixed[$alias][] = $col;
            }
        }
    }
}
