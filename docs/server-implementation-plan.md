# Ploi Server Lifecycle Implementation Plan

## Goal

Add server lifecycle support to the Ploi provider so Shipper can create, reuse, and clean up Ploi servers safely.

## Phase 1: MVP

### Features

- list servers
- get server
- create server
- delete server
- resolve existing vs created server target
- cleanup for Shipper-managed preview servers

### Deliverables

1. Introduce server lifecycle capability declaration in provider metadata
2. Add Ploi API client support for:
   - list servers
   - get server
   - create server
   - delete server
3. Add config parsing for:
   - `infrastructure.server.mode`
   - `infrastructure.server.id`
   - `infrastructure.server.cleanup`
   - `infrastructure.server.spec`
4. Add ownership marking for created servers
5. Add guardrails preventing deletion of unmanaged servers

## Phase 2: Operational support

### Features

- restart server
- monitoring/status lookup
- server logs
- TTL cleanup support
- manual cleanup command support

### Deliverables

1. Add restart operation
2. Add status and monitoring reads
3. Add TTL evaluation for cleanup candidates
4. Add explicit cleanup command for expired preview infrastructure

## Phase 3: Advanced provisioning

### Features

- create custom server
- richer region/size/image choices
- provider template support
- suspend/resume if supported well enough

### Deliverables

1. Expand create-server spec mapping
2. Support more provisioning inputs
3. Evaluate suspend/resume semantics vs destroy

## Concrete task breakdown

### Core modeling

1. Add server lifecycle config DTOs in Shipper core
2. Add lifecycle mode enum: `existing`, `create`
3. Add cleanup policy enum: `destroy`, `retain`, `manual`
4. Add validation rules for lifecycle config

### Ploi provider integration

1. Add server API wrapper methods
2. Add mapper from Shipper server spec to Ploi create-server payload
3. Add resolver for existing server IDs
4. Add ownership detection logic
5. Add destroy cleanup logic

### Safety

1. Reject cleanup for unmanaged servers
2. Reject missing ownership metadata on destroy path
3. Emit clear plan output for server creation vs reuse
4. Require explicit cleanup mode for ephemeral profiles

### UX / plan output

1. Show `Use existing server ...`
2. Show `Create server ...`
3. Show `Cleanup policy: destroy`
4. Show `Refusing to delete unmanaged server ...` when applicable

### Testing targets

1. Existing-server happy path
2. Create-server happy path
3. Destroy cleanup happy path
4. Reject deleting unmanaged server
5. Reject invalid config combinations
6. Preview naming interpolation

## Best first issues

### Issue 1

Add generic server lifecycle config support in Shipper core

### Issue 2

Add Ploi server API client support

### Issue 3

Implement existing server resolution in Ploi provider

### Issue 4

Implement create-server flow in Ploi provider

### Issue 5

Implement Shipper-managed ownership markers for created Ploi servers

### Issue 6

Implement safe destroy cleanup for preview servers

### Issue 7

Add plan/apply output for server lifecycle actions

### Issue 8

Add tests for lifecycle validation and cleanup safety
