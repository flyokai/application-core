<?php

namespace Flyokai\ApplicationCore\Setup\Step;

use Flyokai\ApplicationCore\Setup\Step;
use function Flyokai\DataMate\className;

class Installed
{
    /**
     * @var array<class-string<Step>, bool>
     */
    protected array $installedSteps;

    /**
     * @param array<class-string<Step>, bool> $installedSteps
     */
    public function __construct(
        array $installedSteps = []
    ) {
        $this->installedSteps = $installedSteps;
    }

    /**
     * @param Step|class-string<Step> $step
     * @return $this
     */
    public function addStep(Step|string $step): self
    {
        if (($__cn = className($step))) {
            // @phpstan-ignore-next-line
            $this->installedSteps[$__cn] = true;
        }
        return $this;
    }

    public function isInstalled(Step|string $step): bool
    {
        return ($__cn = className($step))
            && array_key_exists($__cn, $this->installedSteps)
            && $this->installedSteps[$__cn];
    }

    /**
     * @return array<class-string<Step>, bool>
     */
    public function toArray(): array
    {
        return $this->installedSteps;
    }

    public function clear(): self
    {
        $this->installedSteps = [];
        return $this;
    }
}
