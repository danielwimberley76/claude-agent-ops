#!/usr/bin/env python
"""
board.py — a work tracker built for several concurrent AI agent sessions on one repo.

The task files (.claude/pending/tasks/*.md, one per item, flat frontmatter) are the source of
truth. Rendering only READS them and writes the generated board (.claude/pending/pending.md), so
a render can never clobber a concurrent session. Writes are sharded: each session touches only
the one task file it is working.

--claim / --release / --update are the only writers of an existing task file. Each is surgical
(the named keys only, the rest of the file byte-for-byte) and each re-reads the file immediately
before writing, refusing if it changed underneath it.

Usage (from project root):
    python tools/board.py                 # render pending.md
    python tools/board.py --check         # validate frontmatter + machine profile, render nothing
    python tools/board.py --list A        # print one bucket
    python tools/board.py --chores        # bucket E + the rules that make it pre-authorized
    python tools/board.py --show 14       # print one task by #id, slug or title
    python tools/board.py --cold          # the cull ballot: what has gone cold, and why
    python tools/board.py --new-id        # reserve (and commit) the next #id
    python tools/board.py --new-provisional "exploring X"   # id + minimal file + claim
    python tools/board.py --claim 14      # stamp wip: — a session is on it NOW
    python tools/board.py --release 14    # clear wip:
    python tools/board.py --update 14 --set deploy=pending --state "…" --next "…" --manifest @m.txt [--commit]

No third-party dependencies.
"""

import argparse
import datetime as dt
import json
import os
import re
import subprocess
import sys
from pathlib import Path


def _tracker_root() -> Path:
    """The checkout whose .claude/pending/ is the live tracker: ALWAYS the main folder.

    Run from a git worktree, this file's own directory is the worktree, whose copy of the task
    files was frozen when the worktree was made. A worktree session once read an item as open and
    unstarted from exactly that copy, after main had built, deployed and deleted it. So a worktree
    resolves to the main checkout, found through git's common dir.
    """
    here = Path(__file__).resolve().parent.parent
    try:
        out = subprocess.run(
            ["git", "-C", str(here), "rev-parse", "--path-format=absolute", "--git-common-dir", "--git-dir"],
            capture_output=True, text=True, timeout=10, check=True,
        ).stdout.split("\n")
        common, gitdir = Path(out[0].strip()).resolve(), Path(out[1].strip()).resolve()
    except (OSError, subprocess.SubprocessError, IndexError):
        return here
    if common == gitdir or common.name != ".git":
        return here
    main = common.parent
    if (main / ".claude" / "pending" / "tasks").is_dir():
        print(f"(worktree: using the MAIN folder's board at {main})", file=sys.stderr)
        return main
    return here


ROOT = _tracker_root()
PENDING = ROOT / ".claude" / "pending"
TASKS = PENDING / "tasks"
INBOX = PENDING / "inbox.md"
BOARD = PENDING / "pending.md"

# The Found $ ledger: one shard per finding the work surfaced (money recoverable, a cost figure
# corrected, a leak stopped). A SEPARATE directory from tasks/ because a task file is deleted once
# nothing is owed on it (git is the archive), and a finding has to outlive the work that proved it.
FOUND = PENDING / "found"

# The four kinds are four different CLAIMS, not four sizes of one claim, which is why only one
# of them is ever summed into a headline.
FOUND_KINDS = {
    "recoverable-cash": "money we may get back, or should have billed and still can",
    "cost-correction": "a stored figure is wrong and a report reads it; no cash moves",
    "leak-stopped": "a shipped fix that stops a recurring loss — the value is a RATE",
    "exposure-avoided": "a loss prevented or caught by a human — NEVER summed",
}
FOUND_CONFIDENCE = ("proven", "measured", "estimated", "unquotable")
FOUND_BASIS = ("one-time", "to-date", "per-month", "per-year")
FOUND_STATUS = ("open", "in-hand", "written-off", "no-action")

# ⭐ THE SUMMING RULE. A headline total sums recoverable-cash at confidence:proven and nothing
# else. Structural on purpose: a spreadsheet once labelled a figure "(recoverable)" before the
# counterparty had confirmed it. Making the rule a constant beats remembering it.
QUOTABLE_KIND = "recoverable-cash"
QUOTABLE_CONFIDENCE = "proven"

# The id allocator's high-water mark, tracked in git. Ids are NEVER reused — "#14" said in a
# conversation last month must not silently resolve to a different item today. max(ids)+1 alone
# cannot promise that: delete the highest-numbered task and the next one takes its number back.
NEXT_ID = PENDING / ".next-id"

# Machine-posture import chain: CLAUDE.md -> .claude/machine.local.md -> .claude/machines/<x>.md
CLAUDE_MD = ROOT / "CLAUDE.md"
MACHINE_LOCAL = ROOT / ".claude" / "machine.local.md"
MACHINES = ROOT / ".claude" / "machines"

RESUME_WINDOW_DAYS = 3

# How long a `wip:` stamp stays believable. Sessions get killed, compacted and escaped out of, so
# the flag CANNOT depend on cleanup to stop lying. Past this it is treated as absent everywhere.
WIP_EXPIRY_HOURS = 6

# Untouched this long = COLD: not wrong, but overdue a keep/park/drop call.
COLD_DAYS = 30

# Letter order IS reading order. The letter is a positional handle (`--list C`).
BUCKETS = [
    ("A", "actionable", "ACTIONABLE NOW   (no external blocker — pick up today; high→low)"),
    ("B", "verify", "DEPLOYED → awaiting VERIFY   (bytes on prod, second axis open)"),
    ("C", "blocked", "BLOCKED   (waiting on a person/decision — ⟵ names the blocker)"),
    ("D", "parked", "PARKED / low-priority   (deliberately deferred)"),
    ("E", "chore", "CHORES   (no decision to make — PRE-AUTHORIZED: an agent may pick and work these)"),
]
STATUSES = {s for _, s, _ in BUCKETS}

# Bucket E is the one place the "propose, don't pick" gate is lifted, so what may ENTER it is the
# only guard there is. The admission test is NOT "small" — it is "an agent working this cannot
# make a wrong call about HOW." An item with two defensible approaches is `actionable` no matter
# how quick it is. See .claude/decisions.md#bucket-e-chores-are-pre-authorized
CHORE_STATUS = "chore"

# A STANDING chore never finishes (test coverage, doc trimming). It breaks the "delete when done"
# rule on purpose, so it is marked rather than left as an undocumented exception.
STANDING_KEY = "standing"
RULE = "─" * 79
HEAVY = "═" * 79
# A manifest heading: the word DEPLOY opening the line, past markdown/emoji decoration. \b keeps
# "DEPLOYED 2026-09-15" (history) out of a debt list.
DEPLOY_HEAD_RE = re.compile(r"^[\s#>*`⭐⛔✅⚠️]*DEPLOY\b", re.I)
NUMBERED_PATH_RE = re.compile(r"^\s*\d+[a-z]?\.\s+\S")


