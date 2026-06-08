<?php

namespace Flyokai\ApplicationCore\Setup;

use Amp\Injector\Container;
use Amp\Injector\Provider;
use Symfony\Component\Console\Helper\HelperInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Flyokai\ApplicationCore\Setup\Step\Patrol as StepPatrol;
use Flyokai\ApplicationCore\Setup\Step\Installed as InstalledSteps;

interface Context
{
    public function getInstalledSteps(): InstalledSteps;
    public function getStepPatrol(): StepPatrol;

    /**
     * @return Container<Provider>
     */
    public function getDiContainer(): Container;
    public function getInput(): InputInterface;
    public function getOutput(): OutputInterface;
    public function getQuestion(): QuestionHelper;
    public function getInputHelper(string $name): HelperInterface;
}
