---
name: feature-development
description: End-to-end workflow for implementing production-ready Nuvora features.
---
# Feature Development

1. Understand
- Inspect the relevant module and dependencies.
- Identify domain entities, use cases, data changes, permissions, integrations, and tests.
- Do not code before understanding the existing design.

2. Plan
Produce a concise implementation plan covering:
- modules
- domain rules
- database
- backend
- frontend
- APIs/events
- tests
- risks

3. Implement
- Preserve module boundaries.
- Put business rules in the domain/application layer.
- Keep controllers and Vue components thin.
- Add authorization and tenant isolation.
- Add migrations and constraints.
- Use idempotency for external events where required.

4. Test
Add domain, feature, authorization, and tenant-isolation tests as appropriate.

5. Verify
Run formatter, static analysis, tests, frontend checks, and build checks configured by the project.

6. Report
Summarize changed files/modules, migrations, tests, commands run, and remaining risks.
