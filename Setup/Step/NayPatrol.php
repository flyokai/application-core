<?php

namespace Flyokai\ApplicationCore\Setup\Step;

use Flyokai\ApplicationCore\Setup\Step;

class NayPatrol implements Patrol
{
    public function canExecute(Step $step): bool
    {
        return false;
    }
}
