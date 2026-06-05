<?php

namespace Flyokai\ApplicationCore\Setup;

use Amp\Injector\Composition\Composition;

class StepContainer implements Step
{
    /**
     * @param Composition<string, Step> $steps
     */
    public function __construct(
        protected Composition $steps
    ) {
    }

    public function execute(Context $context): void
    {
        $context->getOutput()->writeln(self::class);
        foreach ($this->steps as $step) {
            if ($context->getStepPatrol()->canExecute($step)) {
                $this->executeStep($step, $context);
            }
        }
    }

    protected function executeStep(Step $step, Context $context): void
    {
        $step->execute($context);
    }

}
