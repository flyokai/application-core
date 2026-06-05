<?php

namespace Flyokai\ApplicationCore\Base;

use Amp\Injector\Meta\ParameterAttribute\ServiceParameter;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Flyokai\ApplicationCore\DB\ConnectionPool;
use Flyokai\ApplicationCore\Exception\EntityNotFoundException;
use Flyokai\DataMate\HasAltId;
use Flyokai\DataMate\HasIdDto;
use Flyokai\DataMate\InvalidEntity;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Metadata\MetadataInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\Sql;
use Laminas\Db\TableGateway\TableGateway;
use Flyokai\LaminasDbBulkUpdate\Sql\InsertOnDuplicate;

abstract class AbstractRepository
{
    public const TWEAK_ALT_IDKEY = 'alt_idkey';

    public function __construct(
        #[ServiceParameter] protected ConnectionPool $connectionPool,
        private MapperBuilder                        $mapperBuilder = new MapperBuilder,
    )
    {
    }

    /**
     * @param HasIdDto|positive-int|non-empty-string|non-empty-string[] $entityId
     * @param mixed ...$tweaks
     * @return HasIdDto
     *
     * @throws MappingError
     */
    public function get(HasIdDto|int|string|array $entityId, mixed ...$tweaks): HasIdDto
    {
        if (!$entityId) {
            throw InvalidEntity::missingId($this->entityType());
        }
        if ($entityId instanceof HasIdDto) {
            $dto = $this->fetchExisting($entityId);
            return $dto ?? throw EntityNotFoundException::fromEntity(
                $this->entityType(),
                $entityId->extractIdentity()
            );
        }

        $where = new Sql\Where();
        if (is_string($entityId) || is_array($entityId) || array_key_exists(self::TWEAK_ALT_IDKEY, $tweaks)) {
            if (!is_subclass_of($this->dtoClassName(), HasAltId::class)) {
                throw InvalidEntity::altIdKeyNotSupported($this->entityType());
            }
            if (array_key_exists(self::TWEAK_ALT_IDKEY, $tweaks)) {
                $altIdKey = $tweaks[self::TWEAK_ALT_IDKEY];
                call_user_func([$this->dtoClassName(), 'assetAltIdKey'], $altIdKey);
                $where->equalTo($altIdKey, $entityId);
                $criteria = sprintf($altIdKey."=%s", $entityId);
            } else {
                if (is_array($entityId)) {
                    call_user_func([$this->dtoClassName(), 'assetAltIdKey'], array_keys($entityId));
                    $criteria = '';
                    foreach (array_keys($entityId) as $__akc) {
                        $where->equalTo($__akc, $entityId[$__akc]);
                        $criteria .= sprintf($__akc."=%s", $entityId[$__akc]).' AND ';
                    }
                    $criteria = substr($criteria, 0, -5);
                } else {
                    $altKeyCandidates = [];
                    foreach ($this->altIdKeys() as $__ak) {
                        if (!is_array($__ak)) {
                            $altKeyCandidates[] = $__ak;
                        }
                    }
                    if (empty($altKeyCandidates)) {
                        throw InvalidEntity::altIdKeyNoMatch($this->entityType(), $entityId);
                    }
                    $criteria = [];
                    $where = $where->nest();
                    foreach ($altKeyCandidates as $altKeyCandidate) {
                        $where->or->equalTo($altKeyCandidate, $entityId);
                        $criteria[] = sprintf($altKeyCandidate."=%s", $entityId);
                    }
                    $criteria = implode(' OR ', $criteria);
                    $where = $where->unnest();
                }
            }
        } else {
            $where->equalTo($this->idKey(), $entityId);
            $criteria = sprintf($this->idKey()."=%s", $entityId);
        }

        $resultSet = $this->selectWithWhere($where);

        foreach ($resultSet as $row) {
            if (isset($dto)) {
                throw InvalidEntity::ambiguous(
                    $this->entityType(),
                    $criteria
                );
            }
            $row = call_user_func([$this->dtoClassName(true), 'tuneDbRow'], $row);
            $dto = call_user_func([$this->dtoClassName(true), 'fromArray'], $row);
        }

        return $dto ?? throw EntityNotFoundException::fromEntity($this->entityType(), $entityId);
    }

    protected function selectWithWhere($where = null)
    {
        $dbAdapter = $this->dbAdapter();
        $tableGateway = $this->tableGateway($dbAdapter);
        return $tableGateway->select($where);
    }

