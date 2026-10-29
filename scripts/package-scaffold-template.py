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
import base64
import importlib.machinery
import importlib.util
import sys


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=4) + '\n').encode()


def production_environment(source, check, local_source=False, recorded_environment=None):
    required = json.loads((source / 'tools/toolchain.json').read_bytes())['tools']
    composer_pin = (source / 'scripts/project-composer').read_text()
    versions = {name: required[name]['version'] for name in ['php', 'node', 'python3']}
    versions['pnpm'] = re.fullmatch(r'pnpm@([0-9.]+)(?:\+sha[0-9]+\.[a-fA-F0-9]+)?',
                                  json.loads((source / 'web/package.json').read_bytes())['packageManager'])[1]
    versions['composer'] = re.search(r"^VERSION='([0-9.]+)'$", composer_pin, re.M)[1]
    local_tools = local_source and not os.environ.get('PEANUT_CI_PRODUCER_IDENTITY')
    if check:
        if local_tools:
            tools = (recorded_environment or {}).get('tools')
            validate_local_tool_versions(source, tools)
            return {'tools': tools}
        return {'tools': versions}
    actual = {'php': subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True).strip(),
              'node': subprocess.check_output(['node', '--version'], text=True).strip().removeprefix('v'),
              'python3': platform.python_version(),
              'pnpm': subprocess.check_output(['pnpm', '--version'], text=True).strip(),
              'composer': re.search(r'Composer version ([0-9.]+)', subprocess.check_output(
                  ['composer', '--version', '--no-ansi'], text=True))[1]}
    if local_tools:
        validate_local_tool_versions(source, actual)
    elif actual != versions:
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


def validate_local_tool_versions(source, tools):
    names = {'php', 'node', 'python3', 'pnpm', 'composer'}
    version_pattern = r'\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?'
    if (not isinstance(tools, dict) or set(tools) != names
            or any(not isinstance(value, str) or re.fullmatch(version_pattern, value) is None
                   for value in tools.values())):
        raise ValueError('TEMPLATE_BUILD_ENVIRONMENT_RECEIPT_INVALID')
    loader = importlib.machinery.SourceFileLoader('peanut_project_env', str(source / 'scripts/project-env'))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    project_env = importlib.util.module_from_spec(spec)
    previous_dont_write_bytecode = sys.dont_write_bytecode
    try:
        sys.dont_write_bytecode = True
        loader.exec_module(project_env)
    finally:
        sys.dont_write_bytecode = previous_dont_write_bytecode
    requirements, _ = project_env.requirements(source)
    for name in ('php', 'node'):
        for label, expression in requirements[name].get('manifest_constraints', []):
            if not project_env.semver_satisfies(tools[name], expression):
                raise ValueError(f'TEMPLATE_BUILD_TOOLCHAIN_CONSTRAINT_MISMATCH: {label}')


