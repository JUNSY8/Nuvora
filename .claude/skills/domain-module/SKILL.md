---
name: domain-module
description: Design or implement a Nuvora domain module using the Modular Monolith and DDD-inspired boundaries.
---
# Domain Module

Before creating a module, define:
- responsibility
- owned data
- invariants
- public application contracts
- dependencies
- events

Use:
Domain/
Application/
Infrastructure/
Presentation/

Domain contains entities, value objects, domain services, and domain events.

Application contains use cases and orchestration.

Infrastructure contains persistence and external adapters.

Presentation contains HTTP/UI-facing concerns.

Do not let modules access each other's internal implementation.

Do not create generic repositories/services by default.
