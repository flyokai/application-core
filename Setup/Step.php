<?php

namespace Flyokai\ApplicationCore\Setup;

interface Step
{
    public function execute(Context $context): void;
}
