# Shipper CLI Provider Ploi

![Shipper Banner](https://raw.githubusercontent.com/shippercli/assets/main/banner.png)

Ploi provider plugin for Shipper CLI.

Current scope includes:

- deploy to existing Ploi servers
- create preview or temporary servers on demand
- optional cleanup for Shipper-created preview infrastructure
- provider-owned alias, deploy-script, environment, and SSL post-apply configuration
- `shipper status` and `shipper logs` support

Rollback is not currently supported by Ploi and is reported as unavailable by
`shipper rollback`.

Queues, cron jobs, daemons, network rules, redirects, `php_version`, and
`nginx_config` are not yet applied by this provider. Validation rejects a
project that configures any of these fields instead of silently ignoring them.

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
