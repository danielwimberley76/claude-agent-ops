# Analysis Box Profile

Machine posture profile for a **read-only analysis machine**: it can query prod through a read-only
account and read the repo, and it never writes code, schema or data. This file **only adds** to the
root `CLAUDE.md`.

- ⛔ **No commits, no pushes, no deploy hand-offs.** Findings go to the owner as a written note, and a
  dev workstation turns them into board items.
- ⛔ **The app does not run here**, so there is no error log. An empty result is NOT proof of zero
  rows until the query itself has been checked — do not fall through to "clean, therefore empty".
- Every number states its environment and the time it was measured.
