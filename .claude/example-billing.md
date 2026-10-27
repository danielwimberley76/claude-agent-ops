---
trigger: touching `lib/WriteScopeGuard.php`, any bulk write script, or a report that filters on a status column
status: example doc — shows the header contract
section: data
---

# Example module doc — billing data

⛔ **Traps under the H1 — these are wrong silently, not loudly.**
- **<status column>** is an alive-flag, not a lifecycle. Filtering on it matches nearly every row.
- **<numeric column> = <sentinel>** means "see instructions", not a quantity. Bound every aggregate.

The body carries the proof for each trap: the query that showed it, when, and on which environment.
The `trigger` line above is what `tools/kb_index.py` turns into a one-line routing entry in
`CLAUDE.md`; `tools/kb_check.py` fails if a path it names stops existing.
