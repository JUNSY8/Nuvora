---
name: documentation
description: Create and maintain technical documentation for Nuvora without duplicating source-of-truth information.
---
# Documentation

Document decisions that affect architecture, operations, security, or integrations.

Prefer:
- ADRs for architectural decisions
- OpenAPI for API contracts
- diagrams for system boundaries
- runbooks for operational procedures
- module documentation for domain boundaries

Avoid documenting implementation details that can become stale when the code itself is the source of truth.

When changing a documented contract, update the relevant documentation in the same change.
