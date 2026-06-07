<?php

namespace Flyokai\ApplicationCore\Application;

use Laminas\Config\Config as LaminasConfig;

interface Config
{
    public function mergeLocal(LaminasConfig $config, bool $initial = false): self;
    public function mergeDefault(LaminasConfig $config): self;
    public function get(string $path, mixed $default = null): mixed;
    public function getLocal(string $path, mixed $default = null): mixed;
    public function getDefault(string $path, mixed $default = null): mixed;
    public function set(string $path, mixed $value): self;
    public function has(string $path): bool;
    public function hasLocal(string $path): bool;
    public function hasDefault(string $path): bool;
    public function isModified(): bool;
}
