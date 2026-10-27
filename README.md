# claude-agent-ops

The guardrails, work tracker and knowledge-base tooling that let **several Claude Code sessions work
on one live production codebase at the same time** without breaking each other, prod, or the
owner's trust.

I was hired to modernize the codebase of a small company's operations platform, working alongside
one other developer, the platform's original author. A few months ago I became the only developer. This
repo is an anonymized extract of the system I built so that one person directing several AI agent
sessions could keep shipping dozens of changes a week to a business that runs on the app every day.

Everything here is runnable: PHP 8.1+ and Python 3.10+, no dependencies.
`python tests/run_smoke.py` exercises every hook in both directions.

---

## The problem

One developer, four or five concurrent agent sessions, one working tree, one production server.
Each failure below happened at least once before a rule existed for it, and each rule was first
written as prose in `CLAUDE.md`. **Prose rules did not hold.** The fix, every time, was to move the
rule to the event where the mistake happens:

| What went wrong | Where it's enforced now |
|---|---|
| `git add -A` swept another session's half-finished file into a commit, and it reached prod ahead of its own migration | `git_commit_guard.php` (PreToolUse) |
| `--amend` rewrote a commit another session had just made | `git_commit_guard.php` (pre + post) |
| An agent pushed and deployed when the owner hadn't asked | `deploy_gate.php` (UserPromptSubmit + PreToolUse) |
| A one-off script with a wrong `WHERE` touched far more rows than intended | `prod_write_guard.php` + `lib/WriteScopeGuard.php` |
| Sessions ended with their own files uncommitted, and the owner's last session of the day inherited them | `edit_ledger.php` + `wrapup_guard.php` (Stop) |
| Docs claimed things the code no longer did | `kb_check.py` + `kb_check_hook.php` (PostToolUse) |
| A fresh clone loaded no machine rules at all, silently | `board.py` posture check + the trunk's tripwire |
| Agent SSH could reach a full shell on prod | `ssh_guard.php` + a server-side forced command |

## What's in here

```
CLAUDE.md                       trunk: universal rules only; imports the machine profile last
.claude/
  machine.local.md.example      copy to machine.local.md (gitignored): ONE line naming a profile
  machines/dev.md               dev-workstation posture: deploy handshake, wrap-up, tracker rules
  machines/analysis.md          read-only box posture
  decisions.md                  the "why" behind each rule, reached by anchor breadcrumbs
  settings.json                 hook wiring
  pending/tasks/*.md            the work tracker: one file per item
  pending/found/*.md            the Found $ ledger: value the work surfaced
tools/
  hooks/                        the guards (below)
  board.py                      tracker: render, claim, update, cull ballot, deploy ledger
  kb_index.py / kb_toc.py       generate the doc indexes from each doc's own frontmatter
  kb_check.py                   verify docs against the filesystem and code
  my_changes.php                which uncommitted paths belong to which session
  precheck.php                  the gate before any deploy hand-off
lib/WriteScopeGuard.php         a bulk write that counts its own predicate first
tests/run_smoke.py              36 allow/deny cases across every hook
integrations/samsara/           production fleet-API patterns: event attribution + replay,
                                a no-sandbox write gate, idempotent route creation
```

**[`integrations/samsara/`](integrations/samsara/README.md)** is the other half of the story: code
from the dispatch system itself. It covers geofence events attributed by physics instead of by
current state (proved by replaying history at point-in-time state), a write gate for an API with no
sandbox, and route creation that stays idempotent through webhook bursts and an eventually
consistent lookup.

### The hooks

**`git_commit_guard.php`**: refuses broad `git add`, `commit -a`, a bare `stash` or `stash clear`,
and an `--amend` of a commit this session didn't make. It records HEAD after each commit, so the
amend check is exact. It also refuses an app-code commit without a `#N:` task id, because the
deploy planner finds a task's work only by that prefix. It matches `git` only at a command
position and blanks quoted text first, so `grep "git add -A"` passes.

**`deploy_gate.php`**: `git push` and pipeline tags are refused until the owner types `deploy`
(or `deploy #12`) as the *whole* prompt in that session. The clearance lasts an hour. Permission
rules couldn't do this: `ask` didn't prompt in auto mode, and `deny` can't tell that the owner
consented.

**`prod_write_guard.php`**: refuses to run a PHP script against prod if the script contains
`UPDATE`/`DELETE`/`TRUNCATE`/`DROP` and doesn't use `WriteScopeGuard`. The trigger is a *parse*,
not a search. The first version searched the raw command string and refused four ordinary commits
whose messages said "deployed to prod", which trains people to reach for the override. The current
version lexes the command, examines only real `php` invocations, recurses into `bash -c` and
`powershell -Command`, and ignores comments in the target file.

