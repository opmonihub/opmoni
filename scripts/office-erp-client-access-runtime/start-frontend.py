#!/usr/bin/env python3
"""Start only this worktree's loopback Nuxt process; keep ingress in memory."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

from listener import ensure_port_available, verify_listener_owner

os.umask(0o077)
root = Path(__file__).resolve().parents[2]
state = root / '.superpowers/sdd/manage-office-erp-client-access'
state.mkdir(parents=True, exist_ok=True)
identity_file = state / 'office-erp-client-access-frontend-process.json'
mode = sys.argv[1] if len(sys.argv) > 1 else 'standard'
if mode not in ['standard', 'browser']:
    raise RuntimeError('Select only the standard or browser preview mode')
backend_url = 'http://127.0.0.1:18871' if mode == 'browser' else 'http://127.0.0.1:18870'
backend_service = 'opmoni-office-erp-client-access-browser-backend-1' if mode == 'browser' else 'opmoni-office-erp-client-access-backend-1'
verifier = root / 'scripts/office-erp-client-access-runtime/verify.py'
subprocess.run(['python3', str(verifier), *( ['--browser'] if mode == 'browser' else [])], check=True, stdout=subprocess.DEVNULL)
node = shutil.which('node')
if not node or int(subprocess.check_output([node, '--version'], text=True).split('.')[0][1:]) != 24:
    raise RuntimeError('The isolated frontend requires host Node.js 24')
if identity_file.exists():
    identity = json.loads(identity_file.read_text())
    proc = Path('/proc', str(identity['pid']))
    if proc.exists():
        if (proc / 'cwd').resolve() != root / 'frontend':
            raise RuntimeError('Recorded frontend PID belongs to another directory; process preserved')
        command = (proc / 'cmdline').read_bytes().split(b'\0')
        if b'node_modules/nuxt/bin/nuxt.mjs' not in command or b'3350' not in command:
            raise RuntimeError('Recorded frontend PID has an unexpected command; process preserved')
        recorded_mode = identity.get('mode')
        process_environment = (proc / 'environ').read_bytes().split(b'\0')
        recorded_backend = next((entry.split(b'=', 1)[1].decode() for entry in process_environment
                                 if entry.startswith(b'NUXT_BACKEND_URL=')), None)
        legacy_standard = mode == 'standard' and recorded_mode is None and recorded_backend == backend_url
        if not legacy_standard and (recorded_mode != mode or identity.get('backend_url') != backend_url
                                    or identity.get('origin') != 'http://localhost:3350'):
            raise RuntimeError('Recorded frontend preview belongs to another backend mode; process preserved')
        if recorded_backend != backend_url:
            raise RuntimeError('Recorded frontend process is bound to another backend; process preserved')
        verify_listener_owner(identity['pid'], '127.0.0.1', 3350)
        print(f"PASS: reusing task frontend PID {identity['pid']}")
        raise SystemExit(0)
ensure_port_available('127.0.0.1', 3350)
php = "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config('access.ingress_key');"
key = subprocess.check_output(['docker', 'exec', backend_service, 'php', '-r', php], text=True)
if not key.strip():
    raise RuntimeError('Task backend ingress key is missing')
environment = os.environ.copy()
environment.update({'NODE_ENV': 'development', 'NUXT_BACKEND_URL': backend_url,
                    'NUXT_INGRESS_KEY': key, 'CHOKIDAR_USEPOLLING': 'true', 'CHOKIDAR_INTERVAL': '300'})
log_name = 'office-erp-client-access-frontend-browser-runtime.log' if mode == 'browser' else 'office-erp-client-access-frontend-runtime.log'
with (state / log_name).open('a') as log:
    process = subprocess.Popen([node, 'node_modules/nuxt/bin/nuxt.mjs', 'dev', '--host', '127.0.0.1', '--port', '3350'],
                               cwd=root / 'frontend', env=environment, stdin=subprocess.DEVNULL,
                               stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
try:
    for _ in range(300):
        if process.poll() is not None:
            raise RuntimeError('Task frontend exited before opening its listener')
        try:
            verify_listener_owner(process.pid, '127.0.0.1', 3350)
            break
        except RuntimeError:
            time.sleep(0.1)
    else:
        raise RuntimeError('Task frontend did not own the loopback listener within 30 seconds')
except Exception:
    if process.poll() is None:
        process.terminate()
        process.wait(timeout=5)
    raise
identity_file.write_text(json.dumps({
    'pid': process.pid,
    'node': node,
    'cwd': str(root / 'frontend'),
    'mode': mode,
    'backend_url': backend_url,
    'origin': 'http://localhost:3350',
}))
print(f'PASS: started task {mode} frontend PID {process.pid}; owns loopback port 3350; private ingress configured without output')