class Task:
    def __init__(self, path, meta, body):
        self.path = path
        self.slug = path.stem
        self.meta = meta
        self.body = body.rstrip()

    def __getattr__(self, name):
        return self.meta.get(name, "")

    @property
    def id(self):
        """The ONE handle short enough to say out loud and stable enough to mean the same thing
        tomorrow. Titles get reworded, priorities churn, positions are derived."""
        raw = str(self.meta.get("id", "")).strip().lstrip("#")
        return int(raw) if raw.isdigit() else None

    @property
    def tag(self):
        """`#14` for display. Never blank — a file missing its id must LOOK wrong."""
        return f"#{self.id}" if self.id is not None else "#??"

    @property
    def priority(self):
        try:
            return int(self.meta.get("priority", 99))
        except ValueError:
            return 99

    @property
    def touched_date(self):
        return parse_date(self.meta.get("touched", ""))

    @property
    def is_current(self):
        """The OWNER's star — what they care about. A separate axis from status."""
        return self.meta.get("current", "") == "yes"

    @property
    def wip_at(self):
        return parse_stamp(self.meta.get("wip", ""))

    @property
    def is_wip(self):
        """A session is working this RIGHT NOW (within the expiry window).

        A THIRD axis, distinct from status and `current`: `current` is the owner's star, `wip` is
        a session's own "I picked this up". Conflating them would let a session silently
        overwrite the owner's claim. Bounded at BOTH ends — a future stamp is a typo, and the safe
        direction for an untrusted value is absent, never permanently live.
        """
        at = self.wip_at
        if at is None:
            return False
        age = dt.datetime.now() - at
        return dt.timedelta(0) <= age < dt.timedelta(hours=WIP_EXPIRY_HOURS)

    @property
    def is_standing(self):
        return self.meta.get(STANDING_KEY, "") == "yes"

    def marks(self):
        """Leading markers, owner's star first — it outranks a session's claim."""
        return ("★ " if self.is_current else "") + ("⚡ " if self.is_wip else "")

    def heading(self):
        line = f"### {self.tag}  " + self.marks() + (self.meta.get("title") or self.slug)
        if self.meta.get("blocked_on"):
            line += "   ⟵ " + self.meta["blocked_on"]
        if self.meta.get("refs"):
            line += "   — " + self.meta["refs"]
        return line


def parse_date(s):
    try:
        return dt.date.fromisoformat(str(s).strip())
    except (ValueError, TypeError):
        return None


def parse_stamp(s):
    """`YYYY-MM-DDTHH:MM` -> datetime. A wip stamp needs a time: the expiry window is hours."""
    s = str(s).strip().replace(" ", "T")
    if not s:
        return None
    try:
        return dt.datetime.fromisoformat(s)
    except (ValueError, TypeError):
        return None


def parse_frontmatter(text, path):
    """Flat `key: value` frontmatter between --- fences. No nesting, no lists."""
    if not text.startswith("---"):
        raise ValueError(f"{path.name}: missing --- frontmatter fence")
    parts = text.split("---", 2)
    if len(parts) < 3:
        raise ValueError(f"{path.name}: unterminated frontmatter")
    meta = {}
    for raw in parts[1].strip().splitlines():
        raw = raw.strip()
        if not raw or raw.startswith("#"):
            continue
        if ":" not in raw:
            raise ValueError(f"{path.name}: bad frontmatter line: {raw!r}")
        key, val = raw.split(":", 1)
        val = val.strip()
        if len(val) >= 2 and val[0] == val[-1] and val[0] in "\"'":
            quote, val = val[0], val[1:-1]
            if quote == "'":
                val = val.replace("''", "'")
        meta[key.strip()] = val
    return meta, parts[2].lstrip("\n")


def load_tasks():
    tasks, errors = [], []
    if not TASKS.is_dir():
        return tasks, [f"no task directory at {TASKS}"]
    for path in sorted(TASKS.glob("*.md")):
        try:
            meta, body = parse_frontmatter(path.read_text(encoding="utf-8"), path)
            tasks.append(Task(path, meta, body))
        except ValueError as e:
            errors.append(str(e))
    return tasks, errors


class Finding:
    """One shard of the Found $ ledger. Keyed by slug; `task` may be absent."""

    def __init__(self, path, meta, body):
        self.path = path
        self.slug = path.stem
        self.meta = meta
        self.body = body.rstrip()

    def __getattr__(self, name):
        return self.meta.get(name, "")

    @property
    def amount_value(self):
        """Float, or None when deliberately unpriced. A finding may be SIZED but not PRICED —
        that absence is the honest answer, and must never be coerced to 0.0 and summed."""
        raw = str(self.meta.get("amount", "")).strip().replace("$", "").replace(",", "")
        if not raw:
            return None
        try:
            return float(raw)
        except ValueError:
            return None

    @property
    def is_quotable(self):
        return (self.meta.get("kind") == QUOTABLE_KIND
                and self.meta.get("confidence") == QUOTABLE_CONFIDENCE
                and self.amount_value is not None)


def load_findings():
    findings, errors = [], []
    if not FOUND.is_dir():
        return findings, errors
    for path in sorted(FOUND.glob("*.md")):
        try:
            meta, body = parse_frontmatter(path.read_text(encoding="utf-8"), path)
            findings.append(Finding(path, meta, body))
        except ValueError as e:
            errors.append(str(e))
    return findings, errors


def validate_findings(findings):
    problems = []
    for f in findings:
        where = f"found/{f.path.name}"
        for key, vocab in (("kind", FOUND_KINDS), ("confidence", FOUND_CONFIDENCE),
                           ("basis", FOUND_BASIS), ("status", FOUND_STATUS)):
            val = (f.meta.get(key) or "").strip()
            if not val:
                problems.append(f"{where}: missing {key}")
            elif val not in vocab:
                problems.append(f"{where}: {key} {val!r} not one of {', '.join(sorted(vocab))}")

        raw = str(f.meta.get("amount", "")).strip()
        if raw and f.amount_value is None:
            problems.append(f"{where}: amount {raw!r} does not parse — digits only")

        # ⭐ The rule the ledger's worth rests on: every figure is DERIVED, so a number with no
        # re-runnable source is indistinguishable from one someone remembered wrong.
        if not (f.meta.get("evidence") or "").strip():
            problems.append(f"{where}: evidence is empty — name the script or doc that regenerates the figure")

        if f.meta.get("confidence") == "unquotable" and not (f.meta.get("blocked_on") or "").strip():
            problems.append(f"{where}: confidence unquotable needs blocked_on saying what would settle it")

        # ⭐ A `to-date` figure GROWS, so it goes stale on its own, silently. Requiring the next
        # re-measure date makes that decay visible on the board instead of relying on a habit.
        rm = (f.meta.get("remeasure_after") or "").strip()
        if f.meta.get("basis") == "to-date" and not rm:
            problems.append(f"{where}: basis to-date needs remeasure_after (YYYY-MM-DD)")
        if rm and not parse_date(rm):
            problems.append(f"{where}: remeasure_after {rm!r} must be YYYY-MM-DD")

        # The script that regenerates the number is the load-bearing half of the evidence.
        for ref in re.findall(r"(?:\.claude|tools)[/\\][\w./\\-]+\.(?:md|php|py)", f.meta.get("evidence", "")):
            if not (ROOT / ref.replace("\\", "/")).exists():
                problems.append(f"{where}: evidence points at missing {ref} — restore it or downgrade confidence")
    return problems


