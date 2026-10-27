---
trigger: creating, claiming, updating or closing a board item; `tools/board.py`, `.claude/pending/`
status: live
section: process
---

# Work tracker

⛔ **Never hand-edit `pending.md`** — it is generated. Never bulk-edit task files; touch only the one
your work is on.

## Task file format

```
---
id: 14
title: Short imperative title
status: actionable | verify | blocked | parked | chore
priority: 1-9
touched: YYYY-MM-DD
deploy: none | pending | YYYY-MM-DD
blocked_on: who/what (status blocked only)
verify_after: YYYY-MM-DD (status verify only)
wip: YYYY-MM-DDTHH:MM (written by --claim; expires after 6h)
---
State: where it stands, one line.
Next: the next concrete step and who owns it.
```

Two axes: **status** (can it be worked?) and **deploy** (is it on prod?). An item is deleted when
both close — git is the archive.

## Found $ ledger

`.claude/pending/found/*.md` records value the work surfaced. Four kinds — recoverable cash, cost
correction, leak stopped, exposure avoided — are four different claims and are never summed
together. Only `recoverable-cash` at `confidence: proven` goes into the headline. Every finding names
the script that regenerates its number.
