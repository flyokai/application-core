<?php

namespace Flyokai\ApplicationCore\Application\Config;

use Laminas\Config\Config as LaminasConfig;
use Flyokai\ApplicationCore\Application\Config;

class ConfigPile implements Config
{
    private static \stdClass $null;
    protected bool $isModified = false;
    public function __construct(
        public readonly LaminasConfig $default = new LaminasConfig([], true),
        public readonly LaminasConfig $local = new LaminasConfig([], true)
    ) {
    }

    public static function null(): \stdClass
    {
        return self::$null ??= new \stdClass;
    }

    public function mergeLocal(LaminasConfig $config, bool $initial = false): self
    {
        if (!$initial) $origLocal = $this->local->toArray();
        $this->local->merge($config);
        if (!$initial && $origLocal != $this->local->toArray()) {
            $this->isModified = true;
        }
        return $this;
    }

    public function mergeDefault(LaminasConfig $config): self
    {
        $this->default->merge($config);
        return $this;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->_get($this->local, $path);
        if ($value === self::null()) {
            $value = $this->_get($this->default, $path);
        }
        return $value === self::null() ? $default : $value;
    }

    public function getLocal(string $path, mixed $default = null): mixed
    {
        $value = $this->_get($this->local, $path);
        return $value === self::null() ? $default : $value;
    }

    public function getDefault(string $path, mixed $default = null): mixed
    {
        $value = $this->_get($this->default, $path);
        return $value === self::null() ? $default : $value;
    }

    protected function _get(LaminasConfig $config, string $path): mixed
    {
        $path = $this->splitPath($path);
        $null = self::null();
        $value = $config;
        do {
            $name = array_shift($path);
            if ($name === null) break;
            $value = $value->get($name, $null);
        } while ($value!==$null && !empty($path) && $value instanceof LaminasConfig);
        if (!empty($path)) {
            $value = $null;
        }
        return $value;
    }

    public function set(string $path, mixed $value): self
    {
        $__path = $this->splitPath($path);
        $name = array_shift($__path);
        $oldValue = $this->local->$name ?? [];
        if ($oldValue instanceof LaminasConfig) {
            $oldValue = $oldValue->toArray();
        }
        while (!empty($__path)) {
            $__k = array_pop($__path);
            $value = [$__k => $value];
        }
        $value = array_replace_recursive($oldValue, $value);
        $this->local->$name = $value;
        if ($oldValue !== $value) {
            $this->isModified = true;
        }
        return $this;
    }

    public function has(string $path): bool
    {
        return $this->hasLocal($path)
            || $this->hasDefault($path)
        ;
    }

    public function hasLocal(string $path): bool
    {
        return $this->_get($this->local, $path) !== self::null();
    }

    public function hasDefault(string $path): bool
    {
        return $this->_get($this->default, $path) !== self::null();
    }


    /**
     * @param string $path
     * @return string[]
     */
    private function splitPath(string $path): array
    {
        $__inPath = explode('/', $path);
        $__outPath = array_filter(array_map('trim', $__inPath));
        if (count($__outPath) != count($__inPath) || empty($__outPath)) {
            throw new \InvalidArgumentException('Invalid config path');
        }
        return $__outPath;
    }

    public function isModified(): bool
    {
        return $this->isModified;
    }

}
