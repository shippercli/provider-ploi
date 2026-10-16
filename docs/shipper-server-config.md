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


## Creation option validation (2026-10-16)

Server creation explicitly defaults to PHP `8.4` and database type `mysql84`,
replacing the upstream SDK's implicit PHP `7.4` and unversioned MySQL defaults.
If the project specifies `php_version` and the server spec omits it, the project
version is used. An explicit server `php_version` wins, including `none` when PHP
is not needed. Review the selected versions before creating new infrastructure;
existing servers are not upgraded by this default change.

Explicit server type, database type, PHP version, OS, webserver, and IP mode are
checked against the values documented in the
[Ploi create-server reference](https://developers.ploi.io/servers/create-server).
Retired Ubuntu 22.04 and retired database selectors are rejected for new servers.
`install_monitoring` must be a genuine YAML boolean. Invalid options are rejected
in normal validation and again before the provider sends its create request.
Bare engine aliases remain accepted where documented; their selected version can
change upstream. Explicit values are not silently replaced.

The regression checks the payload produced by the actual SDK with mocked HTTP,
project-version inheritance, explicit overrides, and rejection before a create
request. It does not establish live cloud availability, provider-specific
OS/engine combinations, successful provisioning, or runtime health. Region,
credential, and plan availability still depend on the account/provider.

## Server discovery inventory

Server discovery requires a list of objects with positive integer IDs and
nonempty, unambiguous names. Scalar rows are not silently discarded. IDs must be
unique across all pages; string integer IDs remain supported. Pagination requires
an integral terminal page, and a supplied current page must match the request.
Terminal-page and optional total metadata must remain consistent while walking
pages. A supplied total must match the accumulated inventory. Later-page errors
discard the partial result. These checks follow the documented
[list-server response](https://developers.ploi.io/servers/list-servers) shape.

Name resolution scans the complete validated result and refuses multiple servers
with the managed name. Single-server and creation response IDs are not coerced
from booleans, fractions, malformed numeric strings, or overflow values. Creation
IDs are checked before the SDK's weak integer setter can truncate them.

This guards readable identity/discovery decisions. It does not establish a
transactional snapshot, independent ownership proof from names alone, or a lock
against concurrent account changes. A create request can already have provisioned
a server when an invalid acknowledgement is rejected; no automatic deletion is
attempted in that situation.


## Server deletion acknowledgement

The lifecycle client accepts deletion only when the response contains the exact
requested positive server ID and status `destroying`, with no contradictory
error/failure fields. Missing payloads, message-only acknowledgements, foreign or
malformed IDs, and an unchanged active state are refused. Diagnostics omit the
server's free-form message. See the
[documented delete response](https://developers.ploi.io/servers/delete-server).

This acknowledges an asynchronous operation. The lifecycle client now reads the
server before deletion, sends at most one DELETE, validates the acknowledgement,
and waits for a subsequent HTTP 404 on that server endpoint before returning
success. A server already reported as `destroying` is polled without resending
DELETE. An initial 404 is idempotent API absence. Permission/server errors,
contradictory metadata, and foreign identities are not treated as absence.

The default verification window is 30 seconds with one-second polling. A request
already in flight can finish after that window; the transport's per-attempt
30-second timeout also applies. If the server remains visible, cleanup fails with
an unconfirmed-deletion diagnostic. Retrying a still-destroying server rechecks
its status instead of sending another DELETE. An unreadable acknowledgement is
not retried automatically, since the request may already have acted.

A 404 verifies control-plane absence as observed by this account, not physical
cloud termination or the absence of all related resources. Live completion,
shared lifecycle locking, and concurrent account changes remain unverified. Mock
and real local HTTP tests cover the sequence and refusal cases; no real server
was deleted during the audit.