def allocate_id(tasks):
    """Reserve and return the next id, bumping the counter immediately — on ALLOCATION, not on
    file-write, so a second session allocating in between already sees the number gone."""
    used = [t.id for t in tasks if t.id is not None]
    floor = max(used) + 1 if used else 1
    stored = 0
    if NEXT_ID.exists():
        raw = NEXT_ID.read_text(encoding="utf-8").strip()
        stored = int(raw) if raw.isdigit() else 0
    nxt = max(floor, stored)
    NEXT_ID.write_text(f"{nxt + 1}\n", encoding="utf-8", newline="\n")
    _record_in_ledger(NEXT_ID)
    return nxt


LEDGER_DIR = ROOT / ".claude" / "session-ledger"
SESSION_RE = re.compile(r"^[A-Za-z0-9-]{8,64}$")


def _record_in_ledger(*paths):
    """Append these writes to the running session's edit ledger (see tools/hooks/edit_ledger.php).

    That hook sees only Edit/Write. This script runs under Bash, so without this its writes were
    invisible to my_changes.php AND to the Stop hook, and sessions left task files dirty.
    Outside Claude Code (no CLAUDE_CODE_SESSION_ID) it does nothing. Never raises.
    """
    sid = os.environ.get("CLAUDE_CODE_SESSION_ID", "")
    if not SESSION_RE.match(sid):
        return
    try:
        LEDGER_DIR.mkdir(parents=True, exist_ok=True)
        now = dt.datetime.now().astimezone().isoformat(timespec="seconds")
        rows = "".join(
            json.dumps({"t": now, "p": str(Path(p).resolve()).replace("\\", "/").lower()},
                       separators=(",", ":")) + "\n"
            for p in paths)
        with open(LEDGER_DIR / f"{sid}.jsonl", "a", encoding="utf-8", newline="\n") as fh:
            fh.write(rows)
    except OSError:
        pass


def _git_commit(msg, *paths, add=False):
    """Commit exactly `paths` in the main checkout; return None, or git's complaint. A pathspec
    commit takes only these paths, whatever else another session has staged."""
    def git(*args):
        out = subprocess.run(["git", "-C", str(ROOT), *args, "--", *map(str, paths)],
                             capture_output=True, text=True)
        return None if out.returncode == 0 else (out.stderr or out.stdout).strip()
    return (add and git("add")) or git("commit", "-q", "-m", msg)


def _commit_allocation(nid, msg, *paths):
    """Commit the bumped counter the moment it is allocated, so it never sits dirty and
    unattributed. Notices go to stderr so `--new-id` prints only the number on stdout."""
    err = _git_commit(msg, NEXT_ID, *paths, add=True)
    if err:
        listed = " ".join(str(p.relative_to(ROOT)) for p in (NEXT_ID, *paths))
        print(f"   NOT committed ({err}) — commit {listed} yourself under #{nid}:", file=sys.stderr)
    else:
        print(f"   committed in {ROOT}: {msg}", file=sys.stderr)


def resolve_ref(tasks, ref):
    """`#14` / `14` / a slug / a unique title substring -> Task. Raises LookupError saying WHICH
    way it failed — unknown, or ambiguous."""
    ref = str(ref).strip()
    bare = ref.lstrip("#")
    if bare.isdigit():
        for t in tasks:
            if t.id == int(bare):
                return t
        raise LookupError(f"no task with id #{bare}")
    lowered = ref.lower()
    for t in tasks:
        if t.slug.lower() == lowered:
            return t
    hits = [t for t in tasks if lowered in (t.title or "").lower() or lowered in t.slug.lower()]
    if len(hits) == 1:
        return hits[0]
    if not hits:
        raise LookupError(f"nothing matches {ref!r} — try an id (#14), a slug, or part of a title")
    listing = "\n".join(f"    {t.tag}  {t.slug}  {t.title}" for t in hits)
    raise LookupError(f"{ref!r} matches {len(hits)} tasks — be more specific:\n{listing}")


def validate(tasks):
    problems = []
    seen = {}
    by_id = {}
    for t in tasks:
        where = t.path.name
        raw_id = str(t.meta.get("id", "")).strip()
        if not raw_id:
            problems.append(f"{where}: no id — get one with `python tools/board.py --new-id`")
        elif t.id is None:
            problems.append(f"{where}: id {raw_id!r} is not a positive integer")
        elif t.id in by_id:
            # Never silently renumber — that would break whichever reference happened to be right.
            problems.append(f"{where}: duplicate id #{t.id}, also in {by_id[t.id]} — give ONE a fresh id")
        else:
            by_id[t.id] = where
    for t in tasks:
        where = t.path.name
        if not t.meta.get("title"):
            problems.append(f"{where}: no title")
        status = t.meta.get("status")
        if status not in STATUSES:
            problems.append(f"{where}: status {status!r} not one of {sorted(STATUSES)}")
        if status == "blocked" and not t.meta.get("blocked_on"):
            problems.append(f"{where}: status blocked but no blocked_on")
        if status == "verify" and not t.meta.get("verify_after"):
            problems.append(f"{where}: status verify but no verify_after date")
        # An out-of-range priority pins an item to the BOTTOM of its bucket permanently, which is
        # precisely "pushed below the fold where it gets lost".
        if "priority" in t.meta:
            raw_p = str(t.meta["priority"]).strip()
            if not raw_p.isdigit() or not (1 <= int(raw_p) <= 9):
                problems.append(f"{where}: priority {raw_p!r} must be 1-9")
        if not t.meta.get("touched"):
            problems.append(f"{where}: no touched date")
        elif not t.touched_date:
            problems.append(f"{where}: touched {t.meta['touched']!r} is not YYYY-MM-DD")
        for key in ("current", STANDING_KEY):
            if key in t.meta and t.meta[key] != "yes":
                problems.append(f"{where}: {key} {t.meta[key]!r} must be 'yes' or absent")
        # Bucket E is pre-authorized, so a mislabelled item is an agent cleared to work something
        # that needed the owner first.
        if status == CHORE_STATUS:
            if t.meta.get("blocked_on"):
                problems.append(f"{where}: status chore but blocked_on is set — a chore is unblocked by construction")
            if t.meta.get("verify_after"):
                problems.append(f"{where}: status chore but verify_after is set")
        if "wip" in t.meta:
            # A malformed stamp never expires, because it never parses.
            raw = t.meta["wip"]
            if t.wip_at is None:
                problems.append(f"{where}: wip {raw!r} is not a timestamp — use YYYY-MM-DDTHH:MM")
            elif "T" not in raw.strip().replace(" ", "T"):
                problems.append(f"{where}: wip {raw!r} has no time")
        deploy = t.meta.get("deploy", "none")
        if deploy not in ("none", "pending") and not parse_date(deploy):
            problems.append(f"{where}: deploy {deploy!r} must be none|pending|YYYY-MM-DD")
        # status and deploy are separate axes, but "awaiting verify" means bytes ARE on prod.
        if status == "verify" and not parse_date(deploy):
            problems.append(f"{where}: status verify but deploy is {deploy!r} — verify needs a deploy date")
        for ref in re.findall(r"\.claude[/\\][\w./\\-]+\.md", t.meta.get("refs", "")):
            if not (ROOT / ref.replace("\\", "/")).exists():
                problems.append(f"{where}: refs points at missing {ref}")
        title = (t.meta.get("title") or "").lower()
        if title in seen:
            problems.append(f"{where}: duplicate title, also in {seen[title]}")
        seen[title] = where
    return problems


