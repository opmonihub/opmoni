"""Check the host Nuxt listener without contacting other processes."""

import os
from pathlib import Path
import socket


def ensure_port_available(host: str, port: int) -> None:
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
            probe.bind((host, port))
    except OSError as error:
        raise RuntimeError(f'Loopback port {host}:{port} is occupied or unavailable') from error


def verify_listener_owner(pid: int, host: str, port: int) -> None:
    address = socket.inet_aton(host)[::-1].hex().upper()
    expected = f'{address}:{port:04X}'
    inodes = set()
    for line in Path('/proc/net/tcp').read_text().splitlines()[1:]:
        fields = line.split()
        if fields[1] == expected and fields[3] == '0A':
            inodes.add(fields[9])
    if not inodes:
        raise RuntimeError(f'There is no listener on {host}:{port}')

    descriptors = Path('/proc', str(pid), 'fd')
    if not descriptors.is_dir():
        raise RuntimeError('Listener does not belong to the recorded process')
    owned_inodes = set()
    for descriptor in descriptors.iterdir():
        try:
            target = os.readlink(descriptor)
        except FileNotFoundError:
            continue
        if target.startswith('socket:['):
            owned_inodes.add(target[8:-1])
    if not inodes.issubset(owned_inodes):
        raise RuntimeError('Listener does not belong to the recorded process')
