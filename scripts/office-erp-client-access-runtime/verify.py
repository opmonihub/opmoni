#!/usr/bin/env python3
"""Read-only ownership, listener, mount and test-store checks for task services."""
import argparse
import json
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
project = 'opmoni-office-erp-client-access'
network = project + '-net'
browser_database = 'opmoni_client_access_browser'
browser_database_comment = 'opmoni-office-erp-client-access-browser:v1; test-only; preserve across runs'


def require(condition, reason):
    if not condition:
        raise RuntimeError(reason)


def inspect(kind, name):
    return json.loads(subprocess.check_output(['docker', kind, 'inspect', name]))[0]


def container_environment(container):
    return dict(entry.split('=', 1) for entry in container['Config']['Env'] if '=' in entry)


def verify_mount(container, service):
    mounts = container['Mounts']
    require(len(mounts) == 1, 'Unexpected mount count: ' + service)
    mount = mounts[0]
    if service in ['backend', 'browser-backend']:
        require(mount['Type'] == 'bind' and mount['Source'] == str(root)
                and mount['Destination'] == '/workspace', 'Wrong source mount: ' + service)
        require(container['Config']['WorkingDir'] == '/workspace/backend', 'Wrong working directory: ' + service)
    else:
        require(mount['Name'] == f'{project}-{service}', 'Unexpected store volume: ' + service)
        volume = inspect('volume', mount['Name'])
        require(volume['Labels']['com.docker.compose.project'] == project, 'Wrong volume owner: ' + service)


def verify_container(service, published_port, shared_network, expected_image=None, expected_image_id=None):
    container = inspect('container', f'{project}-{service}-1')
    require(container['State']['Running'], 'Service is not running: ' + service)
    labels = container['Config']['Labels']
    require(labels['com.docker.compose.project'] == project, 'Wrong container owner: ' + service)
    require(labels['com.docker.compose.service'] == service, 'Wrong service label: ' + service)
    require(set(container['NetworkSettings']['Networks']) == {shared_network}, 'Unexpected network: ' + service)
    ports = container['NetworkSettings']['Ports']
    published = ports.get('8000/tcp' if service in ['backend', 'browser-backend'] else ('5432/tcp' if service == 'postgres' else '6379/tcp'))
    require(published == [{'HostIp': '127.0.0.1', 'HostPort': published_port}], 'Unexpected listener: ' + service)
    verify_mount(container, service)
    if expected_image is not None:
        require(container['Config']['Image'] == expected_image, 'Backend image differs from the owned image: ' + service)
    if expected_image_id is not None:
        require(container['Image'] == expected_image_id, 'Backend image ID differs from the owned image: ' + service)

    return container


def verify_backend(container, browser=False):
    env = container_environment(container)
    if browser:
        expected = {
            'APP_URL': 'http://localhost:18871',
            'FRONTEND_ORIGIN': 'http://localhost:3350',
            'DB_CONNECTION': 'pgsql',
            'DB_HOST': 'postgres',
            'DB_DATABASE': browser_database,
            'REDIS_HOST': 'redis',
            'REDIS_DB': '10',
            'REDIS_CACHE_DB': '11',
            'REDIS_SESSION_DB': '12',
            'REDIS_PREFIX': 'opmoni-office-erp-client-access-browser-',
            'CACHE_PREFIX': 'opmoni-office-erp-client-access-browser-cache-',
            'QUEUE_CONNECTION': 'redis',
            'MAIL_MAILER': 'array',
            'WHATSAPP_DISPATCH_ENABLED': 'false',
            'ERP_FISCAL_PROVIDER': 'fake',
            'ERP_FISCAL_PRODUCTION_ENABLED': 'false',
        }
    else:
        expected = {
            'APP_URL': 'http://localhost:18870',
            'FRONTEND_ORIGIN': 'http://localhost:3350',
            'DB_CONNECTION': 'pgsql',
            'DB_HOST': 'postgres',
            'DB_DATABASE': 'opmoni_test',
            'REDIS_HOST': 'redis',
            'REDIS_DB': '13',
            'REDIS_CACHE_DB': '14',
            'REDIS_SESSION_DB': '15',
            'REDIS_PREFIX': 'opmoni-office-erp-client-access-',
            'CACHE_PREFIX': 'opmoni-office-erp-client-access-cache-',
            'QUEUE_CONNECTION': 'redis',
            'MAIL_MAILER': 'array',
            'WHATSAPP_DISPATCH_ENABLED': 'false',
            'ERP_FISCAL_PROVIDER': 'fake',
            'ERP_FISCAL_PRODUCTION_ENABLED': 'false',
        }
    for key, value in expected.items():
        require(env.get(key) == value, 'Unsafe runtime configuration: ' + key)
    for path in ['backend/vendor', 'frontend/node_modules']:
        require((root / path).resolve().is_relative_to(root), 'Shared dependency directory')


