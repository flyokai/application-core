<?php

namespace Flyokai\DbSchema\Model;

class UniqueKeyModel extends ConstraintModel
{
    public function __construct(
        string $name,
        /** @var string[] */
        public readonly array $columns,
    ) {
        parent::__construct($name);
    }
}