def queue_key(task):
    """Priority first, then OLDEST-TOUCHED first, then slug. The middle term is an escalator.

    Sorting ties by slug made position on the board *the first letter of the filename*, forever.
    Sorting ties by oldest-touched turns that into a slow escalator: every time the items around
    it are worked, a neglected item rises, until it has to be looked at. A missing `touched`
    sorts oldest — the safe direction for an untrusted value is *visible*.
    """
    return (task.priority, task.touched_date or dt.date.min, task.slug)


def resume_queue(tasks):
    """Tier 1 = recently touched, starred or claimed, not parked. Tier 2 = the rest of bucket A.
    Chores are held out of both unless starred or claimed."""
    dated = [
        t for t in tasks
        if t.touched_date and t.status != "parked"
        and (t.status != CHORE_STATUS or t.is_current or t.is_wip)
    ]
    if not dated:
        return [], [t for t in tasks if t.status == "actionable"]
    newest = max(t.touched_date for t in dated)
    cutoff = newest - dt.timedelta(days=RESUME_WINDOW_DAYS)
    tier1 = sorted(
        (t for t in dated if t.touched_date >= cutoff or t.is_current or t.is_wip),
        key=lambda t: (not t.is_current, not t.is_wip, -t.touched_date.toordinal(), t.priority),
    )
    hot = {t.slug for t in tier1}
    tier2 = sorted((t for t in tasks if t.status == "actionable" and t.slug not in hot), key=queue_key)
    return tier1, tier2


def one_line_state(task):
    for line in task.body.splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            return line[:150] + ("…" if len(line) > 150 else "")
    return ""


def _labelled_line(body, label, width=190):
    """The first `State:` / `Next:` line, with its indented continuation lines folded in."""
    lines = body.splitlines()
    for i, raw in enumerate(lines):
        if not raw.strip().lower().startswith(label.lower() + ":"):
            continue
        parts = [raw.strip()]
        for cont in lines[i + 1:]:
            if not cont.strip() or not cont[:1].isspace():
                break
            if re.match(r"\s*(state|next|why|risk)\s*:", cont, re.I):
                break
            parts.append(cont.strip())
        text = re.sub(r"\s+", " ", " ".join(parts))
        return text[:width] + ("…" if len(text) > width else "")
    return ""


def body_pointer(task):
    """The task body reduced to its POINTER — State:, Next:, and where the rest lives.

    Printing every body in full once made the board ~800 KB, so only items near the top got read.
    The pointer keeps it scannable; the full text stays one `--show <id>` away. The hidden-line
    count is deliberate: a truncation nobody can see is a truncation nobody can correct.
    """
    out = []
    for label in ("State", "Next"):
        text = _labelled_line(task.body, label)
        if text:
            out.append("    " + text)
    if not out:
        lead = one_line_state(task)
        if lead:
            out.append("    " + lead)
    total = len([ln for ln in task.body.splitlines() if ln.strip()])
    hidden = max(0, total - len(out))
    tail = f"    → tasks/{task.slug}.md"
    if hidden:
        tail += f"  ({hidden} more line(s) — python tools/board.py --show {task.id})"
    out.append(tail)
    return out


def bucket_letter(task):
    for letter, status, _ in BUCKETS:
        if task.status == status:
            return letter
    return "?"


def list_cold(tasks, days=COLD_DAYS):
    """The CULL BALLOT — what has gone cold, segmented by the question it actually asks.

    A backlog with no declining lane grows without bound by construction: `parked` still renders
    and never expires, so nothing left the board except by being finished, and intake ran ahead
    of closure every month.

    ⛔ THIS IS A BALLOT, NOT A WORK QUEUE. Read-only. Every line is a keep / park / re-price / drop
    question for the OWNER — "nobody has touched it" is evidence about attention, never value.
    """
    today = dt.date.today()

    def age(t):
        return (today - t.touched_date).days if t.touched_date else None

    cold = [t for t in tasks if age(t) is not None and age(t) >= days and not t.is_standing]
    out = [
        RULE,
        f"CULL BALLOT — {len(tasks)} open item(s); cold threshold {days} days.",
        RULE,
        "Read-only. A session may NOT act on this list on its own — each line is a",
        "keep / park / re-price / drop call for the owner.",
        "",
    ]

    def segment(title, why, members, extra=None):
        out.extend([RULE, title, "  " + why, RULE])
        if not members:
            out.extend(["  (none)", ""])
            return
        for t in sorted(members, key=queue_key):
            out.append(f"  {t.tag:>5}  p{t.priority:<2} {age(t):>4}d cold  {bucket_letter(t)}  {(t.title or t.slug)[:62]}")
            note = extra(t) if extra else ""
            if note:
                out.append(f"          {note}")
        out.append("")

    segment("A. ACTIONABLE BUT COLD — created, never picked up.",
            "Was it ever going to be done? Re-price or drop.",
            [t for t in cold if t.status == "actionable" and not parse_date(t.deploy)])
    segment("B. SHIPPED RESIDUE — the code landed; a follow-on keeps the item alive.",
            "Re-price to what REMAINS, or drop.",
            [t for t in cold if parse_date(t.deploy) and t.status in ("actionable", "parked")],
            extra=lambda t: f"deployed {t.deploy}")
    segment("C. PARKED AND COLD — deliberately deferred, and still here.",
            "Parking is not closing.",
            [t for t in cold if t.status == "parked" and not parse_date(t.deploy)])
    segment("D. BLOCKED AND COLD — waiting on a person longer than anyone waits.",
            "Usually the ask was never made, or was answered and nobody heard. Chase or drop.",
            [t for t in cold if t.status == "blocked"],
            extra=lambda t: f"⟵ {t.blocked_on}" if t.blocked_on else "")
    return "\n".join(out)


