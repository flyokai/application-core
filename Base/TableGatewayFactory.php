<?php

namespace Flyokai\ApplicationCore\Base;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\ResultSet\ResultSetInterface;
use Laminas\Db\Sql\TableIdentifier;
use Laminas\Db\TableGateway\TableGateway;

/**
 * Builds featureless {@see TableGateway}s that do not leak the per-op DB connection.
 *
 * A stock Laminas TableGateway forms a reference cycle with its FeatureSet:
 * AbstractTableGateway::initialize() sets `FeatureSet->tableGateway = $this` while the
 * gateway itself holds the FeatureSet. A reference cycle can only be reclaimed by PHP's
 * cycle collector — never by refcounting. Repositories build a fresh gateway on every DB
 * op, and each gateway transitively pins the per-op Adapter -> Driver -> Connection ->
 * PooledLink. The async mysqli driver's PooledLink::__destruct() is what returns the
 * borrowed link to a *bounded* pool, so while the gateway is trapped in the cycle the
 * link is never returned; under load the pool starves and LinkPool::pull() blocks
 * forever (and memory grows until the cycle collector happens to run).
 *
 * Repositories attach NO features, so the FeatureSet -> TableGateway back-reference is
 * only ever read by feature hooks during initialize() (already finished by the time the
 * gateway is returned here). Severing it breaks the only cycle, so the gateway + adapter
 * + borrowed link are freed by refcount the instant they leave scope — no cycle collector
 * required. This keeps us on stock upstream laminas/laminas-db with no source fork.
 *
 * The sever is guarded on an empty feature set, so the helper stays correct even if a
 * future caller attaches features (those gateways keep the back-reference the features
 * need at query time; they simply don't get the refcount fast-path).
 */
final class TableGatewayFactory
{
    /**
     * @param string|array|TableIdentifier $table
     */
    public static function create(
        string|array|TableIdentifier $table,
        Adapter $adapter,
        ?ResultSetInterface $resultSetPrototype = null,
    ): TableGateway {
        $tableGateway = new TableGateway(
            table: $table,
            adapter: $adapter,
            resultSetPrototype: $resultSetPrototype ?? new ResultSet(ResultSet::TYPE_ARRAY),
        );

        // Break the TableGateway <-> FeatureSet reference cycle (see class docblock).
        // Bound to FeatureSet scope so we can touch its protected state.
        (function () {
            if (empty($this->features)) {
                $this->tableGateway = null;
            }
        })->call($tableGateway->getFeatureSet());

        return $tableGateway;
    }
}
