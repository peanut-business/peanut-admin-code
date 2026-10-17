#!/usr/bin/env python3
"""The APP's optional collaboration configuration/lifecycle entry; never deploy on enable."""
import argparse
import base64
import contextlib
import fcntl
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

PROTOCOL = 'peanut.collaboration.lifecycle.v1'
# Default up follows mysql -> php -> nginx healthy dependencies. Including probe
# duration, compose.yaml budgets mysql 320s + php 190s; allow 90s for orchestration.
# Only startup up commands use this bound; ordinary observations/drain stay at 40s.
STARTUP_COMMAND_TIMEOUT = 600
IMAGE = re.compile(r'(?:sha256:[a-f0-9]{64}|[A-Za-z0-9][A-Za-z0-9._:/-]{0,160}@sha256:[a-f0-9]{64})\Z')
CONFIG_KEYS = {'protocol', 'schema_version', 'instance_id', 'edition', 'service_instance_id', 'key_id', 'image',
               'php_origin', 'control_origin', 'wss_hostname', 'wss_bind', 'wss_port', 'config_revision', 'projector_version', 'ca_sha256',
               'service_root', 'compose_project', 'network'}
SERVICE_DIRECTORY = 'private/collaboration/service'
SERVICE_SECRET = SERVICE_DIRECTORY + '/service-secret'
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


def service_directory(server, complete=True, secret_required=True):
    path = server / SERVICE_DIRECTORY
    for parent in (server / 'private/collaboration', path):
        if parent.is_symlink() or parent.resolve() != parent or not parent.is_dir() or parent.stat().st_mode & 0o022:
            raise ValueError('COLLABORATION_SERVICE_DIRECTORY_UNSAFE')
    names = {entry.name for entry in path.iterdir()}
    if names - {'service-secret', 'ca.pem'} or (secret_required and 'service-secret' not in names) or (complete and 'ca.pem' not in names):
        raise ValueError('COLLABORATION_SERVICE_DIRECTORY_CONTENTS_INVALID')
    if 'service-secret' in names:
        ordinary(path / 'service-secret')
    if 'ca.pem' in names:
        ordinary(path / 'ca.pem', False)
    return path


def validate_configuration(data, server, service_required=True):
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
    # Fixed project-local references, metadata only. Never generate keys or print/read their contents here.
    for relative in ('private/collaboration/ticket-secret', 'docker/tls/private-key.pem'):
        ordinary(server / relative)
    if service_required:
        service_directory(server, False)
    for relative in ('docker/tls/certificate.pem', 'docker/tls/ca.pem'):
        ordinary(server / relative, False)
    ca=server/'docker/tls/ca.pem'
    if ca.stat().st_mode&0o777 not in (0o444,0o644) or ca.stat().st_size>1048576 or not re.fullmatch(r'[a-f0-9]{64}',data['ca_sha256']) or digest(ca.read_bytes())!=data['ca_sha256']:
        raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
    return data


def result(action, values, state=None):
    unobserved=values is None
    values=values or {}
    enabled = values.get('COLLABORATION_ENABLED') == 'enabled'
    return {'protocol': PROTOCOL, 'schema_version': 1, 'action': action,
            'instance_id': values.get('COLLABORATION_INSTANCE_ID'), 'edition': values.get('DEPLOYMENT_MODE'),
            'config_revision': int(values.get('COLLABORATION_CONFIG_REVISION', '0')),
            'config_sha256': values.get('COLLABORATION_CONFIG_SHA256'), 'desired_enabled': None if unobserved else enabled,
            'applied_revision': None, 'runtime_state': state or ('prepared' if enabled else 'disabled'),
            'checks': [{'key': 'desired_configuration', 'state': 'unknown'}] if unobserved else [{'key': 'runtime', 'state': 'not_probed'}], 'drain': None,
            'redacted_secret_refs': {key: bool(values.get(key)) for key in ('COLLABORATION_SERVICE_SECRET_FILE', 'COLLABORATION_TICKET_SECRET_FILE')},
            'gaps': ['configuration_unobserved'] if unobserved else [], 'error_code': None, 'restart_required': unobserved or enabled or bool(values.get('COLLABORATION_CONFIG_SHA256'))}


def observed_configuration(values):
    """Validate the public projection independently; never return secret/environment values."""
    values=dict(values)
    enabled=values.get('COLLABORATION_ENABLED','disabled')
    revision=values.get('COLLABORATION_CONFIG_REVISION','0')
    edition=values.get('DEPLOYMENT_MODE')
    if enabled not in ('enabled','disabled') or not re.fullmatch(r'0|[1-9][0-9]*',revision) or int(revision)>9007199254740991 or edition not in ('engine','standalone','multi-tenant',None):
        raise ValueError('COLLABORATION_CONFIGURATION_PROJECTION_INVALID')
    for key in ('COLLABORATION_INSTANCE_ID','COLLABORATION_CONFIG_SHA256'):
        value=values.get(key)
        if value=='' and enabled=='disabled':
            values.pop(key);value=None
        pattern=r'[A-Za-z0-9_-]{1,128}' if key.endswith('INSTANCE_ID') else r'[a-f0-9]{64}'
        if value is not None and not re.fullmatch(pattern,value):raise ValueError('COLLABORATION_CONFIGURATION_PROJECTION_INVALID')
        if enabled=='enabled' and value is None:raise ValueError('COLLABORATION_CONFIGURATION_PROJECTION_INVALID')
    if enabled=='enabled' and (int(revision)<1 or edition is None):raise ValueError('COLLABORATION_CONFIGURATION_PROJECTION_INVALID')
    return {key:values[key] for key in ('COLLABORATION_ENABLED','COLLABORATION_INSTANCE_ID','DEPLOYMENT_MODE','COLLABORATION_CONFIG_REVISION','COLLABORATION_CONFIG_SHA256','COLLABORATION_SERVICE_SECRET_FILE','COLLABORATION_TICKET_SECRET_FILE') if key in values}


