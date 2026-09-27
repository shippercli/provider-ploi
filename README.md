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

`nginx_config` is not yet applied by this provider. Configured `php_version`
values are checked against the server's advertised PHP versions and applied only
when the site is currently using a different version. Network rules are
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

## License

MIT
