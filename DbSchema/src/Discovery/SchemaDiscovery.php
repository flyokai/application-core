<?php

namespace Flyokai\DbSchema\Discovery;

use Flyokai\DataMate\Attribute\Json;
use Flyokai\DataMate\Solid;
use Flyokai\DbSchema\Attribute\Column as ColumnAttr;
use Flyokai\DbSchema\Attribute\Enum as EnumAttr;
use Flyokai\DbSchema\Attribute\ForeignKey as ForeignKeyAttr;
use Flyokai\DbSchema\Attribute\Index as IndexAttr;
use Flyokai\DbSchema\Attribute\PrimaryKey as PrimaryKeyAttr;
use Flyokai\DbSchema\Attribute\Table as TableAttr;
use Flyokai\DbSchema\Attribute\UniqueKey as UniqueKeyAttr;
use Flyokai\DbSchema\Attribute\Unmanaged as UnmanagedAttr;
use Flyokai\DbSchema\Model\ColumnModel;
use Flyokai\DbSchema\Model\ForeignKeyModel;
use Flyokai\DbSchema\Model\IndexModel;
use Flyokai\DbSchema\Model\PrimaryKeyModel;
use Flyokai\DbSchema\Model\SchemaModel;
use Flyokai\DbSchema\Model\TableModel;
use Flyokai\DbSchema\Model\UniqueKeyModel;

/**
 * Scans registered packages for Solid DTOs tagged with #[Table] and builds a
 * declarative SchemaModel.
 *
 * Scope: only classes implementing Flyokai\DataMate\Solid and carrying #[Table]
 * are included. Everything else is skipped.
 */
class SchemaDiscovery
{
    /** @var array<class-string, string> */
    private array $dtoClassToTableName = [];

    public function __construct(
        private readonly PackagePathResolver $resolver = new PackagePathResolver(),
    ) {}

    /**
     * Discover #[Table] DTOs by scanning the PSR-4 roots of the given Flyokai
     * bootstrap modules. The caller supplies the module map (typically
     * \Flyokai\Application\Bootstrap\Registry::modules() in a cluster host) so
     * this class carries no dependency on the cluster-only module Registry —
     * sync hosts (Shopware embedding) use {@see discoverFromClasses()} instead.
     *
     * @param iterable<string, class-string> $modules packageName => ModuleBootstrap class
     */
    public function discoverFromModules(iterable $modules): SchemaModel
    {
        return $this->buildSchemaFromClasses($this->collectTaggedDtoClasses($modules));
    }

    /**
     * Build a SchemaModel from an explicit list of DTO classes — sidesteps
     * {@see discoverFromModules()} package scanning for hosts that
     * don't register Flyokai bootstrap modules (e.g. a Shopware plugin
     * embedding marketplace-core via Symfony DI).
     *
     * Validation is identical to {@see discoverFromModules()}: each class must be
     * tagged with #[Table], implement {@see Solid}, and not declare a
     * table name that another class in the same call has already claimed.
     *
     * @param iterable<class-string> $dtoClasses
     */
    public function discoverFromClasses(iterable $dtoClasses): SchemaModel
    {
        return $this->buildSchemaFromClasses($dtoClasses);
    }

    /**
     * Best-effort lookup for a FK target DTO that is not part of our managed
     * #[Table] set: if the class is loadable and carries its own #[Table]
     * attribute, return that table name; otherwise return null (caller skips
     * the FK constraint).
     */
    private function resolveExternalTableName(string $dtoClass): ?string
    {
        if (!class_exists($dtoClass)) {
            return null;
        }
        $attrs = (new \ReflectionClass($dtoClass))->getAttributes(TableAttr::class);
        if (!$attrs) {
            return null;
        }
        return $attrs[0]->newInstance()->name;
    }

