#!/usr/bin/env python3
"""Validate the knowledge base against the code and the filesystem.

    python tools/kb_check.py            # full report
    python tools/kb_check.py --quiet    # print only failures (for hooks/CI)
    python tools/kb_check.py --doc X    # only checks touching .claude/X.md

Exit 1 if any check fails.

⭐ THE DESIGN RULE, learned the hard way: check INVARIANTS, never self-consistency.
`kb_index.py --check` compares the index to what the generator would generate -- so when a
merge-mode bug silently resurrected moved docs, it happily reported "current" against a wrong
index. A checker that only agrees with itself catches nothing. Every check here compares a DOC
CLAIM against an INDEPENDENT source: the filesystem, the code, or git.

Why this exists at all: within three days, five docs were found contradicting the code -- one
described a module that had moved two months earlier, one read "NOT started" for a directory
already deleted, one read "not deployed" for weeks after deploying. All were found by hand.
Discipline does not survive a hundred-plus docs; a check does.
"""

import argparse
import glob
import os
import re
import subprocess
import sys

DOCS = '.claude'
SKIP = {'decisions.md', 'machine.local.md', 'kb-archive.md'}
SECTIONS = {'app', 'data', 'api', 'tools', 'framework', 'plan', 'process', 'testing', 'archive'}
INDEXES = ['CLAUDE.md', '.claude/machines/dev.md', '.claude/kb-archive.md']

# A path is only checkable when its first segment is a real directory at the repo root. That skips
# module-relative shorthand like `Views/foo.php` without a hand-maintained allowlist.
ROOTS = {d for d in os.listdir('.') if os.path.isdir(d) and not d.startswith('.')}


def gitignored(path):
    """A gitignored path (a credentials file, .env) exists only on machines that hold it, so its
    absence on a fresh clone or a CI runner is not a dead reference."""
    return subprocess.run(['git', 'check-ignore', '-q', path], capture_output=True).returncode == 0


def frontmatter(path):
    with open(path, encoding='utf-8') as fh:
        if fh.readline().strip() != '---':
            return {}
        meta, key = {}, None
        for line in fh:
            if line.strip() == '---':
                return meta
            m = re.match(r'^([a-z_]+):\s*(.*)$', line)
            if m:
                key = m.group(1)
                meta[key] = m.group(2).strip()
            elif key and line.startswith((' ', '\t')):
                meta[key] += ' ' + line.strip()
            else:
                key = None
    return {}


def decisions_anchors():
    """All three anchor conventions used in decisions.md."""
    path = f'{DOCS}/decisions.md'
    if not os.path.exists(path):
        return set()
    txt = open(path, encoding='utf-8').read()
    out = set(re.findall(r'\{#([a-z0-9-]+)\}', txt))
    out |= set(re.findall(r'<a id="([a-z0-9-]+)"></a>', txt))
    for m in re.finditer(r'^#+\s+(.*)$', txt, re.M):
        clean = re.sub(r'\{#[a-z0-9-]+\}|<a id="[a-z0-9-]+"></a>', '', m.group(1)).strip()
        if re.fullmatch(r'[a-z0-9-]+', clean):
            out.add(clean)
    return out


def cited_anchors():
    """decisions.md#anchor references in code -> {anchor: [file:line]}."""
    try:
        out = subprocess.run(
            ['git', 'grep', '-n', '-o', '-E', r'decisions\.md#[a-z0-9-]+', '--',
             '*.php', '*.js', '*.py'],
            capture_output=True, text=True, encoding='utf-8', errors='replace').stdout
    except OSError:
        return {}
    # `#anchor` is the literal placeholder in docs that EXPLAIN the convention.
    placeholders = {'anchor'}
    hits = {}
    for line in out.split('\n'):
        m = re.match(r'^([^:]+):(\d+):.*decisions\.md#([a-z0-9-]+)$', line)
        if m and m.group(3) not in placeholders:
            hits.setdefault(m.group(3), []).append(f'{m.group(1)}:{m.group(2)}')
    return hits