def list_chores(tasks):
    """Bucket E, with the rules printed ABOVE the items — this may be the only tracker surface an
    agent told to "work the chores" ever reads, so the limits have to arrive with the list."""
    members = sorted((t for t in tasks if t.status == CHORE_STATUS), key=queue_key)
    out = [
        RULE,
        "BUCKET E — CHORES.  Pre-authorized: pick one, claim it, work it. No plan-and-wait.",
        RULE,
        "  1. CLAIM FIRST — python tools/board.py --claim <id> — before the first edit.",
        "  2. STOP IF THERE IS A DECISION. If you are choosing between two defensible approaches,",
        "     the item was mis-filed: flip it to `actionable`, say why, move on.",
        "  3. DEPLOY IS UNCHANGED. 'Busywork' is not 'safe'.",
        "  4. A `standing` item NEVER closes. Chip, update State:, --release. Do not delete it.",
        "",
    ]
    if not members:
        out.append("(bucket E is empty)")
        return "\n".join(out)
    for t in members:
        flags = []
        if t.is_standing:
            flags.append("STANDING")
        if t.is_wip:
            flags.append(f"⚡ HELD by a session since {t.wip_at:%Y-%m-%d %H:%M} — pick another")
        if t.deploy == "pending":
            flags.append("owed to prod")
        suffix = ("   [" + " | ".join(flags) + "]") if flags else ""
        out.append(f"{t.tag:>5}  p{t.priority}  {t.title}{suffix}")
        state = one_line_state(t)
        if state:
            out.append(f"         {state}")
    return "\n".join(out)


def render_found(findings):
    """Section 2 — the Found $ ledger. ⛔ Deliberately prints NO per-kind subtotals and no grand
    total: four kinds are four claims in different units, and a reader takes any total on a board
    as authoritative. One total, one rule, everything else itemised."""
    out = [HEAVY, "## 2. FOUND $   (value the work surfaced — source: .claude/pending/found/*.md)", HEAVY, ""]
    if not findings:
        return out + ["(no findings recorded)", ""]

    quotable = [f for f in findings if f.is_quotable]
    total = sum(f.amount_value for f in quotable)
    out.append(f"⭐ QUOTABLE TOTAL: ${total:,.2f}")
    out.append(f"   Sums {QUOTABLE_KIND} at confidence:{QUOTABLE_CONFIDENCE} — and NOTHING else, ever.")
    for f in quotable:
        out.append(f"     · {f.slug}  ${f.amount_value:,.2f}")
    if not quotable:
        out.append("   Nothing qualifies yet — which is the correct answer, not a gap.")
    out.append("")

    for kind in FOUND_KINDS:
        members = sorted((f for f in findings if f.meta.get("kind") == kind), key=lambda f: -(f.amount_value or 0))
        out.append(f"── {kind} — {FOUND_KINDS[kind]}")
        if not members:
            out += ["     (none)", ""]
            continue
        for f in members:
            amt = f"${f.amount_value:,.2f}" if f.amount_value is not None else "unpriced"
            basis = f" [{f.basis}]" if f.basis in ("to-date", "per-month", "per-year") else ""
            out.append(f"     {f.slug:32s} {amt:>13s}  {f.confidence:10s} {f.status:11s} {f.counterparty}{basis}")
            if f.remeasure_after:
                rm = parse_date(f.remeasure_after)
                if rm and rm <= dt.date.today():
                    out.append(f"          ⏰ DUE — RE-MEASURE ({f.remeasure_after} has arrived)")
                else:
                    out.append(f"          ⏰ next re-measure on/after {f.remeasure_after}")
            if f.confidence == "unquotable" and f.blocked_on:
                out.append(f"          ⛔ DO NOT QUOTE ⟵ {f.blocked_on}")
            elif f.status == "no-action":
                out.append("          ⛔ DO NOT QUOTE — ruled out by a business decision; kept so it is not re-found")
        out.append("")
    return out


def deploy_manifest_lines(body):
    """The fenced DEPLOY block out of a task body, plus the ordering warnings right after it —
    a manifest without its "step 1 before step 3" note is how a half-deploy happens.

    Two shapes: a DEPLOY heading then a fence, or the heading INSIDE the fence. A block with no
    numbered step is not a manifest: an empty one would read as "nothing owed".
    """
    lines = body.splitlines()
    for i, line in enumerate(lines):
        if not DEPLOY_HEAD_RE.match(line):
            continue
        if i and lines[i - 1].lstrip().startswith("```"):
            start = i
        else:
            start = next((j + 1 for j in range(i + 1, min(i + 4, len(lines)))
                          if lines[j].lstrip().startswith("```")), None)
            if start is None:
                continue
        block = []
        for b in lines[start:]:
            if b.lstrip().startswith("```"):
                break
            block.append(b)
        if not any(NUMBERED_PATH_RE.match(b) for b in block):
            continue
        tail_start = start + len(block) + 1
        for b in lines[tail_start:]:
            s = b.strip()
            if not s or s.startswith(("Next:", "State:", "#")):
                break
            block.append(b)
        return [b for b in block if b.strip()]
    return []


def awaiting_merge():
    """Local branches other than main with commits main lacks. Merging is the owner's word, so the
    prompt to say it has to come from the board, not from memory."""
    def git(*args):
        return subprocess.run(["git", "-C", str(ROOT), *args], capture_output=True,
                              text=True, timeout=10, check=True).stdout
    try:
        rows = []
        fmt = "%(refname:short)%09%(committerdate:short)%09%(subject)"
        for line in git("for-each-ref", "refs/heads/", f"--format={fmt}").splitlines():
            branch, date, subject = (line.split("\t", 2) + ["", ""])[:3]
            if branch in ("main", "master", ""):
                continue
            ahead = int(git("rev-list", "--count", f"HEAD..{branch}").strip() or 0)
            if ahead:
                rows.append({"branch": branch, "date": date, "subject": subject[:70], "ahead": ahead})
    except (OSError, subprocess.SubprocessError, ValueError):
        return []
    return sorted(rows, key=lambda r: r["date"], reverse=True)


