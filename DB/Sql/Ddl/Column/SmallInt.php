<?php

namespace Flyokai\ApplicationCore\DB\Sql\Ddl\Column;

use Laminas\Db\Sql\Ddl\Column\Integer;

class SmallInt extends Integer
{
    /** @var string */
    protected $type = 'SMALLINT';
}
