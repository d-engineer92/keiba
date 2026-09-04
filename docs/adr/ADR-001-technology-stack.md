# ADR-001: Technology Stack

## Status
Accepted

## Decision

- Backend: PHP / Laravel
- Database: PostgreSQL
- Local runtime: WSL2 Ubuntu 26.04 + Docker Compose
- Schema management: Laravel Migration
- JV-Link integration: C# Collector on Windows
- Collector transport: Laravel Ingest API
- Collector durability: SQLite Outbox
- CI/CD: GitHub Actions

## Rationale

PostgreSQL is selected over MySQL because the project is analysis-oriented and is expected to use window functions, statistical aggregation, time-series access patterns, JSONB, partial indexes, BRIN indexes and potentially materialized views.

Laravel is selected because it is already familiar and supports PostgreSQL, migrations, queues, cache abstraction and HTTP APIs well.

JV-Link remains a Windows-local dependency. C# is used only for the acquisition boundary and does not contain analysis/business logic.

Docker is used to make the Laravel/PostgreSQL runtime reproducible locally and portable to a future cloud deployment.
