#!/usr/bin/env python3
"""Copy missing local configuration and locked dependencies without shared writes."""
import os
from pathlib import Path
import shutil
import subprocess

os.umask(0o077)
target = Path(__file__).resolve().parents[2]
source = Path('/home/obsidian/dev/opmoni')
for relative in ('.env', 'backend/.env', 'frontend/.env'):
    destination = target / relative
    if not destination.exists():
        if not (source / relative).is_file():
            raise RuntimeError(f'Configure the source {relative} first')
        shutil.copyfile(source / relative, destination)
        destination.chmod(0o600)
    if destination.is_symlink() or destination.stat().st_mode & 0o777 != 0o600:
        raise RuntimeError(f'Existing {relative} must use mode 0600; file preserved')
for relative, lock in (('backend/vendor', 'backend/composer.lock'), ('frontend/node_modules', 'frontend/pnpm-lock.yaml')):
    destination = target / relative
    if (target / lock).read_bytes() != (source / lock).read_bytes():
        raise RuntimeError(f'Source lockfile differs: {lock}; install locked dependencies independently')
    if not destination.exists():
        subprocess.run(['cp', '-a', '--reflink=auto', str(source / relative), str(destination)], check=True)
    if destination.is_symlink() or not destination.resolve().is_relative_to(target):
        raise RuntimeError(f'Shared dependency path forbidden: {relative}')
    for path in destination.rglob('*'):
        if path.is_symlink() and not path.resolve().is_relative_to(target):
            raise RuntimeError(f'Shared dependency link forbidden: {path.relative_to(target)}')
for relative in ('backend/storage/framework/cache/data', 'backend/storage/framework/sessions',
                 'backend/storage/framework/views', 'backend/storage/logs', 'backend/bootstrap/cache'):
    (target / relative).mkdir(parents=True, exist_ok=True)
print('PASS: private environment files preserved; independent dependencies and runtime directories prepared')
