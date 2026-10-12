---
name: api-design
description: Design Nuvora HTTP APIs, webhooks, and external integration contracts.
---
# API Design

Use versioned APIs such as /api/v1.

Define:
- authentication
- authorization
- request validation
- response shape
- pagination
- filtering
- sorting
- errors
- rate limits
- idempotency where needed

Do not expose internal database structure unnecessarily.

External webhooks must be signed, replay-resistant where practical, and idempotent.

Document public integration contracts with OpenAPI when applicable.
