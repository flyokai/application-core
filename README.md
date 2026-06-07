# flyokai/application-core

Sync slice of `flyokai/application`. No AMPHP, no Revolt, no event loop.

Holds the classes that pure-sync hosts (e.g. Shopware running marketplace
locally via `flyokai/marketplace-embedded`) need to consume from
`flyokai/marketplace-core` without pulling Amp transitively.

## Current contents

- `Base\AbstractRepository` — abstract base for entity repositories. Uses
  `#[ServiceParameter]` as metadata only; the attribute is never instantiated
  in sync hosts.
- `Base\ExtensionRepositoryTrait` — DTO-extension repository trait.
- `DB\ConnectionPool` — interface only. The Amp implementation lives in
  `flyokai/application`; sync hosts provide their own via
  `flyokai/marketplace-embedded\SyncConnectionPool`.
- `Exception\EntityNotFoundException`
- `Http\Controller\ProtectedHandler` — marker interface (was historically
  named for HTTP handlers but is used as a generic "requires auth" marker on
  any request handler).

## BC bridge

The previous namespaces (`Flyokai\Application\…`) are kept alive as
`class_alias` stubs inside `flyokai/application`, so existing consumers
continue to work without an import sweep. New code should use the
`Flyokai\ApplicationCore\…` names.
