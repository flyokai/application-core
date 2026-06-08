<?php

namespace Flyokai\ApplicationCore\Setup\Step;

use Flyokai\ApplicationCore\Setup\Step;

interface Patrol
{
    public function canExecute(Step $step): bool;
}