    /**
     * @return list<array{0: string|array, 1: int|string|array}>
     */
    public function getIdCandidates(array|int|string|HasIdDto $entityId, ...$tweaks): array
    {
        if ($entityId instanceof HasIdDto) {
            $idCandidates[] = [
                $this->idKey(), $entityId->id()
            ];
            foreach ($this->altIdKeys() as $altIdKey) {
                if (($__altId = $entityId->altId($altIdKey))) {
                    $idCandidates[] = [
                        $altIdKey, $__altId
                    ];
                }
            }
            return $idCandidates;
        }
        $forcedAltIdKey = $tweaks[self::TWEAK_ALT_IDKEY]??null;
        $idCandidates = [];
        if (!$forcedAltIdKey) {
            $idCandidates[] = [
                $this->idKey(), $entityId
            ];
        }
        if (is_subclass_of($this->dtoClassName(), HasAltId::class)
            && (is_string($entityId) || is_array($entityId) || $forcedAltIdKey)
        ) {
            if ($forcedAltIdKey) {
                if (call_user_func([$this->dtoClassName(), 'hasAltIdKey'], $forcedAltIdKey)) {
                    $idCandidates[] = [
                        $forcedAltIdKey, $entityId
                    ];
                }
            } else {
                if (is_array($entityId)) {
                    $__altIdKey = call_user_func([$this->dtoClassName(), 'normalizeAltIdKey'], array_keys($entityId));
                    $__altId = [];
                    if (call_user_func([$this->dtoClassName(), 'hasAltIdKey'], $__altIdKey)) {
                        foreach ($__altIdKey as $__aikPart) {
                            $__altId[$__aikPart] = $entityId[$__aikPart];
                        }
                        $idCandidates[] = [
                            $__altIdKey, $__altId
                        ];
                    }
                } else {
                    $altKeyCandidates = [];
                    foreach ($this->altIdKeys() as $__ak) {
                        if (!is_array($__ak)) {
                            $altKeyCandidates[] = $__ak;
                        }
                    }
                    foreach ($altKeyCandidates as $altKeyCandidate) {
                        $idCandidates[] = [
                            $altKeyCandidate, $entityId
                        ];
                    }
                }
            }
        }
        return $idCandidates;
    }

    protected function fetchExisting(HasIdDto $dto): ?HasIdDto
    {
        $existing = null;
        $existingId = $dto->id();
        if ($existingId === null && $dto instanceof HasAltId) {
            $candidateIds = [];
            foreach ($dto->altIdKeys() as $altIdKey) {
                if (!($__altId = $dto->altId($altIdKey))) continue;
                try {
                    $existing = $this->get($__altId, ...[self::TWEAK_ALT_IDKEY => $altIdKey]);
                    $candidateIds[] = $existing->id();
                } catch (EntityNotFoundException $e) {}
            }
            $candidateIds = array_unique($candidateIds);
            if (count($candidateIds)>1) {
                throw InvalidEntity::ambiguous(
                    $this->entityType()
                );
            }
        } elseif ($existingId !== null) {
            try {
                $existing = $this->get($existingId);
            } catch (EntityNotFoundException $e) {}
        }
        return $existing;
    }

    /**
     * @param HasIdDto $dto
     * @param mixed ...$tweaks
     * @return static
     *
     * @throws MappingError
     */
    public function save(HasIdDto $dto, mixed ...$tweaks): static
    {
        $dbAdapter = $this->dbAdapter();
        $tableGateway = $this->tableGateway($dbAdapter);
        $existing = $this->fetchExisting($dto);
        if (isset($existing)) {
            $dto = $dto->cloneWith(...[$this->idKey() => $existing->id()]);
            $tableGateway->update(
                $dto->toDbRow(),
                (new Sql\Where())->equalTo($this->idKey(), $dto->id())
            );
        } else {
            $tableGateway->insert(
                $this->applyDefaults($dto->toDbRow(), $dbAdapter)
            );
        }
        return $this;
    }

