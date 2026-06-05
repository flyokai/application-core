<?php

namespace Flyokai\SearchCriteria\Repository;

use Flyokai\DataMate\Solid;

final readonly class ListResult
{
    /**
     * @param list<Solid> $items Hydrated Solid DTOs.
     * @param int|null $total Total matching rows (null when withTotal was false).
     */
    public function __construct(
        public array $items,
        public ?int $total = null,
    ) {}
}
