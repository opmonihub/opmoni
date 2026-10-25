#!/usr/bin/env python3
"""Start only this worktree's loopback Nuxt process; keep ingress in memory."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

from listener import ensure_port_available, verify_listener_owner

os.umask(0o077)
root = Path(__file__).resolve().parents[2]
state = root / '.superpowers/sdd/tasks'
state.mkdir(parents=True, exist_ok=True)
identity_file = state / 'office-erp-commercial-frontend-process.json'
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
        if b'node_modules/nuxt/bin/nuxt.mjs' not in command or b'3346' not in command:
            raise RuntimeError('Recorded frontend PID has an unexpected command; process preserved')
        verify_listener_owner(identity['pid'], '127.0.0.1', 3346)
        print(f"PASS: reusing task frontend PID {identity['pid']}")
        raise SystemExit(0)
ensure_port_available('127.0.0.1', 3346)
php = "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo config('access.ingress_key');"
key = subprocess.check_output(['docker', 'exec', 'opmoni-office-erp-commercial-backend-1', 'php', '-r', php], text=True)
if not key.strip():
    raise RuntimeError('Task backend ingress key is missing')
environment = os.environ.copy()
environment.update({'NODE_ENV': 'development', 'NUXT_BACKEND_URL': 'http://127.0.0.1:18860',
                    'NUXT_INGRESS_KEY': key, 'CHOKIDAR_USEPOLLING': 'true', 'CHOKIDAR_INTERVAL': '300'})
with (state / 'office-erp-commercial-frontend-runtime.log').open('a') as log:
    process = subprocess.Popen([node, 'node_modules/nuxt/bin/nuxt.mjs', 'dev', '--host', '127.0.0.1', '--port', '3346'],
                               cwd=root / 'frontend', env=environment, stdin=subprocess.DEVNULL,
                               stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
try:
    for _ in range(300):
        if process.poll() is not None:
            raise RuntimeError('Task frontend exited before opening its listener')
        try:
            verify_listener_owner(process.pid, '127.0.0.1', 3346)
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
identity_file.write_text(json.dumps({'pid': process.pid, 'node': node, 'cwd': str(root / 'frontend')}))
print(f'PASS: started task frontend PID {process.pid}; owns loopback port 3346; private ingress configured without output')
