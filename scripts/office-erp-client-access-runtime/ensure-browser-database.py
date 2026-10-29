#!/usr/bin/env python3
"""Create only the named browser test database, or verify its ownership marker."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
project = 'opmoni-office-erp-client-access'
database = 'opmoni_client_access_browser'
marker = 'opmoni-office-erp-client-access-browser:v1; test-only; preserve across runs'


def require(condition, reason):
    if not condition:
        raise RuntimeError(reason)


require(str(root) == '/home/obsidian/dev/opmoni/.worktrees/manage-office-erp-client-access', 'Wrong checkout')
require(subprocess.check_output(['git', '-C', str(root), 'branch', '--show-current']).strip()
        == b'codex/manage-office-erp-client-access', 'Wrong branch')
verifier = root / 'scripts/office-erp-client-access-runtime/verify.py'
require(verifier.is_file(), 'Missing owned runtime verifier')
subprocess.run(['python3', str(verifier)], check=True, stdout=subprocess.DEVNULL)

def query(sql):
    return subprocess.check_output([
        'docker', 'exec', f'{project}-postgres-1', 'psql', '-U', 'opmoni', '-d', 'opmoni_test', '-Atc', sql,
    ]).decode().strip()


def owner_marker():
    return query(
        "SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_database WHERE datname = '" + database + "') "
        "THEN 'present:' || coalesce((SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = '" + database + "'), '') "
        "ELSE 'absent' END"
    )


existing = owner_marker()
if existing == 'absent':
    result = subprocess.run(
        ['docker', 'exec', f'{project}-postgres-1', 'createdb', '-U', 'opmoni', database],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    if result.returncode == 0:
        query("COMMENT ON DATABASE " + database + " IS '" + marker + "'")
    else:
        existing = owner_marker()
        require(existing == 'present:' + marker, 'Browser database creation lost a race with an unowned database; refusing reuse')
else:
    require(existing == 'present:' + marker, 'Existing browser database has no matching owner comment; preserving it and refusing reuse')

require(owner_marker() == 'present:' + marker,
        'Browser database owner comment was not persisted')
print('PASS: isolated browser database exists with its test-only preservation marker')
