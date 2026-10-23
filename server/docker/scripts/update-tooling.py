#!/usr/bin/env python3
"""Explicit, operator-pinned maintenance tooling adoption; never replaces APP files."""
import argparse
import base64
import contextlib
from datetime import datetime, timezone
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
PROTOCOL = 'peanut.maintenance-tooling.v1'
RECEIPT = 'peanut.maintenance-tooling-adoption.v1'
# Audited deterministic defect: selected update-plan is declared before prepare
# intent; VerifyRuntime then redeclares its same-byte trusted copy before dispatch.
# This is deliberately NOT a generic fatal/UNKNOWN recovery capability.
FAILED_SOURCE = '11a327d5a9206c8a8cb1fd21d42c801fec4d55ba'
FAILED_TOOLING = 'beb909dd9563118a810ef454d8438aa9293511903558e00bd96f5fc394ff4ce6'
DISPOSITION = 'peanut.pre-host-prepare-failure.v1'
RECIPE_SOURCE = 'b4e06ae581c19cee60a6c792806d940c8d27f01f'
RECIPE_TOOLING = 'c40be6d88bfaeaf8f3b510370d14e422d7880f0718d103246212ec548037a187'
RECIPE_DISPOSITION = 'peanut.readonly-prepare-rejection.v1'
# These are the original observations of this one dispatched, read-only guard
# rejection. They do not authorize disposition of another exit-1 or host phase.
RECIPE_ORIGINALS = {
    'pre': '5a6f7cb4fc3cffef981c254a4a69abbf389503a7330437c56afbb4d870030d4c',
    'post': '95b27e5c5f948e318a8e201d06c4fa2716a983df169d35728780eb1bf2e42e03',
    'arguments': '4890f38fd012c9ef7cca38e98601da22a181c320978421883733a753d15a5a43',
    'render': '2bef2ec97a03b784efc9e2166f36f3e49d72bd38f51c1977461c3a31ab9d7616',
    'stdout': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'stderr': 'd23003adaabea8cf8d86abd5ff944891ce6358b386c1ea45e385639375b5d0a5',
    'terminal': '4355a46b19d348dc2f57c046f8ef63d4538ebb936000f3c9ee954a27460dd865',
}
RECIPE_LOG = 'bb0a03177644808d588dbd5c22c9fdbf0231683c97c3c38dbcbef6c5927625a2'


