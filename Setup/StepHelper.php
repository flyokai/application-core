<?php

namespace Flyokai\ApplicationCore\Setup;

trait StepHelper
{
    protected function beforeExecute(Step $step, Context $context): void
    {
        $context->getOutput()->writeln(get_class($step));
    }
    protected function afterExecute(Step $step, Context $context): void
    {
        $context->getInstalledSteps()->addStep($step);
    }
}
