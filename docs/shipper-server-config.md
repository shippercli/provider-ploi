# Shipper Server Config Proposal for Ploi

## Goal

Allow Shipper to deploy either:

- to an existing Ploi server
- or to a new Ploi server created on demand

## Principles

- existing infrastructure must stay safe by default
- temporary infrastructure should support automatic cleanup
- provider-specific server creation details should stay under the provider profile

## Existing server example

```yaml
providers:
  ploi:
    api_key: ${PLOI_API_KEY}

projects:
  api:
    provider: ploi
    profiles:
      production:
        infrastructure:
          server:
            mode: existing
            id: "123456"
```

## Created server example

```yaml
providers:
  ploi:
    api_key: ${PLOI_API_KEY}

projects:
  api:
    provider: ploi
    profiles:
      preview:
        infrastructure:
          server:
            mode: create
            cleanup: destroy
            ttl: 72h
            spec:
              name: "api-pr-${PR_NUMBER}"
              region: "eu-west"
              size: "small"
              php_version: "8.3"
```

## Recommended supported fields

### Core fields

- `mode`
- `cleanup`
- `ttl`
- `id`
- `spec`

### Ploi-specific `spec` fields

- `name`
- `region`
- `size`
- `php_version`
- `database_type`
- `server_type`

Only fields supported by the Ploi API should be accepted.

## Cleanup policies

- `destroy`
- `retain`
- `manual`

Recommended defaults:

- production: no cleanup
- preview: `destroy`

## Ownership marking

For created Ploi servers, Shipper should record at least:

- project slug
- profile slug
- environment type
- preview or PR number
- creation timestamp

If Ploi metadata fields are limited, use a deterministic naming convention plus provider-side notes where possible.

## Validation rules

1. `mode: existing` requires `id`
2. `mode: create` requires `spec.name`
3. `cleanup` is invalid for existing servers unless explicitly ignored
4. `destroy` must only be allowed for Shipper-managed servers
5. `ttl` only applies to created servers

## Recommended MVP

Support these first:

- existing server by `id`
- create server with minimal `spec`
- destroy cleanup for Shipper-managed preview servers

That is enough to unlock preview infrastructure and temporary staging flows.

## Current implementation shape

The current core implementation validates and supports:

- `mode: existing` with `id`
- `mode: create` with:
  - `spec.name`
  - `spec.credential` or `spec.provider_id` or `spec.provider`
  - `spec.region`
  - `spec.plan` or `spec.size`
- cleanup policies:
  - `destroy`
  - `retain`
  - `manual`

Current Ploi behavior proves ownership through a deterministic managed name. Cleanup only deletes servers that match that managed identity, and it refuses deletion if only an unmanaged human-facing name exists.

Updated create example:

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