def regular(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or path.resolve() != path:
        raise ValueError('tooling input is not an ordinary canonical file')
    return path.read_bytes()


def sha(path):
    return hashlib.sha256(regular(path)).hexdigest()


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode()


def document(path):
    return json.loads(regular(path))


def absolute(path, exists=True):
    if not path.is_absolute() or path.resolve() != path or (exists and not path.is_dir()):
        raise ValueError('tooling directory must be canonical and absolute')
    return path


def protected_bytes(path):
    data = regular(path)
    if stat.S_IMODE(path.stat().st_mode) != 0o600 or path.stat().st_uid != os.geteuid():
        raise ValueError('tooling receipt must be owned by this operator with mode 0600')
    return data


def protected(path):
    return json.loads(protected_bytes(path))


def durable(path, value, replace=False):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    absolute(path.parent)
    if not replace and (path.exists() or path.is_symlink()):
        raise ValueError('tooling record already exists')
    temporary = path.with_name(path.name + '.next-' + os.urandom(8).hex())
    with temporary.open('xb') as stream:
        os.fchmod(stream.fileno(), 0o600); stream.write(canonical(value)); stream.flush(); os.fsync(stream.fileno())
    os.replace(temporary, path)
    fd = os.open(path.parent, os.O_RDONLY)
    try: os.fsync(fd)
    finally: os.close(fd)


def bundle(root, expected):
    absolute(root)
    manifest = root / 'maintenance-tooling.json'
    if sha(manifest) != expected:
        raise ValueError('maintenance tooling manifest differs from operator pin')
    data = document(manifest)
    if data.get('protocol') != PROTOCOL or data.get('schema_version') != 1 or not isinstance(data.get('files'), dict):
        raise ValueError('unsupported maintenance tooling closure')
    if not isinstance(data.get('runtime_files'), list) or not data['runtime_files'] or any(
        not isinstance(name, str) or name not in data['files'] or Path(name).is_absolute() or '..' in Path(name).parts
        for name in data['runtime_files']):
        raise ValueError('maintenance tooling runtime inventory is invalid')
    actual = set()
    for base, dirs, names in os.walk(root, followlinks=False):
        for name in dirs:
            if (Path(base) / name).is_symlink(): raise ValueError('linked tooling directory')
        for name in names:
            file = Path(base) / name; relative = file.relative_to(root).as_posix(); actual.add(relative)
            if relative == 'maintenance-tooling.json': continue
            row = data['files'].get(relative)
            if not isinstance(row, dict) or sha(file) != row.get('sha256') or stat.S_IMODE(file.stat().st_mode) != row.get('mode'):
                raise ValueError('maintenance tooling bytes or mode changed')
    if actual != set(data['files']) | {'maintenance-tooling.json'}:
        raise ValueError('maintenance tooling closure is incomplete')
    if any(not re.fullmatch(r'[a-f0-9]{40}|[a-f0-9]{64}', str(data.get(key, ''))) for key in ('source_commit', 'source_tree')) \
        or data.get('composer_lock_sha256') != sha(root / 'server/composer.lock'):
        raise ValueError('maintenance tooling fixed source or lock identity is invalid')
    return data


def inventory(root, php):
    if not php.is_absolute() or not php.is_file() or not os.access(php, os.X_OK):
        raise ValueError('tooling packaging requires the explicitly selected PHP CLI')
    result = subprocess.run([str(php), str(root / 'scripts/upgrade'), 'tooling-inventory'], capture_output=True, check=True)
    return json.loads(result.stdout)


def pack(root, output, php):
    absolute(root); absolute(output, False)
    if output.exists() or output.is_relative_to(root): raise ValueError('tooling output must be new and outside source')
    git = lambda *args: subprocess.check_output(['git', '-C', str(root), *args], text=True).strip()
    if git('status', '--porcelain', '--untracked-files=no'): raise ValueError('tooling source must be committed and clean')
    paths = inventory(root, php)
    runtime_files = list(paths)
    lock = document(root / 'server/composer.lock'); installed = document(root / 'server/vendor/composer/installed.json')
    identity = lambda row: {key: row.get(key) for key in ('name', 'version', 'source', 'dist')}
    rows = lambda items: sorted(map(identity, items), key=lambda row: row['name'])
    if installed.get('dev') is not False or rows(installed['packages']) != rows(lock['packages']):
        raise ValueError('tooling dependencies must match the production Composer lock')
    # Composer bin proxies are not part of this runtime and can be symlinks.
    paths += [p.relative_to(root).as_posix() for p in (root / 'server/vendor').rglob('*')
              if not p.is_dir() and not p.is_relative_to(root / 'server/vendor/bin')]
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.tooling-build-', dir=output.parent) as temporary:
        stage = Path(temporary) / 'tools'; stage.mkdir(); files = {}
        for relative in sorted(set(paths)):
            source = root / relative; content = regular(source)
            if not relative.startswith('server/vendor/'):
                fixed = subprocess.check_output(['git', '-C', str(root), 'show', 'HEAD:' + relative])
                if content != fixed: raise ValueError('tooling source differs from fixed Git object')
            mode = 0o755 if source.stat().st_mode & 0o111 else 0o644
            target = stage / relative; target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content); target.chmod(mode)
            files[relative] = {'sha256': hashlib.sha256(content).hexdigest(), 'mode': mode}
        data = {'schema_version': 1, 'protocol': PROTOCOL, 'source_commit': git('rev-parse', 'HEAD'),
                'source_tree': git('rev-parse', 'HEAD^{tree}'), 'composer_lock_sha256': sha(root / 'server/composer.lock'),
                'runtime_files': runtime_files, 'files': files}
        (stage / 'maintenance-tooling.json').write_bytes(canonical(data))
        os.rename(stage, output)
    return {'status': 'packaged', 'root': str(output), 'manifest_sha256': sha(output / 'maintenance-tooling.json'), 'source_commit': data['source_commit']}


def pointer(root):
    return root / '.peanut/upgrades/tooling-active.json'


def source_state(root):
    server = root / 'server'
    required = ['.env', 'docker/.env', 'private/installation/installed.json', 'private/installation/baseline.json',
                '.peanut/release-identity.json', 'docker/scripts/upgrade']
    optional = ['private/installation/deployment.json']
    files = {name: sha(server / name) for name in required}
    files.update({name: sha(server / name) if (server / name).exists() else None for name in optional})
    return files


