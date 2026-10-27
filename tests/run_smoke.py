#!/usr/bin/env python3
"""Smoke tests for the hooks: pipe Claude Code hook JSON into each one and assert allow/deny.

    python tests/run_smoke.py

Every guard is tested in BOTH directions — the shape it must refuse, and the look-alike it must
let through. A guard that only ever passes its first test has not been tested (break it first).
Needs `php` on PATH and must run inside this git checkout.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
HOOKS = ROOT / "tools" / "hooks"
PHP = os.environ.get("PHP_BIN", "php")
passed = failed = 0


def run_hook(hook, payload, *args, env=None):
    e = dict(os.environ, CLAUDE_PROJECT_DIR=str(ROOT))
    e.update(env or {})
    out = subprocess.run([PHP, str(HOOKS / hook), *args], input=json.dumps(payload),
                         capture_output=True, text=True, env=e, cwd=ROOT)
    return out.stdout.strip()


def decision(stdout):
    if not stdout:
        return "allow"
    data = json.loads(stdout)
    if data.get("decision") == "block":
        return "block"
    return data.get("hookSpecificOutput", {}).get("permissionDecision", "allow")


def expect(name, want, hook, cmd, *args, tool="Bash", session="smoke-session-01", env=None):
    global passed, failed
    payload = {"tool_name": tool, "tool_input": {"command": cmd}, "session_id": session, "cwd": str(ROOT)}
    got = decision(run_hook(hook, payload, *args, env=env))
    ok = got == want
    passed += ok
    failed += not ok
    print(f"  [{'PASS' if ok else 'FAIL'}] {want:5s} {name}" + ("" if ok else f"   (got {got})"))


def main():
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    gate_dir = tempfile.mkdtemp(prefix="deploygate_")
    gate = {"CC_DEPLOY_GATE_DIR": gate_dir}

    print("git_commit_guard.php")
    g = "git_commit_guard.php"
    expect("broad git add -A", "deny", g, "git add -A", "pre")
    expect("git add . (sweep)", "deny", g, "git add .", "pre")
    expect("named paths", "allow", g, "git add tools/board.py README.md", "pre")
    expect("grep that merely mentions 'git add -A'", "allow", g, 'git diff | grep "git add -A"', "pre")
    expect("git commit -am", "deny", g, 'git commit -am "x"', "pre")
    expect("app commit with no #id", "deny", g, 'git commit -m "fix rounding" -- src/Billing/Invoice.php', "pre")
    expect("app commit with #id", "allow", g, 'git commit -m "#12: fix rounding" -- src/Billing/Invoice.php', "pre")
    expect("non-app commit with no #id", "allow", g, 'git commit -m "docs" -- README.md', "pre")
    expect("bare git stash", "deny", g, "git stash", "pre")
    expect("stash with pathspec", "allow", g, "git stash push -- README.md", "pre")
    expect("amend with no commit on record", "deny", g, 'git commit --amend -m "#1: x"', "pre", session="smoke-noamend-01")
    expect("PowerShell here-string is fine in PowerShell", "allow", g,
           "git commit -m @'\n#1: subject\n'@ -- README.md", "pre", tool="PowerShell")

    print("deploy_gate.php")
    d = "deploy_gate.php"
    expect("push without the owner's word", "deny", d, "git push origin main", "pre", env=gate)
    expect("push --dry-run", "allow", d, "git push --dry-run origin main", "pre", env=gate)
    expect("grep that mentions git push", "allow", d, 'git log | grep "git push"', "pre", env=gate)
    # The owner says something that merely CONTAINS deploy — must not clear the gate.
    run_hook(d, {"prompt": "can you deploy this later?", "session_id": "smoke-gate-01"}, "prompt", env=gate)
    expect("after 'can you deploy this later?'", "deny", d, "git push origin main", "pre", session="smoke-gate-01", env=gate)
    run_hook(d, {"prompt": "deploy #12", "session_id": "smoke-gate-01"}, "prompt", env=gate)
    expect("after the owner typed 'deploy #12'", "allow", d, "git push origin main", "pre", session="smoke-gate-01", env=gate)
    expect("a different session is still gated", "deny", d, "git push origin main", "pre", session="smoke-gate-02", env=gate)
    expect("pipeline tag without the word", "deny", d, "php tools/pipeline.php tag --task 12", "pre", env=gate)

    print("ssh_guard.php")
    s = "ssh_guard.php"
    expect("ssh to the peek host", "allow", s, "ssh prod-peek deploy-check --task 12")
    expect("ssh anywhere else", "deny", s, "ssh admin@203.0.113.10")
    expect("ssh -i <key>", "deny", s, "ssh -i ~/.ssh/other prod-peek")
    expect("scp", "deny", s, "scp file prod-peek:/tmp/")
    expect("bash -c wrapped ssh", "deny", s, 'bash -c "ssh admin@box"')
    expect("prose 'SSH' in a commit message", "allow", s, 'git commit -m "#3: no SSH changes" -- README.md')
    expect("which ssh (lookup, not a run)", "allow", s, "which ssh")

    print("prod_write_guard.php")
    p = "prod_write_guard.php"
    expect("unguarded bulk write against prod", "deny", p, "php tests/fixtures/unguarded_write.php prod")
    expect("same script against dev", "allow", p, "php tests/fixtures/unguarded_write.php dev")
    expect("guarded write against prod", "allow", p, "php tests/fixtures/guarded_write.php prod")
    expect("commit message says 'Deployed to prod'", "allow", p,
           'git commit -m "#1: Deployed to prod" -- tests/fixtures/unguarded_write.php')
    expect("bash -c wrapper is not a bypass", "deny", p, 'bash -c "php tests/fixtures/unguarded_write.php prod"')
    expect("PRODWRITE_OK=1 override", "allow", p, "PRODWRITE_OK=1 php tests/fixtures/unguarded_write.php prod")

    print("edit_ledger.php + wrapup_guard.php")
    sid = "smoke-wrapup-0001"
    probe = ROOT / "smoke_probe.tmp"
    ledger = ROOT / ".claude" / "session-ledger"
    try:
        probe.write_text("x\n", encoding="utf-8")
        first = run_hook("edit_ledger.php", {"tool_name": "Write", "tool_input": {"file_path": str(probe)},
                                             "session_id": sid, "cwd": str(ROOT)})
        global passed, failed
        ok = sid in first
        passed += ok
        failed += not ok
        print(f"  [{'PASS' if ok else 'FAIL'}] ledger announces its id on the session's first edit")
        stop = {"session_id": sid, "cwd": str(ROOT)}
        got = decision(run_hook("wrapup_guard.php", stop))
        ok = got == "block"
        passed += ok
        failed += not ok
        print(f"  [{'PASS' if ok else 'FAIL'}] block  stop with an uncommitted file this session wrote" + ("" if ok else f" (got {got})"))
        got = decision(run_hook("wrapup_guard.php", stop))
        ok = got == "allow"
        passed += ok
        failed += not ok
        print(f"  [{'PASS' if ok else 'FAIL'}] allow  second stop, same set (says it once)" + ("" if ok else f" (got {got})"))
        probe.unlink()
        got = decision(run_hook("wrapup_guard.php", stop))
        ok = got == "allow"
        passed += ok
        failed += not ok
        print(f"  [{'PASS' if ok else 'FAIL'}] allow  stop once the file is gone" + ("" if ok else f" (got {got})"))
    finally:
        if probe.exists():
            probe.unlink()
        for f in (ledger / f"{sid}.jsonl", ledger / f"{sid}.stop"):
            if f.exists():
                f.unlink()
        shutil.rmtree(gate_dir, ignore_errors=True)

    print(f"\n{passed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
