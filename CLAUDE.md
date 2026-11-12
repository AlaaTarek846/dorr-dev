# Dorr — Claude Code

The project rules live in AGENTS.md (shared with every agent and developer). They are mandatory:

@AGENTS.md

Most often missed — the **admin screen standard** (map, guide, permissions, languages, CRUD like countries) for every
new module and every admin screen added or changed: [docs/screens/README.md](docs/screens/README.md). It is enforced by
`tests/Feature/AdminScreenStandardTest.php`, and the `PostToolUse` hook `.claude/hooks/admin-screen-standard.sh`
repeats the checklist whenever a screen, its API, the sidebar or a module changes.