def render(tasks):
    stamp = dt.datetime.now().strftime("%Y-%m-%d %H:%M")
    out = [
        "# Pending — work tracker",
        "",
        f"⚠️  GENERATED FILE — do not edit. Rendered {stamp} by `python tools/board.py`.",
        "    Source of truth: .claude/pending/tasks/*.md, .claude/pending/found/*.md and",
        "    .claude/pending/inbox.md (the owner's). This is a SNAPSHOT — re-render before trusting it.",
        "",
        "Every item carries a #id — stable, never reused, and the way to name one.",
        "",
        HEAVY,
        f"## 0. RESUME QUEUE   (Tier 1 = touched in the last {RESUME_WINDOW_DAYS} days and still open)",
        HEAVY,
        "",
    ]
    tier1, tier2 = resume_queue(tasks)
    if tier1:
        out.append("Tier 1 — ★ = owner starred it; ⚡ = a session is on it now; then recently worked:")
        for t in tier1:
            out.append(f"  {t.tag:>5}  {t.marks()}{t.title}   [{t.touched}] → bucket {bucket_letter(t)}")
            state = one_line_state(t)
            if state:
                out.append(f"         {state}")
        out.append("")
    if tier2:
        out.append("Tier 2 — other actionable items, priority order:")
        for t in tier2:
            out.append(f"  {t.tag:>5}  {t.title}   → bucket A")
        out.append("")

    chores = [t for t in tasks if t.status == CHORE_STATUS]
    if chores:
        out.append(f"Chores — {len(chores)} open in bucket E, held out of both tiers on purpose: "
                   "python tools/board.py --chores")
        out.append("")

    waiting = awaiting_merge()
    if waiting:
        out.append('Awaiting merge — work on a branch that is NOT on main yet (say "merge #<id>"):')
        for b in waiting:
            out.append(f"  {b['branch']}  {b['ahead']} commit(s), last {b['date']}: {b['subject']}")
        out.append("")

    owed = sorted((t for t in tasks if t.deploy == "pending"), key=queue_key)
    out += [HEAVY, "## 1. DEPLOY LEDGER   (owed to prod — default NOT-deployed until confirmed)", HEAVY, ""]
    if owed:
        for t in owed:
            out.append(f"- {t.tag}  {t.title}   → tasks/{t.slug}.md")
            # Inline the manifest itself: the ledger IS the surface the owner acts on.
            for line in deploy_manifest_lines(t.body):
                out.append(f"    {line}")
            out.append("")
    else:
        out.append("(nothing owed to prod)")
    out.append("")

    findings, _ = load_findings()
    out += render_found(findings)

    out += [HEAVY, "## 3. ACTIVE / BACKLOG   (State:/Next: only — the full body stays in the task file)", HEAVY, ""]
    for letter, status, label in BUCKETS:
        members = sorted((t for t in tasks if t.status == status), key=queue_key)
        out += [RULE, f"### {letter}. {label}", RULE, ""]
        if not members:
            out += ["(empty)", ""]
            continue
        for t in members:
            out.append(t.heading())
            if t.is_wip:
                out.append(f"WIP: ⚡ a session picked this up {t.wip_at:%Y-%m-%d %H:%M} "
                           f"(auto-expires {WIP_EXPIRY_HOURS}h after that)")
            if t.is_standing:
                out.append("STANDING: never closes — chip at it, update State:, release. Do NOT delete this file.")
            if t.deploy == "pending":
                out.append("DEPLOY: pending — manifest is in §1 DEPLOY LEDGER, above")
            elif parse_date(t.deploy):
                out.append(f"DEPLOYED {t.deploy}")
            if t.verify_after:
                # A check date that has ARRIVED must not read the same as one three weeks out.
                va = parse_date(t.verify_after)
                if va and va <= dt.date.today():
                    out.append(f"VERIFY: ** DUE NOW ** — check date {t.verify_after} has arrived")
                else:
                    out.append(f"VERIFY: pending — check on/after {t.verify_after}")
            out += body_pointer(t)
            out.append("")

    out += [HEAVY, "## 4. INBOX   (the owner's — .claude/pending/inbox.md)", HEAVY, ""]
    out += (INBOX.read_text(encoding="utf-8").splitlines() if INBOX.exists() else []) or ["(empty)"]
    out.append("")
    return "\n".join(out).rstrip() + "\n"


# ── surgical frontmatter edits ───────────────────────────────────────────────

def _read_task_text(path):
    """-> (raw bytes, text normalised to \\n, the file's own line ending). A surgical edit must
    not silently convert a CRLF file, turning a one-key change into a whole-file diff."""
    raw = path.read_bytes()
    nl = "\r\n" if b"\r\n" in raw else "\n"
    return raw, raw.decode("utf-8").replace("\r\n", "\n"), nl


def _split_frontmatter(text, name):
    """-> (opening fence, frontmatter lines, rest). Line-level on purpose, so writing back never
    reflows key order, quoting or comments."""
    lines = text.splitlines(keepends=True)
    if not lines or lines[0].strip() != "---":
        raise ValueError(f"{name}: missing --- frontmatter fence")
    for i in range(1, len(lines)):
        if lines[i].strip() == "---":
            return lines[:1], lines[1:i], lines[i:]
    raise ValueError(f"{name}: unterminated frontmatter")


def _key_of(line):
    return line.split(":", 1)[0].strip() if ":" in line else None


def _set_key(fm, key, value, after=()):
    """Replace `key` in place, else insert it after the first anchor present."""
    line = f"{key}: {value}\n"
    for i, raw in enumerate(fm):
        if _key_of(raw) == key:
            fm[i] = line
            return
    for anchor in after:
        for i, raw in enumerate(fm):
            if _key_of(raw) == anchor:
                fm.insert(i + 1, line)
                return
    fm.append(line)


def _drop_key(fm, key):
    return [raw for raw in fm if _key_of(raw) != key]


def _write_task(path, raw_before, fence, fm, rest, nl):
    """Write the edited file, refusing if it changed since we read it. Not a lock; a guard
    against the collision that is actually likely with several sessions writing."""
    if path.read_bytes() != raw_before:
        raise RuntimeError(f"{path.name} changed while this command was running — another session wrote it. "
                           "Nothing written; re-run to pick up their version.")
    path.write_text("".join(fence + fm + rest), encoding="utf-8", newline=nl)
    _record_in_ledger(path)


def claim(tasks, ref, force=False):
    """Stamp `wip:` with the local time, and bump `touched` in the same write."""
    t = resolve_ref(tasks, ref)
    now = dt.datetime.now()
    if t.is_wip and not force:
        # A LIVE stamp means another session is here. Overwriting it would make it a lie.
        print(f"{t.tag} is already claimed — a session stamped it {t.wip_at:%Y-%m-%d %H:%M} "
              f"(expires {WIP_EXPIRY_HOURS}h after that).")
        print("    Pick something else, or --claim --force if you know that session is gone.")
        return 1
    raw, text, nl = _read_task_text(t.path)
    fence, fm, rest = _split_frontmatter(text, t.path.name)
    _set_key(fm, "wip", now.strftime("%Y-%m-%dT%H:%M"), after=("current", "status", "title"))
    _set_key(fm, "touched", now.strftime("%Y-%m-%d"), after=("priority", "status", "title"))
    _write_task(t.path, raw, fence, fm, rest, nl)
    print(f"⚡ claimed {t.tag}  {t.title}")
    print(f"   expires {now + dt.timedelta(hours=WIP_EXPIRY_HOURS):%H:%M}. Clear it when you finish: "
          f"python tools/board.py --release {t.id}")
    if t.status == "parked":
        print("   NOTE this item is PARKED — deliberately deferred. Right one?")
    return 0


