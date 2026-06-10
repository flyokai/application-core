<?php

namespace Flyokai\DbSchema\Discovery;

/**
 * Locates a registered package's filesystem root and parses its composer.json
 * autoload.psr-4 entries. The package root is discovered by walking up from
 * the ModuleBootstrap class file until a composer.json is found.
 */
class PackagePathResolver
{
    /** @var array<class-string, string> cache keyed by ModuleBootstrap class */
    private array $rootCache = [];

    /** @var array<string, array<string, string>> cache keyed by package root dir */
    private array $psr4Cache = [];

    /**
     * @param class-string $bootstrapClass
     * @return string|null Absolute path to the package root, or null if not found.
     */
    public function resolvePackageRoot(string $bootstrapClass): ?string
    {
        if (isset($this->rootCache[$bootstrapClass])) {
            return $this->rootCache[$bootstrapClass];
        }
        if (!class_exists($bootstrapClass)) {
            return null;
        }
        $file = (new \ReflectionClass($bootstrapClass))->getFileName();
        if (!$file) {
            return null;
        }
        $dir = dirname($file);
        while ($dir !== '/' && $dir !== '' && $dir !== '.') {
            if (is_file($dir . '/composer.json')) {
                return $this->rootCache[$bootstrapClass] = $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }

    /**
     * Parse autoload.psr-4 for a package. Returns map of namespace-prefix => absolute directory.
     *
     * @return array<string, string>
     */
    public function readPsr4Roots(string $packageRoot): array
    {
        if (isset($this->psr4Cache[$packageRoot])) {
            return $this->psr4Cache[$packageRoot];
        }
        $composerFile = $packageRoot . '/composer.json';
        if (!is_file($composerFile)) {
            return $this->psr4Cache[$packageRoot] = [];
        }
        $json = json_decode(file_get_contents($composerFile), true);
        $psr4 = $json['autoload']['psr-4'] ?? [];
        $roots = [];
        foreach ($psr4 as $prefix => $dir) {
            $roots[rtrim($prefix, '\\') . '\\'] = rtrim($packageRoot . '/' . $dir, '/');
        }
        return $this->psr4Cache[$packageRoot] = $roots;
    }
}
