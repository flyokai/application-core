<?php

namespace Flyokai\ApplicationCore\Base;

use Flyokai\ApplicationCore\Application\DtoExtensionRegistry;
use Flyokai\ApplicationCore\DB\ConnectionPool;
use Flyokai\DataMate\DtoExtensionConfig;
use Flyokai\DataMate\HasIdDto;
use Flyokai\SearchCriteria\Repository\ListResult;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;

/**
 * Trait for repository decorators that load/save 1:1 DTO extensions.
 *
 * Provides methods to:
 * - loadExtensions()     — enrich a single Solid DTO with extension data
 * - loadExtensionsBulk() — batch-enrich a list of Solid DTOs (avoids N+1)
 * - saveExtensions()     — persist extension data after base entity save
 * - deleteExtensions()   — remove extension rows before/after base entity delete
 * - enrichListResult()   — enrich a ListResult's items in bulk
 */
trait ExtensionRepositoryTrait
{
    abstract protected function extensionRegistry(): DtoExtensionRegistry;
    abstract protected function extensionConnectionPool(): ConnectionPool;

    protected function loadExtensions(HasIdDto $dto): HasIdDto
    {
        $configs = $this->extensionRegistry()->forDto($dto->className());
        if (empty($configs)) {
            return $dto;
        }

        $dbAdapter = $this->extensionConnectionPool()->createConnection();
        foreach ($configs as $cfg) {
            $dto = $this->loadSingleExtension($dto, $cfg, $dbAdapter);
        }
        return $dto;
    }

    /**
     * Batch-load extensions for a list of DTOs. One SELECT per extension type (not per entity).
     *
     * @param list<HasIdDto> $dtos
     * @return list<HasIdDto>
     */
    protected function loadExtensionsBulk(array $dtos): array
    {
        if (empty($dtos)) {
            return $dtos;
        }

        $configs = $this->extensionRegistry()->forDto($dtos[0]->className());
        if (empty($configs)) {
            return $dtos;
        }

        // Build ID → index map
        $idMap = [];
        foreach ($dtos as $idx => $dto) {
            $id = $dto->id();
            if ($id !== null) {
                $idMap[$id] = $idx;
            }
        }
        if (empty($idMap)) {
            return $dtos;
        }

        $dbAdapter = $this->extensionConnectionPool()->createConnection();
        $ids = array_keys($idMap);

        foreach ($configs as $cfg) {
            $tableGateway = $this->extensionTableGateway($dbAdapter, $cfg->tableName);
            $resultSet = $tableGateway->select(function ($select) use ($cfg, $ids) {
                $select->where->in($cfg->foreignKeyColumn, $ids);
            });

            foreach ($resultSet as $row) {
                $fkValue = $row[$cfg->foreignKeyColumn];
                if (!isset($idMap[$fkValue])) {
                    continue;
                }
                $row = call_user_func([$cfg->solidClass, 'tuneDbRow'], $row);
                $ext = call_user_func([$cfg->solidClass, 'fromArray'], $row);
                $idx = $idMap[$fkValue];
                $dtos[$idx] = $dtos[$idx]->withExtension($cfg->solidClass, $ext);
            }
        }

        return $dtos;
    }

    /**
     * Enrich a ListResult's items with extensions in bulk.
     */
    protected function enrichListResult(ListResult $result): ListResult
    {
        if (empty($result->items)) {
            return $result;
        }
        $enriched = $this->loadExtensionsBulk($result->items);
        return new ListResult($enriched, $result->total);
    }

    /**
     * Save extension data for a DTO that already has an ID.
     */
    protected function saveExtensions(HasIdDto $dto): void
    {
        $configs = $this->extensionRegistry()->forDto($dto->className());
        if (empty($configs)) {
            return;
        }

        $dbAdapter = $this->extensionConnectionPool()->createConnection();
        foreach ($configs as $cfg) {
            $ext = $dto->getExtension($cfg->solidClass)
                ?? $dto->getExtension($cfg->draftClass);
            if ($ext === null) {
                continue;
            }

            // Ensure FK points to base entity's ID
            $ext = $ext->cloneWith(...[$cfg->foreignKeyColumn => $dto->id()]);

            $tableGateway = $this->extensionTableGateway($dbAdapter, $cfg->tableName);
            $where = [$cfg->foreignKeyColumn => $dto->id()];
            $existing = $tableGateway->select($where);

            if ($existing->count() > 0) {
                $tableGateway->update($ext->toDbRow(), $where);
            } else {
                $tableGateway->insert($ext->toDbRow());
            }
        }
    }

    /**
     * Delete extension rows for a DTO. Belt-and-suspenders alongside FK CASCADE.
     */
    protected function deleteExtensions(HasIdDto $dto): void
    {
        $configs = $this->extensionRegistry()->forDto($dto->className());
        if (empty($configs)) {
            return;
        }

        $dbAdapter = $this->extensionConnectionPool()->createConnection();
        foreach ($configs as $cfg) {
            $tableGateway = $this->extensionTableGateway($dbAdapter, $cfg->tableName);
            $tableGateway->delete([$cfg->foreignKeyColumn => $dto->id()]);
        }
    }

    private function loadSingleExtension(HasIdDto $dto, DtoExtensionConfig $cfg, Adapter $dbAdapter): HasIdDto
    {
        $tableGateway = $this->extensionTableGateway($dbAdapter, $cfg->tableName);
        $resultSet = $tableGateway->select([$cfg->foreignKeyColumn => $dto->id()]);

        foreach ($resultSet as $row) {
            $row = call_user_func([$cfg->solidClass, 'tuneDbRow'], $row);
            $ext = call_user_func([$cfg->solidClass, 'fromArray'], $row);
            $dto = $dto->withExtension($cfg->solidClass, $ext);
            break; // 1:1 relation — only one row expected
        }

        return $dto;
    }

    private function extensionTableGateway(Adapter $dbAdapter, string $tableName): TableGateway
    {
        return new TableGateway(
            table: $tableName,
            adapter: $dbAdapter,
            resultSetPrototype: new ResultSet(ResultSet::TYPE_ARRAY)
        );
    }
}