def local_core_archive_source(output, release, lock, tooling_source):
    versions = json.loads((tooling_source.parent / 'release-versions.json').read_bytes())
    core = versions.get('core_php')
    if not isinstance(core, dict) or core.get('source_type') not in {'local', 'git'}:
        return None
    identity = core.get('source_identity')
    local = core['source_type'] == 'local'
    if core.get('package') != 'peanut-admin/core' or (local and (
            not isinstance(identity, dict) or set(identity) != {'kind', 'snapshot_sha256'}
            or identity.get('kind') != 'local'
            or re.fullmatch(r'[0-9a-f]{64}', str(identity.get('snapshot_sha256', ''))) is None
            or not isinstance(release.get('release', {}).get('source_identity'), dict)
            or release['release']['source_identity'].get('kind') != 'local')):
        raise ValueError('TEMPLATE_LOCAL_CORE_SOURCE_IDENTITY_INVALID')
    packages = [item for item in lock.get('packages', []) if item.get('name') == 'peanut-admin/core']
    if len(packages) != 1:
        raise ValueError('TEMPLATE_LOCAL_CORE_LOCK_INVALID')
    version = str(packages[0].get('version', '')).removeprefix('v')
    resolved_version = core.get('resolved_version')
    constraint = core.get('constraint')
    if (re.fullmatch(r'\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?', version) is None
            or not isinstance(resolved_version, str) or resolved_version.removeprefix('v') != version
            or not isinstance(constraint, str) or constraint.removeprefix('v') != version):
        raise ValueError('TEMPLATE_LOCAL_CORE_VERSION_INVALID')
    relative = 'packages/core-php/peanut-admin-core-' + version + '.zip'
    dist = packages[0].get('dist')
    if not local:
        if not isinstance(dist, dict) or not str(dist.get('url', '')).startswith('../packages/core-php/'):
            return None
        source = packages[0].get('source')
        if (re.fullmatch(r'[0-9a-f]{40}', str(core.get('source_reference', ''))) is None
                or not isinstance(source, dict) or source.get('type') != 'git'
                or source.get('url') != core.get('source_url')
                or source.get('reference') != core['source_reference']
                or dist.get('reference') != core['source_reference']):
            raise ValueError('TEMPLATE_LOCAL_CORE_GIT_IDENTITY_INVALID')
    if (not isinstance(dist, dict) or dist.get('type') != 'zip'
            or dist.get('url') != '../' + relative
            or not isinstance(dist.get('shasum'), str)
            or re.fullmatch(r'[0-9a-f]{40}', dist['shasum']) is None):
        raise ValueError('TEMPLATE_LOCAL_CORE_DIST_INVALID')
    entries = [item for item in release.get('files', []) if item.get('path') == relative]
    if len(entries) != 1:
        raise ValueError('TEMPLATE_LOCAL_CORE_ARCHIVE_MANIFEST_INVALID')
    entry = entries[0]
    mode = entry.get('mode')
    if (entry.get('source') != 'files/' + relative
            or not isinstance(entry.get('template_sha256'), str)
            or re.fullmatch(r'[0-9a-f]{64}', entry['template_sha256']) is None
            or mode not in (0o644, 0o755)):
        raise ValueError('TEMPLATE_LOCAL_CORE_ARCHIVE_MANIFEST_INVALID')
    source = output / 'files' / relative
    current = output
    for part in ('files', *pathlib.PurePosixPath(relative).parts):
        current = current / part
        if current.is_symlink():
            raise ValueError('TEMPLATE_LOCAL_CORE_ARCHIVE_PATH_INVALID')
    resolved = source.resolve(strict=True)
    if (not resolved.is_relative_to(output) or not resolved.is_file()
            or resolved.stat().st_mode & 0o777 != mode):
        raise ValueError('TEMPLATE_LOCAL_CORE_ARCHIVE_PATH_INVALID')
    content = resolved.read_bytes()
    if (digest(content) != entry['template_sha256']
            or hashlib.sha1(content).hexdigest() != dist['shasum']):
        raise ValueError('TEMPLATE_LOCAL_CORE_ARCHIVE_DIGEST_MISMATCH')
    return relative, content, mode


