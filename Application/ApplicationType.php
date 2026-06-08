<?php

namespace Flyokai\ApplicationCore\Application;

enum ApplicationType: string
{
    case Setup = 'setup';
    case Cluster = 'cluster';
    case Worker = 'worker';
    case Task = 'task';
    case Web = 'web';
    case Cli = 'cli';

    public const SEPARATOR = ':';

    public static function fromName(string $name): ApplicationType
    {
        $nameArray = explode(self::SEPARATOR, $name);
        $typeStr = array_pop($nameArray);
        return self::from($typeStr);
    }

    public function buildName(string $baseName): string
    {
        return $baseName.self::SEPARATOR.$this->value;
    }
}