    /**
     * @param iterable<class-string> $dtoClasses
     */
    private function buildSchemaFromClasses(iterable $dtoClasses): SchemaModel
    {
        /** @var array<string, TableModel> $tables */
        $tables = [];

        foreach ($dtoClasses as $dtoClass) {
            $rc = new \ReflectionClass($dtoClass);
            $tableAttr = $rc->getAttributes(TableAttr::class)[0]->newInstance();
            $this->dtoClassToTableName[$dtoClass] = $tableAttr->name;
        }

        foreach (array_keys($this->dtoClassToTableName) as $dtoClass) {
            $table = $this->buildTableModel(new \ReflectionClass($dtoClass));
            if (isset($tables[$table->name])) {
                throw new \LogicException(sprintf(
                    'Duplicate #[Table("%s")] declared by %s and %s',
                    $table->name,
                    $tables[$table->name]->sourceDtoClass,
                    $dtoClass
                ));
            }
            $tables[$table->name] = $table;
        }

        return new SchemaModel($tables, $this->dtoClassToTableName);
    }

    /**
     * @param iterable<string, class-string> $modules packageName => ModuleBootstrap class
     * @return iterable<class-string>
     */
    private function collectTaggedDtoClasses(iterable $modules): iterable
    {
        $seen = [];
        foreach ($modules as $packageName => $bootstrapClass) {
            $packageRoot = $this->resolver->resolvePackageRoot($bootstrapClass);
            if ($packageRoot === null) {
                continue;
            }
            $psr4 = $this->resolver->readPsr4Roots($packageRoot);
            foreach ($psr4 as $prefix => $dir) {
                foreach ($this->scanDirectory($dir) as $file) {
                    $fqcn = $this->fileToClassName($prefix, $dir, $file);
                    if ($fqcn === null || isset($seen[$fqcn])) {
                        continue;
                    }
                    if (!$this->hasTableMarker($file)) {
                        continue;
                    }
                    if (!class_exists($fqcn)) {
                        continue;
                    }
                    $rc = new \ReflectionClass($fqcn);
                    if ($rc->isAbstract() || $rc->isInterface() || $rc->isTrait()) {
                        continue;
                    }
                    if (!$rc->implementsInterface(Solid::class)) {
                        continue;
                    }
                    if (!$rc->getAttributes(TableAttr::class)) {
                        continue;
                    }
                    $seen[$fqcn] = true;
                    yield $fqcn;
                }
            }
        }
    }

