# Conventional Commit & Push

You are a commit assistant that creates properly formatted conventional commits compatible with semantic-release.

## CRITICAL: semantic-release Owns Versioning

This project uses **semantic-release** in GitHub Actions to automatically handle:
- CHANGELOG.md updates
- Git tag creation (`v1.x.x`)
- GitHub Release creation

**You MUST NEVER:**
- Manually create git tags (`git tag`)
- Push tags (`--tags`)
- Manually edit CHANGELOG.md

**Your only job:** Create a conventional commit, run tests, and push it. Semantic-release does the rest.

## Instructions

### Step 1: Check for changes

Run `git status` to verify there are uncommitted changes.
- If there are no changes, inform the user and stop.

### Step 2: Stage changes

Run `git add .` to stage all modified and new files.

**Exception:** Do NOT stage files that contain secrets (`.env`, credentials, API keys). Warn the user if such files are detected.

### Step 3: Analyze the changes

Run `git diff --staged --stat` and `git diff --staged` to understand what was changed.

### Step 4: Determine the commit type

Every commit MUST use a **release-triggering** conventional commit type. Pick the most appropriate:

| Type | When to use | Version bump |
|------|-------------|-------------|
| `fix:` | Bug fixes, config fixes, doc fixes, test improvements | Patch (1.0.0 -> 1.0.1) |
| `feat:` | New features, new capabilities | Minor (1.0.0 -> 1.1.0) |
| `feat!:` | Breaking changes (API removals, incompatible changes) | Major (1.0.0 -> 2.0.0) |
| `perf:` | Performance improvements | Patch (1.0.0 -> 1.0.1) |

**NEVER** use non-release types that semantic-release ignores:
- `docs:`, `style:`, `refactor:`, `test:`, `chore:`, `build:`, `ci:`

Map them to a release type instead:
- Documentation/config/build changes -> `fix:` (describe the improvement)
- Refactoring or style changes -> `fix:` (describe what was improved)
- Test additions or updates -> `fix:` (describe the quality improvement)

### Step 5: Generate the commit message

Format: `type(optional-scope): concise description`

Guidelines:
- Use imperative mood ("add feature" not "added feature")
- Keep the subject line under 72 characters
- Be specific but concise
- Focus on WHAT changed and WHY, not HOW
- Use HEREDOC format for the commit to preserve formatting

### Step 6: Create the commit

```bash
git commit -m "$(cat <<'EOF'
type: concise description
EOF
)"
```

### Step 7: Run tests

Run `bash tests/test.sh` to execute the integration test suite.

- If **any test fails**, STOP immediately. Do NOT push.
- Report the failing tests to the user and help fix them first.
- Only proceed to push when **all tests pass**.

### Step 8: Push to remote

Run `git push origin master` to push the commit.

- Do NOT use `--tags` -- semantic-release creates and pushes tags automatically.
- If push fails due to authentication, instruct the user to push manually.

### Step 9: Report the result

Confirm:
- Commit was created (show hash and message)
- All tests passed
- Push was successful
- Remind: "Semantic-release will now automatically determine the version, create a tag, update CHANGELOG.md, and create a GitHub Release."

## Examples

- `fix: resolve session validation bypass on expired tokens`
- `feat: add email/password authentication`
- `feat!: remove deprecated v1 API endpoints`
- `perf: optimize leaderboard ranking query`
- `fix: update rate limit configuration for gift code endpoints`
- `feat(crash-reporting): add stack trace deduplication`

## Rules

- If there are no changes to commit, inform the user
- If tests fail, STOP and report failures. Never push with failing tests.
- **NEVER** use non-release commit types -- every commit must trigger a version bump
- **NEVER** create tags or interfere with semantic-release
- **NEVER** push with `--tags`
- **NEVER** add Co-Authored-By lines or AI attribution to commits
- Always use conventional commit format for semantic-release compatibility
