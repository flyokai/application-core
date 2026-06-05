<?php

namespace Flyokai\SearchCriteria\Operator;

use Flyokai\SearchCriteria\Criteria\Operator;

/**
 * Registry for custom operator → predicate-factory mappings.
 *
 * Standard operators (eq, gt, in, etc.) are handled directly by SelectCompiler.
 * This registry is the extension point for non-standard operators (e.g. findInSet).
 */
class OperatorRegistry
{
    /** @var array<string, PredicateFactory> keyed by operator value string */
    private array $factories = [];

    public function register(string $operatorValue, PredicateFactory $factory): static
    {
        $this->factories[$operatorValue] = $factory;
        return $this;
    }

    public function getFactory(Operator $op): ?PredicateFactory
    {
        return $this->factories[$op->value] ?? null;
    }
}
