---
name: testing
description: Build and review automated tests for Nuvora backend, frontend, integrations, and critical workflows.
---
# Testing Workflow

Identify the business invariant first.

Backend:
- unit tests for isolated domain logic
- feature/integration tests for workflows
- authorization tests
- tenant-isolation tests

Frontend:
- test meaningful interaction and state behavior, not implementation details

E2E:
- critical user journeys
- reservations
- entry/exit
- payments where integrated
- administration

Integration/device:
- duplicate events
- offline mode
- reconnection
- malformed payloads
- delayed events

Never weaken tests just to make a suite pass.
