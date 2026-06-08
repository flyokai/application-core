<?php

namespace Flyokai\ApplicationCore\DB;

/**
 * Marker interface for the cluster's "infra" connection pool. Repositories that
 * back the cluster's own bootstrap tables (`flyok_user`, `flyok_oauth_*`,
 * `flyok_acl_role`, `flyok_indexer`) inject this instead of {@see ConnectionPool}
 * so they stay pinned to the cluster's own MySQL regardless of
 * `db.connection.active`. Without this seam, `active=shopware` would route
 * those queries to Shopware's DB where the `flyok_*` tables don't exist and
 * every bootstrap path (token issuance, role check, indexer init) returns 401.
 */
interface InfraConnectionPool extends ConnectionPool
{
}