def release(tasks, ref):
    t = resolve_ref(tasks, ref)
    if "wip" not in t.meta:
        print(f"{t.tag} carries no wip stamp — nothing to release.")
        return 0
    raw, text, nl = _read_task_text(t.path)
    fence, fm, rest = _split_frontmatter(text, t.path.name)
    fm = _drop_key(fm, "wip")
    _set_key(fm, "touched", dt.date.today().isoformat(), after=("priority", "status", "title"))
    _write_task(t.path, raw, fence, fm, rest, nl)
    print(f"released {t.tag}  {t.title}")
    print("   Still owed: update State:/Next: to where it actually stands.")
    return 0


SETTABLE_KEYS = ("status", "priority", "deploy", "blocked_on", "verify_after", "refs", "title", "current", "provisional")
BODY_LABEL_RE = re.compile(r"^(State|Next|Also)\b")


def _flatten(text):
    return " ".join(part.strip() for part in text.replace("\r\n", "\n").split("\n") if part.strip())


def _arg_text(value):
    """`@path` reads the text from a file, so long text never goes through shell quoting."""
    if value.startswith("@"):
        return Path(value[1:]).read_text(encoding="utf-8")
    return value


def _block_end(body, i):
    j = i + 1
    while j < len(body) and body[j].strip() and not BODY_LABEL_RE.match(body[j]) \
            and not body[j].startswith(("#", "```")):
        j += 1
    return j


def _replace_labelled(body, label, text):
    new_line = f"{label}: {text}\n"
    for i, line in enumerate(body):
        if line.startswith(f"{label}:"):
            return body[:i] + [new_line] + body[_block_end(body, i):]
    if label == "Next":
        for i, line in enumerate(body):
            if line.startswith("State:"):
                k = _block_end(body, i)
                return body[:k] + [new_line] + body[k:]
    return [new_line] + body


def _manifest_block(text):
    lines = text.replace("\r\n", "\n").split("\n")
    for i, line in enumerate(lines):
        if line.lstrip().startswith("```") and i + 1 < len(lines) and DEPLOY_HEAD_RE.match(lines[i + 1]):
            for j in range(i + 1, len(lines)):
                if lines[j].lstrip().startswith("```"):
                    block = [l + "\n" for l in lines[i:j + 1]]
                    if not any(NUMBERED_PATH_RE.match(l) for l in block):
                        raise ValueError("the DEPLOY block has no numbered step")
                    return block
    raise ValueError("no fenced DEPLOY block found")


def _replace_manifest(body, block_lines):
    for i, line in enumerate(body):
        if line.lstrip().startswith("```") and i + 1 < len(body) and DEPLOY_HEAD_RE.match(body[i + 1]):
            for j in range(i + 1, len(body)):
                if body[j].lstrip().startswith("```"):
                    return body[:i] + block_lines + body[j + 1:]
    sep = [] if not body or body[-1].strip() == "" else ["\n"]
    return body + sep + block_lines


def update(tasks, ref, sets=(), state=None, next_=None, manifest=None, commit=False):
    """Edit one task file in one guarded write. Works from a worktree (ROOT is the main folder).
    The result is re-validated: a write that would make --check fail is undone."""
    t = resolve_ref(tasks, ref)
    raw, text, nl = _read_task_text(t.path)
    fence, fm, rest = _split_frontmatter(text, t.path.name)
    closing, body = rest[:1], rest[1:]
    done = []
    for pair in sets:
        if "=" not in pair:
            raise ValueError(f"--set takes key=value, got {pair!r}")
        key, value = (s.strip() for s in pair.split("=", 1))
        if key not in SETTABLE_KEYS:
            raise ValueError(f"--set {key}: not settable (allowed: {', '.join(SETTABLE_KEYS)})")
        if value == "":
            fm = _drop_key(fm, key)   # an empty value REMOVES the key
            done.append(f"{key} removed")
            continue
        _set_key(fm, key, value, after=("status", "title"))
        done.append(f"{key}={value}")
    if manifest is not None:
        body = _replace_manifest(body, _manifest_block(_arg_text(manifest)))
        if not any(p.startswith("deploy=") for p in done):
            _set_key(fm, "deploy", "pending", after=("priority", "status", "title"))
            done.append("deploy=pending")
        done.append("manifest")
    if state is not None:
        body = _replace_labelled(body, "State", _flatten(_arg_text(state)))
        done.append("State")
    if next_ is not None:
        body = _replace_labelled(body, "Next", _flatten(_arg_text(next_)))
        done.append("Next")
    if not done:
        raise ValueError("--update needs at least one of --set, --state, --next, --manifest")
    _set_key(fm, "touched", dt.date.today().isoformat(), after=("priority", "status", "title"))
    _write_task(t.path, raw, fence, fm, closing + body, nl)

    fresh, errs = load_tasks()
    mine = [p for p in errs + validate(fresh) if t.path.name in p]
    if mine:
        t.path.write_bytes(raw)
        raise ValueError("the edit would break the board, so it was undone:\n  " + "\n  ".join(mine))
    print(f"updated {t.tag}  {', '.join(done)}")
    if commit:
        msg = f"{t.tag}: board — {', '.join(done)}"
        err = _git_commit(msg, t.path)
        if err:
            print(f"   NOT committed: {err}", file=sys.stderr)
            return 1
        print(f"   committed: {msg}")
    return 0


def new_provisional(tasks, title):
    """Allocate an id, write a minimal task file, and claim it, in one command — for work that
    starts before its task is shaped. The deploy planner finds a task's work by its `#N:` commit
    prefix, so that work needs an id from its FIRST commit."""
    title = title.strip()
    if not title:
        raise ValueError("--new-provisional needs a working title")
    nid = allocate_id(tasks)
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")[:60].rstrip("-") or "provisional"
    path = TASKS / f"{slug}.md"
    if path.exists():
        path = TASKS / f"{slug}-{nid}.md"
    now = dt.datetime.now()
    text = (
        "---\n"
        f"id: {nid}\n"
        f"title: {title}\n"
        "status: actionable\n"
        f"wip: {now:%Y-%m-%dT%H:%M}\n"
        "provisional: yes\n"
        "priority: 5\n"
        f"touched: {now:%Y-%m-%d}\n"
        "deploy: none\n"
        "---\n"
        "State: provisional, opened before the task was shaped, so commits have an id from the start.\n"
        "Next: retitle once the shape settles (--set title=… --set provisional=), or delete if nothing shipped.\n"
    )
    with open(path, "x", encoding="utf-8", newline="\n") as fh:   # x = never overwrite
        fh.write(text)
    _record_in_ledger(path)
    print(f"⚡ opened and claimed #{nid}  {title}  (provisional)")
    sys.stdout.flush()
    _commit_allocation(nid, f"#{nid}: board — open (provisional)", path)
    print(f"   Prefix every commit for this work with  #{nid}:")
    return 0


