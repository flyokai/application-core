<?php

namespace Flyokai\ApplicationCore\DB\Sql\Ddl\Column;

use Laminas\Db\Sql\Ddl\Column\AbstractLengthColumn;

class MediumText extends AbstractLengthColumn
{
    /** @var string */
    protected $type = 'MEDIUMTEXT';
}