def relocate_service_layout(server, data, expected_revision, owner):
    """One owner-controlled rename for an already bound configuration; no runtime old-reference support."""
    if expected_revision != data['config_revision']:
        raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
    directory = server / SERVICE_DIRECTORY
    if directory.exists() or directory.is_symlink():
        service_directory(server, False, False)
    sources = []
    for old_relative, new_relative, private in (
        ('private/collaboration/service-secret', SERVICE_SECRET, True),
        ('private/collaboration/ca.pem', SERVICE_CA, False),
    ):
        old = server / old_relative; new = server / new_relative
        old_exists = old.exists() or old.is_symlink(); new_exists = new.exists() or new.is_symlink()
        if old_exists == new_exists:
            raise ValueError('COLLABORATION_SERVICE_LAYOUT_CONFLICT')
        source = old if old_exists else new
        stat = ordinary(source, private)
        if private and (stat.st_uid != owner.st_uid or not 32 <= stat.st_size <= 4096):
            raise ValueError('COLLABORATION_SERVICE_KEY_UNSAFE')
        if not private and digest(source.read_bytes()) != data['ca_sha256']:
            raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
        sources.append((source, new))
    # Close before any rename. Interrupted publication leaves old references unusable,
    # and this same configuration/current revision can finish the unique relocation.
    close_configuration_traffic(server)
    if not directory.exists():
        directory.mkdir(mode=0o700)
    for source, destination in sources:
        if source != destination:
            os.rename(source, destination)
            for parent in (source.parent, destination.parent):
                fd = os.open(parent, os.O_RDONLY)
                try: os.fsync(fd)
                finally: os.close(fd)
    ca_fd = os.open(server / SERVICE_CA, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        os.fchmod(ca_fd, 0o644); os.fsync(ca_fd)
    finally: os.close(ca_fd)
    service_directory(server)


def enable(server, data, expected_revision):
    validate_configuration(data, server, False)
    with locked(server):
        path = server / '.env'; old = path.read_bytes(); st = ordinary(path); values = environment(path)
        revision = int(values.get('COLLABORATION_CONFIG_REVISION', '0'))
        config_sha = digest(json.dumps(data, sort_keys=True, separators=(',', ':')).encode())
        if values.get('COLLABORATION_CONFIG_SHA256') == config_sha and values.get('COLLABORATION_ENABLED') == 'enabled':
            if values.get('COLLABORATION_PUBLIC_WSS_URL') != public_wss_url(data):
                raise ValueError('COLLABORATION_PUBLIC_TRANSPORT_STALE')
            bindings = {'DEPLOYMENT_MODE': data['edition'], 'COLLABORATION_INSTANCE_ID': data['instance_id'],
                        'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'], 'COLLABORATION_SERVICE_KEY_ID': data['key_id'],
                        'COLLABORATION_CONTROL_ORIGIN': data['control_origin'], 'COLLABORATION_CA_SHA256': data['ca_sha256'],
                        'COLLABORATION_CONFIG_REVISION': str(data['config_revision']),
                        'COLLABORATION_SERVICE_ROOT': data['service_root'], 'COLLABORATION_COMPOSE_PROJECT': data['compose_project']}
            if any(values.get(key) != value for key, value in bindings.items()):
                raise ValueError('COLLABORATION_SERVICE_LAYOUT_BINDING_INVALID')
            references = (values.get('COLLABORATION_SERVICE_SECRET_FILE'), values.get('COLLABORATION_CA_FILE'))
            if references == (SERVICE_SECRET, SERVICE_CA):
                service_directory(server)
                key_stat = ordinary(server / SERVICE_SECRET)
                if key_stat.st_uid != st.st_uid or not 32 <= key_stat.st_size <= 4096:
                    raise ValueError('COLLABORATION_SERVICE_KEY_UNSAFE')
                if any((server / relative).exists() or (server / relative).is_symlink()
                       for relative in ('private/collaboration/service-secret', 'private/collaboration/ca.pem')):
                    raise ValueError('COLLABORATION_SERVICE_LAYOUT_CONFLICT')
                if digest((server / SERVICE_CA).read_bytes()) != data['ca_sha256']:
                    raise ValueError('COLLABORATION_CA_REFERENCE_INVALID')
            elif references == ('private/collaboration/service-secret', 'private/collaboration/ca.pem'):
                relocate_service_layout(server, data, expected_revision, st)
                updates = {'COLLABORATION_SERVICE_SECRET_FILE': SERVICE_SECRET, 'COLLABORATION_CA_FILE': SERVICE_CA}
                lines = [line for line in old.decode().splitlines() if line.partition('=')[0] not in updates]
                payload = ('\n'.join(lines)+'\n'+''.join(key+'='+json.dumps(value)+'\n' for key,value in updates.items())).encode()
                atomic(path, payload, digest(old), st)
                return result('enable', environment(path))
            else:
                raise ValueError('COLLABORATION_SERVICE_LAYOUT_BINDING_INVALID')
            return result('enable', values)
        if revision != expected_revision or data['config_revision'] != revision + 1 or values.get('COLLABORATION_ENABLED') == 'enabled':
            raise ValueError('COLLABORATION_CONFIGURATION_CAS_CONFLICT')
        if values.get('DEPLOYMENT_MODE') != data['edition']:
            raise ValueError('COLLABORATION_EDITION_MISMATCH')
        service_directory(server, False)
        key_stat = ordinary(server / SERVICE_SECRET)
        if key_stat.st_uid != st.st_uid or not 32 <= key_stat.st_size <= 4096:
            raise ValueError('COLLABORATION_SERVICE_KEY_UNSAFE')
        if any((server / relative).exists() or (server / relative).is_symlink()
               for relative in ('private/collaboration/service-secret', 'private/collaboration/ca.pem')):
            raise ValueError('COLLABORATION_SERVICE_LAYOUT_CONFLICT')
        updates = {'COLLABORATION_ENABLED': 'enabled', 'COLLABORATION_INSTANCE_ID': data['instance_id'],
                   'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'], 'COLLABORATION_SERVICE_KEY_ID': data['key_id'],
                   'COLLABORATION_SERVICE_SECRET_FILE': SERVICE_SECRET, 'COLLABORATION_TICKET_SECRET_FILE': 'private/collaboration/ticket-secret',
                   'COLLABORATION_CONTROL_ORIGIN': data['control_origin'], 'COLLABORATION_PUBLIC_WSS_URL': public_wss_url(data),
                   'COLLABORATION_CONFIG_REVISION': str(data['config_revision']), 'COLLABORATION_CONFIG_SHA256': config_sha,
                   'COLLABORATION_SERVICE_ROOT': data['service_root'], 'COLLABORATION_COMPOSE_PROJECT': data['compose_project']}
        updates['COLLABORATION_CA_FILE']=SERVICE_CA
        updates['COLLABORATION_CA_SHA256']=data['ca_sha256']
        node = {'COLLABORATION_IMAGE': data['image'], 'COLLABORATION_INSTANCE_ID': data['instance_id'], 'COLLABORATION_SERVICE_INSTANCE_ID': data['service_instance_id'],
                'COLLABORATION_CA_SHA256':data['ca_sha256'],
                'COLLABORATION_KEY_ID': data['key_id'], 'COLLABORATION_EDITION': data['edition'], 'COLLABORATION_PHP_ORIGIN': data['php_origin'],
                'COLLABORATION_CONFIG_REVISION': str(data['config_revision']), 'COLLABORATION_CONFIG_SHA256': config_sha,
                'COLLABORATION_PHP_TLS_HOST': urlsplit(data['php_origin']).hostname, 'COLLABORATION_CONTROL_TLS_HOST': urlsplit(data['control_origin']).hostname,
                'COLLABORATION_WSS_BIND': data['wss_bind'], 'COLLABORATION_WSS_PORT': str(data['wss_port']), 'COLLABORATION_WSS_HOSTNAME': data['wss_hostname'].lower()}
        atomic(server / 'docker/collaboration.env', ''.join(k+'='+v+'\n' for k, v in node.items()).encode())
        atomic(server/SERVICE_CA,(server/'docker/tls/ca.pem').read_bytes(),owner=st,private=False)
        service_directory(server)
        publish_independent_service(server, data, node, st)
        # Backend .env is the sole commit point. Profile consumers require its matching digest/revision.
        lines = [line for line in old.decode().splitlines() if line.partition('=')[0] not in updates and line.partition('=')[0] not in ('RICH_TEXT_COLLABORATION_URL', 'RICH_TEXT_COLLABORATION_SECRET')]
        payload = ('\n'.join(lines)+'\n'+''.join(k+'='+json.dumps(v)+'\n' for k, v in updates.items())).encode()
        close_configuration_traffic(server)
        atomic(path, payload, digest(old), st)
        return result('enable', environment(path))


