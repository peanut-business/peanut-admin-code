#!/usr/bin/env python3
"""Explicit, operator-pinned maintenance tooling adoption; never replaces APP files."""
import argparse
import base64
import contextlib
import fcntl
import hashlib
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


def protected(path):
    regular(path)
    if stat.S_IMODE(path.stat().st_mode) != 0o600 or path.stat().st_uid != os.geteuid():
        raise ValueError('tooling receipt must be owned by this operator with mode 0600')
    return document(path)


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


def states(root):
    result = {}
    for path in sorted((root / '.peanut/upgrades/product-state').glob('*.json')):
        state = document(path)
        if state.get('phase') not in ('planned', 'blocked', 'completed', 'recovered') or state.get('phase_in_progress') is not None:
            raise ValueError('an installed product operation is active or unknown')
        if state.get('phase') in ('planned', 'blocked') and (state.get('activation_started') or state.get('database_recovery_required')):
            raise ValueError('an installed product operation needs recovery')
        result[str(path)] = sha(path)
    return result


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
    parser.add_argument('operation', choices=('pack', 'observe', 'adopt', 'check', 'recover'))
    parser.add_argument('--source-root', type=Path); parser.add_argument('--output', type=Path)
    parser.add_argument('--php', type=Path)
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