    /**
     * @param array<HasIdDto> $dto
     * @param mixed ...$tweaks
     * @return static
     *
     * @throws MappingError
     */
    public function bulkSave(array $dtoBulk, mixed ...$tweaks): static
    {
        $dbAdapter = $this->dbAdapter();
        $tableGateway = $this->tableGateway($dbAdapter);
        $bulk = [];
        foreach ($dtoBulk as $dto) {
            $bulk[] = $this->applyDefaults($dto->toDbRow(), $dbAdapter);
        }
        if (!empty($bulk)) {
            $sql = new Sql\Sql($dbAdapter);
            $columns = $this->tableColumnNames($dbAdapter);
            $insert = InsertOnDuplicate::create(
                $tableGateway->getTable(), $columns
            );
            $onDuplicate = $columns;
            $onDuplicateFilter = [$this->idKey()];
            foreach ($this->altIdKeys() as $altIdKey) {
                if (is_array($altIdKey)) {
                    $onDuplicateFilter += $altIdKey;
                } else {
                    $onDuplicateFilter[] = $altIdKey;
                }
            }
            $onDuplicate = array_filter($onDuplicate, fn($column) => !in_array($column, $onDuplicateFilter));
            $onDuplicate = array_filter($onDuplicate, fn($column) => in_array($column, $columns));
            if (!empty($onDuplicate)) {
                $insert->onDuplicate($onDuplicate);
            } else {
                $insert->withIgnore(true);
            }
            foreach ($bulk as $row) {
                $insert->withRow(...array_map(fn($cn) => $row[$cn]??null, $columns));
            }
            $insert->executeIfNotEmpty($sql);
        }
        return $this;
    }

    protected function tableColumnNames(Adapter $dbAdapter): array
    {
        return array_map(fn($c) => $c->getName(), $this->tableColumns($dbAdapter));
    }
    protected function tableColumns(Adapter $dbAdapter): array
    {
        return $this->metadata($dbAdapter)->getColumns($this->tableName());
    }

    protected function applyDefaults(array $dbRow, Adapter $dbAdapter): array
    {
        foreach ($this->tableColumns($dbAdapter) as $column) {
            $name = $column->getName();
            if (!$column->getIsNullable() && null === ($dbRow[$name]??null)
                || !array_key_exists($name, $dbRow)
            ) {
                $dbRow[$name] = $column->getColumnDefault();
                if (in_array($dbRow[$name], ['CURRENT_TIMESTAMP'])) {
                    $dbRow[$name] = new Sql\Expression($dbRow[$name]);
                }
            }
        }
        return $dbRow;
    }

    /**
     * @param HasIdDto|positive-int|non-empty-string $dto
     * @param mixed ...$tweaks
     * @return static
     *
     * @throws MappingError
     */
    public function delete(HasIdDto|int|string $dto, mixed ...$tweaks): static
    {
        if (!$dto instanceof HasIdDto) {
            $dto = $this->get($dto, ...$tweaks);
        }
        if (!$dto->id()) {
            throw InvalidEntity::missingId($this->entityType());
        }
        $dbAdapter = $this->dbAdapter();
        $tableGateway = $this->tableGateway($dbAdapter);
        $tableGateway->delete(
            (new Sql\Where())->equalTo($this->idKey(), $dto->id())
        );
        return $this;
    }

    protected function tableGateway(Adapter $dbAdapter, ?string $tableName=null): TableGateway
    {
        $tableName ??= $this->tableName();
        return new TableGateway(
            table: $tableName,
            adapter: $dbAdapter,
            resultSetPrototype: new ResultSet(ResultSet::TYPE_ARRAY)
        );
    }

    protected function metadata(Adapter $dbAdapter): MetadataInterface
    {
        return \Laminas\Db\Metadata\Source\Factory::createSourceFromAdapter($dbAdapter);
    }

    protected function dbAdapter(): Adapter
    {
        return $this->connectionPool->createConnection();
    }

    protected ?TreeMapper $dtoMapper = null;
    public function dtoMapper(): TreeMapper
    {
        if (null === $this->dtoMapper) {
            $this->dtoMapper = $this->mapperBuilder
                ->allowSuperfluousKeys()
                ->enableFlexibleCasting()
                ->allowPermissiveTypes()
                ->mapper();
        }
        return $this->dtoMapper;
    }

    protected function altIdKeys(): array
    {
        return call_user_func([$this->dtoClassName(), 'altIdKeys']);
    }

    protected function idKey(): string
    {
        return call_user_func([$this->dtoClassName(), 'idKey']);
    }

    abstract public function entityType(): string;

    abstract public function tableName(): string;

    /**
     * @return class-string
     */
    abstract public function dtoClassName(bool $solid=false): string;
}
