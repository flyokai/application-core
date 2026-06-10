<?php

namespace Flyokai\ApplicationCore\Setup;

abstract class AbstractStep implements Step
{
    use StepHelper;
    public function execute(Context $context): void
    {
        $this->beforeExecute($this, $context);
        $this->_execute($context);
        $this->afterExecute($this, $context);
    }
    abstract protected function _execute(Context $context): void;
}
