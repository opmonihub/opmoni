#!/usr/bin/env python3
"""Fail closed before using this task's containers; never print environments."""
import json
from pathlib import Path
import subprocess

from listener import verify_listener_owner

if not __debug__:
    raise RuntimeError('Runtime verification requires active Python assertions')

root = str(Path(__file__).resolve().parents[2])
project = "opmoni-office-erp-commercial"
network = "opmoni-office-erp-commercial-net"
ports = {"backend": ("8000/tcp", "18860"),
         "postgres": ("5432/tcp", "15460"), "redis": ("6379/tcp", "16360")}


def inspect(kind, name):
    return json.loads(subprocess.check_output(["docker", kind, "inspect", name]))[0]


net = inspect("network", network)
assert net["Labels"]["com.docker.compose.project"] == project
for service in ("backend", "postgres", "redis"):
    container = inspect("container", f"{project}-{service}-1")
    assert container["State"]["Running"], f"{service} is not running"
    assert container["Config"]["Labels"]["com.docker.compose.project"] == project
    assert set(container["NetworkSettings"]["Networks"]) == {network}
    assert service in container["NetworkSettings"]["Networks"][network]["Aliases"]
    mounts = container["Mounts"]
    if service == "backend":
        assert len(mounts) == 1, "Unexpected shared mount"
        assert mounts[0]["Type"] == "bind" and mounts[0]["Source"] == root
        assert mounts[0]["Destination"] == "/workspace" and mounts[0]["RW"]
        assert container["Config"]["WorkingDir"] == "/workspace/backend"
        # Resolve and reject links to another checkout's dependency/build trees.
        for path in ("backend/vendor", "frontend/node_modules", "frontend/.nuxt"):
            assert Path(root, path).resolve().is_relative_to(root), f"Shared dependency path: {path}"
        for path in ("backend/routes/api.php", "frontend/nuxt.config.ts", "docker-compose.yml"):
            actual = subprocess.check_output(["docker", "exec", f"{project}-{service}-1", "cat", f"/workspace/{path}"])
            assert actual == Path(root, path).read_bytes(), f"Source contents differ: {path}"
    else:
        expected = f"{project}-{service}"
        assert len(mounts) == 1 and mounts[0]["Name"] == expected
        volume = inspect("volume", expected)
        assert volume["Labels"]["com.docker.compose.project"] == project
    for bindings in container["NetworkSettings"]["Ports"].values():
        assert not bindings or all(b["HostIp"] == "127.0.0.1" for b in bindings)
    port, published = ports[service]
    assert container["NetworkSettings"]["Ports"][port] == [{"HostIp": "127.0.0.1", "HostPort": published}], "Missing or unexpected published listener"
members = set(net["Containers"])
assert len(members) == 3, "Unexpected task network member (workers/schedulers are forbidden)"
identity = json.loads(Path(root, '.superpowers/sdd/tasks/office-erp-commercial-frontend-process.json').read_text())
proc = Path('/proc', str(identity['pid']))
assert (proc / 'cwd').resolve() == Path(root, 'frontend'), "Unexpected frontend process directory"
command = (proc / 'cmdline').read_bytes().split(b'\0')
assert b'node_modules/nuxt/bin/nuxt.mjs' in command and b'127.0.0.1' in command and b'3346' in command
verify_listener_owner(identity['pid'], '127.0.0.1', 3346)
# Compare only the expected private values; never output the process environment.
environment = dict(entry.split(b'=', 1) for entry in (proc / 'environ').read_bytes().split(b'\0') if b'=' in entry)
assert environment[b'NUXT_BACKEND_URL'] == b'http://127.0.0.1:18860'
assert environment[b'NUXT_INGRESS_KEY']
print("PASS: task ownership, whole-worktree mount/source contents, host frontend PID/cwd and owned loopback listener, private dependencies, aliases, independent volumes, no workers/scheduler")
