# Nuvora Cursor Engineering Pack

This directory contains project-specific Cursor Rules and Skills for Nuvora.

## Rules

Rules define persistent engineering constraints:
- core principles
- Modular Monolith architecture
- security
- PostgreSQL
- Vue/TypeScript
- testing

## Skills

Skills define reusable workflows:
- feature development
- domain modules
- database design
- API design
- testing
- code review
- security audits
- LPR/ANPR
- Nuvora Edge
- observability
- documentation
- Nuvora design (Velzon dark)

## Usage

Cursor should automatically discover skills under `.cursor/skills`.

Useful explicit invocations include:

/feature-development
/domain-module
/database-design
/api-design
/testing
/code-review
/security-audit
/lpr-integration
/nuvora-edge
/observability
/documentation
/nuvora-design

## Principle

The AI must treat the repository as the source of truth.

When instructions conflict:
1. Existing project behavior and explicit developer/user requirements
2. Project Rules
3. Relevant Skill
4. Generic assumptions

Do not invent architecture, business rules, APIs, database columns, or integrations without inspecting the project first.