def compose_arguments(server):
    values = backend_environment(server)
    arguments = ['docker', 'compose', '--env-file', '.env', '-f', 'compose.yaml']
    if values.get('COLLABORATION_ENABLED') == 'enabled':
        node = environment(server / 'docker/collaboration.env')
        if any(node.get(key) != values.get(key) for key in ('COLLABORATION_INSTANCE_ID', 'COLLABORATION_SERVICE_INSTANCE_ID', 'COLLABORATION_CONFIG_REVISION', 'COLLABORATION_CONFIG_SHA256')):
            raise ValueError('COLLABORATION_PROFILE_CONFIGURATION_STALE')
        arguments += ['--env-file', 'collaboration.env', '-f', 'compose.collaboration.yaml']
    return arguments


def publish_independent_service(server, data, node, owner):
    root = Path(data['service_root'])
    # Host exposure belongs to this deployment, not to the PHP identity protocol.
    # Require its explicit local assignment before publishing any service files.
    port = environment(root / 'docker/.env').get('COLLABORATION_PORT', '')
    if not re.fullmatch(r'[0-9]{1,5}', port) or not 1 <= int(port) <= 65535:
        raise ValueError('COLLABORATION_SERVICE_PORT_REQUIRED')
    for path in (root / 'docker', root / 'certs'):
        if not path.exists(): path.mkdir(mode=0o755)
        if path.is_symlink() or path.resolve() != path or not path.is_dir() or path.stat().st_mode & 0o022:
            raise ValueError('COLLABORATION_INDEPENDENT_PRIVATE_DIRECTORY_INVALID')
    # The existing application identity/key remains unchanged; the independent
    # service gets only its own service key and CA, never the ticket key or SQL credentials.
    for name, private in (('service-secret', True), ('ca.pem', False)):
        atomic(root / 'certs' / name, (server / SERVICE_DIRECTORY / name).read_bytes(), owner=owner, private=private)
    runtime_keys = ('COLLABORATION_INSTANCE_ID','COLLABORATION_SERVICE_INSTANCE_ID','COLLABORATION_CA_SHA256',
                    'COLLABORATION_KEY_ID','COLLABORATION_EDITION','COLLABORATION_PHP_ORIGIN','COLLABORATION_CONFIG_REVISION','COLLABORATION_CONFIG_SHA256')
    runtime = {key: node[key] for key in runtime_keys}
    deployment = {'COMPOSE_PROJECT_NAME': data['compose_project'], 'COLLABORATION_NETWORK': data['network'],
                  'COLLABORATION_PORT': port}
    deployment.update(runtime)
    atomic(root / 'docker/.env', ''.join(key+'='+str(value)+'\n' for key,value in deployment.items()).encode())


