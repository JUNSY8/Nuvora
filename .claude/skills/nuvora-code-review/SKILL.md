---
name: nuvora-code-review
description: Perform production-grade code reviews for Nuvora.
---
# Code Review

Review in this order:
1. Correctness
2. Security
3. Tenant isolation
4. Business rules
5. Data integrity
6. Concurrency
7. Performance
8. Architecture
9. Maintainability
10. Tests

Look for:
- IDOR
- cross-tenant access
- missing authorization
- race conditions
- N+1 queries
- missing constraints
- duplicated domain logic
- fat controllers/components
- unsafe external integrations
- missing idempotency
- inadequate tests

Do not recommend changes solely for stylistic preference.
For each finding provide location, impact, and concrete remediation.