def failed_prepare_state(root, path, state):
    candidate = state.get('candidate', '')
    if not re.fullmatch(r'server_update_[0-9]{14}_[a-f0-9]{12}', candidate):
        raise ValueError('invalid failed prepare candidate')
    record_path = root / '.peanut/upgrades/readonly-prepare-rejections' / (candidate + '.json')
    recipe = record_path.exists()
    if not recipe: record_path = root / '.peanut/upgrades/pre-host-failures' / (candidate + '.json')
    record = protected(record_path)
    protected(Path(record['admission']))
    if record.get('protocol') != (RECIPE_DISPOSITION if recipe else DISPOSITION) or record.get('instance_root') != str(root) \
        or record.get('status') != ('failed-after-readonly-prepare' if recipe else 'failed-before-host') \
        or record.get('source_commit') != (RECIPE_SOURCE if recipe else FAILED_SOURCE) \
        or record.get('tooling_manifest_sha256') != (RECIPE_TOOLING if recipe else FAILED_TOOLING) or record.get('state_path') != str(path) \
        or record.get('plan_path') != str(root / '.peanut/upgrades/product-plans' / (candidate + '.json')) \
        or record.get('state_sha256') != sha(path) or record.get('plan_sha256') != state.get('plan_sha256') \
        or record.get('admission_sha256') != sha(Path(record['admission'])) \
        or record.get('plan_file_sha256') != sha(Path(record['plan_path'])):
        raise ValueError('failed prepare disposition changed')


def states(root, admitted=None):
    result = {}
    for path in sorted((root / '.peanut/upgrades/product-state').glob('*.json')):
        state = document(path)
        if state.get('phase_in_progress') == 'prepare' and state.get('phase') == 'planned' \
            and state.get('database_recovery_required') is False and state.get('migration_completed') is False \
            and state.get('activation_started') is False and state.get('evidence') == []:
            if (admitted or {}).get(str(path)) != sha(path): failed_prepare_state(root, path, state)
        elif state.get('phase') not in ('planned', 'blocked', 'completed', 'recovered') or state.get('phase_in_progress') is not None:
            raise ValueError('an installed product operation is active or unknown')
        if state.get('phase') in ('planned', 'blocked') and (state.get('activation_started') or state.get('database_recovery_required')):
            raise ValueError('an installed product operation needs recovery')
        result[str(path)] = sha(path)
    return result


def instant(value):
    if not isinstance(value, str): raise ValueError('missing authenticated observation time')
    match = re.fullmatch(r'([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,9}))?(?:Z|\+00:00)', value)
    if match is None: raise ValueError('observation time must carry UTC')
    # Preserve Docker nanoseconds on supported Python versions without floats.
    parsed = datetime.strptime(match[1], '%Y-%m-%dT%H:%M:%S').replace(tzinfo=timezone.utc)
    return parsed, int((match[2] or '').ljust(9, '0'))