def service_owner(server, action, receipt=None, original=None):
    values = backend_environment(server)
    root = Path(values.get('COLLABORATION_SERVICE_ROOT', ''))
    if not root.is_absolute() or root.resolve() != root or root.is_symlink() or not (root / 'docker/compose.yaml').is_file():
        raise ValueError('COLLABORATION_INDEPENDENT_SERVICE_REQUIRED')
    configuration = environment(root / 'docker/.env')
    for path in (root / 'docker', root / 'certs'):
        if path.is_symlink() or path.resolve() != path or not path.is_dir() or path.stat().st_mode & 0o022:
            raise ValueError('COLLABORATION_INDEPENDENT_PRIVATE_DIRECTORY_INVALID')
    expected = {'COMPOSE_PROJECT_NAME': values.get('COLLABORATION_COMPOSE_PROJECT'),
                'COLLABORATION_INSTANCE_ID': values.get('COLLABORATION_INSTANCE_ID'),
                'COLLABORATION_SERVICE_INSTANCE_ID': values.get('COLLABORATION_SERVICE_INSTANCE_ID'),
                'COLLABORATION_CONFIG_REVISION': values.get('COLLABORATION_CONFIG_REVISION'),
                'COLLABORATION_CONFIG_SHA256': values.get('COLLABORATION_CONFIG_SHA256'),
                'COLLABORATION_KEY_ID': values.get('COLLABORATION_SERVICE_KEY_ID'),
                'COLLABORATION_EDITION': values.get('DEPLOYMENT_MODE'),
                'COLLABORATION_CA_SHA256': values.get('COLLABORATION_CA_SHA256')}
    if any(not value or configuration.get(key) != value for key, value in expected.items()):
        raise RuntimeError('COLLABORATION_INDEPENDENT_OWNER_UNCONFIRMED')
    runtime_keys = {'COLLABORATION_PHP_ORIGIN', 'COLLABORATION_NETWORK', 'COLLABORATION_PORT'}
    if set(configuration) != set(expected) | runtime_keys or not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', configuration['COMPOSE_PROJECT_NAME']):
        raise ValueError('COLLABORATION_INDEPENDENT_CONFIGURATION_INVALID')
    if not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}', configuration['COLLABORATION_NETWORK']):
        raise ValueError('COLLABORATION_INDEPENDENT_CONFIGURATION_INVALID')
    port = configuration['COLLABORATION_PORT']
    if not re.fullmatch(r'[0-9]{1,5}', port) or not 1 <= int(port) <= 65535:
        raise ValueError('COLLABORATION_SERVICE_PORT_REQUIRED')
    if not (root / 'dist/src/main.js').is_file() or not (root / 'node_modules/@hocuspocus/server').is_dir():
        raise ValueError('COLLABORATION_INDEPENDENT_PROGRAM_NOT_PREPARED')
    # The APP consumes the same standard Compose file as manual deployments.
    # Pin its observed .env values for this subprocess so ambient variables cannot
    # change the application-bound instance while it is being managed.
    compose_environment = os.environ.copy()
    compose_environment.update(configuration)
    arguments = ['docker', 'compose', '--project-directory', str(root / 'docker'),
                 '--env-file', str(root / 'docker/.env'), '-f', str(root / 'docker/compose.yaml')]
    image = 'node:22.23.2-bookworm-slim@sha256:48e4b67d85f87bd551df43704e24d252f56cc5f8e9718841aace50f19948f0f9'

    def command(parts, timeout=40):
        return run(server, parts, timeout=timeout, cwd=root / 'docker', env=compose_environment)

    def inspect():
        ids = command(arguments + ['ps', '-a', '-q', 'collaboration']).splitlines()
        if len(ids) != 1:
            raise RuntimeError('COLLABORATION_CONTAINER_IDENTITY_UNKNOWN')
        detail = json.loads(command(['docker', 'inspect', '--format', '{{json .}}', ids[0]]))
        state, labels = detail['State'], detail['Config']['Labels']
        discovered = command(['docker', 'ps', '-a', '--no-trunc', '--filter',
                              'label=com.docker.compose.project=' + configuration['COMPOSE_PROJECT_NAME'],
                              '--filter', 'label=com.docker.compose.service=collaboration', '--format', '{{.ID}}']).splitlines()
        image_id = command(['docker', 'image', 'inspect', '--format', '{{.Id}}', image]).strip()
        required = [(str(root), '/srv/peanut-hocuspocus'),
                    (str(root / 'certs'), '/run/peanut-collaboration')]
        mounts = [mount for mount in detail['Mounts'] if mount.get('Type') == 'bind']
        if discovered != ids or detail['Id'] != ids[0] or detail['Image'] != image_id or len(mounts) != 2 or any(not any(m['Source'] == source and m['Destination'] == destination and m['RW'] is False for m in mounts) for source, destination in required):
            raise RuntimeError('COLLABORATION_CONTAINER_BINDING_UNCONFIRMED')
        if labels.get('com.docker.compose.project') != configuration['COMPOSE_PROJECT_NAME'] or labels.get('com.docker.compose.service') != 'collaboration' or labels.get('com.docker.compose.project.working_dir') != str(root / 'docker') or state.get('OOMKilled') is not False or state.get('Paused') is not False or state.get('Restarting') is not False or type(state.get('Running')) is not bool:
            raise RuntimeError('COLLABORATION_CONTAINER_STATE_UNCONFIRMED')
        if state['Status'] != ('running' if state['Running'] else 'exited') or not state['Running'] and state.get('ExitCode') != 0:
            raise RuntimeError('COLLABORATION_CONTAINER_STATE_UNCONFIRMED')
        return {'id': detail['Id'], 'image_id': image_id, 'compose_project': configuration['COMPOSE_PROJECT_NAME'],
                'compose_service': 'collaboration', 'compose_working_dir': str(root / 'docker'),
                'program_mount': {'source': str(root), 'destination': '/srv/peanut-hocuspocus', 'read_only': True},
                'service_mount': {'source': required[1][0], 'destination': required[1][1], 'read_only': True},
                'state': state['Status'], 'running': state['Running']}

    command(arguments + ['config', '--quiet'])
    if action == 'start':
        command(arguments + ['up', '-d', '--wait', '--no-build', '--no-deps', 'collaboration'], timeout=120)
        return inspect()
    before = inspect()
    if action == 'status':
        return before
    if action != 'stop' or original != container_identity(before) or not isinstance(receipt, dict) or set(receipt) != {'protocol', 'drain_id', 'plan_sha256', 'targets_sha256', 'durably_drained', 'room_count'} or receipt.get('protocol') != 2 or receipt.get('durably_drained') is not True or type(receipt.get('room_count')) is not int or receipt['room_count'] < 0:
        raise ValueError('COLLABORATION_ORIGINAL_DURABLE_DRAIN_REQUIRED')
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,128}', receipt.get('drain_id', '')) or any(not isinstance(receipt.get(key), str) or not re.fullmatch(r'[a-f0-9]{64}', receipt[key]) for key in ('plan_sha256', 'targets_sha256')):
        raise ValueError('COLLABORATION_ORIGINAL_DURABLE_DRAIN_REQUIRED')
    command(arguments + ['stop', '--timeout', '-1', 'collaboration'])
    after = inspect()
    if after['running'] or container_identity(after) != original:
        raise RuntimeError('COLLABORATION_ORIGINAL_STOP_UNCONFIRMED')
    return after


