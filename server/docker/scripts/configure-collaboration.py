#!/usr/bin/env python3
"""Publish collaboration configuration; deployment remains owner-controlled."""
import argparse
import contextlib
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

PROTOCOL = 'peanut.collaboration.configuration.v1'
# Default up follows mysql -> php -> nginx healthy dependencies. Including probe
# duration, compose.yaml budgets mysql 320s + php 190s; allow 90s for orchestration.
# Only explicit application startup invokes Compose through this module.
STARTUP_COMMAND_TIMEOUT = 600
IMAGE = re.compile(r'(?:sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})\Z')
CONFIG_KEYS = {'protocol', 'schema_version', 'instance_id', 'edition', 'service_instance_id', 'key_id', 'image',
               'php_origin', 'control_origin', 'wss_hostname', 'wss_bind', 'wss_port', 'config_revision', 'projector_version', 'ca_sha256',
               'service_root', 'compose_project', 'network', 'secret'}
SERVICE_DIRECTORY = 'private/collaboration/service'
SERVICE_CA = SERVICE_DIRECTORY + '/ca.pem'

def public_wss_url(data):
    hostname=data.get('wss_hostname')
    port=data.get('wss_port')
    if not isinstance(hostname,str) or len(hostname)>253 or not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*',hostname) or type(port) is not int or not 1024<=port<=65535:
        raise ValueError('COLLABORATION_WSS_IDENTITY_INVALID')
    return 'wss://'+hostname.lower()+':'+str(port)+'/collaboration'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def ordinary(path, private=True):
    path = Path(path)
    if not path.exists() and not path.is_symlink():
        raise FileNotFoundError('COLLABORATION_CONFIGURATION_REFERENCE_UNAVAILABLE')
    if not path.is_absolute() or path.resolve() != path or not path.is_file() or path.is_symlink():
        raise ValueError('COLLABORATION_FILE_UNSAFE')
    stat = path.stat()
    if stat.st_nlink != 1 or (private and stat.st_mode & 0o777 != 0o600) or (not private and stat.st_mode & 0o022):
        raise ValueError('COLLABORATION_FILE_PERMISSIONS_INVALID')
    return stat


def environment(path):
    ordinary(path)
    rows = {}
    for line in path.read_text().splitlines():
        if not line or line.startswith('#'):
            continue
        key, separator, value = line.partition('=')
        if not separator or key in rows or not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
            raise ValueError('COLLABORATION_ENVIRONMENT_INVALID')
        if value.startswith('"'):
            value = json.loads(value)
        elif value.startswith("'") and value.endswith("'"):
            value = value[1:-1].replace("\\'", "'")
        rows[key] = value
    return rows


def backend_environment(server):
    path=server / '.env'
    return environment(path) if path.exists() or path.is_symlink() else {}


def canonical_root(root):
    if not root.is_absolute() or root.resolve() != root or root.is_symlink() or not (root / 'server/docker/compose.yaml').is_file():
        raise ValueError('COLLABORATION_APP_ROOT_INVALID')
    for relative in ('server', 'server/docker', 'server/private'):
        if (root / relative).is_symlink():
            raise ValueError('COLLABORATION_PARENT_LINKED')
    return root / 'server'


def atomic(path, payload, expected=None, owner=None, private=True):
    if path.exists() or path.is_symlink():
        ordinary(path, private)
        if expected is not None and digest(path.read_bytes()) != expected:
            raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
    elif expected is not None:
        raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
    if path.parent.is_symlink() or path.parent.resolve() != path.parent:
        raise ValueError('COLLABORATION_PARENT_LINKED')
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            os.fchmod(stream.fileno(), 0o600 if private else 0o644)
            if owner is not None:
                os.fchown(stream.fileno(), owner.st_uid, owner.st_gid)
            stream.write(payload); stream.flush(); os.fsync(stream.fileno())
        os.replace(name, path)
        descriptor = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    finally:
        if os.path.exists(name):
            os.unlink(name)


@contextlib.contextmanager
def locked(server):
    path = server / '.env.collaboration.lock'
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        st = os.fstat(fd)
        if st.st_nlink != 1 or st.st_mode & 0o777 != 0o600 or st.st_uid != os.geteuid():
            raise ValueError('COLLABORATION_LOCK_UNSAFE')
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)


