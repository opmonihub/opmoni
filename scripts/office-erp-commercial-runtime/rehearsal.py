#!/usr/bin/env python3
"""Guarded, resumable orchestration for the isolated commercial migration rehearsal."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys


ROOT = Path('/home/obsidian/dev/opmoni')
CENTRAL = ROOT / '.worktrees/office-erp-commercial-design'
TASK = Path(__file__).resolve().parents[2]
SCRIPT = TASK / 'scripts/office-erp-commercial-runtime/rehearsal-compose.yml'
BASELINE = '14f9f22fc656327b467761bafd4b43d0bd08ec50'
COMPATIBLE = 'd55622aacd2d770d754d020e6fbdea410ad7a135'
PROJECT = 'opmoni-office-erp-commercial-rehearsal'
NETWORK = PROJECT + '-net'
VOLUMES = [PROJECT + '-postgres', PROJECT + '-redis']
STATE_DIR = CENTRAL / '.superpowers/sdd/2026-10-24-office-erp-commercial-management/parallel-wave1/rehearsal'
STATE_FILE = STATE_DIR / 'orchestration.json'
PROBE_STATE_FILE = STATE_DIR / 'state.json'
BACKEND_ENV = STATE_DIR / 'backend.env'
COMPOSE_ENV = STATE_DIR / 'compose.env'
WORKTREES = {
    'baseline': ROOT / '.worktrees/office-erp-commercial-rehearsal-baseline',
    'compatible': ROOT / '.worktrees/office-erp-commercial-rehearsal-compatible',
}
PHASES = ('baseline', 'migrate', 'fixture', 'old-probe', 'current-probe')
COMMERCIAL_MIGRATIONS = (
    '2026_10_24_090000_add_erp_commercial_to_plans_table',
    '2026_10_24_100000_create_office_erp_commercial_core',
    '2026_10_24_110000_create_office_erp_billing',
    '2026_10_24_120000_create_office_erp_receipt_proofs',
    '2026_10_24_130000_create_office_erp_owner_invitations',
)
ALLOWED_CENTRAL_UNTRACKED = frozenset({
    'GLOSSARY.md',
    'openspec/changes/add-office-erp-commercial-management/.openspec.yaml',
    'openspec/changes/add-office-erp-commercial-management/component-matrix.md',
    'openspec/changes/add-office-erp-commercial-management/design.md',
    'openspec/changes/add-office-erp-commercial-management/proposal.md',
    'openspec/changes/add-office-erp-commercial-management/specs/access-pages/spec.md',
    'openspec/changes/add-office-erp-commercial-management/specs/erp-commercial-access/spec.md',
    'openspec/changes/add-office-erp-commercial-management/specs/office-erp-billing/spec.md',
    'openspec/changes/add-office-erp-commercial-management/specs/office-erp-commercial-management/spec.md',
    'openspec/changes/add-office-erp-commercial-management/specs/platform-commercial-management/spec.md',
    'openspec/changes/add-office-erp-commercial-management/tasks.md',
    'openspec/changes/add-office-erp-commercial-management/ui-reference-audit.md',
    'scripts/office-erp-commercial-runtime/__pycache__/listener.cpython-312.pyc',
})


def run(args: list[str], *, cwd: Path = ROOT, env: dict[str, str] | None = None) -> str:
    result = subprocess.run(args, cwd=cwd, env=env, text=True, capture_output=True, check=False)
    if result.returncode != 0:
        diagnostics = []
        for line in (result.stdout + '\n' + result.stderr).splitlines():
            if line.startswith('ABORT probe phase '):
                diagnostics.append(line[:200])
        if STATE_DIR.is_dir() and not STATE_DIR.is_symlink():
            failure_path = STATE_DIR / 'last-failure.json'
            if failure_path.is_symlink():
                raise RuntimeError('Private failure record is a symlink; preserve it and stop')
            failure = {
                'command': [Path(args[0]).name, *args[1:]],
                'return_code': result.returncode,
                'safe_probe_diagnostics': diagnostics,
            }
            failure_path.write_text(json.dumps(failure, sort_keys=True, indent=2) + '\n', encoding='utf-8')
            failure_path.chmod(0o600)
        raise RuntimeError(f'Command failed ({result.returncode}): {Path(args[0]).name}; safe diagnostics retained privately')
    return result.stdout.strip()


def git_value(path: Path, *args: str) -> str:
    return run(['git', *args], cwd=path)


def exact_worktree(role: str, revision: str, create: bool) -> Path:
    path = WORKTREES[role]
    if path.is_symlink() or not path.resolve(strict=False).is_relative_to((ROOT / '.worktrees').resolve()):
        raise RuntimeError(f'{role} worktree path is unsafe; preserve it and stop')
    if path.exists():
        if not (path / '.git').exists() or git_value(path, 'rev-parse', 'HEAD') != revision:
            raise RuntimeError(f'{role} worktree exists but is not the requested revision; preserve it and stop')
        if git_value(path, 'status', '--porcelain'):
            raise RuntimeError(f'{role} worktree has changes; preserve it and stop')
        return path
    if not create:
        raise RuntimeError(f'Missing {role} source worktree; run phase prepare-sources first')
    run(['git', 'worktree', 'add', '--detach', str(path), revision])
    return path


def set_env_file(source_sha: str, source: Path) -> None:
    if STATE_DIR.is_symlink() or not STATE_DIR.resolve(strict=False).is_relative_to(CENTRAL.resolve()):
        raise RuntimeError('Private rehearsal state directory is unsafe')
    STATE_DIR.mkdir(parents=True, exist_ok=True, mode=0o700)
    STATE_DIR.chmod(0o700)
    source_env = CENTRAL / 'backend/.env'
    if not source_env.is_file() or source_env.is_symlink():
        raise RuntimeError('Central backend private environment file is missing or unsafe')
    original = source_env.read_text(encoding='utf-8').splitlines()
    keys: list[str | None] = []
    for line in original:
        match = re.match(r'^\s*(?:export\s+)?([A-Za-z0-9_]+)\s*=', line)
        keys.append(match.group(1) if match else None)
    app_key_lines = [line for key, line in zip(keys, original, strict=True) if key == 'APP_KEY']
    if len(app_key_lines) != 1 or not app_key_lines[0].split('=', 1)[1].strip():
        raise RuntimeError('Private APP_KEY is missing; source remains unchanged')
    key_digest = hashlib.sha256(app_key_lines[0].encode()).hexdigest()
    source_file = STATE_DIR / 'source.json'
    if source_file.is_symlink():
        raise RuntimeError('Private source record must not be a symlink')
    if source_file.exists():
        old_source = json.loads(source_file.read_text(encoding='utf-8'))
        if old_source.get('app_key_digest') not in (None, key_digest):
            raise RuntimeError('APP_KEY changed during rehearsal; preserve state and stop')
    overrides = {
        'APP_ENV': 'local', 'APP_DEBUG': 'false', 'DB_CONNECTION': 'pgsql',
        'DB_HOST': 'postgres', 'DB_PORT': '5432', 'DB_DATABASE': 'opmoni_test',
        'DB_USERNAME': 'opmoni', 'DB_PASSWORD': 'rehearsal-private', 'DB_URL': '',
        'CACHE_STORE': 'redis', 'SESSION_DRIVER': 'redis', 'SESSION_CONNECTION': 'sessions',
        'REDIS_CLIENT': 'phpredis', 'REDIS_HOST': 'redis', 'REDIS_PORT': '6379',
        'REDIS_PASSWORD': 'null', 'REDIS_URL': '', 'REDIS_DB': '13',
        'REDIS_CACHE_DB': '14', 'REDIS_SESSION_DB': '15',
        'REDIS_PREFIX': 'opmoni-office-erp-commercial-rehearsal-',
        'CACHE_PREFIX': 'opmoni-office-erp-commercial-rehearsal-cache-',
        'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'array',
        'WHATSAPP_DISPATCH_ENABLED': 'false', 'ERP_REGISTRATION_ENABLED': 'false',
        'ERP_RELEASE_ENABLED': 'false', 'ERP_FISCAL_PRODUCTION_ENABLED': 'false',
        'ERP_FISCAL_PROVIDER': 'fake', 'FISCAL_CTE_ENABLED': 'false',
        'FISCAL_NFSE_ENABLED': 'false', 'FISCAL_MANIFESTACAO_ENABLED': 'false',
        'FISCAL_MANIFESTACAO_ROLLOUT_MODE': 'disabled',
    }
    override_keys = set(overrides)
    preserved = [line for key, line in zip(keys, original, strict=True) if key not in override_keys]
    if BACKEND_ENV.is_symlink() or COMPOSE_ENV.is_symlink():
        raise RuntimeError('Private environment paths must not be symlinks')
    tmp = BACKEND_ENV.with_suffix('.env.tmp')
    if tmp.is_symlink():
        raise RuntimeError('Private temporary environment path must not be a symlink')
    tmp.write_text('\n'.join([*preserved, *(f'{key}={value}' for key, value in overrides.items())]) + '\n', encoding='utf-8')
    tmp.chmod(0o600)
    tmp.replace(BACKEND_ENV)
    compose_values = {
        'REHEARSAL_BACKEND_ENV': str(BACKEND_ENV),
        'REHEARSAL_BASELINE_SOURCE': str(WORKTREES['baseline']),
        'REHEARSAL_COMPATIBLE_SOURCE': str(WORKTREES['compatible']),
        'REHEARSAL_CURRENT_SOURCE': str(CENTRAL),
        'REHEARSAL_VENDOR': str(CENTRAL / 'backend/vendor'),
        'REHEARSAL_STATE': str(STATE_DIR),
        'REHEARSAL_HARNESS': str(SCRIPT.parent),
    }
    COMPOSE_ENV.write_text('\n'.join(f'{key}={value}' for key, value in compose_values.items()) + '\n', encoding='utf-8')
    COMPOSE_ENV.chmod(0o600)
    source_file.write_text(json.dumps({'source_sha': source_sha, 'app_key_digest': key_digest}, sort_keys=True) + '\n', encoding='utf-8')
    source_file.chmod(0o600)


def compose(source_sha: str, source: Path, command: list[str]) -> str:
    set_env_file(source_sha, source)
    return run(['docker', 'compose', '-p', PROJECT, '--env-file', str(COMPOSE_ENV), '-f', str(SCRIPT), *command])


def assert_private_names_unused() -> None:
    containers = subprocess.run(
        ['docker', 'ps', '-aq', '--filter', f'label=com.docker.compose.project={PROJECT}'],
        text=True, capture_output=True, check=False,
    )
    if containers.returncode != 0 or containers.stdout.strip():
        raise RuntimeError('Unexpected existing rehearsal containers or Docker inspection failure; preserve and stop')
    for resource_type, name in [('network', NETWORK), *[('volume', value) for value in VOLUMES]]:
        result = subprocess.run(['docker', resource_type, 'inspect', name], text=True, capture_output=True, check=False)
        missing = 'no such' in result.stderr.lower() or 'not found' in result.stderr.lower()
        if result.returncode == 0 or not missing:
            raise RuntimeError(f'Unexpected existing private {resource_type}; preserve it and stop')


def assert_private_resources_owned(*, allow_missing: bool = False) -> None:
    for resource_type, name in [('network', NETWORK), *[('volume', value) for value in VOLUMES]]:
        result = subprocess.run(
            ['docker', resource_type, 'inspect', '--format', '{{ index .Labels "com.opmoni.office-erp-rehearsal" }}', name],
            text=True, capture_output=True, check=False,
        )
        missing = 'no such' in result.stderr.lower() or 'not found' in result.stderr.lower()
        if result.returncode != 0 and missing and allow_missing:
            continue
        if result.returncode != 0 or result.stdout.strip() != 'true':
            raise RuntimeError(f'Existing private {resource_type} has unexpected labels; preserve it and stop')
        project = run(['docker', resource_type, 'inspect', '--format', '{{ index .Labels "com.docker.compose.project" }}', name])
        if project != PROJECT:
            raise RuntimeError(f'Existing private {resource_type} has an unexpected Compose project label')
        if resource_type == 'network' and run(['docker', 'network', 'inspect', '--format', '{{.Internal}}', name]) != 'true':
            raise RuntimeError('Rehearsal network must be internal to prevent outbound traffic')
    containers = run(['docker', 'ps', '-aq', '--filter', f'label=com.docker.compose.project={PROJECT}']).splitlines()
    source_by_role = {
        'backend-baseline': str(WORKTREES['baseline']),
        'backend-compatible': str(WORKTREES['compatible']),
        'backend-current': str(CENTRAL),
    }
    service_by_role = {
        'postgres': 'postgres',
        'redis': 'redis',
        'backend-baseline': 'backend-baseline',
        'backend-compatible': 'backend-compatible',
        'backend-current': 'backend-current',
    }
    seen_roles: list[str] = []
    for container in containers:
        owned = run(['docker', 'inspect', '--format', '{{ index .Config.Labels "com.opmoni.office-erp-rehearsal" }}', container])
        role = run(['docker', 'inspect', '--format', '{{ index .Config.Labels "com.opmoni.office-erp-rehearsal.role" }}', container])
        if owned != 'true' or role not in {'postgres', 'redis', *source_by_role} or role in seen_roles:
            raise RuntimeError('Unexpected rehearsal container role; preserve it and stop')
        if run(['docker', 'inspect', '--format', '{{.State.Running}}', container]) != 'true':
            raise RuntimeError('An expected rehearsal container is not running; preserve it and stop')
        if run(['docker', 'inspect', '--format', '{{ index .Config.Labels "com.docker.compose.project" }}', container]) != PROJECT:
            raise RuntimeError('Rehearsal container has an unexpected Compose project label')
        service = run(['docker', 'inspect', '--format', '{{ index .Config.Labels "com.docker.compose.service" }}', container])
        if service != service_by_role[role]:
            raise RuntimeError('Rehearsal container service label does not match its reviewed role')
        seen_roles.append(role)
        network_names = run(['docker', 'inspect', '--format', '{{range $name, $network := .NetworkSettings.Networks}}{{$name}}{{"\\n"}}{{end}}', container]).splitlines()
        if network_names != [NETWORK]:
            raise RuntimeError('Rehearsal container is attached to an unexpected network; preserve it and stop')
        port_bindings = run(['docker', 'inspect', '--format', '{{json .HostConfig.PortBindings}}', container])
        if port_bindings not in ('null', '{}'):
            raise RuntimeError('Rehearsal container unexpectedly publishes host ports')
        if role in source_by_role:
            image = run(['docker', 'inspect', '--format', '{{.Config.Image}}', container])
            if image != 'opmoni-backend:dev':
                raise RuntimeError('Rehearsal backend image changed; preserve it and stop')
            mounts = json.loads(run(['docker', 'inspect', '--format', '{{json .Mounts}}', container]))
            tmpfs_config = json.loads(run(['docker', 'inspect', '--format', '{{json .HostConfig.Tmpfs}}', container]))
            expected_binds = {
                ('bind', '', str(source_by_role[role]), '/workspace', False),
                ('bind', '', str(CENTRAL / 'backend/vendor'), '/workspace/backend/vendor', False),
                ('bind', '', str(STATE_DIR), '/rehearsal-state', True),
                ('bind', '', str(SCRIPT.parent), '/rehearsal-harness', False),
            }
            expected_tmpfs = {'/workspace/backend/storage', '/workspace/backend/bootstrap/cache'}
            if not isinstance(mounts, list) or not isinstance(tmpfs_config, dict):
                raise RuntimeError('Rehearsal backend mount inspection returned an unexpected shape')
            binds = set()
            inspected_tmpfs: dict[str, bool] = {}
            for mount in mounts:
                mount_type = mount.get('Type')
                if mount_type == 'bind':
                    binds.add((mount_type, mount.get('Name', ''), mount.get('Source', ''),
                               mount.get('Destination', ''), mount.get('RW')))
                elif mount_type == 'tmpfs':
                    inspected_tmpfs[mount.get('Destination', '')] = mount.get('RW')
                else:
                    raise RuntimeError('Rehearsal backend has an unexpected volume or mount type')
            if binds != expected_binds or set(tmpfs_config) != expected_tmpfs:
                raise RuntimeError('Rehearsal backend mounts differ from the reviewed bind/tmpfs contract')
            required_tmpfs_options = {'rw', 'nosuid', 'nodev', 'uid=1000', 'gid=1000'}
            for options in tmpfs_config.values():
                if not isinstance(options, str):
                    raise RuntimeError('Rehearsal backend tmpfs options have an unexpected shape')
                option_tokens = set(options.split(','))
                modes = [token.removeprefix('mode=') for token in option_tokens if token.startswith('mode=')]
                if (not required_tmpfs_options.issubset(option_tokens) or 'ro' in option_tokens
                        or len(modes) != 1 or not modes[0].isdigit() or int(modes[0], 8) != 0o770):
                    raise RuntimeError('Rehearsal backend tmpfs ownership, mode, or writability differs from the reviewed contract')
            if inspected_tmpfs and (set(inspected_tmpfs) != expected_tmpfs or not all(inspected_tmpfs.values())):
                raise RuntimeError('Rehearsal backend tmpfs mount type or writability differs from the reviewed contract')
        elif role in {'postgres', 'redis'}:
            expected_volume = PROJECT + ('-postgres' if role == 'postgres' else '-redis')
            target = '/var/lib/postgresql' if role == 'postgres' else '/data'
            expected_image = 'postgres:18-alpine' if role == 'postgres' else 'redis:8-alpine'
            if run(['docker', 'inspect', '--format', '{{.Config.Image}}', container]) != expected_image:
                raise RuntimeError('Rehearsal data image changed; preserve it and stop')
            mount_text = run(['docker', 'inspect', '--format', '{{range .Mounts}}{{.Type}}|{{.Name}}|{{.Destination}}|{{.RW}}{{"\\n"}}{{end}}', container])
            if set(mount_text.splitlines()) != {f'volume|{expected_volume}|{target}|true'}:
                raise RuntimeError('Rehearsal data container uses an unexpected volume')
    if not allow_missing and not {'postgres', 'redis', 'backend-current'}.issubset(set(seen_roles)):
        raise RuntimeError('Required private rehearsal containers are missing; preserve current state and stop')


def write_state(state: dict[str, object]) -> None:
    if STATE_DIR.is_symlink() or not STATE_DIR.resolve(strict=False).is_relative_to(CENTRAL.resolve()):
        raise RuntimeError('Private rehearsal state directory is unsafe')
    STATE_DIR.mkdir(parents=True, exist_ok=True, mode=0o700)
    STATE_DIR.chmod(0o700)
    if STATE_FILE.is_symlink():
        raise RuntimeError('Orchestration state must not be a symlink')
    tmp = STATE_FILE.with_suffix('.json.tmp')
    if tmp.is_symlink():
        raise RuntimeError('Temporary orchestration state must not be a symlink')
    tmp.write_text(json.dumps(state, sort_keys=True, indent=2) + '\n', encoding='utf-8')
    tmp.chmod(0o600)
    tmp.replace(STATE_FILE)


def load_state() -> dict[str, object]:
    if STATE_DIR.is_symlink() or STATE_FILE.is_symlink():
        raise RuntimeError('Orchestration state must not be a symlink')
    if not STATE_FILE.exists():
        return {'project': PROJECT, 'phases': [], 'source_sha': None}
    if STATE_FILE.stat().st_mode & 0o077:
        raise RuntimeError('Orchestration state must use mode 0600')
    state = json.loads(STATE_FILE.read_text(encoding='utf-8'))
    if state.get('project') != PROJECT or not isinstance(state.get('phases'), list):
        raise RuntimeError('Unexpected rehearsal state; preserve it and stop')
    return state


def load_probe_markers() -> list[str]:
    if STATE_DIR.is_symlink() or not STATE_DIR.resolve(strict=False).is_relative_to(CENTRAL.resolve()):
        raise RuntimeError('Private rehearsal state directory is unsafe')
    if PROBE_STATE_FILE.is_symlink():
        raise RuntimeError('Private probe state must not be a symlink')
    if not PROBE_STATE_FILE.exists():
        return []
    if not PROBE_STATE_FILE.is_file() or PROBE_STATE_FILE.stat().st_mode & 0o777 != 0o600:
        raise RuntimeError('Private probe state must be a regular file with mode 0600')
    probe_state = json.loads(PROBE_STATE_FILE.read_text(encoding='utf-8'))
    markers = probe_state.get('probe_markers') if isinstance(probe_state, dict) else None
    allowed_markers = {
        'preflight', 'seed-baseline', 'verify-forward', 'commercial-fixture',
        'compatible-readonly', 'current-readonly',
    }
    if (not isinstance(markers, list) or any(not isinstance(marker, str) for marker in markers)
            or len(markers) != len(set(markers)) or not set(markers).issubset(allowed_markers)):
        raise RuntimeError('Private probe state has an unexpected marker shape')
    return markers


def ensure_revision(revision: str) -> None:
    if git_value(ROOT, 'cat-file', '-t', revision) != 'commit':
        raise RuntimeError(f'Required source commit is unavailable: {revision}')


def check_source(source_sha: str) -> Path:
    if len(source_sha) != 40 or any(char not in '0123456789abcdef' for char in source_sha):
        raise RuntimeError('--source-sha must be a full lowercase commit SHA')
    actual = git_value(CENTRAL, 'rev-parse', 'HEAD')
    if actual != source_sha or git_value(CENTRAL, 'status', '--porcelain', '--untracked-files=no'):
        raise RuntimeError('Central accepted source must be clean and exactly match --source-sha')
    untracked = set(git_value(CENTRAL, 'ls-files', '--others', '--exclude-standard').splitlines())
    unexpected_untracked = untracked - ALLOWED_CENTRAL_UNTRACKED
    if unexpected_untracked:
        raise RuntimeError('Central accepted source has unreviewed untracked files; preserve them and stop')
    if not (CENTRAL / 'backend/vendor/autoload.php').is_file():
        raise RuntimeError('Existing locked backend/vendor is unavailable')
    for relative in (
        'scripts/office-erp-commercial-runtime/rehearsal.py',
        'scripts/office-erp-commercial-runtime/rehearsal-compose.yml',
        'scripts/office-erp-commercial-runtime/rehearsal-probe.php',
    ):
        expected = subprocess.run(
            ['git', 'show', f'{source_sha}:{relative}'], cwd=CENTRAL,
            capture_output=True, check=False,
        )
        if expected.returncode != 0:
            raise RuntimeError(f'Accepted source is missing reviewed harness path: {relative}')
        actual_content = (TASK / relative).read_bytes()
        if hashlib.sha256(expected.stdout).digest() != hashlib.sha256(actual_content).digest():
            raise RuntimeError(f'Reviewed harness differs from accepted tracked source: {relative}')
    return CENTRAL


def run_probe(source_sha: str, source: Path, service: str, phase: str) -> None:
    compose(source_sha, source, ['up', '-d', service])
    assert_private_resources_owned()
    compose(source_sha, source, ['exec', '-T', service, 'php',
                                 '/rehearsal-harness/rehearsal-probe.php', phase,
                                 '/rehearsal-state/state.json'])


def run_artisan(source_sha: str, source: Path, service: str) -> None:
    compose(source_sha, source, ['up', '-d', service])
    assert_private_resources_owned()
    compose(source_sha, source, ['exec', '-T', service, 'php', 'artisan',
                                 'migrate', '--force', '--no-interaction'])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=['prepare-sources', 'preflight', *PHASES])
    parser.add_argument('--source-sha', required=True)
    args = parser.parse_args()
    source = check_source(args.source_sha)
    ensure_revision(BASELINE)
    ensure_revision(COMPATIBLE)
    state = load_state()
    if state.get('source_sha') not in (None, args.source_sha):
        raise RuntimeError('This private runtime belongs to another accepted source SHA; preserve it and stop')
    state['source_sha'] = args.source_sha

    if args.phase == 'prepare-sources':
        baseline_tree = exact_worktree('baseline', BASELINE, True)
        compatible_tree = exact_worktree('compatible', COMPATIBLE, True)
        for relative in ('backend/composer.json', 'backend/composer.lock'):
            current_hash = hashlib.sha256((source / relative).read_bytes()).hexdigest()
            if hashlib.sha256((baseline_tree / relative).read_bytes()).hexdigest() != current_hash:
                raise RuntimeError(f'Baseline dependency manifest differs: {relative}')
            if hashlib.sha256((compatible_tree / relative).read_bytes()).hexdigest() != current_hash:
                raise RuntimeError(f'Compatible dependency manifest differs: {relative}')
        vendor = (CENTRAL / 'backend/vendor').resolve()
        if not vendor.is_relative_to(CENTRAL.resolve()) or not (vendor / 'autoload.php').is_file():
            raise RuntimeError('Locked vendor tree is missing or outside central source')
        state['sources_ready'] = True
        write_state(state)
        print('PASS: exact baseline and compatible rollback worktrees prepared; no cleanup is configured')
        return 0

    if args.phase == 'preflight':
        if not state.get('sources_ready'):
            raise RuntimeError('Run prepare-sources before creating private runtime resources')
        if state['phases']:
            raise RuntimeError('Preflight is only valid before the first database write')
        if not state.get('preflight_started'):
            assert_private_names_unused()
            state['preflight_started'] = True
            write_state(state)
        else:
            assert_private_resources_owned(allow_missing=True)
        compose(args.source_sha, source, ['up', '-d', 'postgres', 'redis'])
        run_probe(args.source_sha, source, 'backend-current', 'preflight')
        state['preflight'] = True
        write_state(state)
        print('PASS: isolated PostgreSQL/Redis and private environment guards verified')
        return 0

    if not state.get('preflight'):
        raise RuntimeError('Run preflight first; existing runtime state is preserved on failure')
    assert_private_resources_owned()
    phases = state['phases']
    assert isinstance(phases, list)
    if args.phase in phases:
        raise RuntimeError('This phase is already complete; no phase rerun or reset is permitted')
    expected = PHASES[len(phases)] if len(phases) < len(PHASES) else None
    if args.phase != expected:
        raise RuntimeError(f'Next resumable phase is {expected!r}; no later phase may run')

    markers = load_probe_markers()
    marker_for_phase = {
        'baseline': 'seed-baseline', 'migrate': 'verify-forward', 'fixture': 'commercial-fixture',
        'old-probe': 'compatible-readonly', 'current-probe': 'current-readonly',
    }[args.phase]
    if marker_for_phase in markers:
        phases.append(args.phase)
        write_state(state)
        print(f'PASS: recovered completed phase {args.phase}; private state retained')
        return 0

    if args.phase == 'baseline':
        exact_worktree('baseline', BASELINE, False)
        run_artisan(args.source_sha, source, 'backend-baseline')
        run_probe(args.source_sha, source, 'backend-baseline', 'seed-baseline')
    elif args.phase == 'migrate':
        run_artisan(args.source_sha, source, 'backend-current')
        run_probe(args.source_sha, source, 'backend-current', 'verify-forward')
    elif args.phase == 'fixture':
        run_probe(args.source_sha, source, 'backend-current', 'commercial-fixture')
    elif args.phase == 'old-probe':
        exact_worktree('compatible', COMPATIBLE, False)
        run_probe(args.source_sha, source, 'backend-compatible', 'compatible-readonly')
    elif args.phase == 'current-probe':
        run_probe(args.source_sha, source, 'backend-current', 'current-readonly')

    state = load_state()
    if state.get('source_sha') not in (None, args.source_sha):
        raise RuntimeError('Private runtime source changed during phase; state preserved')
    phases = state['phases']
    assert isinstance(phases, list)
    phases.append(args.phase)
    write_state(state)
    print(f'PASS: rehearsal phase {args.phase} completed; private state retained')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, RuntimeError, ValueError, json.JSONDecodeError) as error:
        print(f'ABORT: {error}', file=sys.stderr)
        sys.exit(1)
