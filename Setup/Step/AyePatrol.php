<?php

namespace Flyokai\ApplicationCore\Setup\Step;

use Flyokai\ApplicationCore\Setup\Step;

class AyePatrol implements Patrol
{
    public function canExecute(Step $step): bool
    {
        return true;
    }
}
