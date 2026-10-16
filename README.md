# Shipper CLI Provider Ploi

![Shipper Banner](https://raw.githubusercontent.com/shippercli/assets/main/banner.png)

Ploi provider plugin for Shipper CLI.

Current scope includes:

- deploy to existing Ploi servers
- create preview or temporary servers on demand
- optional cleanup for Shipper-created preview infrastructure
- provider-owned alias, deploy-script, environment, and SSL post-apply configuration
- provider-owned queue workers, cron jobs, and daemon processes through the Ploi API
- `shipper status` and `shipper logs` support

Rollback is not currently supported by Ploi and is reported as unavailable by
`shipper rollback`.

Configured `php_version` values are checked against the server's advertised PHP
versions and applied only when the site is currently using a different version.
`nginx_config` is treated as the complete site NGINX configuration because the
Ploi API replaces the vhost content; it is applied only when it differs from the
current configuration. Network rules are
reconciled with Shipper ownership markers and removed on destroy.
Redirects are created idempotently; disabled redirects are skipped,
and conflicting existing redirects are reported instead of modifying unmanaged
configuration. Validation rejects a project that configures unsupported fields
instead of silently ignoring them.

Queue workers, cron jobs, and daemons are created idempotently during
post-apply. Cron and daemon commands carry a Shipper marker so they can be
recognized on subsequent applies; queue workers are matched by their complete
provider configuration. Existing unrelated Ploi workloads are never removed.

Ploi accepts `site_id` when a database is created but does not publish an API
operation for attaching an already-existing database to a different site. If a
configured database exists without the current site association, apply fails
closed instead of deploying with an unusable database.

This repository also contains the provider metadata and logo used by the Shipper website provider catalog.

## Installation

```bash
composer global require shippercli/provider-ploi
```

## Requirements

- PHP ^8.3
- Shipper CLI

## Server lifecycle config

Existing server:

```yaml
providers:
  ploi:
    api_key: ${PLOI_API_KEY}

projects:
  api:
    provider: ploi
    profiles:
      production:
        domain: "api.example.com"
        infrastructure:
          server:
            mode: existing
            id: "123456"
```

Create a preview server:

```yaml
providers:
  ploi:
    api_key: ${PLOI_API_KEY}

projects:
  api:
    provider: ploi
    profiles:
      preview:
        domain: "preview.example.com"
        infrastructure:
          server:
            mode: create
            cleanup: destroy
            ttl: 72h
            spec:
              name: "api-pr-${PR_NUMBER}"
              credential: "42"
              region: "fra1"
              plan: "vc2-1c-2gb"
              php_version: "8.3"
```

`credential`, `region`, and `plan` are required for create mode.

Created servers are marked as Shipper-managed using a deterministic managed name based on project, profile, and configured server name. Cleanup only deletes servers that match that managed identity.

## Database connection environments

A single new database exports `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and
`DB_PASSWORD`. Multiple databases require `database_environment_prefixes` in the
provider configuration, keyed by each declared database name, for example:

```yaml
database_environment_prefixes:
  main: DB
  analytics: ANALYTICS_DB
```

The analytics connection uses `ANALYTICS_DB_HOST`, `ANALYTICS_DB_DATABASE`,
`ANALYTICS_DB_USERNAME`, and `ANALYTICS_DB_PASSWORD`. Prefixes must be unique
uppercase environment identifiers. Configure the application to read them.
For a new database, an explicitly configured application `<PREFIX>_PASSWORD`
(for example `DB_PASSWORD` or `ANALYTICS_DB_PASSWORD`) is used unchanged for
creation and application delivery. Profile values override project values.
Explicit passwords must be nonempty strings; invalid values fail before any
database provisioning. When absent, passwords are generated independently.
Database names, users and hosts still come from the declarations/provider
settings; this password option does not redirect the database connection.
`database_host` defaults to `127.0.0.1`;
override it for a different database endpoint. Re-apply preserves existing
credentials without rotation; existing databases must already be linked to the
site and their credentials available in the application environment.

## Site ownership

New deployments persist `SHIPPERCLI_OWNERSHIP` in the site's environment. Its
value is the SHA-256 hash of the JSON-encoded `[project-name, profile-name]` array
(PHP `json_encode` with `JSON_THROW_ON_ERROR`). Encoding the pair preserves its
boundaries even when names contain delimiters. Re-apply,
destroy, and preview cleanup require this persisted marker before modifying an
existing site. Matching a domain alone does not establish ownership.

Existing installations created before this marker was introduced fail closed.
Review the site's identity, project/profile scope, and all attached databases
before explicitly recording the marker in its environment through Ploi. Shipper
does not automatically adopt unmarked sites. Preserve the marker in any custom
environment-management automation. If deployment fails before the marker is
persisted, inspect the partially created site before retrying or removing it.

Database cleanup also requires Ploi's returned `site.id` to match the deployment
site. Missing or different associations block deletion. A database deletion
failure preserves the site so its connection details remain available.

## License

MIT

### Plan ownership inspection

For an existing server, plan reads paginated site inventory and verifies the
project/profile ownership marker on a matching domain. Unverifiable ownership,
invalid site identities, malformed markers, and API read failures return
`blocked: true` with a public blocked reason. Planning performs no writes.
Core enforcement is proposed in shippercli/cli#154.

Create-server plans cannot inspect a server that does not yet exist; apply must
resolve the server and recheck site ownership. Database/workload prerequisites
and live connection readiness are also rechecked during apply. An unblocked
plan does not certify those deferred checks.

### Provider HTTP transport policy

The provider uses a Ploi SDK subclass that keeps the SDK's resource and response
interfaces. Each request disables redirects, bounds the HTTP attempt to 30 seconds,
and rejects every non-2xx response before decoding successful data. Existing SDK
HTTP exception classes are preserved for their documented status codes. Error
messages retain the status without server-provided text, which can echo submitted
credentials. Transport failures omit raw underlying diagnostics. Token rotation
still updates the Authorization header on subsequent SDK resource calls.

`python3 tests/http/test_responses.py` exercises the real Guzzle path with
synthetic credentials and loopback HTTP. Sixty GET/POST rejection cases cover
five redirects and ten error statuses with JSON/text bodies; two success controls
check response decoding. The upstream SDK followed a 301 and exposed a synthetic
credential echoed in a 401 response. The provider adapter blocks these cases.
Unit tests cover actual SDK resource routing, token rotation, timeout/default
option enforcement, typed errors, and safe transport diagnostics. CI runs the
HTTP regression alongside the PHP matrix. Live Ploi compatibility, successful
API data, and application log redaction are outside this check.


### Distribution archive checks

Composer archive exclusions and Git export attributes omit installed dependencies,
tests, workflow files, root environment/auth configuration, and PHPUnit caches and
configuration. Runtime source, package metadata, documentation, and assets remain
available. `python3 tests/package/verify-dist.py` checks Composer and Git exports,
then loads all declared runtime classes from an extracted ZIP with an overridden
class map. Dependency classes come from the installed checkout; this check does
not perform a fresh production dependency install or live deployment. CI runs it
with the existing provider tests. Git export verification uses current worktree
attributes; committed attributes determine subsequent GitHub source archives.
