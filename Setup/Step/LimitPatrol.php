<?php

namespace Flyokai\ApplicationCore\Setup\Step;

use Flyokai\ApplicationCore\Setup\Step;
use Flyokai\ApplicationCore\Setup\StepContainer;

class LimitPatrol implements Patrol
{
    public function __construct(
        protected Installed $installedSteps
    ) {
    }

    public function canExecute(Step $step): bool
    {
        return $step instanceof Repeatable
            || $step instanceof StepContainer
            || !$this->installedSteps->isInstalled($step);
    }
}
