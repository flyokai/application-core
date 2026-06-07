<?php

namespace Flyokai\DbSchema\Apply;

class ApplyResult
{
    public function __construct(
        /** @var string[] human-readable descriptions of applied operations */
        public readonly array $applied,
        /** @var string[] human-readable descriptions of destructive ops that were filtered out */
        public readonly array $skippedDestructive,
    ) {}
}
