<?php

namespace Flyokai\ApplicationCore\DB;

use Laminas\Db\Adapter\Adapter;

interface ConnectionPool
{
    /**
     * @return Adapter
     */
    public function createConnection(): Adapter;
}
