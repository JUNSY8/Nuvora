---
name: database-design
description: Design and review PostgreSQL schemas, migrations, constraints, and indexes for Nuvora.
---
# Database Design

For every schema change consider:
- tenant isolation
- primary/foreign keys
- unique constraints
- check constraints
- indexes
- query patterns
- deletion/retention behavior
- concurrency

Use precise monetary representation.

For reservations and capacity, explicitly analyze overlapping reservations and race conditions.

For high-volume event tables, consider partitioning only when justified by measured scale.

Migrations must account for existing production data and deployment order.