parser = argparse.ArgumentParser()
parser.add_argument('--browser', action='store_true', help='Require and validate the isolated browser backend')
parser.add_argument('--browser-image', action='store_true',
                    help='Require the local backend tag to resolve to the verified backend image ID')
args = parser.parse_args()

require(str(root) == '/home/obsidian/dev/opmoni/.worktrees/manage-office-erp-client-access', 'Wrong checkout')
require(subprocess.check_output(['git', '-C', str(root), 'branch', '--show-current']).strip()
        == b'codex/manage-office-erp-client-access', 'Wrong branch')

network_data = inspect('network', network)
require(network_data['Labels']['com.docker.compose.project'] == project, 'Wrong network owner')
actual_names = {entry['Name'].lstrip('/') for entry in network_data['Containers'].values()}
base_names = {f'{project}-{service}-1' for service in ['backend', 'postgres', 'redis']}
browser_name = f'{project}-browser-backend-1'
require(actual_names in [base_names, base_names | {browser_name}], 'Unexpected network member')
browser_present = browser_name in actual_names
require(not args.browser or browser_present, 'Isolated browser backend is not running')

postgres = verify_container('postgres', '15470', network)
redis = verify_container('redis', '16370', network)
backend = verify_container('backend', '18870', network)
verify_backend(backend)
if args.browser_image:
    local_image = inspect('image', 'opmoni-backend:dev')
    require(local_image['Id'] == backend['Image'],
            'Local backend image tag differs from the running owned backend; refusing browser activation')

browser = None
if browser_present:
    browser = verify_container('browser-backend', '18871', network, backend['Config']['Image'], backend['Image'])
    verify_backend(browser, browser=True)

database = subprocess.check_output([
    'docker', 'exec', f'{project}-postgres-1', 'psql', '-U', 'opmoni', '-d', 'opmoni_test', '-Atc',
    'select current_database()',
]).strip()
require(database == b'opmoni_test', 'Wrong physical primary test database')
require(subprocess.check_output(['docker', 'exec', f'{project}-redis-1', 'redis-cli', 'ping']).strip() == b'PONG',
        'Redis unavailable')

if browser_present:
    marker = subprocess.check_output([
        'docker', 'exec', f'{project}-postgres-1', 'psql', '-U', 'opmoni', '-d', 'opmoni_test', '-Atc',
        "SELECT coalesce(shobj_description(oid, 'pg_database'), '') FROM pg_database WHERE datname = '" + browser_database + "'",
    ]).decode().strip()
    require(marker == browser_database_comment, 'Browser database is not marked as owned test data')
    browser_current = subprocess.check_output([
        'docker', 'exec', f'{project}-postgres-1', 'psql', '-U', 'opmoni', '-d', browser_database, '-Atc',
        'select current_database()',
    ]).strip()
    require(browser_current == browser_database.encode(), 'Browser backend cannot select its isolated database')

if args.browser:
    print('PASS: owned checkout, exact four-service network, isolated browser DB, Redis 10-12, distinct browser backend, loopback 18871')
elif browser_present:
    print('PASS: owned checkout, exact four-service network, original opmoni_test/Redis 13-15 and isolated browser DB/Redis 10-12')
else:
    print('PASS: owned checkout, exact three-service network, mounts, volumes, loopback ports, opmoni_test and Redis 13-15')
if args.browser_image:
    print('PASS: local backend image tag matches the verified running backend image ID')

identity = root.stat()
(root / '.superpowers/sdd/manage-office-erp-client-access/verified-source-identity').write_text(
    f'{identity.st_dev}:{identity.st_ino}'
)
