# Codex workspace rules

## Git worktrees

- Create worktrees for this repository under `/home/obsidian/dev/opmoni/.worktrees/`.
- Reuse a suitable existing worktree before creating another one, and preserve its uncommitted work.
- Use native worktree tools only when they honor this directory. Otherwise use `git worktree add` with an explicit path inside `.worktrees/`.
- Do not create this project's worktrees under `/home/obsidian/.codex/worktrees/` or outside the repository's `.worktrees/` directory.
- Verify `.worktrees/` is ignored by Git before creating a worktree. Use the `codex/` branch prefix for new branches unless the user requests another name.
- Apply `backend/AGENTS.md` when working on backend files.
