---
name: observability
description: Design logging, metrics, tracing, health checks, and operational diagnostics for Nuvora.
---
# Observability

Use structured logs.

Every important request, job, device event, and external integration should be traceable with a correlation ID where practical.

Never log secrets or unnecessary personal data.

Expose health/readiness checks appropriate to the deployment.

Track meaningful metrics such as:
- request latency
- error rates
- queue depth
- job failures
- device connectivity
- LPR processing failures
- synchronization lag
- reservation conflicts
- payment webhook failures

Logs should answer:
what happened, when, where, tenant/site context, correlation ID, and outcome.