def indexed_docs():
    """doc path -> [index files listing it]"""
    seen = {}
    for idx in INDEXES:
        if not os.path.exists(idx):
            continue
        txt = open(idx, encoding='utf-8').read()
        for m in re.finditer(r'^- `(\.claude[\\/][a-z0-9._-]+\.md)`', txt, re.M):
            seen.setdefault(m.group(1).replace('\\', '/'), []).append(idx)
    return seen


def check():
    fails, notes = [], []
    docs = [p.replace('\\', '/') for p in sorted(glob.glob(f'{DOCS}/*.md'))
            if os.path.basename(p) not in SKIP]
    index = indexed_docs()

    # --- C1: every decisions.md anchor cited from code resolves ---------------
    have, cited = decisions_anchors(), cited_anchors()
    for anchor, where in sorted(cited.items()):
        if anchor not in have:
            fails.append(f'C1 broken breadcrumb: decisions.md#{anchor} '
                         f'({where[0]}{" +%d more" % (len(where) - 1) if len(where) > 1 else ""})')
    notes.append(f'C1 {len(cited)} anchors cited from code, {len(have)} defined')

    # --- C2: every doc is in exactly one index --------------------------------
    for d in docs:
        n = len(index.get(d, []))
        if n == 0:
            fails.append(f'C2 invisible: {d} is in no index')
        elif n > 1:
            fails.append(f'C2 double-indexed: {d} in {", ".join(index[d])}')
    for d in index:
        if not os.path.exists(d):
            fails.append(f'C2 index points at a missing doc: {d}')
    notes.append(f'C2 {len(docs)} docs, {sum(1 for d in docs if len(index.get(d, [])) == 1)} indexed once')

    # --- C3/C4/C5: header shape, paths, supersedes ----------------------------
    checked_paths = 0
    for d in docs:
        meta = frontmatter(d)
        if not meta:
            fails.append(f'C3 no header: {d}')
            continue
        for key in ('trigger', 'status', 'section'):
            if not meta.get(key):
                fails.append(f'C3 header missing `{key}`: {d}')
        sec = meta.get('section')
        if sec and sec not in SECTIONS:
            fails.append(f'C3 unknown section `{sec}`: {d}')

        allow = {p.strip() for p in meta.get('allow_missing', '').split(',') if p.strip()}

        # C4 -- paths named in the TRIGGER must exist. The trigger means "go here to do this
        # work", so a dead path there is always a defect. Status/traps may legitimately name
        # deleted things, so they are not checked.
        for raw in re.findall(r'`([A-Za-z0-9_./-]+)`', meta.get('trigger', '')):
            p = raw.rstrip('/')
            if '/' not in p or p in allow:
                continue
            if p.split('/')[0] not in ROOTS:
                continue
            checked_paths += 1
            if not os.path.exists(p) and not gitignored(p):
                fails.append(f'C4 trigger names a missing path: {d} -> {raw}')

        sup = meta.get('supersedes', '').strip()
        if sup and not os.path.exists(f'{DOCS}/{sup}'):
            fails.append(f'C5 supersedes a missing doc: {d} -> {sup}')
    notes.append(f'C4 {checked_paths} resolvable paths in triggers')
    return fails, notes


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--quiet', action='store_true', help='print only failures')
    ap.add_argument('--doc', help='limit output to one doc (substring match)')
    args = ap.parse_args()
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

    fails, notes = check()
    if args.doc:
        fails = [f for f in fails if args.doc in f]

    if not args.quiet:
        for n in notes:
            print(f'  {n}')
    if fails:
        print(f'kb_check: {len(fails)} FAILURE(S)')
        for f in fails:
            print(f'  !! {f}')
        sys.exit(1)
    if not args.quiet:
        print('kb_check: all invariants hold')


if __name__ == '__main__':
    main()