**`edit_ledger.php` → `my_changes.php` → `wrapup_guard.php`**: every Edit/Write is logged per
session. At the end of a turn, the Stop hook blocks *once* if this session's files are still
uncommitted. It only blocks again if that set changes, so pausing to ask a question doesn't loop.

**`kb_check_hook.php`**: after any edit to `.claude/*.md`, it runs the KB invariant checker and
puts the failures back into the same turn. It reports and never blocks: a doc refactor is
legitimately inconsistent for a few edits.

**`ssh_guard.php`**: the only SSH an agent may run is `ssh <peek-host> …`. That key is bound on the
server to a forced command that only runs read-only checks. Every other ssh, scp, sftp or rsync
shape is refused, and so is any command that names the owner's key files.

### The knowledge base

The instructions an agent auto-loads are layered: a **trunk** (`CLAUDE.md`) holds rules that are
true on every machine, and a **posture profile** (`machines/dev.md` or `machines/analysis.md`) is
picked by a gitignored one-line file. Profiles only add rules. Every link in that import chain
fails silently, so `board.py` checks the whole chain each time it renders.

- **Docs own their routing line.** Each `.claude/*.md` doc carries `trigger` / `status` / `section`
  frontmatter, and `kb_index.py` regenerates the indexes from it. The hand-maintained index this
  replaced had drifted into summaries.
- **Auto-loaded text has a byte budget** (75 KB). Rules accumulate after every incident and are
  never retired unless something counts them.
- **Check invariants, not self-consistency.** `kb_check.py` compares doc claims with independent
  sources: does a path named in a trigger exist, does every `decisions.md#anchor` cited in code
  resolve, is every doc in exactly one index. A checker that only agrees with its own generator
  catches nothing.

### The work tracker (`board.py`)

- **One file per item, flat frontmatter.** Rendering only reads. Writes touch one file and re-read
  it immediately before writing, so concurrent sessions don't clobber each other.
- **Ids are never reused.** A high-water counter is committed when an id is allocated, so "#14"
  means the same item next month.
- **Three separate axes:** `status` (can it be worked?), `deploy` (is it on prod?) and two kinds of
  "current": the owner's ★ and a session's ⚡ `wip` claim, which expires after 6 hours because
  sessions get killed and never clean up.
- **Reading the board is not permission to start.** The one exception is bucket E, chores with a
  single sensible implementation. `--check` rejects a chore that carries a blocker.
- **Ties sort oldest-touched first**, so a neglected item rises instead of being stuck behind
  alphabetical order. `--cold` prints a read-only *cull ballot* for the owner, because a backlog
  with no way to decline items grows without bound.
- **The deploy ledger** puts each pending item's numbered DEPLOY block on the board, because that
  is the surface the owner actually acts on.
- **The Found $ ledger** records value the work surfaced in four kinds that are **never summed
  together** (recoverable cash, cost correction, leak stopped, exposure avoided). Only proven,
  recoverable cash goes into the headline, and every figure must name the script that regenerates it.

## Try it

```bash
cp .claude/machine.local.md.example .claude/machine.local.md
python tools/board.py            # render .claude/pending/pending.md
python tools/board.py --check    # validate tasks + the machine-profile chain
python tools/kb_index.py --check
python tools/kb_check.py
python tests/run_smoke.py        # 36 hook cases, allow and deny
php integrations/samsara/tests/run_tests.php   # 32 cases, incl. the attribution replay
php tools/precheck.php --full    # lint + docs; exits 2 if phpstan/phpunit aren't installed (SKIP ≠ PASS)
```

To adopt the hooks, copy `tools/hooks/` and `.claude/settings.json`, then edit `HOOK_APP_ROOTS` in
`app_paths.php`, `PEEK_HOST` / `OWNER_KEY_RE` in `ssh_guard.php` and `PROD_CRED_FILE` in
`prod_write_guard.php`.

## Design rules I'd keep

1. **A rule that matters fires on an event.** If a mistake keeps happening, the rule belongs in a
   hook, not in the prompt.
2. **A guard that fires on ordinary work is worse than no guard**, because it teaches the override.
   Parse instead of search, and test the look-alike that must pass.
3. **Choose fail-open or fail-closed on purpose, per guard.** The task-id check fails open. The
   deploy gate and the SSH guard fail closed. Every Stop/PostToolUse hook exits 0 on internal
   error, so a broken guard never traps a session.
4. **A step that couldn't run is a SKIP, never a PASS.**
5. **Break a new check before trusting it.** Every hook test here was mutated to confirm it can fail.

MIT licensed.