def validate_configuration(data, server):
    if set(data) != CONFIG_KEYS or data['protocol'] != 'peanut.collaboration.configuration.v1' or data['schema_version'] != 1:
        raise ValueError('COLLABORATION_CONFIGURATION_PROTOCOL_INVALID')
    for key in ('instance_id', 'service_instance_id', 'key_id'):
        if not isinstance(data[key], str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,128}', data[key]):
            raise ValueError('COLLABORATION_IDENTITY_INVALID')
    if data['edition'] not in ('engine', 'standalone', 'multi-tenant') or data['projector_version'] != 'peanut.richtext-yjs/1':
        raise ValueError('COLLABORATION_COMPATIBILITY_INVALID')
    if type(data['config_revision']) is not int or data['config_revision'] < 1 or not IMAGE.fullmatch(data['image']):
        raise ValueError('COLLABORATION_IMAGE_REVISION_INVALID')
    root = Path(data['service_root'])
    if not root.is_absolute() or root.resolve() != root or root.is_symlink() or not (root / 'docker/compose.yaml').is_file():
        raise ValueError('COLLABORATION_INDEPENDENT_SERVICE_REQUIRED')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', data['compose_project']) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}', data['network']):
        raise ValueError('COLLABORATION_INDEPENDENT_SERVICE_BINDING_INVALID')
    if data['image'] != 'node:22.23.2-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9':
        raise ValueError('COLLABORATION_FIXED_RUNTIME_IMAGE_REQUIRED')
    for key in ('php_origin', 'control_origin'):
        url = urlsplit(data[key])
        if url.scheme != 'https' or not url.hostname or url.username or url.password or url.path not in ('', '/') or url.query or url.fragment or url.port != 8443:
            raise ValueError('COLLABORATION_PRIVATE_TLS_ORIGIN_INVALID')
    public_wss_url(data)
    if ipaddress.ip_address(data['wss_bind']).version != 4:
        raise ValueError('COLLABORATION_WSS_BIND_INVALID')
    if not isinstance(data['secret'], str) or not 32 <= len(data['secret'].encode('utf-8')) <= 4096 or any(c in data['secret'] for c in ('\n', '\r', '\0')):
        raise ValueError('COLLABORATION_SECRET_INVALID')
    ordinary(server / 'docker/tls/private-key.pem')
    for relative in ('docker/tls/certificate.pem', 'docker/tls/ca.pem'):
        ordinary(server / relative, False)
    ca=server/'docker/tls/ca.pem'
    if ca.stat().st_mode&0o777 not in (0o444,0o644) or ca.stat().st_size>1048576 or not re.fullmatch(r'[a-f0-9]{64}',data['ca_sha256']) or digest(ca.read_bytes())!=data['ca_sha256']:
        raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
    return data


def result(action, values):
    return {'protocol': PROTOCOL, 'schema_version': 1, 'action': action,
            'desired_enabled': values.get('COLLABORATION_ENABLED') == 'enabled',
            'config_revision': int(values.get('COLLABORATION_CONFIG_REVISION', '0')),
            'runtime_state': 'not_observed'}


def env_payload(values, compose=False):
    def quote(value):
        # Compose single quotes suppress interpolation, including secrets containing '$'.
        return "'" + str(value).replace("'", "\\'") + "'" if compose else json.dumps(str(value), ensure_ascii=False)
    return ''.join(key + '=' + quote(value) + '\n' for key, value in values.items()).encode()


