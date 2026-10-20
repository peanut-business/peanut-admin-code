#!/usr/bin/env python3
"""Bound MySQL recovery point for the existing server update journal."""

import argparse
import gzip
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys
import tarfile
import time

SNAPSHOT_NAMES = (".env", "docker/.env", "private/installation/installed.json",
                  "private/installation/baseline.json", "private/installation/deployment.json",
                  "private/resources/project-resources.json", "docker/collaboration.env")
SNAPSHOT_SET = set(SNAPSHOT_NAMES)
STORAGE_NAMES = ('public/storage', 'public/uploads', 'private/storage', 'private/installation', 'private/collaboration', 'docker/tls')
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
IMAGE_ID = re.compile(r"sha256:[a-f0-9]{64}\Z")


def sha(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def regular(path, mode=None):
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
        raise ValueError(f"missing or unsafe recovery input: {path}")
    if mode is not None and (path.stat().st_mode & 0o777) != mode:
        raise ValueError(f"recovery input has unsafe mode: {path}")


def safe_parents(root, name):
    if name not in SNAPSHOT_SET | set(STORAGE_NAMES) or name.startswith("/") or ".." in Path(name).parts:
        raise ValueError("snapshot path is not in the exact recovery allowlist")
    cursor = root
    for part in name.split("/")[:-1]:
        cursor /= part
        if cursor.is_symlink() or not cursor.is_dir():
            raise ValueError("snapshot parent is linked or unavailable")


def read_json(path):
    regular(path, 0o600)
    return json.loads(path.read_text())


def verification_key(server):
    legacy = server / "private/installation/update-verification.key"
    if legacy.exists() or legacy.is_symlink():
        raise ValueError("OWNER_MAINTENANCE_REQUIRED: previous fixed-tool transaction and legacy verification key require their original owner")
    parent = server / "private"
    if parent.is_symlink() or not parent.is_dir() or parent.stat().st_mode & 0o022 or parent.stat().st_uid not in (0, server.stat().st_uid):
        raise ValueError("private maintenance parent requires its deployment owner")
    directory = parent / "maintenance"
    if directory.is_symlink() or not directory.is_dir() or directory.stat().st_mode & 0o777 != 0o700:
        raise ValueError("private maintenance directory is unsafe")
    if directory.stat().st_uid not in (0, server.stat().st_uid):
        raise ValueError("private maintenance directory requires its deployment owner")
    path = directory / "update-verification.key"
    regular(path, 0o600)
    key = path.read_text().strip()
    if not HEX64.fullmatch(key):
        raise ValueError("private update verification key is invalid")
    return key.encode()


def signature_for(server, path):
    regular(path, 0o600)
    return hmac.new(verification_key(server), path.read_bytes(), hashlib.sha256).hexdigest()


def check_signature(server, path):
    signature = path.with_name(path.name + ".hmac")
    regular(signature, 0o600)
    if not hmac.compare_digest(signature.read_text().strip(), signature_for(server, path)):
        raise ValueError("recovery record signature differs from instance")


def check_backup_signature(server, workspace):
    check_signature(server, workspace / "backup.json")


def durable(path, data):
    if path.exists() or path.is_symlink():
        raise ValueError(f"recovery record already exists: {path}")
    with path.open("xb") as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    fd = os.open(path.parent, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def env(path):
    regular(path, 0o600)
    result = {}
    for line in path.read_text().splitlines():
        if not line or line.startswith("#"):
            continue
        key, separator, value = line.partition("=")
        if not separator or key in result:
            raise ValueError("instance environment contains a missing or duplicated key")
        result[key] = value
    return result


def docker(*args, cwd=None, input=None, input_file=None, output=None, timeout=60):
    try:
        process = subprocess.run(["docker", *args], cwd=cwd, input=input, stdin=input_file,
                                 stdout=output or subprocess.PIPE,
                                 stderr=subprocess.PIPE, check=False, timeout=timeout)
    except subprocess.TimeoutExpired as error:
        raise RuntimeError(f"docker command timed out after {timeout}s") from error
    if process.returncode:
        raise RuntimeError(f"docker command exited {process.returncode}; stderr_sha256={hashlib.sha256(process.stderr).hexdigest()}; stderr_bytes={len(process.stderr)}")
    return process.stdout.decode().strip() if output is None else ""


def compose(docker_dir, *args, output=None, input=None, input_file=None, timeout=60):
    owner=collaboration_owner()
    return docker(*owner.compose_arguments(docker_dir.parent)[1:], *args,
                  cwd=docker_dir, output=output, input=input, input_file=input_file, timeout=timeout)


def collaboration_owner():
    spec=importlib.util.spec_from_file_location('collaboration_owner',Path(__file__).parent/'configure-collaboration.py')
    owner=importlib.util.module_from_spec(spec);spec.loader.exec_module(owner)
    return owner


def instance(args):
    server, workspace = args.server, args.workspace
    if not server.is_absolute() or not workspace.is_absolute() or server.resolve() != server or workspace.resolve() != workspace:
        raise ValueError("server and workspace must be canonical ordinary directories")
    plan_path = workspace / "plan.json"
    plan = read_json(plan_path)
    product, journal = product_binding(workspace)
    plan_sha = sha(plan_path)
    if plan.get("protocol") not in ('peanut.server-release-inputs.v1',) or plan.get('update_id') != product.get('candidate') \
        or (product.get('inputs_sha256') is not None and product['inputs_sha256'] != plan_sha):
        raise ValueError("recovery point differs from active update plan")
    marker = read_json(server / "runtime/upgrade/maintenance.json")
    if marker.get("update_id") != plan["update_id"] or marker.get("status") != "active":
        raise ValueError("matching maintenance marker is required")
    backend = env(server / ".env")
    database = backend.get("DB_NAME", "")
    if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", database):
        raise ValueError("DB_NAME is invalid")
    identity = {"resource_id": backend.get("PEANUT_DATABASE_RESOURCE_ID"),
                "endpoint_id": backend.get("PEANUT_DATABASE_ENDPOINT_ID"), "database": database}
    if not all(identity.values()):
        raise ValueError("database resource identity is incomplete")
    registry_path = server / "private/resources/project-resources.json"
    if not registry_path.exists() and not registry_path.is_symlink():
        registry_path = server / "resources/project-resources.json"
    registry = json.loads(registry_path.read_text()) if registry_path.is_file() and not registry_path.is_symlink() else None
    rows = registry.get("resources", {}).get("databases") if isinstance(registry, dict) else None
    selected = [row for row in rows if isinstance(row, dict) and row.get("stable_resource_id") == identity["resource_id"]] if isinstance(rows, list) else []
    endpoint = selected[0].get("container_endpoint", {}) if len(selected) == 1 else {}
    if len(selected) != 1 or selected[0].get("database") != database or selected[0].get("service_type") != "mysql" or selected[0].get("fallback") != "none" \
        or endpoint.get("endpoint_id") != identity["endpoint_id"] or str(endpoint.get("host")) != backend.get("DB_HOST") or str(endpoint.get("port")) != backend.get("DB_PORT"):
        raise ValueError("database identity differs from the instance registry")
    installed = server / "private/installation/installed.json"
    baseline = server / "private/installation/baseline.json"
    regular(installed)
    regular(baseline)
    if sha(installed) != plan.get("source", {}).get("installed_receipt_sha256") or sha(baseline) != plan.get("source", {}).get("baseline_sha256"):
        raise ValueError("installed instance identity differs from update source")
    release = server / ".peanut/release-identity.json"
    regular(release)
    if sha(release) not in {plan.get("source", {}).get("identity_sha256"), plan.get("target", {}).get("identity_sha256")}:
        raise ValueError("current release identity differs from update")
    return server, workspace, plan, journal, plan_sha, identity


def product_binding(workspace):
    binding = read_json(workspace / 'product-binding.json')
    product = read_json(Path(binding['plan_path']))
    digest = product.pop('plan_sha256', None)
    canonical = lambda value: json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()
    if product.get('protocol') != 'peanut.product-upgrade-plan.v2' or product.get('workspace') != str(workspace) \
        or digest != binding.get('plan_sha256') or digest != 'sha256:' + hashlib.sha256(canonical(product)).hexdigest():
        raise ValueError('phase workspace is not bound to the product plan')
    state = read_json(Path(product['state_path']))
    state_digest = state.pop('state_sha256', None)
    if state.get('protocol') != 'peanut.product-upgrade-state.v1' or state.get('candidate') != product['candidate'] \
        or state.get('plan_sha256') != digest or state_digest != 'sha256:' + hashlib.sha256(canonical(state)).hexdigest():
        raise ValueError('product state digest differs from phase workspace')
    product['plan_sha256'] = digest
    return product, state


def storage_inventory(root):
    if root.is_symlink() or not root.is_dir():
        raise ValueError('protected storage root is unsafe')
    rows = {}
    for base, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            path = Path(base) / name
            if path.is_symlink() or (not path.is_dir() and not path.is_file()) or '\\' in name or any(ord(c) < 32 for c in name):
                raise ValueError('protected storage contains links or special paths')
            if path.is_file():
                regular(path)
            rows[path.relative_to(root).as_posix()] = [sha(path) if path.is_file() else None, path.stat().st_mode & 0o777]
    return rows


def storage_archive(path):
    regular(path, 0o600)
    with tarfile.open(path, 'r:gz') as archive:
        seen = set()
        for member in archive.getmembers():
            name = member.name.rstrip('/')
            if name in seen or not name or name.startswith('/') or '\\' in name or any(ord(c) < 32 for c in name) \
                or any(part in ('', '.', '..') for part in name.split('/')) or not (member.isdir() or member.isfile()):
                raise ValueError('protected storage archive contains unsafe paths')
            seen.add(name)
    return path


def source_database_binding(server, ordinary=False):
    """Source maintenance uses only this APP's declared container endpoint."""
    values = env(server / '.env')
    def value(key):
        selected = values.get(key, '')
        if selected.startswith('"'):
            selected = json.loads(selected)
        if not isinstance(selected, str) or any(c in selected for c in '\x00\r\n'):
            raise ValueError('invalid source database declaration')
        return selected
    source = value('PEANUT_DATABASE_SOURCE')
    if source in ('', 'bundled'):
        return {'source': 'bundled'}
    if source != 'registered':
        raise ValueError('unknown source database kind')
    registry_path = server / 'private/resources/project-resources.json'
    regular(registry_path, 0o600)
    registry = read_json(registry_path)
    manifest = read_json(server / '.peanut/release-identity.json' if ordinary
                         else server.parent / '.peanut/application-manifest.json')
    rows = [row for row in registry.get('resources', {}).get('databases', [])
            if row.get('stable_resource_id') == value('PEANUT_DATABASE_RESOURCE_ID')]
    if registry.get('authority', {}).get('role') != 'application' \
            or registry.get('project_id') != manifest.get('application', {}).get('slug') or len(rows) != 1:
        raise ValueError('source database is not owned by this APP')
    resource = rows[0]
    endpoints = [resource.get(key) for key in ('upstream_endpoint', 'container_endpoint')]
    endpoints = [row for row in endpoints if isinstance(row, dict) and value('PEANUT_DATABASE_CONSUMER') in row.get('consumers', [])]
    if len(endpoints) != 1 or value('PEANUT_DATABASE_CONSUMER') != 'container':
        raise ValueError('source admission requires its declared container endpoint')
    endpoint = endpoints[0]
    if resource.get('service_type') != 'mysql' or resource.get('fallback') != 'none' or resource.get('application_runtime') is not True \
            or resource.get('database') != value('DB_NAME') or resource.get('namespace') != value('DB_NAME') \
            or endpoint.get('endpoint_id') != value('PEANUT_DATABASE_ENDPOINT_ID') \
            or endpoint.get('host') != value('DB_HOST') or endpoint.get('port') != int(value('DB_PORT')):
        raise ValueError('source registered database binding changed')
    return {'source': source, 'resource_id': resource['stable_resource_id'], 'endpoint': endpoint,
            'database': resource['database'], 'registry_sha256': sha(registry_path), 'environment_sha256': sha(server / '.env')}


def source_network(server, project, container_ids):
    config = json.loads(compose(server / 'docker', 'config', '--format', 'json'))
    if config.get('name') != project:
        raise ValueError('source network project changed')
    network_id = None; logical = None
    for service in ('php', 'nginx'):
        projection = '{"Labels":{{json .Config.Labels}},"Networks":{{json .NetworkSettings.Networks}}}'
        observed = json.loads(docker('inspect', '--format', projection, container_ids[service]))
        networks = observed['Networks']
        declared = config['services'][service].get('networks', {})
        if len(networks) != 1 or len(declared) != 1:
            raise ValueError('source admission has no unique declared APP network')
        name, details = next(iter(networks.items())); selected = next(iter(declared))
        configured = config.get('networks', {}).get(selected, {})
        if configured.get('external') is True or configured.get('name') != name or not details.get('NetworkID'):
            raise ValueError('source network is not the declared APP-owned network')
        if network_id is not None and (network_id != details['NetworkID'] or logical != selected):
            raise ValueError('source PHP and nginx network routes differ')
        network_id, logical = details['NetworkID'], selected
    rows = json.loads(docker('network', 'inspect', network_id))
    if len(rows) != 1 or rows[0].get('Id') != network_id or rows[0].get('Driver') != 'bridge' \
            or rows[0].get('Labels', {}).get('com.docker.compose.project') != project \
            or rows[0].get('Labels', {}).get('com.docker.compose.network') != logical:
        raise ValueError('source network identity or ownership changed')
    return {'id': network_id, 'name': rows[0]['Name'], 'logical': logical, 'driver': 'bridge'}


def assert_stopped(server, allow_registered=False, ordinary=False):
    # Check the APP writer boundary; independent Node custody is not transferred.
    docker_dir = server / "docker"
    project = env(docker_dir / ".env").get("COMPOSE_PROJECT_NAME", "")
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,62}", project):
        raise ValueError("Compose project identity is invalid")
    binding = source_database_binding(server, ordinary=ordinary) if allow_registered else {'source': 'bundled'}
    registered = binding['source'] == 'registered'
    services = compose(docker_dir, "ps", "--status", "running", "--services").splitlines()
    if services != ([] if registered else ["mysql"]):
        raise ValueError("business writers are not drained")
    images = {}; containers = {}
    for service in (("php", "nginx") if registered else ("php", "nginx", "mysql")):
        ids = compose(docker_dir, "ps", "-a", "-q", service).splitlines()
        discovered = docker("ps", "-a", "--no-trunc", "--filter", f"label=com.docker.compose.project={project}",
                            "--filter", f"label=com.docker.compose.service={service}", "--format", "{{.ID}}").splitlines()
        if len(ids) != 1 or discovered != ids:
            raise ValueError("Compose service does not have exactly one bound container")
        projection = '{"Id":{{json .Id}},"Image":{{json .Image}},"State":{{json .State}},"RestartCount":{{json .RestartCount}},"Config":{"Labels":{{json .Config.Labels}}},"Mounts":{{json .Mounts}}}'
        details = json.loads(docker("inspect", "--format", projection, ids[0]))
        labels = details.get("Config", {}).get("Labels", {})
        mounts = details.get("Mounts", [])
        status = details.get("State", {}).get("Status")
        if details.get("Id") != ids[0] or labels.get("com.docker.compose.project") != project \
            or labels.get("com.docker.compose.service") != service \
            or labels.get("com.docker.compose.project.working_dir") != str(docker_dir) \
            or status != ("running" if service == "mysql" else "exited"):
            raise ValueError("Compose container identity, working directory or state changed")
        expected = {"php": (server, "/run/peanut-owner/server"),
                    "nginx": (server / "public", "/var/www/peanut-admin/server/public"),
                    "mysql": (docker_dir / "mysql", "/var/lib/mysql")}[service]
        if sum(m.get("Type") == "bind" and m.get("Source") == str(expected[0]) and m.get("Destination") == expected[1] for m in mounts) != 1:
            raise ValueError("Compose container mount belongs to another instance")
        if service == "php":
            if sum(m.get("Type") == "bind" and m.get("Source") == str(server)
                   and m.get("Destination") == "/var/www/peanut-http/server" and m.get("RW") is False for m in mounts) != 1:
                raise ValueError("FPM readonly program mount belongs to another instance")
        image = details.get("Image", "")
        if not IMAGE_ID.fullmatch(image):
            raise ValueError("Compose image ID is invalid")
        images[service] = image
        containers[service] = {'id': details['Id'], 'started_at': details['State'].get('StartedAt'),
                               'finished_at': details['State'].get('FinishedAt'), 'restart_count': details.get('RestartCount')}
    running = docker("ps", "--no-trunc", "--filter", f"label=com.docker.compose.project={project}", "--format", "{{.ID}}").splitlines()
    if registered:
        mysql = docker('ps', '-a', '--no-trunc', '--filter', f'label=com.docker.compose.project={project}',
                       '--filter', 'label=com.docker.compose.service=mysql', '--format', '{{.ID}}').splitlines()
        if running or mysql:
            raise ValueError('registered source instance has a running writer or a bundled database container')
        network = source_network(server, project, {name: row['id'] for name, row in containers.items()})
        return {'project': project, 'images': images, 'containers': containers,
                'database_binding': binding, 'admission_network': network}
    mysql_id = compose(docker_dir, "ps", "-a", "-q", "mysql")
    if running != [mysql_id]:
        raise ValueError("another Compose writer is running")
    return {"project": project, "images": images, 'containers': containers}


def snapshot_paths(server):
    return [(name, server / name) for name in SNAPSHOT_NAMES]


def ordinary_database_provider(server, workspace):
    """Ordinary plan authority only; never accept a partial-installation proof."""
    product, _ = product_binding(workspace)
    binding = source_database_binding(server, ordinary=True)
    reference = product.get('backup_tool')
    if binding['source'] == 'bundled':
        if reference is not None: raise ValueError('bundled recovery forbids an external backup tool')
        return {'source': 'bundled'}
    if not isinstance(reference, dict) or set(reference) != {'path', 'sha256', 'owner_uid', 'mode'}:
        raise ValueError('registered recovery requires its frozen ordinary-plan backup tool')
    path = Path(reference['path'])
    regular(path, 0o600)
    if path.resolve() != path or path.stat().st_uid not in (0, server.stat().st_uid) \
            or path.stat().st_uid != reference['owner_uid'] or reference['mode'] != 0o600 \
            or path.stat().st_size > 65536 or sha(path) != reference['sha256']:
        raise ValueError('frozen registered backup tool changed')
    tool = read_json(path); contract = tool.get('source_schema_backup', {})
    if tool.get('transport') != 'ssh-docker-exec' or contract.get('whole_schema') is not True \
            or contract.get('database_resource_id') != binding['resource_id'] or contract.get('database') != binding['database'] \
            or not re.fullmatch(r'[A-Za-z0-9_]{1,64}', binding['database']) \
            or not re.fullmatch(r'8\.4\.[0-9]+', str(contract.get('mysql_version', ''))) \
            or not re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', str(contract.get('server_uuid', ''))) \
            or type(contract.get('timeout_seconds')) is not int or not 30 <= contract['timeout_seconds'] <= 600:
        raise ValueError('registered whole-schema provider identity or lifetime is invalid')
    for key in ('ssh_command', 'docker_command', 'mysql_command', 'mysqldump_command', 'timeout_command', 'digest_command'):
        if not re.fullmatch(r'/[A-Za-z0-9_./-]+', str(tool.get(key, ''))) or '..' in tool[key]:
            raise ValueError('registered provider command is not a fixed absolute executable')
    for key in ('ssh_alias', 'container_name'):
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*', str(tool.get(key, ''))):
            raise ValueError('registered provider transport identity is invalid')
    return {'source': 'registered', 'database_binding': binding, 'backup_tool': reference}


def root_client(server, workspace, provider, operation, *, sql=None, input_file=None, output=None):
    if operation not in ('probe', 'metadata', 'capture', 'restore') \
            or ordinary_database_provider(server, workspace) != provider:
        raise ValueError('ordinary privileged client authority changed')
    tool = read_json(Path(provider['backup_tool']['path']))
    contract = tool['source_schema_backup']; seconds = contract['timeout_seconds']
    database = provider['database_binding']['database']
    prefix = Path(workspace) / 'root-client'
    if not prefix.exists(): prefix.mkdir(mode=0o700)
    if prefix.resolve() != prefix or prefix.stat().st_mode & 0o777 != 0o700 \
            or prefix.stat().st_uid not in (0, os.geteuid()):
        raise ValueError('privileged client evidence directory is unsafe')
    stem = prefix / (operation + '-' + os.urandom(16).hex())
    stdout = output if output is not None else stem.with_suffix('.stdout')
    stderr = stem.with_suffix('.stderr'); process_path = stem.with_suffix('.process.json')
    client = 'mysqldump_command' if operation == 'capture' else 'mysql_command'
    common = ['--no-defaults', '--no-login-paths', '--default-character-set=utf8mb4', '-uroot']
    if operation == 'capture':
        arguments = [*common, '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob',
                     '--set-gtid-purged=OFF', '--no-tablespaces',
                     '--databases', '--add-drop-database', '--', database]
    elif operation == 'restore': arguments = common
    else: arguments = [*common, '--batch', '--skip-column-names', '--database=' + database, '--execute=' + sql]
    command = shlex.join([tool['timeout_command'], '-k', '5s', str(seconds) + 's', tool[client], *arguments])
    shell = 'MYSQL_PWD=$' + '{MYSQL_ROOT_PASSWORD:?}; export MYSQL_PWD; exec ' + command
    if operation in ('probe', 'metadata'):
        shell = shlex.join([tool['digest_command'], tool['mysql_command'], tool['mysqldump_command']]) + ' && ' \
            + shlex.join([tool['mysql_command'], '--version']) + ' && ' \
            + shlex.join([tool['mysqldump_command'], '--version']) + ' && (' + shell + ')'
    remote = shlex.join([tool['docker_command'], 'exec', '-i', tool['container_name'],
                        tool['timeout_command'], '-k', '5s', str(seconds + 5) + 's', '/bin/sh', '-c', shell])
    argv = [tool['ssh_command'], '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=3', tool['ssh_alias'], remote]
    evidence = {'operation': operation, 'status': 'started', 'provider': provider, 'started_at': int(time.time()),
                'argv_sha256': hashlib.sha256(json.dumps(argv).encode()).hexdigest(),
                'product_plan_sha256': product_binding(workspace)[0]['plan_sha256']}
    durable(process_path, json.dumps(evidence, sort_keys=True).encode())
    environment = {key: value for key, value in os.environ.items() if key in ('HOME', 'SSH_AUTH_SOCK')}
    environment.update(PATH='/usr/bin:/bin:/usr/sbin:/sbin', LANG='C', LC_ALL='C')
    with stdout.open('xb') as out, stderr.open('xb') as err:
        os.fchmod(out.fileno(), 0o600); os.fchmod(err.fileno(), 0o600)
        try:
            result = subprocess.run(argv, stdin=input_file or subprocess.DEVNULL, stdout=out, stderr=err,
                                    timeout=seconds + 20, env=environment)
            evidence.update(status='terminal', rc=result.returncode, remote_success_terminal_confirmed=result.returncode == 0)
        except (OSError, subprocess.TimeoutExpired):
            evidence.update(status='unknown', rc=None, remote_success_terminal_confirmed=False)
        out.flush(); os.fsync(out.fileno()); err.flush(); os.fsync(err.fileno())
    evidence.update(ended_at=int(time.time()), stdout={'path': str(stdout), 'sha256': sha(stdout)},
                    stderr={'path': str(stderr), 'sha256': sha(stderr)})
    durable(process_path, json.dumps(evidence, sort_keys=True).encode())
    if not evidence['remote_success_terminal_confirmed']:
        raise ValueError('privileged client failed or has an unknown remote outcome; retain evidence and do not replay')
    if ordinary_database_provider(server, workspace) != provider:
        raise ValueError('privileged provider changed during command')
    return {'path': str(process_path), 'sha256': sha(process_path)}


def root_observation(server, workspace, provider, metadata=False):
    sql = "SET TRANSACTION READ ONLY; START TRANSACTION READ ONLY; SELECT HEX(JSON_OBJECT(" \
          "'server_uuid',@@server_uuid,'database',DATABASE(),'version',VERSION(),'current_user',CURRENT_USER(),'partial_revokes',@@GLOBAL.partial_revokes," \
          "'charset',@@character_set_database,'collation',@@collation_database," \
          "'table_count',(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()))); SHOW GRANTS;"
    nonce = os.urandom(16).hex()
    sql += " SELECT 'GRANTS_END_" + nonce + "';"
    if metadata:
        # Cross-schema references are metadata edges only; never read another schema's rows or definitions.
        queries = {
            'tables': "SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()",
            'tablespaces': "SELECT NAME,SPACE_TYPE FROM information_schema.INNODB_TABLES WHERE SUBSTRING_INDEX(NAME,'/',1)=DATABASE()",
            'views': "SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,DEFINER,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
            'routines': "SELECT ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_DEFINITION,SQL_DATA_ACCESS,SECURITY_TYPE,SQL_MODE,DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
            'events': "SELECT EVENT_NAME,DEFINER,EVENT_DEFINITION,STATUS,ON_COMPLETION,SQL_MODE FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()",
            'triggers': "SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT,SQL_MODE,DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
            'foreign_keys': "SELECT TABLE_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_SCHEMA=DATABASE() OR REFERENCED_TABLE_SCHEMA=DATABASE())",
        }
        for name, query in queries.items():
            # HEX per actual row preserves definitions without mysql batch escaping or delimiter guesses.
            columns = query.split(' FROM ', 1)[0].removeprefix('SELECT ').split(',')
            obj = ','.join("'" + col + "'," + col for col in columns)
            sql += " SELECT '" + name + "'; SELECT HEX(JSON_OBJECT(" + obj + ")) FROM (" + query + ") AS observed;"
        sql += " SELECT 'METADATA_END_" + nonce + "';"
    sql += ' ROLLBACK;'
    reference = root_client(server, workspace, provider, 'metadata' if metadata else 'probe', sql=sql)
    process = read_json(Path(reference['path'])); output = Path(process['stdout']['path'])
    if output.stat().st_size > 16 * 1024 * 1024: raise ValueError('whole-schema metadata exceeds its bounded evidence contract')
    lines = output.read_text().splitlines(); contract = read_json(Path(provider['backup_tool']['path']))['source_schema_backup']
    if len(lines) < 7 or any(not re.match(r'[a-f0-9]{64}\s+', line) for line in lines[:2]) \
            or any('Ver ' + contract['mysql_version'] + ' ' not in line for line in lines[2:4]):
        raise ValueError('Root client digest or exact MySQL version is unavailable')
    identity = json.loads(bytes.fromhex(lines[4])); end = lines.index('GRANTS_END_' + nonce)
    if identity.get('server_uuid') != contract['server_uuid'] or identity.get('database') != contract['database'] \
            or identity.get('version') != contract['mysql_version'] or identity.get('partial_revokes') not in (0, 1, False, True) \
            or not str(identity.get('current_user', '')).startswith('root@'):
        raise ValueError('registered Root observation differs from the exact target UUID/schema/version')
    grants = lines[5:end]
    if any(line.startswith('REVOKE ') for line in grants) \
            or not any(re.match(r'GRANT ALL PRIVILEGES ON \*\.\* TO ', line) for line in grants):
        raise ValueError('complete direct Root metadata and restoration visibility is NOT_READY')
    observed = {}
    if metadata:
        cursor = end + 1
        for name in queries:
            if cursor >= len(lines) or lines[cursor] != name: raise ValueError('whole-schema metadata section is missing')
            cursor += 1; rows = []
            while cursor < len(lines) and lines[cursor] not in (*queries, 'METADATA_END_' + nonce):
                row = json.loads(bytes.fromhex(lines[cursor]))
                if not isinstance(row, dict): raise ValueError('invalid actual metadata row')
                rows.append(row); cursor += 1
            observed[name] = sorted(rows, key=lambda row: json.dumps(row, sort_keys=True))
        if lines[cursor:] != ['METADATA_END_' + nonce] \
                or len(observed['tables']) != identity['table_count']:
            raise ValueError('whole-schema metadata coverage is incomplete')
    elif len(lines) != end + 1: raise ValueError('unexpected privileged identity output')
    return {'identity': identity, 'metadata': observed, 'process': reference,
            'mysql_sha256': lines[0].split()[0], 'mysqldump_sha256': lines[1].split()[0]}


def assert_whole_schema_effects(observation):
    metadata = observation['metadata']; database = observation['identity']['database']
    unknown = {name: [row.get({'views': 'TABLE_NAME', 'routines': 'ROUTINE_NAME',
                              'events': 'EVENT_NAME', 'triggers': 'TRIGGER_NAME'}[name]) for row in metadata[name]]
               for name in ('views', 'routines', 'events', 'triggers') if metadata[name]}
    foreign = [row for row in metadata['foreign_keys']
               if row['TABLE_SCHEMA'] != database or row['REFERENCED_TABLE_SCHEMA'] != database]
    if foreign: unknown['cross_schema_foreign_keys'] = foreign
    unsupported = [row['TABLE_NAME'] for row in metadata['tables']
                   if row['TABLE_TYPE'] != 'BASE TABLE' or row['ENGINE'] != 'InnoDB']
    if unsupported: unknown['non_transactional_objects'] = unsupported
    shared = [row['NAME'] for row in metadata['tablespaces'] if row['SPACE_TYPE'] != 'Single']
    if shared: unknown['shared_tablespace_objects'] = shared
    if unknown:
        # Definitions remain in the protected real observation. A complete dump does not review effects.
        raise ValueError('ordinary recovery effects NOT_READY; review protected metadata objects: '
                         + json.dumps(unknown, sort_keys=True))


def db_probe(server, database, workspace=None):
    if workspace is not None:
        provider = ordinary_database_provider(server, workspace)
        if provider['source'] == 'registered':
            identity = root_observation(server, workspace, provider)['identity']
            return {key: identity[key] for key in ('server_uuid', 'database', 'charset', 'collation', 'table_count')}
    output = compose(server / "docker", "exec", "-T", "mysql", "sh", "-c",
                     'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -N -B -uroot --database="$1" -e "SELECT @@server_uuid,DATABASE(),@@character_set_database,@@collation_database,(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE())"',
                     "sh", database)
    parts = output.split("\t")
    if len(parts) != 5 or not re.fullmatch(r"[a-fA-F0-9-]{36}", parts[0]) or parts[1] != database \
        or not re.fullmatch(r"[A-Za-z0-9_]{1,64}", parts[2]) or not re.fullmatch(r"[A-Za-z0-9_]{1,64}", parts[3]) \
        or not parts[4].isdigit():
        raise ValueError("MySQL server or schema identity probe is invalid")
    return {"server_uuid": parts[0].lower(), "database": parts[1], "charset": parts[2],
            "collation": parts[3], "table_count": int(parts[4])}


def backup(args):
    server, workspace, plan, journal, plan_sha, identity = instance(args)
    if journal.get('phase_in_progress') != 'backup' or journal.get('database_recovery_required') is not False \
        or journal.get('activation_started') is not False:
        raise ValueError("recovery point must precede program changes")
    verification_key(server)
    provider = ordinary_database_provider(server, workspace)
    registered = provider['source'] == 'registered'
    runtime = assert_stopped(server, allow_registered=True, ordinary=True)
    recovery = workspace / "recovery"
    if recovery.exists() or recovery.is_symlink() or (workspace / "backup.json").exists():
        raise ValueError("recovery point already exists")
    recovery.mkdir(mode=0o700)
    observation = root_observation(server, workspace, provider, metadata=True) if registered else None
    probe = ({key: observation['identity'][key] for key in ('server_uuid', 'database', 'charset', 'collation', 'table_count')}
             if registered else db_probe(server, identity["database"]))
    if registered:
        durable(recovery / 'database-metadata.json', json.dumps(observation, sort_keys=True).encode())
    storage = {}
    for name in STORAGE_NAMES:
        source = server / name
        safe_parents(server, name)
        if not source.exists() and not source.is_symlink():
            storage[name] = None
            continue
        rows = storage_inventory(source)
        path = recovery / (name.replace('/', '-') + '.tar.gz')
        with path.open('xb') as output:
            os.fchmod(output.fileno(), 0o600)
            with tarfile.open(fileobj=output, mode='w:gz', dereference=False) as archive:
                for relative in sorted(rows):
                    archive.add(source / relative, arcname=relative, recursive=False)
            output.flush(); os.fsync(output.fileno())
        storage_archive(path)
        if storage_inventory(source) != rows:
            raise ValueError('protected storage changed while writers were drained')
        storage[name] = {'filename': path.name, 'sha256': sha(path), 'inventory': rows,
                         'root_mode': source.stat().st_mode & 0o777}
    snapshots = {}
    for name, source in snapshot_paths(server):
        safe_parents(server, name)
        if not source.exists() and not source.is_symlink():
            snapshots[name] = None
            continue
        regular(source, None if name == "private/resources/project-resources.json" else 0o600)
        target = recovery / name
        target.parent.mkdir(parents=True, exist_ok=True)
        with source.open("rb") as src, target.open("xb") as dst:
            os.fchmod(dst.fileno(), 0o600)
            shutil.copyfileobj(src, dst)
            dst.flush()
            os.fsync(dst.fileno())
        if sha(source) != sha(target):
            raise ValueError("recovery snapshot changed during copy")
        snapshots[name] = sha(target)
    if snapshots["private/installation/installed.json"] != plan["source"]["installed_receipt_sha256"] or snapshots["private/installation/baseline.json"] != plan["source"]["baseline_sha256"]:
        raise ValueError("installation identity changed after planning")
    docker_dir = server / "docker"
    raw = recovery / "database.sql"
    capture = None
    if registered:
        capture = root_client(server, workspace, provider, 'capture', output=raw)
    else:
        with raw.open("xb") as output:
            os.fchmod(output.fileno(), 0o600)
            compose(docker_dir, "exec", "-T", "mysql", "sh", "-c",
                    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --routines --triggers --events --hex-blob --databases --add-drop-database -uroot -- "$1"',
                    "sh", identity["database"], output=output, timeout=600)
            output.flush()
            os.fsync(output.fileno())
    if raw.stat().st_size == 0:
        raise ValueError("database dump is empty")
    validate_dump(raw, identity["database"])
    compressed = recovery / "database.sql.gz"
    with raw.open("rb") as src, compressed.open("xb") as dst:
        os.fchmod(dst.fileno(), 0o600)
        with gzip.GzipFile(fileobj=dst, mode="wb", mtime=0) as zipper:
            shutil.copyfileobj(src, zipper)
        dst.flush()
        os.fsync(dst.fileno())
    with gzip.open(compressed, "rb") as check:
        if hashlib.sha256(check.read()).hexdigest() != sha(raw):
            raise ValueError("compressed database dump failed round-trip verification")
    raw_digest = sha(raw)
    raw.unlink()
    after = None
    if registered:
        after = root_observation(server, workspace, provider, metadata=True)
        durable(recovery / 'database-metadata-after.json', json.dumps(after, sort_keys=True).encode())
        if any(after[key] != observation[key] for key in ('identity', 'metadata', 'mysql_sha256', 'mysqldump_sha256')):
            raise ValueError('whole-schema metadata or client changed during capture; retain backup, NOT_READY')
        assert_whole_schema_effects(observation)
        assert_stopped(server, allow_registered=True, ordinary=True)
    record = {"protocol": "peanut.server-update-backup.v1", "update_id": plan["update_id"],
              "plan_sha256": plan_sha, "database_identity": identity, "database_dump_sha256": sha(compressed),
              "source_images": runtime["images"], "compose_project": runtime["project"], 'quiesced_containers': runtime['containers'],
              "mysql_identity": probe, "snapshots": snapshots, 'storage': storage,
              'product_plan_sha256': product_binding(workspace)[0]['plan_sha256'],
              'database_provider': provider,
              'registered_evidence': {'metadata_sha256': sha(recovery / 'database-metadata.json'),
                  'metadata_after_sha256': sha(recovery / 'database-metadata-after.json'),
                  'capture': capture, 'raw_dump_sha256': raw_digest} if registered else None}
    backup_bytes = json.dumps(record, sort_keys=True).encode()
    durable(workspace / "backup.json", backup_bytes)
    durable(workspace / "backup.json.hmac", (hmac.new(verification_key(server), backup_bytes, hashlib.sha256).hexdigest() + "\n").encode())
    print(json.dumps({"status": "complete", "update_id": plan["update_id"], "database_dump_sha256": record["database_dump_sha256"]}))


def verify(args):
    server, workspace, plan, journal, plan_sha, identity = instance(args)
    check_backup_signature(server, workspace)
    record = read_json(workspace / "backup.json")
    if record.get("protocol") != "peanut.server-update-backup.v1" or record.get("update_id") != plan["update_id"] or record.get("plan_sha256") != plan_sha or record.get("database_identity") != identity:
        raise ValueError("recovery point is bound to another plan or database")
    if record.get('product_plan_sha256') != product_binding(workspace)[0]['plan_sha256'] or set(record.get('storage', {})) != set(STORAGE_NAMES):
        raise ValueError('paired storage belongs to another product plan')
    for name, entry in record['storage'].items():
        if entry is None: continue
        expected_name = name.replace('/', '-') + '.tar.gz'
        if entry.get('filename') != expected_name or sha(storage_archive(workspace / 'recovery' / expected_name)) != entry.get('sha256'):
            raise ValueError('paired storage archive changed')
    snapshots = record.get("snapshots")
    if not isinstance(snapshots, dict) or set(snapshots) != SNAPSHOT_SET:
        raise ValueError("snapshot set differs from the exact recovery allowlist")
    for name in SNAPSHOT_NAMES:
        safe_parents(workspace / "recovery", name)
        expected = snapshots[name]
        if expected is not None and (not isinstance(expected, str) or not HEX64.fullmatch(expected)):
            raise ValueError("snapshot digest is invalid")
    compressed = workspace / "recovery/database.sql.gz"
    if compressed.parent.is_symlink() or not compressed.parent.is_dir():
        raise ValueError("recovery directory is unsafe")
    regular(compressed, 0o600)
    if sha(compressed) != record.get("database_dump_sha256"):
        raise ValueError("database recovery bytes changed")
    validate_dump(compressed, identity["database"], compressed=True)
    for name in SNAPSHOT_NAMES:
        expected = snapshots[name]
        if expected is None:
            if (workspace / "recovery" / name).exists() or (workspace / "recovery" / name).is_symlink():
                raise ValueError("unexpected recovery snapshot exists")
            continue
        path = workspace / "recovery" / name
        regular(path, 0o600)
        if sha(path) != expected:
            raise ValueError("installation or configuration recovery bytes changed")
    if snapshots["private/installation/installed.json"] != plan["source"]["installed_receipt_sha256"] \
        or snapshots["private/installation/baseline.json"] != plan["source"]["baseline_sha256"] \
        or snapshots["private/installation/deployment.json"] != plan["source"]["deployment_state_sha256"]:
        raise ValueError("recovery snapshot differs from original installation identity")
    provider = ordinary_database_provider(server, workspace)
    if record.get('database_provider') != provider:
        raise ValueError('signed backup provider differs from the frozen ordinary plan')
    if provider['source'] == 'registered':
        evidence = record.get('registered_evidence', {})
        before_path = workspace / 'recovery/database-metadata.json'
        after_path = workspace / 'recovery/database-metadata-after.json'
        for path, key in ((before_path, 'metadata_sha256'), (after_path, 'metadata_after_sha256')):
            regular(path, 0o600)
            if sha(path) != evidence.get(key): raise ValueError('signed privileged metadata changed')
        observation = read_json(before_path); after = read_json(after_path)
        if any(after[key] != observation[key] for key in ('identity', 'metadata', 'mysql_sha256', 'mysqldump_sha256')):
            raise ValueError('paired metadata differs across native capture')
        assert_whole_schema_effects(observation)
        for reference, operation in ((observation['process'], 'metadata'), (after['process'], 'metadata'),
                                     (evidence['capture'], 'capture')):
            process_path = Path(reference['path'])
            if process_path.parent != workspace / 'root-client': raise ValueError('privileged process escaped its workspace')
            regular(process_path, 0o600)
            if sha(process_path) != reference['sha256']: raise ValueError('privileged process receipt changed')
            process = read_json(process_path)
            if process.get('operation') != operation or process.get('provider') != provider \
                    or process.get('product_plan_sha256') != record['product_plan_sha256'] \
                    or process.get('status') != 'terminal' or process.get('rc') != 0 \
                    or process.get('remote_success_terminal_confirmed') is not True:
                raise ValueError('privileged command has no bound successful terminal')
            for key in ('stdout', 'stderr'):
                path = Path(process[key]['path'])
                if process['operation'] == 'capture' and key == 'stdout':
                    if path != workspace / 'recovery/database.sql': raise ValueError('capture payload path changed')
                    digest = hashlib.sha256()
                    with gzip.open(compressed, 'rb') as stream:
                        for chunk in iter(lambda: stream.read(1024 * 1024), b''): digest.update(chunk)
                    if digest.hexdigest() != process[key]['sha256'] or digest.hexdigest() != evidence['raw_dump_sha256']:
                        raise ValueError('archived dump differs from actual terminal capture')
                    continue
                if path.parent != workspace / 'root-client': raise ValueError('privileged output escaped its workspace')
                regular(path, 0o600)
                if sha(path) != process[key]['sha256']: raise ValueError('privileged command output changed')
        current = root_observation(server, workspace, provider)
        if any(current[key] != observation[key] for key in ('mysql_sha256', 'mysqldump_sha256')):
            raise ValueError('registered privileged client binaries changed since backup')
    runtime = assert_stopped(server, allow_registered=True, ordinary=True)
    if record.get("source_images") != runtime["images"] or record.get("compose_project") != runtime["project"]:
        raise ValueError("source image or Compose project changed after backup")
    if journal.get('phase_in_progress') in ('backup', 'quiesce', 'migrate') and record.get('quiesced_containers') != runtime['containers']:
        raise ValueError('a writer restarted after the paired backup; stale recovery point reuse is forbidden')
    current_probe = db_probe(server, identity["database"], workspace)
    if current_probe["server_uuid"] != record.get("mysql_identity", {}).get("server_uuid") \
        or current_probe["database"] != record.get("mysql_identity", {}).get("database"):
        raise ValueError("MySQL server or schema changed after backup")
    return server, workspace, plan, journal, record


def validate_dump(path, database, compressed=False):
    seen_use = seen_create = seen_drop = False
    opener = gzip.open if compressed else open
    with opener(path, "rb") as stream:
        for raw in stream:
            line = re.sub(r"/\*![0-9]+\s*|\*/", " ", raw.decode("utf-8", errors="replace"))
            for action in ("USE", "CREATE DATABASE", "DROP DATABASE"):
                match = re.search(r"\b" + action + r"\b(?:\s+IF\s+(?:NOT\s+)?EXISTS)?\s+`([^`]+)`", line, re.IGNORECASE)
                if match:
                    if match.group(1) != database:
                        raise ValueError("database dump names another schema")
                    seen_use |= action == "USE"
                    seen_create |= action == "CREATE DATABASE"
                    seen_drop |= action == "DROP DATABASE"
    if not (seen_use and seen_create and seen_drop):
        raise ValueError("database dump lacks a complete same-schema restore contract")


def restore(args):
    server, workspace, plan, journal, record = verify(args)
    if journal.get("activation_started") is True or journal.get("phase") == "completed":
        raise ValueError("activation started; automatic old database recovery is forbidden")
    state_path = workspace / "database-recovery.json"
    if state_path.exists() or state_path.is_symlink():
        state = read_json(state_path)
        if state.get("status") == "completed" and state.get("backup_sha256") == sha(workspace / "backup.json"):
            check_signature(server, state_path)
            if db_probe(server, record["database_identity"]["database"], workspace) != record["mysql_identity"]:
                raise ValueError("already restored database no longer matches recovery point")
            for name, entry in record['storage'].items():
                if entry is not None and storage_inventory(server / name) != entry['inventory']:
                    raise ValueError('already restored protected storage changed')
            print(json.dumps({"status": "already_restored", "update_id": plan["update_id"]}))
            return
        raise ValueError("database restore has an unknown or interrupted result; inspect before retry")
    provider = ordinary_database_provider(server, workspace)
    if provider['source'] == 'registered':
        # Current outside effects may have changed since backup; DROP/USE must never cross that boundary.
        assert_whole_schema_effects(root_observation(server, workspace, provider, metadata=True))
    durable(state_path, json.dumps({"status": "started", "update_id": plan["update_id"],
                                   "backup_sha256": sha(workspace / "backup.json")}).encode())
    dump = workspace / "recovery/database.sql.gz"
    docker_dir = server / "docker"
    database = record["database_identity"]["database"]
    raw = workspace / "recovery/database-restore.sql"
    if raw.exists() or raw.is_symlink():
        raise ValueError("restore staging file already exists")
    try:
        with gzip.open(dump, "rb") as source, raw.open("xb") as target:
            os.fchmod(target.fileno(), 0o600)
            shutil.copyfileobj(source, target)
            target.flush()
            os.fsync(target.fileno())
        with raw.open("rb") as statement:
            if provider['source'] == 'registered':
                restored_process = root_client(server, workspace, provider, 'restore', input_file=statement)
            else:
                compose(docker_dir, "exec", "-T", "mysql", "sh", "-c",
                        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot',
                        input_file=statement, timeout=600)
    finally:
        raw.unlink(missing_ok=True)
    restored = db_probe(server, database, workspace)
    if restored != record["mysql_identity"]:
        raise ValueError("restored schema identity or table count differs from recovery point")
    restore_storage(server, workspace, record)
    for name, expected in record["snapshots"].items():
        path = server / name
        if expected is None:
            if path.exists() or path.is_symlink():
                raise ValueError("previously absent installation state appeared during recovery")
            continue
        regular(path)
        if sha(path) != expected:
            raise ValueError("protected installation or configuration state changed during update")
    temporary = workspace / "database-recovery.complete"
    durable(temporary, json.dumps({"status": "completed", "update_id": plan["update_id"],
                                   "backup_sha256": sha(workspace / "backup.json"),
                                   "mysql_identity": restored,
                                   "registered_restore_process": restored_process if provider['source'] == 'registered' else None}).encode())
    os.replace(temporary, state_path)
    durable(state_path.with_name(state_path.name + ".hmac"), (signature_for(server, state_path) + "\n").encode())
    print(json.dumps({"status": "completed", "update_id": plan["update_id"]}))


def restore_storage(server, workspace, record):
    # Only these plan-bound protected directories are replaced, while all writers are stopped.
    for name in STORAGE_NAMES:
        safe_parents(server, name)
        current = server / name
        entry = record['storage'][name]
        if entry is None:
            if current.exists() or current.is_symlink():
                raise ValueError('previously absent protected directory appeared')
            continue
        if current.exists() and storage_inventory(current) == entry['inventory']:
            continue
        stage = workspace / 'recovery' / (name.replace('/', '-') + '.restore')
        old = workspace / 'recovery' / (name.replace('/', '-') + '.previous')
        archive_path = storage_archive(workspace / 'recovery' / entry['filename'])
        if not stage.exists():
            stage.mkdir(mode=entry['root_mode'])
            with tarfile.open(archive_path, 'r:gz') as archive:
                # Members have already been checked: ordinary directories/files, no links or traversal.
                archive.extractall(stage, filter=lambda member, destination: member)
            os.chmod(stage, entry['root_mode'])
        if storage_inventory(stage) != entry['inventory']:
            raise ValueError('staged protected directory differs from paired backup')
        if current.exists():
            storage_inventory(current)
            if old.exists() or old.is_symlink():
                raise ValueError('protected directory recovery has an unknown result')
            os.replace(current, old)
        os.replace(stage, current)
        for directory in (current.parent, stage.parent):
            descriptor = os.open(directory, os.O_RDONLY)
            try: os.fsync(descriptor)
            finally: os.close(descriptor)
        if storage_inventory(current) != entry['inventory']:
            raise ValueError('restored protected directory differs from paired backup')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("backup", "verify", "restore"))
    parser.add_argument("--server", type=Path, required=True)
    parser.add_argument("--workspace", type=Path, required=True)
    args = parser.parse_args()
    {"backup": backup, "verify": lambda a: (verify(a), print('{"status":"verified"}')),
     "restore": restore}[args.operation](args)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, KeyError, json.JSONDecodeError, gzip.BadGzipFile) as error:
        print(f"update-recovery: {error}", file=sys.stderr)
        sys.exit(1)