def stale_sources():
    """Source files modified since pending.md was last rendered (None if never rendered)."""
    if not BOARD.exists():
        return None
    board_mtime = BOARD.stat().st_mtime
    sources = sorted(TASKS.glob("*.md")) + ([INBOX] if INBOX.exists() else [])
    return [p.name for p in sources if p.stat().st_mtime > board_mtime]


def warn_if_stale():
    stale = stale_sources()
    if stale is None:
        print("NOTE  pending.md has never been rendered — run `python tools/board.py`.")
    elif stale:
        print(f"⚠️  pending.md is STALE — {len(stale)} source file(s) newer than the last render.")


def _strip_fences(text):
    """Drop fenced code blocks — an @import shown inside one is documentation, not live."""
    out, fenced = [], False
    for line in text.splitlines():
        if line.lstrip().startswith("```"):
            fenced = not fenced
            continue
        if not fenced:
            out.append(line)
    return "\n".join(out)


def check_machine_profile():
    """Verify CLAUDE.md -> .claude/machine.local.md -> .claude/machines/<posture>.md is intact.

    Every link fails SILENTLY: a dropped import line or a missing file imports nothing, raises no
    error, and leaves the session looking normal while running with no posture rules at all — no
    deploy handshake, no read-only constraint. Runs on the plain render, not only --check, because
    the render is the command every session runs at start. A check nobody runs is not a check.
    """
    if not CLAUDE_MD.exists():
        return ["CLAUDE.md is missing from the repo root"]
    problems = []
    available = sorted(p.stem for p in MACHINES.glob("*.md")) if MACHINES.is_dir() else []
    hint = f" (available: {', '.join(available)})" if available else ""
    if "@.claude/machine.local.md" not in _strip_fences(CLAUDE_MD.read_text(encoding="utf-8", errors="replace")):
        problems.append("CLAUDE.md no longer imports @.claude/machine.local.md — no posture can load on ANY machine")
    if not MACHINE_LOCAL.exists():
        problems.append(".claude/machine.local.md is missing — this machine has no posture profile. It is "
                        f"gitignored, so a fresh clone never has one. Create it with one line, e.g. '@machines/dev.md'{hint}")
        return problems
    imports = re.findall(r"^\s*@(\S+)", _strip_fences(MACHINE_LOCAL.read_text(encoding="utf-8", errors="replace")), re.M)
    if not imports:
        problems.append(f".claude/machine.local.md imports nothing — it needs one line naming a profile{hint}")
    elif len(imports) > 1:
        problems.append(f".claude/machine.local.md imports {len(imports)} profiles — exactly one posture per machine")
    for imp in imports:
        if not (MACHINE_LOCAL.parent / imp).exists():
            problems.append(f".claude/machine.local.md imports {imp!r}, which does not exist — it imports NOTHING, silently{hint}")
    return problems


def main():
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, ValueError):
        pass

    ap = argparse.ArgumentParser(description="Render the work tracker board.")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--list", metavar="BUCKET")
    ap.add_argument("--chores", action="store_true")
    ap.add_argument("--show", metavar="REF")
    ap.add_argument("--cold", nargs="?", type=int, const=COLD_DAYS, metavar="DAYS")
    ap.add_argument("--new-id", action="store_true")
    ap.add_argument("--new-provisional", metavar="TITLE")
    ap.add_argument("--claim", metavar="REF")
    ap.add_argument("--release", metavar="REF")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--update", metavar="REF")
    ap.add_argument("--set", action="append", default=[], metavar="KEY=VALUE")
    ap.add_argument("--state", metavar="TEXT|@FILE")
    ap.add_argument("--next", metavar="TEXT|@FILE")
    ap.add_argument("--manifest", metavar="@FILE")
    ap.add_argument("--commit", action="store_true")
    args = ap.parse_args()

    tasks, errors = load_tasks()
    findings, found_errors = load_findings()
    live_ids = {t.id for t in tasks if t.id is not None}
    problems = errors + validate(tasks) + found_errors + validate_findings(findings)
    for f in findings:
        ref = str(f.meta.get("task", "")).strip().lstrip("#")
        if ref and (not ref.isdigit() or int(ref) not in live_ids):
            problems.append(f"found/{f.path.name}: task {ref!r} does not resolve — leave it blank if the task is gone")
    posture = check_machine_profile()

    if args.check:
        for p in posture:
            print("FAIL machine profile: " + p)
        for p in problems:
            print("FAIL " + p)
        print(f"{len(tasks)} task(s), {len(findings)} finding(s), {len(problems) + len(posture)} problem(s)")
        warn_if_stale()
        return 1 if (problems or posture) else 0

    if errors:
        for e in errors:
            print("FAIL " + e, file=sys.stderr)
        return 1

    def rerender():
        BOARD.write_text(render(load_tasks()[0]), encoding="utf-8", newline="\n")
        print(f"   re-rendered {BOARD.relative_to(ROOT)}")

    try:
        if args.new_id:
            nid = allocate_id(tasks)
            print(nid)
            sys.stdout.flush()
            _commit_allocation(nid, f"#{nid}: board — reserve id")
            return 0
        if args.new_provisional is not None:
            rc = new_provisional(tasks, args.new_provisional)
            rerender()
            return rc
        if args.update:
            rc = update(tasks, args.update, sets=args.set, state=args.state, next_=args.next,
                        manifest=args.manifest, commit=args.commit)
            rerender()
            return rc
        if args.claim or args.release:
            rc = claim(tasks, args.claim, force=args.force) if args.claim else release(tasks, args.release)
            if rc == 0:
                rerender()   # the stamp exists to be SEEN
            return rc
    except (LookupError, ValueError, RuntimeError, OSError) as e:
        print(str(e), file=sys.stderr)
        return 1

    if args.show:
        try:
            t = resolve_ref(tasks, args.show)
        except LookupError as e:
            print(str(e), file=sys.stderr)
            return 1
        print(f"{t.tag}  {t.title}   bucket {bucket_letter(t)}")
        print(RULE)
        print(t.path.read_text(encoding="utf-8").rstrip())
        return 0
    if args.cold is not None:
        print(list_cold(tasks, args.cold))
        return 0
    if args.chores:
        print(list_chores(tasks))
        return 0
    if args.list:
        for t in sorted(tasks, key=queue_key):
            if bucket_letter(t) == args.list.upper():
                print(f"{t.tag:>5}  {t.slug:45s} p{t.priority}  {t.title}")
        return 0

    BOARD.write_text(render(tasks), encoding="utf-8", newline="\n")
    print(f"wrote {BOARD.relative_to(ROOT)} — {len(tasks)} task(s)")
    for p in posture:
        print("⚠️  MACHINE PROFILE — " + p)
    if problems:
        print(f"{len(problems)} validation problem(s) — run --check")
    return 0


if __name__ == "__main__":
    sys.exit(main())
