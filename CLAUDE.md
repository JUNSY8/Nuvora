# Nuvora — Claude Code

Guía maestra del proyecto (fuente de verdad compartida con Cursor y otros agentes):

@AGENTS.md

## Rules (siempre aplicables)

Las reglas viven en `.cursor/rules/` y se importan aquí para no duplicarlas:

@.cursor/rules/nuvora-core.mdc
@.cursor/rules/architecture.mdc
@.cursor/rules/database.mdc
@.cursor/rules/security.mdc
@.cursor/rules/frontend.mdc
@.cursor/rules/testing.mdc

## Skills

Las skills del proyecto están en `.claude/skills/` (copiadas de `.cursor/skills/`).
`code-review` se renombró a `nuvora-code-review` para no chocar con la skill integrada de Claude Code.
Si cambias una skill en `.cursor/skills/`, replica el cambio en `.claude/skills/`.

## Comandos

- Backend: `php artisan test`
- Frontend: `cd frontend && npm test` y `npm run build`