    /**
     * @return iterable<string> absolute file paths ending in .php
     */
    private function scanDirectory(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Cheap substring pre-filter to skip files that can't possibly be Table-tagged.
     */
    private function hasTableMarker(string $file): bool
    {
        $content = @file_get_contents($file);
        if ($content === false) return false;
        return str_contains($content, 'DbSchema\\Attribute\\Table')
            || str_contains($content, 'Attribute\\Table')
            || str_contains($content, '#[Table');
    }

    private function fileToClassName(string $prefix, string $dir, string $file): ?string
    {
        $real = realpath($file);
        $root = realpath($dir);
        if ($real === false || $root === false || !str_starts_with($real, $root . '/')) {
            return null;
        }
        $rel = substr($real, strlen($root) + 1);
        if (!str_ends_with($rel, '.php')) {
            return null;
        }
        $rel = substr($rel, 0, -4);
        return $prefix . str_replace('/', '\\', $rel);
    }

    private function buildTableModel(\ReflectionClass $rc): TableModel
    {
        $tableAttr = $rc->getAttributes(TableAttr::class)[0]->newInstance();
        $ctor = $rc->getConstructor();
        if ($ctor === null) {
            throw new \LogicException(sprintf(
                '%s has no constructor; #[Table] DTOs must declare columns as promoted constructor parameters',
                $rc->getName()
            ));
        }

        /** @var array<string, ColumnModel> $columns */
        $columns = [];
        $unmanaged = [];
        $paramPkColumn = null;
        $paramPkAutoInc = false;

        foreach ($ctor->getParameters() as $param) {
            if (!$param->isPromoted()) {
                continue;
            }
            if ($param->getAttributes(UnmanagedAttr::class)) {
                // Preserve the DB column name so the differ can skip it in the drop comparison.
                $colAttr = $param->getAttributes(\Flyokai\DbSchema\Attribute\Column::class);
                if ($colAttr) {
                    /** @var \Flyokai\DbSchema\Attribute\Column $c */
                    $c = $colAttr[0]->newInstance();
                    $unmanaged[] = $c->name ?? $param->getName();
                } else {
                    $unmanaged[] = $param->getName();
                }
                continue;
            }
            $column = $this->buildColumn($rc, $param);
            $columns[$column->name] = $column;

            $paramPkAttr = $param->getAttributes(PrimaryKeyAttr::class);
            if ($paramPkAttr) {
                if ($paramPkColumn !== null) {
                    throw new \LogicException(sprintf(
                        '%s declares #[PrimaryKey] on multiple parameters — use the class-level form for composite keys',
                        $rc->getName()
                    ));
                }
                /** @var PrimaryKeyAttr $inst */
                $inst = $paramPkAttr[0]->newInstance();
                $paramPkColumn = $column->name;
                $paramPkAutoInc = $inst->autoIncrement;
            }
        }

        $primaryKey = $this->resolvePrimaryKey($rc, $columns, $paramPkColumn, $paramPkAutoInc);
        if ($primaryKey !== null) {
            foreach ($primaryKey->columns as $pkCol) {
                if (!isset($columns[$pkCol])) {
                    throw new \LogicException(sprintf(
                        '%s #[PrimaryKey] references unknown column "%s"',
                        $rc->getName(),
                        $pkCol
                    ));
                }
            }
        }

        $uniqueKeys = [];
        foreach ($rc->getAttributes(UniqueKeyAttr::class) as $ua) {
            /** @var UniqueKeyAttr $u */
            $u = $ua->newInstance();
            $uniqueKeys[$u->name] = new UniqueKeyModel($u->name, $u->columns);
        }

        $indexes = [];
        foreach ($rc->getAttributes(IndexAttr::class) as $ia) {
            /** @var IndexAttr $i */
            $i = $ia->newInstance();
            $indexes[$i->name] = new IndexModel($i->name, $i->columns, $i->type);
        }

        $foreignKeys = [];
        foreach ($rc->getAttributes(ForeignKeyAttr::class) as $fa) {
            /** @var ForeignKeyAttr $f */
            $f = $fa->newInstance();
            $referencedTable = $this->dtoClassToTableName[$f->referencesDto]
                ?? $this->resolveExternalTableName($f->referencesDto);
            if ($referencedTable === null) {
                // FK target DTO is neither in our managed set nor independently
                // loadable with a #[Table] attribute. Skip the FK rather than
                // failing the whole apply — the column itself is still declared
                // by the source DTO, just without the referential constraint.
                // This is the right behaviour for sync-host setups (e.g. Local
                // marketplace embedded in Shopware) where the referenced table
                // lives in a different ownership domain and is provisioned out
                // of band — the soft cross-domain reference is acceptable.
                continue;
            }
            $foreignKeys[$f->name] = new ForeignKeyModel(
                $f->name,
                $f->columns,
                $referencedTable,
                $f->referencesColumns,
                $f->onDelete,
                $f->onUpdate,
            );
        }

        // Resolve alias: explicit from attribute, or derive from short class name minus "Solid" suffix.
        $alias = $tableAttr->alias;
        if ($alias === null) {
            $shortName = $rc->getShortName();
            if (str_ends_with($shortName, 'Solid')) {
                $shortName = substr($shortName, 0, -5);
            }
            $alias = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
        }

        return new TableModel(
            name: $tableAttr->name,
            columns: $columns,
            primaryKey: $primaryKey,
            uniqueKeys: $uniqueKeys,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            engine: $tableAttr->engine,
            charset: $tableAttr->charset,
            collation: $tableAttr->collation,
            comment: $tableAttr->comment,
            sourceDtoClass: $rc->getName(),
            unmanagedColumns: $unmanaged,
            alias: $alias,
        );
    }

    private function buildColumn(\ReflectionClass $rc, \ReflectionParameter $param): ColumnModel
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException(sprintf(
                '%s::$%s has union/intersection/no type — add #[Column(type: "...")] to specify DB type',
                $rc->getName(),
                $param->getName()
            ));
        }