def publish_independent_service(server, data, node, owner):
    root = Path(data['service_root'])
    existing = environment(root / 'docker/.env')
    port = existing.get('COLLABORATION_PORT', '')
    # The independent Compose port expression also supports an explicit loopback
    # binding. Preserve it when publishing; a source-only service need not listen
    # on every host interface. Other address/port expressions fail closed.
    port_number = port.removeprefix('127.0.0.1:')
    if not re.fullmatch(r'[0-9]{1,5}', port_number) or not 1 <= int(port_number) <= 65535:
        raise ValueError('COLLABORATION_SERVICE_PORT_REQUIRED')
    for directory in (root / 'docker', root / 'certs', server / 'private/collaboration', server / SERVICE_DIRECTORY):
        if not directory.exists(): directory.mkdir(mode=0o755)
        if directory.is_symlink() or directory.resolve() != directory or not directory.is_dir() or directory.stat().st_mode & 0o022:
            raise ValueError('COLLABORATION_DIRECTORY_UNSAFE')
    ca = (server / 'docker/tls/ca.pem').read_bytes()
    atomic(root / 'certs/ca.pem', ca, private=False)
    atomic(server / SERVICE_CA, ca, owner=owner, private=False)
    runtime = {key: node[key] for key in ('COLLABORATION_INSTANCE_ID', 'COLLABORATION_SERVICE_INSTANCE_ID',
               'COLLABORATION_CA_SHA256', 'COLLABORATION_KEY_ID', 'COLLABORATION_EDITION', 'COLLABORATION_PHP_ORIGIN')}
    runtime.update(COMPOSE_PROJECT_NAME=data['compose_project'], COLLABORATION_NETWORK=data['network'],
                   COLLABORATION_PORT=port, COLLABORATION_SECRET=data['secret'])
    atomic(root / 'docker/.env', env_payload(runtime, True))


def enable(server, data, expected_revision):
    validate_configuration(data, server)
    with locked(server):
        path = server / '.env'; owner = ordinary(path); old = path.read_bytes(); values = environment(path)
        revision = int(values.get('COLLABORATION_CONFIG_REVISION', '0'))
        config_sha = digest(json.dumps(data, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode())
        if values.get('COLLABORATION_CONFIG_SHA256') == config_sha and values.get('COLLABORATION_ENABLED') == 'enabled':
            return result('enable', values)
        if revision != expected_revision or data['config_revision'] != revision + 1 or values.get('COLLABORATION_ENABLED') == 'enabled':
            raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
        if values.get('DEPLOYMENT_MODE') != data['edition']:
            raise ValueError('COLLABORATION_EDITION_MISMATCH')
        node = {'COLLABORATION_IMAGE': data['image'], 'COLLABORATION_INSTANCE_ID': data['instance_id'],
                'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'], 'COLLABORATION_CA_SHA256': data['ca_sha256'],
                'COLLABORATION_KEY_ID': data['key_id'], 'COLLABORATION_EDITION': data['edition'],
                'COLLABORATION_PHP_ORIGIN': data['php_origin'], 'COLLABORATION_PHP_TLS_HOST': urlsplit(data['php_origin']).hostname,
                'COLLABORATION_CONTROL_TLS_HOST': urlsplit(data['control_origin']).hostname,
                'COLLABORATION_WSS_BIND': data['wss_bind'], 'COLLABORATION_WSS_PORT': str(data['wss_port']),
                   'COLLABORATION_WSS_HOSTNAME': data['wss_hostname'].lower(),
                   'COLLABORATION_NETWORK': data['network']}
        updates = {'COLLABORATION_ENABLED': 'enabled', 'COLLABORATION_INSTANCE_ID': data['instance_id'],
                   'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'], 'COLLABORATION_SERVICE_KEY_ID': data['key_id'],
                   'COLLABORATION_SECRET': data['secret'], 'COLLABORATION_CONTROL_ORIGIN': data['control_origin'],
                   'COLLABORATION_PUBLIC_WSS_URL': public_wss_url(data), 'COLLABORATION_CA_FILE': SERVICE_CA,
                   'COLLABORATION_CA_SHA256': data['ca_sha256'], 'COLLABORATION_CONFIG_REVISION': str(data['config_revision']),
                   'COLLABORATION_CONFIG_SHA256': config_sha, 'COLLABORATION_SERVICE_ROOT': data['service_root'],
                   'COLLABORATION_COMPOSE_PROJECT': data['compose_project']}
        publish_independent_service(server, data, node, owner)
        atomic(server / 'docker/collaboration.env', env_payload(node, True))
        retired = {'COLLABORATION_SERVICE_SECRET_FILE', 'COLLABORATION_TICKET_SECRET_FILE',
                   'RICH_TEXT_COLLABORATION_URL', 'RICH_TEXT_COLLABORATION_SECRET'}
        lines = [line for line in old.decode().splitlines() if line.partition('=')[0] not in updates.keys() | retired]
        atomic(path, ('\n'.join(lines) + '\n').encode() + env_payload(updates), digest(old), owner)
        return result('enable', updates)


