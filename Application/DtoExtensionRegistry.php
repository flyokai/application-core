<?php

namespace Flyokai\ApplicationCore\Application;

use Flyokai\DataMate\DtoExtensionConfig;

/**
 * Registry of DTO extensions, keyed by base Solid/Draft class.
 *
 * Built once at boot from an iterable of {@see DtoExtensionConfig} records.
 * Hosts contribute their extensions in whichever shape their DI container
 * supports — under Flyokai's AMPHP Injector it's a `Composition` (which is
 * itself iterable); under sync Symfony DI it's a plain array. Both satisfy
 * the `iterable` constructor type.
 */
class DtoExtensionRegistry
{
    /** @var array<class-string, list<DtoExtensionConfig>> keyed by base Solid class */
    private array $bySolid = [];

    /** @var array<class-string, list<DtoExtensionConfig>> keyed by base Draft class */
    private array $byDraft = [];

    /**
     * @param iterable<DtoExtensionConfig> $extensions
     */
    public function __construct(iterable $extensions)
    {
        foreach ($extensions as $config) {
            /** @var DtoExtensionConfig $config */
            $this->bySolid[$config->baseSolidClass][] = $config;
            $this->byDraft[$config->baseDraftClass][] = $config;
        }
    }

    /**
     * @param class-string $solidClass
     * @return list<DtoExtensionConfig>
     */
    public function forSolid(string $solidClass): array
    {
        return $this->bySolid[$solidClass] ?? [];
    }

    /**
     * @param class-string $draftClass
     * @return list<DtoExtensionConfig>
     */
    public function forDraft(string $draftClass): array
    {
        return $this->byDraft[$draftClass] ?? [];
    }

    /**
     * @param class-string $dtoClass Solid or Draft class
     * @return list<DtoExtensionConfig>
     */
    public function forDto(string $dtoClass): array
    {
        return $this->bySolid[$dtoClass] ?? $this->byDraft[$dtoClass] ?? [];
    }
}