def run(server, arguments, timeout=40, cwd=None, env=None):
    try:
        process = subprocess.run(arguments, cwd=cwd or server / 'docker', env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
    except subprocess.TimeoutExpired:
        # The observer cannot confirm native effects; never retry or expose captured output.
        raise RuntimeError('COLLABORATION_NATIVE_COMMAND_UNCONFIRMED') from None
    if process.returncode:
        raise RuntimeError('COLLABORATION_NATIVE_COMMAND_UNCONFIRMED')
    return process.stdout.decode()


def owner_canonical(value):
    def check(item):
        if isinstance(item,float):raise ValueError('COLLABORATION_OWNER_PROOF_INVALID')
        if isinstance(item,dict):
            for key,child in item.items():
                if not isinstance(key,str):raise ValueError('COLLABORATION_OWNER_PROOF_INVALID')
                check(child)
        elif isinstance(item,list):
            for child in item:check(child)
        elif item is not None and not isinstance(item,(str,int,bool)):raise ValueError('COLLABORATION_OWNER_PROOF_INVALID')
    check(value)
    raw=json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode('utf-8')
    if len(raw)>16384:raise ValueError('COLLABORATION_OWNER_PROOF_INVALID')
    return raw


def native(server, action, drain_id, owner_container=None, stop_proof=None):
    arguments=compose_arguments(server)+['exec', '-T', 'php', 'php', 'think', 'collaboration:lifecycle', action, '--drain-id='+drain_id]
    if owner_container is not None:arguments.append('--owner-container='+base64.b64encode(owner_canonical(owner_container)).decode('ascii'))
    if stop_proof is not None:arguments.append('--stop-proof='+base64.b64encode(owner_canonical(stop_proof)).decode('ascii'))
    output = run(server, arguments)
    value = json.loads(output)
    if value.get('protocol') != PROTOCOL or value.get('ok') is not True:
        raise RuntimeError('COLLABORATION_NATIVE_PROTOCOL_UNCONFIRMED')
    return value['data']


def close_configuration_traffic(server):
    """Consume the original deployment owner's close; publication never grants HTTP."""
    ready=server/'runtime/upgrade/.traffic-ready'
    if not ready.exists() and not ready.is_symlink():return
    output=run(server,compose_arguments(server)+['exec','-T','php','php','docker/scripts/update-plan.php','close-startup-traffic','--instance-server=/run/peanut-owner/server'])
    if json.loads(output).get('status')!='closed' or ready.exists() or ready.is_symlink():
        raise RuntimeError('COLLABORATION_HTTP_CLOSURE_UNCONFIRMED')


def refresh_php_owner(server,args,extra=()):
    # The original entrypoint closes traffic, reloads actual FPM workers, validates installed
    # health and republishes VerifiedServerDeployment before it opens ordinary HTTP.
    run(server,args+list(extra)+['up','-d','--wait','--no-build','mysql'],timeout=STARTUP_COMMAND_TIMEOUT)
    run(server,args+list(extra)+['up','-d','--wait','--no-build','--no-deps','--force-recreate','php'],timeout=STARTUP_COMMAND_TIMEOUT)


def start(server, extra=()):
    """Consumer of the original APP start/upgrade entry, not another deploy engine."""
    values=backend_environment(server); args=compose_arguments(server)
    run(server,args+list(extra)+['config','--quiet'])
    if values.get('COLLABORATION_ENABLED')!='enabled':
        if values.get('COLLABORATION_CONFIG_SHA256'):
            refresh_php_owner(server,args,extra)
        run(server,args+list(extra)+['up','-d','--no-build'],timeout=STARTUP_COMMAND_TIMEOUT); return
    refresh_php_owner(server,args,extra)
    prepared=native(server,'prepare','runtime-start')
    recovering=prepared.get('phase') in ('draining','durably_drained')
    if recovering:
        if not re.fullmatch(r'[A-Za-z0-9_-]{1,128}',prepared.get('drain_id','')) or not re.fullmatch(r'[a-f0-9]{64}',prepared.get('plan_sha256','')):
            raise RuntimeError('COLLABORATION_ORIGINAL_DRAIN_REQUIRED')
    elif prepared.get('phase') not in ('prepared','active'):raise RuntimeError('COLLABORATION_ORIGINAL_DRAIN_REQUIRED')
    if recovering:
        # A new process UUID cannot inherit the old unresolved drain. Keep the
        # recorded container/plan and let the owner resume disable with that ID.
        if prepared.get('phase')=='draining':
            original=verify_container(server,args,True)
            if container_identity(original)!=prepared.get('plan',{}).get('owner_container'):
                raise RuntimeError('COLLABORATION_ORIGINAL_OWNER_CONTAINER_REQUIRED')
        return prepared
    service_owner(server, 'start')
    # Regenerate the installed TLS template and resolve the new independent peer.
    run(server,args+list(extra)+['up','-d','--wait','--no-build','--no-deps','--force-recreate','nginx'],timeout=STARTUP_COMMAND_TIMEOUT)
    verify_container(server,args,True)
    # The service now obtains its committed activation through the authenticated
    # lifecycle API. Observe that result; do not replace its activation identity.
    return native(server,'status','runtime-start')


def stop(server):
    values=backend_environment(server)
    if values.get('COLLABORATION_ENABLED')=='enabled':
        disable(server,'stop-'+values['COLLABORATION_CONFIG_SHA256'][:32],True)
    run(server,compose_arguments(server)+['stop'])


def verify_container(server,args,running):
    container = service_owner(server, 'status')
    if running is not None and container.get('running') is not running:
        raise RuntimeError('COLLABORATION_CONTAINER_STATE_UNCONFIRMED')
    return container

def container_identity(container):
    return {key:value for key,value in container.items() if key not in ('state','running')}


def native_stop_proof(server,values,plan,receipt,container):
    current=backend_environment(server)
    if any(current.get(key)!=values.get(key) for key in ('COLLABORATION_CONFIG_REVISION','COLLABORATION_CONFIG_SHA256','COLLABORATION_INSTANCE_ID','COLLABORATION_SERVICE_INSTANCE_ID','COLLABORATION_SERVICE_KEY_ID')):
        raise RuntimeError('COLLABORATION_STOP_CONFIGURATION_STALE')
    proof={'protocol':2,'drain_id':receipt['drain_id'],'plan_sha256':receipt['plan_sha256'],'targets_sha256':receipt['targets_sha256'],
           'instance_id':values['COLLABORATION_INSTANCE_ID'],'edition':values['DEPLOYMENT_MODE'],'service_instance_id':values['COLLABORATION_SERVICE_INSTANCE_ID'],
           'key_id':values['COLLABORATION_SERVICE_KEY_ID'],'config_revision':int(values['COLLABORATION_CONFIG_REVISION']),
           'config_sha256':values['COLLABORATION_CONFIG_SHA256'],'holder_key':plan['holder_key'],'container':container}
    proof_hash=digest(owner_canonical(proof))
    service_directory(server)
    secret_path=server/SERVICE_SECRET;secret_stat=ordinary(secret_path)
    if secret_stat.st_size<32 or secret_stat.st_size>4096:raise RuntimeError('COLLABORATION_SERVICE_KEY_UNSAFE')
    secret=secret_path.read_bytes().strip()
    if len(secret)<32 or len(secret)>4096 or any(byte in secret for byte in (b'\n',b'\r',b'\0')):raise RuntimeError('COLLABORATION_SERVICE_KEY_UNSAFE')
    signature_input='\n'.join(('peanut.collaboration.native.stop-proof.v2',proof['instance_id'],proof['service_instance_id'],proof['key_id'],proof['holder_key'],proof_hash)).encode('utf-8')
    proof['proof_sha256']=proof_hash;proof['signature']=hmac.new(secret,signature_input,hashlib.sha256).hexdigest()
    return proof


def disable(server, drain_id, preserve_desired=False, invoke=None, observation=None):
    if not drain_id or not re.fullmatch(r'[A-Za-z0-9_-]{1,128}', drain_id):
        raise ValueError('COLLABORATION_ORIGINAL_DRAIN_ID_REQUIRED')
    values = backend_environment(server); answer = result('disable', values)
    if values.get('COLLABORATION_ENABLED') != 'enabled':
        return answer
    call=invoke or (lambda action,**inputs: native(server,action,drain_id,**inputs))
    args=compose_arguments(server)
    before=verify_container(server,args,None)
    owner=container_identity(before)
    prepared=call('begin',owner_container=owner)
    plan=prepared.get('plan')
    if not isinstance(plan,dict) or plan.get('owner_container')!=owner or not re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}',plan.get('holder_key','')):
        raise RuntimeError('COLLABORATION_ORIGINAL_OWNER_CONTAINER_REQUIRED')
    if plan.get('drain_id')!=drain_id or plan.get('instance_id')!=values.get('COLLABORATION_INSTANCE_ID') or plan.get('edition')!=values.get('DEPLOYMENT_MODE') or plan.get('config_sha256')!=values.get('COLLABORATION_CONFIG_SHA256') or plan.get('config_revision')!=int(values['COLLABORATION_CONFIG_REVISION']) or not isinstance(prepared.get('targets_sha256'),str) or not re.fullmatch(r'[a-f0-9]{64}',prepared['targets_sha256']):
        raise RuntimeError('COLLABORATION_ORIGINAL_DRAIN_BINDING_UNKNOWN')
    plan_sha=prepared.get('plan_sha256')
    if not isinstance(plan_sha,str) or not re.fullmatch(r'[a-f0-9]{64}',plan_sha):
        raise RuntimeError('COLLABORATION_ORIGINAL_DRAIN_BINDING_UNKNOWN')
    if observation is not None:
        observation['drain']={'drain_id':drain_id,'plan_sha256':plan_sha,'targets_sha256':prepared['targets_sha256']}
    outcome = call('drain')
    if outcome.get('phase') not in ('durably_drained', 'stopped'):
        raise RuntimeError('COLLABORATION_DRAIN_UNCONFIRMED')
    receipt=outcome.get('receipt')
    receipt_keys={'protocol','drain_id','plan_sha256','targets_sha256','durably_drained','room_count'}
    if not isinstance(receipt,dict) or set(receipt)!=receipt_keys or receipt['protocol']!=2 or receipt['drain_id']!=drain_id or receipt['plan_sha256']!=plan_sha or receipt['durably_drained'] is not True or type(receipt['room_count']) is not int or receipt['room_count']<0 or any(not isinstance(receipt[key],str) or not re.fullmatch(r'[a-f0-9]{64}',receipt[key]) for key in ('plan_sha256','targets_sha256')):
        raise RuntimeError('COLLABORATION_DRAIN_UNCONFIRMED')
    # The APP consumes Compose only after confirming the same durable receipt and
    # original independent container; it never implicitly kills the service.
    after=service_owner(server, 'stop', receipt=receipt, original=owner)
    if after.get('running') is not False or container_identity(after)!=owner:
        raise RuntimeError('COLLABORATION_ORIGINAL_OWNER_CONTAINER_REQUIRED')
    proof=native_stop_proof(server,values,plan,receipt,after)
    stopped=call('stopped',stop_proof=proof)
    if stopped.get('phase')!='stopped' or stopped.get('stop_confirmed') is not True or stopped.get('receipt')!=receipt:
        raise RuntimeError('COLLABORATION_STOP_UNCONFIRMED')
    if not preserve_desired:
        with locked(server):
            path=server / '.env'; old=path.read_bytes(); st=ordinary(path); current=environment(path)
            if any(current.get(key)!=values.get(key) for key in ('COLLABORATION_CONFIG_REVISION', 'COLLABORATION_CONFIG_SHA256')):
                raise RuntimeError('COLLABORATION_STOP_CONFIGURATION_STALE')
            payload='\n'.join('COLLABORATION_ENABLED="disabled"' if line.startswith('COLLABORATION_ENABLED=') else line for line in old.decode().splitlines())+'\n'
            close_configuration_traffic(server)
            atomic(path,payload.encode(),digest(old),st)
    answer.update(desired_enabled=preserve_desired, applied_revision=answer['config_revision'] if preserve_desired else None, runtime_state='stopped', restart_required=not preserve_desired,
                  checks=[{'key':'native_room_drain','state':'ok'},{'key':'durable_drain','state':'ok'},{'key':'native_stop','state':'ok'}],drain=outcome.get('receipt'))
    return answer