        /** @var ColumnAttr|null $override */
        $override = null;
        if ($attrs = $param->getAttributes(ColumnAttr::class)) {
            $override = $attrs[0]->newInstance();
        }

        $jsonAttr = null;
        if ($js = $param->getAttributes(Json::class)) {
            /** @var Json $jsonAttr */
            $jsonAttr = $js[0]->newInstance();
        }

        $enumAttr = null;
        if ($es = $param->getAttributes(EnumAttr::class)) {
            /** @var EnumAttr $enumAttr */
            $enumAttr = $es[0]->newInstance();
        }

        $dbName = $override?->name ?? $param->getName();
        $nullable = $override?->nullable ?? $type->allowsNull();

        [$hasDefault, $default] = $this->extractDefault($param, $override);

        $typeName = $type->getName();
        $overrideType = $override?->type !== null ? strtolower($override->type) : null;

        // Explicit JSON beats everything.
        if ($jsonAttr !== null || $overrideType === 'json') {
            return new ColumnModel(
                name: $dbName,
                type: 'json',
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                collation: $override?->collation,
                renamedFrom: $override?->renamedFrom,
            );
        }

        // Explicit type override
        if ($overrideType !== null) {
            return $this->columnFromOverride($dbName, $overrideType, $override, $nullable, $hasDefault, $default);
        }

        // BackedEnum with #[Enum] attribute → ENUM type
        if ($enumAttr !== null) {
            if (!is_subclass_of($typeName, \BackedEnum::class)) {
                throw new \LogicException(sprintf(
                    '%s::$%s has #[Enum] but its type %s is not a BackedEnum',
                    $rc->getName(),
                    $param->getName(),
                    $typeName
                ));
            }
            $values = $enumAttr->values ?? array_map(fn ($c) => (string)$c->value, $typeName::cases());
            return new ColumnModel(
                name: $dbName,
                type: 'enum',
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                comment: $override?->comment,
                enumValues: $values,
                renamedFrom: $override?->renamedFrom,
            );
        }