def failure_runtime_evidence(approved, root, plan, state, receipt_path, old_tools, current):
    evidence = approved.get('runtime_evidence', {})
    if 'runtime_before' in approved or set(evidence) != {'variant', 'pre', 'post', 'arguments'} \
        or evidence['variant'] != 'post-start-inference':
        raise ValueError('explicit original pre/post evidence variant is required; do not backfill StartedAt')
    originals = {}
    for name in ('pre', 'post', 'arguments'):
        spec = evidence[name]
        if set(spec) != {'path', 'sha256'}: raise ValueError('original evidence accepts path/hash only, no rewritten header')
        path = Path(spec['path']); content = protected_bytes(path)
        if hashlib.sha256(content).hexdigest() != spec['sha256']: raise ValueError('original failure evidence changed')
        originals[name] = json.loads(content)
    pre = originals['pre']; post = originals['post']; arguments = originals['arguments']
    expected_tail = ['apply', '--instance-server=' + str(root / 'server'),
                     '--tooling-receipt=' + str(receipt_path), '--workspace=' + plan['workspace']]
    entry = str(old_tools / 'server/docker/scripts/update.sh'); argv = arguments.get('argv', [])
    if argv.count(entry) != 1 or argv[argv.index(entry) + 1:] != expected_tail \
        or arguments.get('candidate') != plan['candidate'] \
        or arguments.get('plan_sha256') != plan['plan_sha256'].removeprefix('sha256:') \
        or pre.get('instance_root') != str(root) or pre.get('service_action_executed') is not False \
        or pre.get('backup_executed') is not False:
        raise ValueError('original observations or argv differ from the exact failed apply')
    for key in ('candidate', 'plan_sha256', 'state_sha256', 'phase', 'phase_in_progress',
                'database_recovery_required', 'migration_completed', 'activation_started'):
        if post['state'].get(key) != state.get(key): raise ValueError('post observation belongs to another state')
    if post.get('evidence_phases') != [] or any(post.get(key) is not False for key in
        ('backup_exists', 'recovery_exists', 'prepare_host_log_exists')):
        raise ValueError('post observation contains transaction or host activity')
    anchors = source_state(root)
    if post.get('old_program_identity_sha256') != anchors['.peanut/release-identity.json'] \
        or post.get('old_installed_sha256') != anchors['private/installation/installed.json']:
        raise ValueError('post observation installation identity changed')
    required_anchors = {'.env', 'docker/.env', 'private/installation/installed.json',
                        'private/installation/baseline.json', 'private/installation/deployment.json',
                        'private/resources/project-resources.json'}
    if set(pre.get('protected_files', {})) != required_anchors:
        raise ValueError('original protected pre-observation is incomplete')
    for name, row in pre['protected_files'].items():
        if name not in set(anchors) | {'private/resources/project-resources.json'}:
            raise ValueError('unknown protected pre-observation path')
        file = root / 'server' / name
        if row['exists'] is False:
            if file.exists() or file.is_symlink(): raise ValueError('protected predecessor appeared')
        elif row.get('is_symlink') is not False or sha(file) != row.get('sha256') \
            or oct(stat.S_IMODE(file.stat().st_mode)) != row.get('mode'):
            raise ValueError('protected original observation changed')

    # created_at is authenticated by the original native plan; updated_at is the
    # prepare-intent event, not a claimed command-start measurement.
    if not instant(pre['observed_at']) < instant(plan['created_at']) <= instant(state['updated_at']) <= instant(post['observed_at']):
        raise ValueError('original pre/plan/prepare-intent/post chronology is unknown')
    before = {row['service']: row for row in pre['containers']}
    after = {row['id']: row for row in post['containers']}
    if len(before) != len(pre['containers']) or len(after) != len(post['containers']) \
        or set(before) != set(current) or set(after) != {row['id'] for row in current.values()}:
        raise ValueError('original container inventory differs')
    for service, actual in current.items():
        first = before[service]; last = after[actual['id']]
        if not re.fullmatch(r'[a-f0-9]{64}', first['id']) or first['id'] != actual['id'] \
            or first.get('state') != 'running' or last.get('running') is not True or actual['running'] is not True \
            or first.get('image') != actual['image'] or first.get('name') != last.get('name') \
            or 'started_at' in first or 'image' in last or last.get('started_at') != actual['started_at'] \
            or not instant(last['started_at']) < instant(pre['observed_at']):
            raise ValueError('container inference does not match original observations and current engine facts')
    return {'variant': 'post-start-inference', 'originals': evidence,
        'pre_observed_at': pre['observed_at'], 'plan_created_at': plan['created_at'],
        'prepare_intent_at': state['updated_at'], 'post_observed_at': post['observed_at'],
        'pre_started_at': 'NOT_CAPTURED', 'post_image': 'NOT_CAPTURED', 'command_start': 'NOT_CAPTURED',
        'inference': 'same container IDs; post/current StartedAt predates pre observation; no container restart in that window',
        'not_proven': ['internal process restart absence', 'absence of business database writes', 'full recovery']}