def main(observation):
    class ProtocolParser(argparse.ArgumentParser):
        def error(self, message):
            raise ValueError('COLLABORATION_COMMAND_INPUT_INVALID')
    parser=ProtocolParser(description=__doc__)
    parser.add_argument('action', choices=('plan','enable','status','disable'));parser.add_argument('--app-root',type=Path,required=True)
    parser.add_argument('--configuration',type=Path);parser.add_argument('--expected-revision',type=int);parser.add_argument('--drain-id');parser.add_argument('--probe',action='store_true');parser.add_argument('--preserve-desired',action='store_true')
    args=parser.parse_args();server=canonical_root(args.app_root)
    observation['action']=args.action;observation['server']=server
    values=backend_environment(server)
    observation['values']=observed_configuration(values)
    if args.action=='enable':
        if args.configuration is None or args.expected_revision is None: raise ValueError('COLLABORATION_CONFIGURATION_REQUIRED')
        ordinary(args.configuration,False); answer=enable(server,json.loads(args.configuration.read_text()),args.expected_revision)
    elif args.action=='disable': answer=disable(server,args.drain_id,args.preserve_desired,observation=observation)
    else:
        answer=result(args.action,observation['values'])
        if args.action=='plan':
            for key,relative in (('service_secret_reference',SERVICE_SECRET),('ticket_secret_reference','private/collaboration/ticket-secret'),('tls_certificate_reference','docker/tls/certificate.pem'),('tls_key_reference','docker/tls/private-key.pem'),('tls_ca_reference','docker/tls/ca.pem')):
                try:
                    if key=='service_secret_reference': service_directory(server,False)
                    ordinary(server/relative,key not in ('tls_certificate_reference','tls_ca_reference'))
                except (OSError,ValueError): answer['gaps'].append(key)
            if args.configuration is not None:
                ordinary(args.configuration,False);validate_configuration(json.loads(args.configuration.read_text()),server)
            elif values.get('COLLABORATION_ENABLED')!='enabled': answer['gaps'].append('non_secret_configuration_required')
        if args.action=='status' and args.probe and values.get('COLLABORATION_ENABLED')=='enabled':
            probe=native(server,'status','runtime-status')
            paired=probe.get('native_protocol')==2 and probe.get('php_ready') is True and probe.get('node_ready') is True and probe.get('paired_ready') is True
            states={'active':'ready' if paired and probe.get('ready') is True and probe.get('admission_enabled') is True else 'unknown','prepared':'prepared','draining':'draining','durably_drained':'draining','stopped':'stopped'}
            state=states.get(probe.get('phase'),'unknown')
            answer.update(applied_revision=answer['config_revision'],runtime_state=state,restart_required=state!='ready',checks=[{'key':'signed_runtime','state':'ok' if paired else 'failed'},{'key':'paired_native_runtime','state':'ok' if paired else 'failed'}])
    print(json.dumps(answer,sort_keys=True))


def execute():
    observation={'action':sys.argv[1] if len(sys.argv)>1 and sys.argv[1] in ('plan','enable','status','disable') else 'plan','values':None,'drain':None}
    try: main(observation)
    except (OSError,ValueError,TypeError,KeyError,RuntimeError,subprocess.SubprocessError) as error:
        # Input/configuration diagnostics never include private values, paths, URLs or subprocess output.
        if 'server' in observation:
            try:observation['values']=observed_configuration(environment(observation['server']/'.env'))
            except (OSError,ValueError,TypeError,KeyError):observation['values']=None
        answer=result(observation['action'],observation['values'],'unknown')
        answer.update(error_code='COLLABORATION_LIFECYCLE_UNCONFIRMED',drain=observation['drain'])
        if observation['values'] is not None:answer['gaps']=['configuration_or_runtime_unconfirmed']
        print(json.dumps(answer,sort_keys=True));return 2 if isinstance(error,(ValueError,TypeError,KeyError)) else 3 if isinstance(error,FileNotFoundError) else 4
    return 0


if __name__=='__main__':sys.exit(execute())