def sanitize_process_detail(detail):
    for name, value in os.environ.items():
        if value and re.search(r'(?:TOKEN|PASSWORD|SECRET|CREDENTIAL|AUTH)', name, re.I):
            detail = detail.replace(value, '[REDACTED]')
    detail = re.sub(r'(?i)(https?://)[^/@\s]+@', r'\1[REDACTED]@', detail)
    detail = re.sub(r'(?i)([?&](?:access_token|token|password|secret|key)=)[^&\s]+', r'\1[REDACTED]', detail)
    return detail


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
    if release['protocol'] not in ['peanut.scaffold-release.v3', 'peanut.scaffold-release.v4']:
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
    source_identity = release['release'].get('source_identity')
    build_inputs_path = output / 'scaffold-build-inputs.json'
    build_inputs = json.loads(build_inputs_path.read_bytes()) if args.check else None
    environment_identity = production_environment(
        tooling_source.parent, args.check,
        isinstance(source_identity, dict) and source_identity.get('kind') == 'local',
        (build_inputs or {}).get('environment'),
    )
    snapshot_proof_bytes = None
    if source_identity is not None and source_identity.get('kind') == 'local':
        if any(key in release['release'] for key in ['source_commit', 'source_tree']):
            raise ValueError('TEMPLATE_SOURCE_IDENTITY_MIXED')
        snapshot_proof_bytes = subprocess.check_output([
            'php', '-r',
            'require $argv[1]; echo json_encode(\\app\\common\\value\\scaffold\\ScaffoldSourceIdentity::proof($argv[2]), JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);',
            str(tooling_source.parent / 'scripts/scaffold-runtime/ScaffoldSourceIdentity.php'), str(tooling_source.parent)])
        proof = json.loads(snapshot_proof_bytes)
        if (proof['source_identity'] != source_identity
                or manifest['template'].get('source_identity') != source_identity
                or manifest['generation_source'].get('source_identity') != source_identity
                or digest(base64.b64decode(proof['inventory_base64'], validate=True)) != release['release']['inventory_sha256']
                or digest(base64.b64decode(proof['edition_profile_base64'], validate=True)) != manifest['generation_source']['edition_profile_sha256']):
            raise ValueError('TEMPLATE_SOURCE_SNAPSHOT_MISMATCH')
    elif source_identity is not None and source_identity.get('kind') != 'git':
        raise ValueError('TEMPLATE_SOURCE_IDENTITY_INVALID')
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
            temporary_root = pathlib.Path(temporary)
            server = temporary_root / 'server'
            server.mkdir()
            for name, content in tooling_inputs.items():
                (server / name).write_bytes(content)
            module_nodes = [package for package in lock['packages'] if package.get('dist', {}).get('type') == 'path']
            if module_nodes:
                loader = importlib.machinery.SourceFileLoader(
                    'peanut_template_vendor_state', str(tooling_source / 'docker/scripts/vendor-state.py'))
                spec = importlib.util.spec_from_loader(loader.name, loader)
                vendor_state = importlib.util.module_from_spec(spec)
                previous_bytecode = sys.dont_write_bytecode
                try:
                    sys.dont_write_bytecode = True
                    loader.exec_module(vendor_state)
                finally:
                    sys.dont_write_bytecode = previous_bytecode
                modules = vendor_state.module_packages(tooling_source)
                for package in module_nodes:
                    if not vendor_state.native_module(package, modules):
                        raise ValueError('TEMPLATE_TOOLING_MODULE_ORIGIN_INVALID')
                    relative = modules[package['name']]['url']
                    source = tooling_source / relative
                    destination = server / relative
                    destination.mkdir(parents=True)
                    shutil.copyfile(source / 'composer.json', destination / 'composer.json')
                    for path in (source / 'src').rglob('*'):
                        if (path.is_symlink() or not (path.is_file() or path.is_dir())
                                or any(part in {'vendor', 'node_modules', 'private', 'credentials'}
                                       for part in path.relative_to(source).parts)
                                or path.name == '.env' or path.name.startswith('.env.')):
                            raise ValueError('TEMPLATE_TOOLING_MODULE_SOURCE_INVALID')
                    shutil.copytree(source / 'src', destination / 'src')
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
            local_archive = local_core_archive_source(output, release, lock, tooling_source)
            if local_archive is not None:
                relative, content, mode = local_archive
                archive = temporary_root / 'packages' / pathlib.PurePosixPath(relative).relative_to('packages')
                archive.parent.mkdir(parents=True, exist_ok=True)
                if archive.exists() or archive.is_symlink():
                    raise ValueError('TEMPLATE_LOCAL_CORE_ARCHIVE_TEMP_COLLISION')
                archive.write_bytes(content)
                archive.chmod(mode)
            environment = {key: value for key, value in os.environ.items() if key != 'COMPOSER' and not key.startswith('COMPOSER_')}
            environment['COMPOSER_HOME'] = str(server / 'composer-home')
            result = subprocess.run(['composer', '--no-plugins', '--no-scripts', '--working-dir', str(server),
                                     'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress'],
                                    env=environment, capture_output=True)
            if result.returncode:
                detail = sanitize_process_detail('\n'.join(
                    item.decode(errors='replace').strip() for item in (result.stdout, result.stderr) if item
                ))[-5000:]
                raise ValueError('TEMPLATE_TOOLING_LOCKED_INSTALL_FAILED: exit ' + str(result.returncode)
                                 + (': ' + detail if detail else ''))
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
    if snapshot_proof_bytes is not None:
        proof['payload_files'] = [{key: entry[key] for key in ['path', 'mode', 'sha256', 'classification']} for entry in files]
        snapshot_proof_bytes = encoded(proof)
        members['source-snapshot.json'] = (snapshot_proof_bytes, 0o644)
        snapshot_path = output / 'source-snapshot.json'
        if args.check:
            if snapshot_path.read_bytes() != snapshot_proof_bytes:
                raise ValueError('TEMPLATE_SOURCE_SNAPSHOT_CHANGED')
        else:
            with snapshot_path.open('xb') as snapshot_file:
                snapshot_file.write(snapshot_proof_bytes)
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
    if snapshot_proof_bytes is not None:
        package['source_snapshot_sha256'] = digest(snapshot_proof_bytes)
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
        receipt = build_inputs
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