def recipe_failure_evidence(approved, root, plan, state, receipt_path, old_tools, current):
    evidence = approved.get('runtime_evidence', {})
    if set(evidence) != set(RECIPE_ORIGINALS) or approved.get('exit_code') != 1:
        raise ValueError('the exact dispatched read-only rejection originals are required')
    originals = {}
    for name, digest in RECIPE_ORIGINALS.items():
        spec = evidence[name]
        if set(spec) != {'path', 'sha256'} or spec['sha256'] != digest:
            raise ValueError('unknown read-only rejection evidence')
        content = protected_bytes(Path(spec['path']))
        if hashlib.sha256(content).hexdigest() != digest: raise ValueError('read-only rejection original changed')
        originals[name] = json.loads(content) if name in ('pre', 'post', 'arguments', 'render') else content
    pre, post, arguments, render = (originals[name] for name in ('pre', 'post', 'arguments', 'render'))
    tail = ['apply', '--instance-server=' + str(root / 'server'),
            '--tooling-receipt=' + str(receipt_path), '--workspace=' + plan['workspace']]
    entry = str(old_tools / 'server/docker/scripts/update.sh'); argv = arguments.get('argv', [])
    if argv.count(entry) != 1 or argv[argv.index(entry) + 1:] != tail \
        or arguments.get('candidate') != plan['candidate'] \
        or arguments.get('plan_sha256') != plan['plan_sha256'].removeprefix('sha256:') \
        or pre.get('instance_root') != str(root) or originals['stdout'] != b'' \
        or originals['terminal'] != b'1\n' \
        or originals['stderr'] != b'{"status":"error","error":"PRODUCT_UPGRADE_HOST_PHASE_FAILED:prepare:exit-1"}\n' \
        or sha(Path(approved['stdout'])) != RECIPE_ORIGINALS['stdout']:
        raise ValueError('terminal or argv is not the exact known host rejection')
    for key, value in post['state'].items():
        if state.get(key) != value: raise ValueError('read-only rejection native state changed')
    if post['phase_evidence'] != [] or any(post['workspace_phase_artifacts'].values()):
        raise ValueError('read-only failure contains transaction evidence')
    anchors = source_state(root)
    if post['installed_identity_sha256'] != anchors['.peanut/release-identity.json'] \
        or post['installation_sha256'] != anchors['private/installation/installed.json']:
        raise ValueError('read-only failure installation identity changed')
    logs = list((root / '.peanut/upgrades/host-logs').glob(plan['candidate'] + '-*.log'))
    expected_log = root / '.peanut/upgrades/host-logs' / (plan['candidate'] + '-prepare.log')
    if logs != [expected_log] or sha(expected_log) != RECIPE_LOG \
        or protected_bytes(expected_log) != b'product-upgrade-host: inactive or auxiliary runtime recipe changed\n':
        raise ValueError('host dispatch differs from the known read-only guard rejection')
    # All originals carry full captured StartedAt. This is not the earlier
    # pre-host inference variant and never claims that the host was not run.
    if set(pre['containers']) != set(current) or pre['containers'] != post['containers']:
        raise ValueError('read-only rejection original container inventory differs')
    if not instant(pre['observed_at']) < instant(arguments['local_transport_dispatch_at_utc']) \
        <= instant(state['updated_at']) <= instant(post['observed_at']):
        raise ValueError('read-only rejection observation chronology differs')
    for service, actual in current.items():
        first = pre['containers'][service]
        if first != actual or not re.fullmatch(r'[a-f0-9]{64}', actual['id']) or actual['running'] is not True:
            raise ValueError('read-only rejection container identity or StartedAt changed')
    server = root / 'server'; target = Path(plan['workspace']) / 'prepared/server'
    inputs = document(Path(plan['workspace']) / 'plan.json')
    if sha(target / '.peanut/release-identity.json') != inputs['target']['identity_sha256']:
        raise ValueError('read-only rejection target identity changed')
    identity = {row['path']: row for row in document(target / '.peanut/release-identity.json')['files']}
    recipes = render['recipes']
    additions = {'docker/compose.registered-database.yaml', 'docker/compose.collaboration.yaml',
        'docker/conf/nginx.collaboration.conf.template', 'docker/conf/nginx.source-collaboration.conf.template'}
    if len(recipes) != 11 or set(post['guard_failing_auxiliary_paths']) != additions \
        or render['selected_compose_files'] != ['compose.yaml'] or render['database_source'] != 'bundled' \
        or render['collaboration_selected'] is not False \
        or render['config_differing_paths'] != ['/services/php/stop_signal'] \
        or render['after_signal_normalization_differing_paths'] != []:
        raise ValueError('read-only rejection recipe selection differs')
    for name, pair in recipes.items():
        for side, base in (('current', server), ('target', target)):
            file = base / name; expected = pair[side]
            if expected is None:
                if file.exists() or file.is_symlink(): raise ValueError('absent recipe appeared')
            elif sha(file) != expected['sha256'] or stat.S_IMODE(file.stat().st_mode) != expected['mode']:
                raise ValueError('read-only rejection recipe bytes or mode changed')
        row = identity.get('server/' + name)
        if pair['target'] is not None and (row is None or any(row[key] != pair['target'][key] for key in ('sha256', 'mode'))):
            raise ValueError('read-only rejection recipes differ from fixed B identity')
        if name in additions and (pair['current'] is not None or pair['target']['mode'] != 0o644):
            raise ValueError('known inactive addition is no longer absent-to-add')
    for name, digest in render['protected_environment_hashes_unchanged'].items():
        if sha(Path(name)) != digest: raise ValueError('protected environment differs from rendered observation')
    return {'variant': 'known-b4e-readonly-recipe-rejection', 'originals': evidence,
        'host_dispatched': True, 'host_log_sha256': RECIPE_LOG,
        'control_flow': 'fixed b4e closure rejects auxiliary changes before vendor/preparation/copy/nginx or transaction work',
        'not_proven': ['internal process restart absence', 'absence of business database writes', 'full recovery']}


