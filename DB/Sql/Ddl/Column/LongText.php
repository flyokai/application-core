<?php

namespace Flyokai\ApplicationCore\DB\Sql\Ddl\Column;

use Laminas\Db\Sql\Ddl\Column\AbstractLengthColumn;

class LongText extends AbstractLengthColumn
{
    /** @var string */
    protected $type = 'LONGTEXT';
}
