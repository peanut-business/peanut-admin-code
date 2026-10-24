#!/usr/bin/env python3
"""Serialize the native scaffold projection and its locked upgrade tooling."""
import argparse
import gzip
import hashlib
import io
import json
import pathlib
import re
import os
import subprocess
import tempfile
import tarfile
import shutil
import platform
import zlib


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=4) + '\n').encode()


def production_environment(source, check):
    required = json.loads((source / 'tools/toolchain.json').read_bytes())['tools']
    composer_pin = (source / 'scripts/project-composer').read_text()
    versions = {name: required[name]['version'] for name in ['php', 'node', 'python3']}
    versions['pnpm'] = re.fullmatch(r'pnpm@([0-9.]+)(?:\+sha[0-9]+\.[a-fA-F0-9]+)?',
                                  json.loads((source / 'web/package.json').read_bytes())['packageManager'])[1]
    versions['composer'] = re.search(r"^VERSION='([0-9.]+)'$", composer_pin, re.M)[1]
    if check:
        return {'tools': versions}
    actual = {'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True).strip(),
              'node': subprocess.check_output(['node', '--version'], text=True).strip().removeprefix('v'),
              'python3': platform.python_version(),
              'pnpm': subprocess.check_output(['pnpm', '--version'], text=True).strip(),
              'composer': re.search(r'Composer version ([0-9.]+)', subprocess.check_output(
                  ['composer', '--version', '--no-ansi'], text=True))[1]}
    if actual != versions:
        raise ValueError('TEMPLATE_BUILD_TOOLCHAIN_MISMATCH')
    environment = {'tools': actual, 'system': platform.system(), 'architecture': platform.machine(),
                   'zlib_compile': zlib.ZLIB_VERSION, 'zlib_runtime': zlib.ZLIB_RUNTIME_VERSION}
    if os.environ.get('PEANUT_CI_PRODUCER_IDENTITY'):
        identity_path = pathlib.Path(os.environ['PEANUT_CI_PRODUCER_IDENTITY'])
        if identity_path.is_symlink() or identity_path.stat().st_nlink != 1:
            raise ValueError('TEMPLATE_BUILD_CI_IDENTITY_INVALID')
        identity = json.loads(identity_path.read_bytes())
        if identity['actual']['tools'] != actual:
            raise ValueError('TEMPLATE_BUILD_CI_TOOLCHAIN_MISMATCH')
        environment['ci_toolchain'] = identity
    return environment


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--application', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--tooling-source', required=True)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    app = pathlib.Path(args.application).resolve(strict=True)
    output = pathlib.Path(args.output).resolve(strict=True)
    release_bytes = (output / 'scaffold-manifest.json').read_bytes()
    release = json.loads(release_bytes)
    manifest = json.loads((app / '.peanut/application-manifest.json').read_bytes())
    if release['protocol'] != 'peanut.scaffold-release.v3':
        raise ValueError('TEMPLATE_RELEASE_PROTOCOL_INVALID')
    projection = release.get('internal_core_projection')
    if projection is not None and (projection.get('protocol') != 'peanut.internal-core-scaffold-projection.v1'
            or projection.get('scope') != 'internal-upgrade-target-not-formal-release'
            or manifest.get('scaffold_candidate') != {
                'manifest_sha256': digest(release_bytes),
                'core_inputs_sha256': projection.get('core_inputs_sha256'),
                'formal_release_eligible': False}):
        raise ValueError('TEMPLATE_CANDIDATE_IDENTITY_INVALID')
    if 'workflow' in release['release'] and release['release']['workflow'] != manifest['application']['workflow']:
        raise ValueError('TEMPLATE_WORKFLOW_IDENTITY_MISMATCH')
    tokens = release['release']['tokens']
    members = {'scaffold-manifest.json': (release_bytes, 0o644)}
    tooling_source = pathlib.Path(args.tooling_source).resolve(strict=True)
    environment_identity = production_environment(tooling_source.parent, args.check)
    tooling_inputs = {name: (tooling_source / name).read_bytes() for name in ['composer.json', 'composer.lock']}
    lock = json.loads(tooling_inputs['composer.lock'])
    def tooling_identity(package):
        return {key: package.get(key) for key in ['name', 'version', 'source', 'dist']}
    expected_packages = sorted(map(tooling_identity, lock['packages']), key=lambda row: row['name'])
    if args.check:
        # Verify the fixed tool payload, without reinstalling dependencies during --check.
        with tarfile.open(output / ('peanut-scaffold-' + manifest['application']['edition'] + '.tgz'), 'r:gz') as tar:
            for item in tar:
                if item.name.startswith('tooling/') or item.name == 'upgrade-tooling.json':
                    if not item.isfile() or item.name in members:
                        raise ValueError('TEMPLATE_TOOLING_MEMBER_INVALID')
                    members[item.name] = (tar.extractfile(item).read(), item.mode)
    else:
        with tempfile.TemporaryDirectory(prefix='peanut-upgrade-tooling-') as temporary:
            server = pathlib.Path(temporary)
            for name, content in tooling_inputs.items():
                (server / name).write_bytes(content)
            composer = json.loads(tooling_inputs['composer.json'])
            if composer.get('autoload', {}).get('files'):
                raise ValueError('TEMPLATE_TOOLING_ROOT_AUTOLOAD_FILES_UNSUPPORTED')
            for relative in composer.get('autoload', {}).get('classmap', []):
                original = (tooling_source / relative).resolve(strict=True)
                if not original.is_relative_to(tooling_source) or original.is_symlink():
                    raise ValueError('TEMPLATE_TOOLING_CLASSMAP_SOURCE_INVALID')
                destination = server / relative
                if original.is_dir():
                    shutil.copytree(original, destination)
                else:
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(original, destination)
            environment = {key: value for key, value in os.environ.items() if key != 'COMPOSER' and not key.startswith('COMPOSER_')}
            environment['COMPOSER_HOME'] = str(server / 'composer-home')
            result = subprocess.run(['composer', '--no-plugins', '--no-scripts', '--working-dir', str(server),
                                     'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress'],
                                    env=environment, capture_output=True)
            if result.returncode:
                raise ValueError('TEMPLATE_TOOLING_LOCKED_INSTALL_FAILED: exit ' + str(result.returncode))
            for file in sorted((server / 'vendor').rglob('*')):
                if file.is_symlink():
                    raise ValueError('TEMPLATE_TOOLING_LINK_FORBIDDEN')
                if file.is_file():
                    members['tooling/' + file.relative_to(server).as_posix()] = (file.read_bytes(), 0o755 if file.stat().st_mode & 0o111 else 0o644)
            for name, content in tooling_inputs.items():
                members['tooling/' + name] = (content, 0o644)
    for name, content in tooling_inputs.items():
        if members.get('tooling/' + name) != (content, 0o644):
            raise ValueError('TEMPLATE_TOOLING_LOCK_CHANGED')
    installed = json.loads(members['tooling/vendor/composer/installed.json'][0])
    if installed['dev'] is not False or sorted(map(tooling_identity, installed['packages']), key=lambda row: row['name']) != expected_packages:
        raise ValueError('TEMPLATE_TOOLING_INSTALLED_LOCK_MISMATCH')
    tool_files = [{'path': name, 'sha256': digest(content), 'mode': mode}
                  for name, (content, mode) in sorted(members.items()) if name.startswith('tooling/')]
    tooling = {'protocol': 'peanut.scaffold-upgrade-tooling.v1', 'autoload': 'tooling/vendor/autoload.php',
               'composer_lock_sha256': digest(tooling_inputs['composer.lock']), 'packages': expected_packages, 'files': tool_files}
    tooling_bytes = encoded(tooling)
    if args.check and members.get('upgrade-tooling.json') != (tooling_bytes, 0o644):
        raise ValueError('TEMPLATE_TOOLING_MANIFEST_CHANGED')
    members['upgrade-tooling.json'] = (tooling_bytes, 0o644)
    files = []
    frozen = re.compile(r'^(?:server/app/modules|(?:web|platform|uniapp)/src/modules|pc/modules)/')
    for entry in manifest['files']:
        if entry['classification'] == 'excluded':
            continue
        relative = entry['path']
        file = app / relative
        if file.is_symlink() or not file.is_file() or not file.resolve().is_relative_to(app):
            raise ValueError('TEMPLATE_FILE_INVALID: ' + relative)
        content = file.read_bytes()
        if digest(content) != entry['sha256']:
            raise ValueError('TEMPLATE_FILE_CHANGED: ' + relative)
        is_frozen = bool(frozen.match(relative)) or relative == 'plugins.lock' or relative.startswith('plugins/')
        has_tokens = any(value.encode() in content for value in tokens.values())
        if is_frozen and (has_tokens or re.search(rb'\{\{(?:PRODUCT_NAME|SLUG|PACKAGE_IDENTITY|APPLICATION_VERSION)\}\}', content)):
            raise ValueError('TEMPLATE_MODULE_IDENTITY_MUTABLE: ' + relative)
        source = ('defaults/' if entry['classification'] == 'app-owned' else 'files/') + relative
        mode = entry['mode']
        if mode not in (0o644, 0o755):
            raise ValueError('TEMPLATE_MODE_INVALID: ' + relative)
        if source in members:
            raise ValueError('TEMPLATE_DUPLICATE_MEMBER')
        members[source] = (content, mode)
        files.append({'path': relative, 'source': source, 'sha256': digest(content), 'mode': mode,
                      'classification': entry['classification'], 'render': has_tokens and not is_frozen})
    managed = {entry['path']: entry for entry in release['files']}
    for entry in files:
        if entry['classification'] != 'app-owned' and managed[entry['path']]['template_sha256'] != entry['sha256']:
            raise ValueError('TEMPLATE_UPGRADE_PAYLOAD_MISMATCH')
    directories = []
    for relative in ['server/runtime/cache', 'server/runtime/log', 'server/runtime/session',
                     'server/runtime/temp', 'server/runtime/storage', 'server/runtime/generator',
                     'server/runtime/file', 'server/private/installation', 'server/private/resources/pending',
                     'server/public/storage', 'server/private/storage']:
        if not (app / relative).is_dir():
            raise ValueError('TEMPLATE_WRITABLE_DIRECTORY_MISSING')
        directories.append(relative)
    create = {'schema_version': 1, 'protocol': 'peanut.template-create.v1',
              'tokens': tokens, 'application_manifest': manifest, 'files': files,
              'directories': directories, 'scaffold_manifest_sha256': digest(release_bytes),
              'derived': {'composer_lock': 'server/composer.lock', 'api_catalog': 'server/generated/api-catalog.json'}}
    members['template-create.json'] = (encoded(create), 0o644)
    edition = manifest['application']['edition']
    name = 'peanut-scaffold-' + edition
    archive = output / (name + '.tgz')
    if args.check:
        seen = set()
        with tarfile.open(archive, 'r:gz') as tar:
            for item in tar:
                expected = members.get(item.name)
                if item.name in seen or expected is None or not item.isfile() or item.mode != expected[1] or item.size != len(expected[0]):
                    raise ValueError('TEMPLATE_ARCHIVE_MEMBER_INVALID: ' + item.name)
                if tar.extractfile(item).read() != expected[0]:
                    raise ValueError('TEMPLATE_ARCHIVE_MEMBER_CHANGED: ' + item.name)
                seen.add(item.name)
        if seen != set(members):
            raise ValueError('TEMPLATE_ARCHIVE_MEMBER_MISSING')
    else:
        with archive.open('xb') as raw:
            with gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as compressed:
                with tarfile.open(fileobj=compressed, mode='w', format=tarfile.USTAR_FORMAT) as tar:
                    for relative, (content, mode) in sorted(members.items()):
                        item = tarfile.TarInfo(relative)
                        item.size, item.mode, item.mtime = len(content), mode, 0
                        tar.addfile(item, io.BytesIO(content))
    package = {'schema_version': 1, 'protocol': 'peanut.template-package.v1', 'kind': 'scaffold-template',
               'version': release['release']['version'], 'edition': edition,
               'profile': manifest['application']['profile'], 'workflow': manifest['application']['workflow'],
               'source': manifest['generation_source'],
               'archive': {'name': archive.name, 'bytes': archive.stat().st_size, 'sha256': digest(archive.read_bytes())},
               'create_manifest_sha256': digest(members['template-create.json'][0]),
               'upgrade_tooling_sha256': digest(tooling_bytes),
               'scaffold_manifest_sha256': digest(release_bytes)}
    metadata = output / (name + '.json')
    if args.check:
        if metadata.read_bytes() != encoded(package):
            raise ValueError('TEMPLATE_PACKAGE_MANIFEST_CHANGED')
    else:
        metadata.write_bytes(encoded(package))

    # Keep the actual production environment outside the portable delivery bytes.
    # Qualification binds this receipt together with the four final attachments.
    source = tooling_source.parent
    input_paths = ['release-versions.json', 'scaffold/application-template-inventory.json',
                   'server/composer.json', 'server/composer.lock', 'web/package.json', 'web/pnpm-lock.yaml',
                   'platform/package.json', 'platform/package-lock.json', 'pc/package.json', 'pc/package-lock.json',
                   'uniapp/package.json', 'uniapp/package-lock.json',
                   'tools/toolchain.json', 'scripts/project-composer', 'scripts/build-scaffold-release',
                   'scripts/package-scaffold-template.py', 'scripts/generate-api-contracts.php']
    inputs = {name: digest((source / name).read_bytes()) for name in input_paths}
    artifacts = {file.name: {'bytes': file.stat().st_size, 'sha256': digest(file.read_bytes())}
                 for file in [archive, metadata, output / 'scaffold-manifest.json']}
    payload = digest(encoded([{'path': name, 'mode': mode, 'sha256': digest(content)}
                              for name, (content, mode) in sorted(members.items())]))
    receipt_path = output / 'scaffold-build-inputs.json'
    identity = {'protocol': 'peanut.scaffold-build-inputs.v1', 'source': manifest['generation_source'],
                'inputs': inputs, 'artifacts': artifacts, 'payload_sha256': payload}
    if args.check:
        receipt = json.loads(receipt_path.read_bytes())
        if ({key: receipt.get(key) for key in identity} != identity
                or receipt.get('environment', {}).get('tools') != environment_identity['tools']
                or not all(isinstance(receipt['environment'].get(key), str) and receipt['environment'][key]
                           for key in ['system', 'architecture', 'zlib_compile', 'zlib_runtime'])):
            raise ValueError('TEMPLATE_BUILD_INPUTS_CHANGED')
    else:
        identity['environment'] = environment_identity
        with receipt_path.open('xb') as receipt:
            receipt.write(encoded(identity))


if __name__ == '__main__':
    main()