def dispose_failed_prepare(root, admission):
    approved = protected(admission)
    recipe = approved.get('protocol') == 'peanut.readonly-prepare-rejection-admission.v1'
    source = RECIPE_SOURCE if recipe else FAILED_SOURCE
    tooling = RECIPE_TOOLING if recipe else FAILED_TOOLING
    if approved.get('protocol') not in ('peanut.pre-host-prepare-failure-admission.v1',
        'peanut.readonly-prepare-rejection-admission.v1') or approved.get('instance_root') != str(root):
        raise ValueError('explicit owner-approved failed prepare admission is required')
    receipt_path = Path(approved['tooling_receipt']); receipt = protected(receipt_path)
    old_tools = Path(receipt['tools_root']); binding = check(root, receipt_path, old_tools, True)
    if binding['manifest_sha256'] != tooling or bundle(old_tools, tooling)['source_commit'] != source:
        raise ValueError('failure is not the audited deterministic source for this category')
    metadata = document(old_tools / 'maintenance-tooling.json')
    candidate = approved['candidate']
    if not re.fullmatch(r'server_update_[0-9]{14}_[a-f0-9]{12}', candidate): raise ValueError('invalid failed candidate')
    plan_path = root / '.peanut/upgrades/product-plans' / (candidate + '.json')
    state_path = root / '.peanut/upgrades/product-state' / (candidate + '.json')
    plan = protected(plan_path); state = protected(state_path)
    if sha(plan_path) != approved['plan_file_sha256'] or sha(state_path) != approved['state_sha256'] \
        or approved.get('runtime_sha256') != plan.get('runtime_sha256') \
        or plan.get('protocol') != 'peanut.product-upgrade-plan.v2' or plan.get('scope') != 'server' \
        or plan.get('instance_root') != str(root) or plan.get('state_path') != str(state_path) \
        or plan.get('candidate') != candidate or state.get('candidate') != candidate \
        or plan.get('maintenance_tooling') != binding or state.get('plan_sha256') != plan.get('plan_sha256') \
        or state.get('package') != plan.get('package') or state.get('protocol') != 'peanut.product-upgrade-state.v1' \
        or state.get('phase') != 'planned' or state.get('phase_in_progress') != 'prepare' \
        or state.get('database_recovery_required') is not False or state.get('migration_completed') is not False \
        or state.get('activation_started') is not False or state.get('evidence') != []:
        raise ValueError('failure does not match the precise planned prepare intent')
    for data, key in ((plan, 'plan_sha256'), (state, 'state_sha256')):
        unsigned = dict(data); digest = unsigned.pop(key, None)
        expected_digest = 'sha256:' + hashlib.sha256(json.dumps(unsigned, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()
        if digest != expected_digest: raise ValueError('native plan or state canonical digest changed')
    workspace = absolute(Path(plan['workspace'])); trusted = workspace / 'trusted'
    rows = protected(trusted / 'manifest.json')
    excluded = {'scripts/upgrade', 'scripts/scaffold-runtime/ScaffoldPathGuard.php',
                'server/docker/scripts/update.sh', 'server/composer.json', 'server/composer.lock'}
    expected = {name: metadata['files'][name]['sha256'] for name in metadata['runtime_files'] if name not in excluded}
    if sha(trusted / 'manifest.json') != plan.get('runtime_sha256') or rows != expected:
        raise ValueError('failed runtime differs from the audited closure')
    actual = set()
    for base, dirs, names in os.walk(trusted, followlinks=False):
        for name in dirs:
            if (Path(base) / name).is_symlink(): raise ValueError('linked failed runtime directory')
        for name in names:
            file = Path(base) / name; relative = file.relative_to(trusted).as_posix(); actual.add(relative)
            if relative != 'manifest.json' and (sha(file) != rows.get(relative) or stat.S_IMODE(file.stat().st_mode)
                != (0o700 if metadata['files'][relative]['mode'] == 0o755 else 0o600)):
                raise ValueError('failed runtime bytes or mode changed')
    if actual != set(rows) | {'manifest.json'} or sha(workspace / 'plan.json') != plan.get('inputs_sha256'):
        raise ValueError('failed runtime inventory or native inputs changed')
    stdout = Path(approved['stdout']); stdout_bytes = protected_bytes(stdout)
    if not recipe and (sha(stdout) != approved['stdout_sha256'] or approved.get('exit_code') != 255 \
        or stdout_bytes.decode().strip() != ('Fatal error: Cannot declare class PeanutServerUpdatePlan, because the name is already in use in '
            + str(trusted / 'server/docker/scripts/update-plan.php') + ' on line 6')):
        raise ValueError('terminal failure differs from the exact known declaration fault')
    if any((workspace / name).exists() or (workspace / name).is_symlink() for name in
        ('prepared.json', 'preparation.json', 'backup.json', 'journal.json', 'migration.json', 'dependency-switch.json')) \
        or (not recipe and list((root / '.peanut/upgrades/host-logs').glob(candidate + '-*.log'))):
        raise ValueError('host or transaction activity makes the failure UNKNOWN')
    if approved.get('source') != source_state(root) or approved['source'] != receipt['source']:
        raise ValueError('installed identity or protected prestate changed')
    spec = importlib.util.spec_from_file_location('failure_owner', old_tools / 'server/docker/scripts/configure-collaboration.py')
    owner = importlib.util.module_from_spec(spec); spec.loader.exec_module(owner)
    environment = owner.backend_environment(root / 'server')
    if environment.get('COLLABORATION_ENABLED') == 'enabled': raise ValueError('unknown collaboration failure boundary')
    services = ('php', 'nginx') if environment.get('PEANUT_DATABASE_SOURCE', 'bundled') == 'registered' else ('php', 'nginx', 'mysql')
    current = {}
    for service in services:
        ids = subprocess.check_output([*owner.compose_arguments(root / 'server'), 'ps', '-a', '-q', service],
                                      cwd=root / 'server/docker', text=True).splitlines()
        if len(ids) != 1: raise ValueError('original runtime service is not unique')
        current[service] = json.loads(subprocess.check_output(['docker', 'inspect', '--format',
            '{"id":{{json .Id}},"image":{{json .Image}},"started_at":{{json .State.StartedAt}},"running":{{json .State.Running}}}', ids[0]], text=True))
    runtime_evidence = (recipe_failure_evidence(approved, root, plan, state, receipt_path, old_tools, current)
        if recipe else failure_runtime_evidence(approved, root, plan, state, receipt_path, old_tools, current))
    states(root, {str(state_path): sha(state_path)})
    status = 'failed-after-readonly-prepare' if recipe else 'failed-before-host'
    disposition = {'protocol': RECIPE_DISPOSITION if recipe else DISPOSITION, 'status': status, 'instance_root': str(root),
        'source_commit': source, 'tooling_manifest_sha256': tooling,
        'candidate': candidate, 'state_path': str(state_path), 'state_sha256': sha(state_path),
        'plan_path': str(plan_path), 'plan_file_sha256': sha(plan_path), 'plan_sha256': plan['plan_sha256'],
        'runtime_sha256': plan['runtime_sha256'], 'admission': str(admission), 'admission_sha256': sha(admission),
        'runtime_evidence': runtime_evidence}
    output = root / '.peanut/upgrades' / ('readonly-prepare-rejections' if recipe else 'pre-host-failures') / (candidate + '.json')
    if output.exists():
        if protected(output) != disposition: raise ValueError('failed prepare disposition already differs')
    else: durable(output, disposition)
    return {'status': status, 'disposition': str(output), 'sha256': sha(output), 'old_state_unchanged': True}


@contextlib.contextmanager
def locks(root):
    handles = []
    try:
        for file in (root / '.peanut/upgrades/product.lock', root / 'server/runtime/upgrade/update.lock'):
            file.parent.mkdir(parents=True, exist_ok=True, mode=0o700); absolute(file.parent)
            fd = os.open(file, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            if os.fstat(fd).st_nlink != 1: os.close(fd); raise ValueError('tooling lock is linked')
            handles.append(fd); fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        for fd in handles: os.close(fd)


def check(root, receipt, tools, planning=False):
    data = protected(receipt)
    if data.get('protocol') != RECEIPT or data.get('instance_root') != str(root) or data.get('tools_root') != str(tools):
        raise ValueError('maintenance tooling adoption belongs to another instance or closure')
    if (receipt.parent / 'recovered.json').exists() or protected(pointer(root)) != {'receipt': str(receipt), 'sha256': sha(receipt)}:
        raise ValueError('maintenance tooling adoption is not active')
    bundle(tools, data['manifest_sha256'])
    if planning and source_state(root) != data['source']:
        raise ValueError('installed prestate changed since tooling adoption')
    return {'receipt': str(receipt), 'sha256': sha(receipt), 'manifest_sha256': data['manifest_sha256'], 'tools_root': str(tools)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('pack', 'observe', 'adopt', 'check', 'recover', 'dispose-failed-prepare'))
    parser.add_argument('--source-root', type=Path); parser.add_argument('--output', type=Path)
    parser.add_argument('--php', type=Path)
    parser.add_argument('--admission', type=Path)
    parser.add_argument('--instance-root', type=Path); parser.add_argument('--tooling-root', type=Path)
    parser.add_argument('--expected-tooling-sha256'); parser.add_argument('--expected-before-sha256')
    parser.add_argument('--workspace', type=Path); parser.add_argument('--receipt', type=Path); parser.add_argument('--planning', action='store_true')
    args = parser.parse_args()
    if args.operation == 'pack': result = pack(args.source_root, args.output, args.php)
    else:
        root = absolute(args.instance_root)
        if args.operation == 'check': result = check(root, args.receipt, args.tooling_root, args.planning)
        else:
            with locks(root):
                if args.operation == 'dispose-failed-prepare':
                    print(json.dumps(dispose_failed_prepare(root, args.admission), sort_keys=True)); return
                before = {'source': source_state(root), 'states': states(root), 'active': sha(pointer(root)) if pointer(root).exists() else None}
                fingerprint = hashlib.sha256(canonical(before)).hexdigest()
                if args.operation == 'observe': result = {'before_sha256': fingerprint, 'state_count': len(before['states']), 'source': before['source']}
                elif args.operation == 'adopt':
                    specification = bundle(absolute(args.tooling_root), args.expected_tooling_sha256)
                    workspace = absolute(args.workspace, False)
                    if workspace.is_relative_to(root) or root.is_relative_to(workspace) or args.tooling_root.is_relative_to(root) or root.is_relative_to(args.tooling_root) \
                        or workspace.is_relative_to(args.tooling_root) or args.tooling_root.is_relative_to(workspace):
                        raise ValueError('tooling adoption must be outside the instance')
                    receipt = workspace / 'adoption.json'
                    if receipt.exists():
                        saved = protected(receipt)
                        if saved['manifest_sha256'] != args.expected_tooling_sha256 or saved['before_sha256'] != args.expected_before_sha256:
                            raise ValueError('existing adoption differs from operator pins')
                        # A crash between durable receipt and atomic activation is resumable
                        # only against the exact original prestate, under both instance locks.
                        if not (workspace / 'recovered.json').exists() and fingerprint == args.expected_before_sha256:
                            bundle(workspace / 'tools', args.expected_tooling_sha256)
                            durable(pointer(root), {'receipt': str(receipt), 'sha256': sha(receipt)}, replace=True)
                        result = check(root, receipt, workspace / 'tools', True)
                    else:
                        if fingerprint != args.expected_before_sha256: raise ValueError('installed tooling prestate differs from owner approval')
                        if workspace.exists() and any(workspace.iterdir()): raise ValueError('tooling adoption workspace is not empty')
                        workspace.mkdir(parents=True, exist_ok=True, mode=0o700)
                        previous = base64.b64encode(regular(pointer(root))).decode() if pointer(root).exists() else None
                        shutil.copytree(args.tooling_root, workspace / 'tools')
                        bundle(workspace / 'tools', args.expected_tooling_sha256)
                        backup = workspace / 'recovery'; backup.mkdir(mode=0o700)
                        for name in specification['runtime_files']:
                            installed = root / ('server/docker/' + name if name.startswith('scripts/') else name)
                            if installed.is_file():
                                target = backup / name; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(regular(installed))
                        data = {'protocol': RECEIPT, 'instance_root': str(root), 'source': before['source'], 'states': before['states'],
                                'before_sha256': fingerprint, 'tools_root': str(workspace / 'tools'),
                                'manifest_sha256': args.expected_tooling_sha256, 'previous_active': previous}
                        durable(receipt, data)
                        durable(pointer(root), {'receipt': str(receipt), 'sha256': sha(receipt)}, replace=True)
                        result = check(root, receipt, workspace / 'tools', True)
                else:
                    receipt = args.receipt; data = protected(receipt)
                    check(root, receipt, Path(data['tools_root']))
                    if source_state(root) != data['source']: raise ValueError('business state changed; tooling-only recovery is forbidden')
                    current_states = states(root)
                    if any(current_states.get(path) != digest for path, digest in data['states'].items()):
                        raise ValueError('original product plan state changed')
                    for path in set(current_states) - set(data['states']):
                        state = document(Path(path))
                        if state['phase'] not in ('planned', 'blocked'): raise ValueError('new product operation prevents tooling-only recovery')
                    if data['previous_active'] is None: pointer(root).unlink()
                    else: durable(pointer(root), json.loads(base64.b64decode(data['previous_active'])), replace=True)
                    durable(receipt.parent / 'recovered.json', {'status': 'recovered', 'receipt_sha256': sha(receipt)})
                    result = {'status': 'recovered', 'scope': 'tooling-only'}
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('update-tooling: ' + str(error), file=sys.stderr); sys.exit(1)
