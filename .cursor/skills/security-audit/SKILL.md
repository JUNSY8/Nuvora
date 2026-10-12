---
name: security-audit
description: Audit Nuvora code for application, API, tenant, file, payment, device, and privacy security risks.
---
# Security Audit

Check authentication and authorization first.

Then inspect:
- tenant isolation and IDOR
- input validation
- mass assignment
- SQL injection
- XSS/CSRF
- SSRF
- path traversal
- command execution
- unsafe uploads
- secret exposure
- webhook verification
- replay/idempotency
- payment data handling
- device credentials
- LPR data retention/access

Classify findings as CRITICAL, HIGH, MEDIUM, or LOW.

Do not claim a system is secure merely because no issue was found in a limited review.