        // Pure PHP type inference
        return match (true) {
            $typeName === 'int' => new ColumnModel(
                name: $dbName,
                type: 'int',
                length: $override?->length,
                unsigned: $override?->unsigned ?? false,
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                renamedFrom: $override?->renamedFrom,
            ),
            $typeName === 'float' => new ColumnModel(
                name: $dbName,
                type: 'decimal',
                precision: $override?->precision ?? 12,
                scale: $override?->scale ?? 4,
                unsigned: $override?->unsigned ?? false,
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                renamedFrom: $override?->renamedFrom,
            ),
            $typeName === 'bool' => new ColumnModel(
                name: $dbName,
                type: 'tinyint',
                length: 1,
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                renamedFrom: $override?->renamedFrom,
            ),
            $typeName === 'string' => new ColumnModel(
                name: $dbName,
                type: 'varchar',
                length: $override?->length ?? 255,
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                collation: $override?->collation,
                renamedFrom: $override?->renamedFrom,
            ),
            $typeName === 'array' => new ColumnModel(
                name: $dbName,
                type: 'json',
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                renamedFrom: $override?->renamedFrom,
            ),
            is_subclass_of($typeName, \BackedEnum::class) => new ColumnModel(
                name: $dbName,
                type: $this->enumBackingType($typeName),
                length: $this->enumBackingType($typeName) === 'varchar' ? ($override?->length ?? 64) : null,
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                renamedFrom: $override?->renamedFrom,
            ),
            is_subclass_of($typeName, \DateTimeInterface::class) || $typeName === \DateTimeInterface::class => new ColumnModel(
                name: $dbName,
                type: 'datetime',
                nullable: $nullable,
                hasDefault: $hasDefault,
                default: $default,
                defaultExpression: $override?->defaultExpression,
                onUpdateExpression: $override?->onUpdateExpression,
                comment: $override?->comment,
                renamedFrom: $override?->renamedFrom,
            ),
            default => throw new \LogicException(sprintf(
                '%s::$%s has unsupported type "%s" — use #[Column(type: "...")] or #[Json] to specify DB storage',
                $rc->getName(),
                $param->getName(),
                $typeName
            )),
        };
    }

    private function enumBackingType(string $enumClass): string
    {
        $rc = new \ReflectionEnum($enumClass);
        $backingType = (string)$rc->getBackingType();
        return $backingType === 'int' ? 'smallint' : 'varchar';
    }

    private function columnFromOverride(
        string $dbName,
        string $overrideType,
        ColumnAttr $override,
        bool $nullable,
        bool $hasDefault,
        mixed $default,
    ): ColumnModel {
        return new ColumnModel(
            name: $dbName,
            type: $overrideType,
            length: $override->length,
            precision: $override->precision,
            scale: $override->scale,
            unsigned: $override->unsigned,
            nullable: $nullable,
            hasDefault: $hasDefault,
            default: $default,
            defaultExpression: $override->defaultExpression,
            onUpdateExpression: $override->onUpdateExpression,
            comment: $override->comment,
            collation: $override->collation,
            renamedFrom: $override->renamedFrom,
        );
    }

    /**
     * @return array{bool, mixed}
     *
     * Defaults are opt-in via #[Column(default: ...)] or #[Column(defaultExpression: ...)].
     * PHP parameter defaults are NOT reflected in the schema — they exist for DTO
     * construction ergonomics and may not be representable as MySQL literals.
     */
    private function extractDefault(\ReflectionParameter $param, ?ColumnAttr $override): array
    {
        if ($override !== null && $override->default !== ColumnAttr::NO_DEFAULT) {
            return [true, $override->default];
        }
        return [false, null];
    }

    /**
     * @param array<string, ColumnModel> $columns mutated in place to mark the auto-increment column
     */
    private function resolvePrimaryKey(
        \ReflectionClass $rc,
        array &$columns,
        ?string $paramPkColumn,
        bool $paramPkAutoIncrement,
    ): ?PrimaryKeyModel {
        // Class-level #[PrimaryKey] wins.
        $classPk = $rc->getAttributes(PrimaryKeyAttr::class);
        if ($classPk) {
            /** @var PrimaryKeyAttr $attr */
            $attr = $classPk[0]->newInstance();
            $cols = is_array($attr->columns) ? $attr->columns : (array)$attr->columns;
            if (!$cols) {
                throw new \LogicException(sprintf(
                    '%s class-level #[PrimaryKey] requires the columns argument',
                    $rc->getName()
                ));
            }
            if ($attr->autoIncrement && count($cols) === 1) {
                $this->promoteAutoIncrement($columns, $cols[0]);
            }
            return new PrimaryKeyModel($cols);
        }
        // Parameter-level #[PrimaryKey]
        if ($paramPkColumn !== null) {
            if ($paramPkAutoIncrement) {
                $this->promoteAutoIncrement($columns, $paramPkColumn);
            }
            return new PrimaryKeyModel([$paramPkColumn]);
        }
        // Convention: single int column named 'id' or '*_id'
        $candidates = [];
        foreach ($columns as $name => $col) {
            if (in_array($col->type, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)
                && ($name === 'id' || str_ends_with($name, '_id'))
            ) {
                $candidates[] = $name;
            }
        }
        if (count($candidates) === 1) {
            $this->promoteAutoIncrement($columns, $candidates[0]);
            return new PrimaryKeyModel([$candidates[0]]);
        }
        return null;
    }

    /**
     * @param array<string, ColumnModel> $columns
     */
    private function promoteAutoIncrement(array &$columns, string $name): void
    {
        if (!isset($columns[$name])) return;
        $c = $columns[$name];
        if ($c->autoIncrement) return;
        $columns[$name] = new ColumnModel(
            name: $c->name,
            type: $c->type,
            length: $c->length,
            precision: $c->precision,
            scale: $c->scale,
            unsigned: true,
            nullable: false,
            hasDefault: $c->hasDefault,
            default: $c->default,
            defaultExpression: $c->defaultExpression,
            autoIncrement: true,
            comment: $c->comment,
            collation: $c->collation,
            enumValues: $c->enumValues,
            renamedFrom: $c->renamedFrom,
        );
    }
}