def compose_arguments(server):
    values = backend_environment(server)
    arguments = ['docker', 'compose', '--env-file', '.env', '-f', 'compose.yaml']
    source = values.get('PEANUT_DATABASE_SOURCE', 'bundled')
    if source not in ('bundled', 'registered'):
        raise ValueError('RUNTIME_DATABASE_SOURCE_INVALID')
    if source == 'registered':
        ordinary(server / 'docker/compose.registered-database.yaml', private=False)
        arguments += ['-f', 'compose.registered-database.yaml']
    if values.get('COLLABORATION_ENABLED') == 'enabled':
        node = environment(server / 'docker/collaboration.env')
        if any(node.get(key) != values.get(key) for key in ('COLLABORATION_INSTANCE_ID', 'COLLABORATION_SERVICE_INSTANCE_ID', 'COLLABORATION_CA_SHA256')):
            raise ValueError('COLLABORATION_PROFILE_CONFIGURATION_STALE')
        arguments += ['--env-file', 'collaboration.env', '-f', 'compose.collaboration.yaml']
    return arguments


def registered_database_services(server, arguments):
    # Recognise this package-owned file even before the wizard publishes .env.
    # Explicit service targets also exclude MySQL when COMPOSE_PROFILES is set.
    official = server / 'docker/compose.registered-database.yaml'
    for index, argument in enumerate(arguments):
        value = None
        if argument in ('-f', '--file') and index + 1 < len(arguments):
            value = arguments[index + 1]
        elif argument.startswith('--file='):
            value = argument[len('--file='):]
        if value is not None:
            path = Path(value)
            if not path.is_absolute():
                path = server / 'docker' / path
            if path.resolve() == official.resolve():
                ordinary(official, private=False)
                ordinary(path, private=False)
                return ['php', 'nginx']
    return []


def run(server, arguments):
    try:
        process = subprocess.run(arguments, cwd=server / 'docker', stdin=subprocess.DEVNULL,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=STARTUP_COMMAND_TIMEOUT)
    except subprocess.TimeoutExpired:
        raise RuntimeError('COLLABORATION_NATIVE_COMMAND_UNCONFIRMED') from None
    if process.returncode:
        raise RuntimeError('COLLABORATION_NATIVE_COMMAND_UNCONFIRMED')
    return process.stdout.decode()


def start(server, extra=()):
    # Application deployment only. Node starts independently from its own config.
    arguments = compose_arguments(server) + list(extra)
    run(server, arguments + ['config', '--quiet'])
    run(server, arguments + ['up', '-d', '--wait', '--no-build'] + registered_database_services(server, arguments))


def stop(server, extra=()):
    # This composition contains APP services and ingress, not the independent Node.
    arguments = compose_arguments(server) + list(extra)
    run(server, arguments + ['stop'] + registered_database_services(server, arguments))


def execute():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('plan', 'enable', 'status'))
    parser.add_argument('--app-root', type=Path, required=True)
    parser.add_argument('--configuration', type=Path)
    parser.add_argument('--expected-revision', type=int)
    try:
        args = parser.parse_args(); server = canonical_root(args.app_root)
        values = backend_environment(server)
        if args.action == 'enable':
            if args.configuration is None or args.expected_revision is None:
                raise ValueError('COLLABORATION_CONFIGURATION_REQUIRED')
            ordinary(args.configuration)
            answer = enable(server, json.loads(args.configuration.read_text()), args.expected_revision)
        else:
            if args.configuration is not None:
                ordinary(args.configuration); validate_configuration(json.loads(args.configuration.read_text()), server)
            answer = result(args.action, values)
        print(json.dumps(answer, sort_keys=True)); return 0
    except (OSError, ValueError, TypeError, KeyError, RuntimeError, subprocess.SubprocessError) as error:
        print(json.dumps({'protocol': PROTOCOL, 'schema_version': 1, 'error_code': 'COLLABORATION_CONFIGURATION_UNCONFIRMED', 'runtime_state': 'unknown'}))
        return 4 if isinstance(error, RuntimeError) else 2


if __name__ == '__main__':
    sys.exit(execute())
