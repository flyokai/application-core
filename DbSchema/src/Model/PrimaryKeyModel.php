<?php

namespace Flyokai\DbSchema\Model;

class PrimaryKeyModel extends ConstraintModel
{
    public function __construct(
        /** @var string[] */
        public readonly array $columns,
    ) {
        parent::__construct('PRIMARY');
    }
}
