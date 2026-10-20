#!/usr/bin/env python3
"""发行包文件装配：复用应用清单/基线，只分发源码和浏览器资产，不复制安装依赖。"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tarfile
from pathlib import Path, PurePosixPath

CLIENTS = ('web', 'platform', 'pc', 'uniapp')
LOCKS = {'web': 'pnpm-lock.yaml', 'platform': 'package-lock.json',
         'pc': 'package-lock.json', 'uniapp': 'package-lock.json'}
MANIFEST = '.peanut/application-manifest.json'
FORBIDDEN = {'vendor', 'node_modules', '.git', '.local', '.cache', '.nuxt', '.output',
             'coverage', '.pnpm-store'}
VERSION = re.compile(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\Z')
CLIENT_ENVS = {f'{client}/.env.{suffix}' for client in CLIENTS
               for suffix in ('example', 'development', 'production', 'standalone', 'multi-tenant')}
RELEASE_OUTPUTS = {'.peanut/application-release.json', 'release-manifest.txt'}
SERVER_RELEASE_TOOLS = {
    'scripts/upgrade': ('docker/scripts/upgrade', 0o755),
    'scripts/product-upgrade-host': ('docker/scripts/product-upgrade-host', 0o755),
    'scripts/scaffold-runtime/ScaffoldPathGuard.php':
        ('docker/scripts/scaffold-runtime/ScaffoldPathGuard.php', 0o644),
    'scripts/ops-backup-worker': ('docker/scripts/ops-backup-worker', 0o755),
    'scripts/ops-restore-worker': ('docker/scripts/ops-restore-worker', 0o755),
}
SERVER_RELEASE_OUTPUTS = {
    'server/plugins.lock', 'server/resources/project-resources.json',
    *(f'server/.peanut/{name}' for name in (
        'release-identity.json', 'internal-candidate.json', 'RELEASE_METADATA.json', 'release-versions.json',
        'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md', 'RELEASE_SBOM.spdx.json')),
    *(f'server/{relative}' for relative, _ in SERVER_RELEASE_TOOLS.values()),
}
BROWSER_RELEASE_PREFIXES = tuple(f'server/public/{name}/' for name in ('admin', 'platform', 'pc', 'mobile'))
PROTECTED_RUNTIME_PREFIXES = (
    'server/runtime/', 'server/public/storage/', 'server/private/storage/',
    'server/private/installation/', 'server/private/resources/', 'server/public/uploads/',
    'server/docker/mysql/', 'server/docker/secrets/', 'updates/', 'backups/',
)
PLACEHOLDER_PREFIXES = {
    'server/runtime/', 'server/public/storage/', 'server/private/storage/',
    'server/docker/mysql/', 'server/docker/secrets/',
}


def document(root: Path, relative: str) -> dict:
    value = json.loads(regular_file(root, relative).read_text())
    if not isinstance(value, dict):
        raise ValueError(f'JSON object required: {relative}')
    return value


def safe_relative(value: str) -> str:
    if not isinstance(value, str) or not value or '\\' in value or any(ord(c) < 32 for c in value):
        raise ValueError('invalid release path')
    path = PurePosixPath(value)
    if path.is_absolute() or any(x in ('.', '..') for x in value.split('/')):
        raise ValueError(f'unsafe release path: {value}')
    if str(path) != value:
        raise ValueError(f'non-canonical release path: {value}')
    return value


def regular_file(root: Path, relative: str) -> Path:
    path = root
    for part in PurePosixPath(safe_relative(relative)).parts:
        path = path / part
        if path.is_symlink():
            raise ValueError(f'symlink is not a release input: {relative}')
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError(f'non-regular or hard-linked release input: {relative}')
    return path


def reject_symlinked_path(root: Path, relative: str = '') -> Path:
    root = root.absolute()
    if '..' in root.parts:
        raise ValueError('non-canonical browser asset root')
    candidate = root / safe_relative(relative) if relative else root
    current = Path(candidate.anchor)
    for part in candidate.parts[1:]:
        current /= part
        if current.is_symlink():
            raise ValueError(f'symlink is not a release input or output: {candidate}')
    return candidate


def reject_nested_paths(parent: Path, child: Path, message: str) -> None:
    parent = parent.resolve(strict=True)
    child = child.resolve(strict=False)
    if child == parent or parent in child.parents:
        raise ValueError(message)


def ensure_new_release_file(path: Path) -> None:
    parts = path.parts
    current = Path(parts[0])
    for index, part in enumerate(parts[1:], start=1):
        parent = current
        current = current / part
        if parent.exists():
            if not parent.is_dir():
                raise ValueError(f'browser asset target path conflicts with a file: {path}')
            for existing in parent.iterdir():
                if existing.name.casefold() == part.casefold() and existing.name != part:
                    raise ValueError(f'browser asset target has a case-colliding path: {path}')
        if current.is_symlink():
            raise ValueError(f'browser asset target contains a symlink: {path}')
        if index < len(parts) - 1 and current.exists() and not current.is_dir():
            raise ValueError(f'browser asset target path conflicts with a file: {path}')
    if path.exists():
        raise ValueError(f'browser asset conflicts with managed source: {path}')


def logical_release_path(relative: str) -> str:
    logical = relative
    if relative.startswith('.peanut/scaffold-baseline/') and '/files/' in relative:
        logical = relative.split('/files/', 1)[1]
    return logical


def release_generated_source(relative: str) -> bool:
    logical = logical_release_path(relative)
    return (logical in RELEASE_OUTPUTS or logical in SERVER_RELEASE_OUTPUTS
            or re.fullmatch(r'server/plugins/[a-z][a-z0-9.-]*/plugin\.json', logical) is not None
            or any(logical.startswith(prefix) for prefix in BROWSER_RELEASE_PREFIXES))


def allowed_source(relative: str) -> bool:
    parts = PurePosixPath(relative).parts
    logical = logical_release_path(relative)
    name = PurePosixPath(logical).name
    if logical.startswith('scaffold/') or release_generated_source(logical):
        return False
    if FORBIDDEN.intersection(parts) or name in {'auth.json', '.npmrc', '.DS_Store'}:
        return False
    if name.endswith(('.tgz', '.phar', '.pem', '.key', '.p12', '.pfx', '.log')):
        return False
    if name.startswith('.env') and name != '.env.example' and logical not in CLIENT_ENVS:
        return False
    for prefix in PROTECTED_RUNTIME_PREFIXES:
        if logical.startswith(prefix):
            return prefix in PLACEHOLDER_PREFIXES and name in {'.gitkeep', '.gitignore'}
    return True


def released_dependencies(root: Path) -> dict:
    versions = document(root, 'release-versions.json')
    product = versions.get('source_product_version', '')
    if not isinstance(product, str) or not VERSION.fullmatch(product) or re.search(r'(?:^|[.-])dev(?:[.+-]|$)', product, re.I):
        raise ValueError('prepare an explicit fixed product version before packaging')
    manifest = document(root, 'server/composer.json')
    core = manifest.get('require', {}).get('peanut-admin/core', '')
    if not isinstance(core, str) or not VERSION.fullmatch(core) or re.search(r'(?:^|[.-])dev(?:[.+-]|$)', core, re.I):
        raise ValueError('PHP Core must use an exact published version, not dev/path/link')
    identity = versions.get('core_php', {})
    if identity.get('constraint') != core or identity.get('resolved_version', '').lstrip('v') != core:
        raise ValueError('PHP Core declaration and product identity disagree')
    lock = document(root, 'server/composer.lock')
    matches = [p for p in lock.get('packages', []) if p.get('name') == 'peanut-admin/core']
    if len(matches) != 1 or matches[0].get('version', '').lstrip('v') != core:
        raise ValueError('PHP Core lock does not match the exact version')
    if matches[0].get('source', {}).get('reference') != identity.get('source_reference'):
        raise ValueError('PHP Core source identity differs from its native lock')
    for repo in manifest.get('repositories', []):
        if isinstance(repo, dict) and repo.get('type') == 'path':
            raise ValueError('local Composer path repository cannot be distributed')
    for package in lock.get('packages', []) + lock.get('packages-dev', []):
        if package.get('dist', {}).get('type') == 'path':
            raise ValueError('local Composer path dependency cannot be distributed')
    for client in CLIENTS:
        package = document(root, f'{client}/package.json')
        regular_file(root, f'{client}/{LOCKS[client]}')
        for section in ('dependencies', 'devDependencies', 'optionalDependencies'):
            for name, specifier in package.get(section, {}).items():
                if not isinstance(specifier, str) or specifier.startswith(('file:', 'link:', 'workspace:', '/', '../', './')):
                    raise ValueError(f'non-portable dependency: {client}:{name}')
                if name.startswith('@peanut-admin/'):
                    expected = versions.get('core_web', {}).get('packages', {}).get(name, {}).get('version')
                    if not VERSION.fullmatch(specifier) or expected != specifier or re.search(r'(?:^|[.-])dev(?:[.+-]|$)', specifier, re.I):
                        raise ValueError(f'Web Core must use matching exact versions: {client}:{name}')
    # Registry availability and all native lock semantics are subsequently checked
    # by actual frozen installs, not inferred from the version strings above.
    return versions


def copy_checked(root: Path, target: Path, relative: str, digest: str, mode: int,
                 git_blob: str | None = None, git_hash: str = 'sha1',
                 approved_archives: set[str] | None = None) -> None:
    if not allowed_source(relative) and relative not in (approved_archives or set()):
        raise ValueError(f'installed dependency, secret or runtime data in manifest: {relative}')
    source = regular_file(root, relative)
    data = source.read_bytes()
    if not isinstance(digest, str) or hashlib.sha256(data).hexdigest() != digest:
        raise ValueError(f'source/baseline has changed: {relative}')
    if git_blob is not None:
        hashed = hashlib.new(git_hash)
        hashed.update(b'blob ' + str(len(data)).encode() + b'\0' + data)
        if hashed.hexdigest() != git_blob:
            raise ValueError(f'application file differs from committed Git blob: {relative}')
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open('xb') as output:
        output.write(data)
    destination.chmod(mode)


def application_git(root: Path, manifest: dict, generated_template: bool) -> tuple[str | None, str | None, list[str], dict[str, str], str]:
    if generated_template:
        if (root / '.git').exists() or (root / '.git').is_symlink():
            raise ValueError('generated-template mode cannot package an APP Git worktree')
        paths = [safe_relative(item['path']) for item in manifest['files']]
        for item in manifest['files']:
            relative = item['path']
            if hashlib.sha256(regular_file(root, relative).read_bytes()).hexdigest() != item['sha256']:
                raise ValueError(f'generated template was changed after creation: {relative}')
            if item.get('classification') in ('managed', 'generated-managed'):
                paths.append(safe_relative(item['baseline_path']))
        paths.append(MANIFEST)
        expected = set(paths)
        actual = set()
        for path in root.rglob('*'):
            if path.is_symlink():
                raise ValueError('generated template contains a symlink')
            if path.is_file():
                actual.add(path.relative_to(root).as_posix())
        if actual != expected:
            raise ValueError('generated template contains added or missing source files')
        generation = manifest.get('generation_source', {})
        template = manifest.get('template', {})
        if (not re.fullmatch(r'[a-f0-9]{40}', str(generation.get('commit', '')))
                or not re.fullmatch(r'[a-f0-9]{40}', str(generation.get('tree', '')))
                or not re.fullmatch(r'[a-f0-9]{64}', str(generation.get('inventory_sha256', '')))
                or generation.get('commit') != template.get('source_commit')
                or generation.get('tree') != template.get('source_tree')
                or generation.get('inventory_sha256') != template.get('inventory_sha256')):
            raise ValueError('generated template source and baseline identities disagree')
        return None, None, sorted(set(paths)), {}, 'sha1'

    def run(*arguments: str) -> bytes:
        result = subprocess.run(['git', '-C', str(root), *arguments], capture_output=True, check=True)
        return result.stdout.strip()

    if Path(run('rev-parse', '--show-toplevel').decode()).resolve() != root:
        raise ValueError('application root must be its own Git worktree')
    if run('status', '--porcelain=v1', '--untracked-files=all'):
        raise ValueError('application release requires a clean committed Git worktree')
    commit = run('rev-parse', 'HEAD^{commit}').decode()
    tree = run('rev-parse', 'HEAD^{tree}').decode()
    git_hash = run('rev-parse', '--show-object-format').decode()
    if git_hash not in ('sha1', 'sha256'):
        raise ValueError('unsupported application Git object format')
    blobs = {}
    for item in run('ls-files', '--stage', '-z').split(b'\0'):
        if not item:
            continue
        metadata, name = item.split(b'\t', 1)
        mode, blob, stage = metadata.decode().split(' ')
        if mode not in ('100644', '100755') or stage != '0':
            raise ValueError('application release has a non-regular Git entry')
        blobs[name.decode()] = blob
    tracked = sorted(name for name in blobs if not release_generated_source(safe_relative(name)))
    if not tracked or any(not allowed_source(safe_relative(name)) for name in tracked):
        raise ValueError('application Git tree contains forbidden release input')
    for name in tracked:
        regular_file(root, name)
    return commit, tree, tracked, blobs, git_hash


def validate_release_metadata(source: Path, manifest: dict, versions: dict) -> None:
    metadata = document(source, 'RELEASE_METADATA.json')
    application = manifest.get('application', {})
    if metadata.get('source_product_version') != versions.get('source_product_version') \
            or metadata.get('instance_version') != application.get('version') \
            or metadata.get('application_identity') != application.get('package_identity'):
        raise ValueError('application release metadata differs from application identity')


def internal_contract():
    # Lazy import: the contract imports this module's file policy itself.
    import importlib.util
    import sys
    sys.dont_write_bytecode = True
    spec = importlib.util.spec_from_file_location('internal_candidate_contract', Path(__file__).with_name('internal_candidate_contract.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def snapshot(root: Path, target: Path, generated_template: bool, internal: bool = False,
             candidate_scaffold: Path | None = None, candidate_core: Path | None = None) -> tuple[dict, dict]:
    if target.exists() or target.is_symlink():
        raise ValueError('snapshot destination must not exist')
    root = root.resolve(strict=True)
    manifest = document(root, MANIFEST)
    if not internal and ((root / '.peanut/internal-candidate.json').exists()
                         or (root / '.peanut/internal-candidate.json').is_symlink()):
        raise ValueError('internal candidate must retain its explicit candidate packaging mode')
    sealed = None
    artifact_marker = None
    artifact_proof_bytes = None
    approved_archives = set()
    if internal:
        contract = internal_contract()
        if not (root / contract.MARKER).exists() and not (root / contract.MARKER).is_symlink():
            if candidate_scaffold is None or candidate_core is None:
                raise ValueError('artifact-derived APP needs its actual candidate scaffold manifest and Core input receipt')
            artifact_marker, artifact_proof_bytes = contract.artifact_source(root, candidate_scaffold, candidate_core)
            sealed = {'source_files': artifact_marker['source_files'],
                      'archive_paths': sorted(contract.archives(artifact_marker))}
            marker = artifact_marker
            tracked = sorted(sealed['source_files'])
        else:
            sealed = contract.verify(root)
            marker = document(root, contract.MARKER)
            tracked = sorted([*sealed['source_files'], contract.MARKER])
            if marker['projection'].get('changes') == [] and marker['projection'].get('additions') == []:
                proof = contract.artifact_proofs(root, candidate_scaffold, candidate_core)
                artifact_proof_bytes = proof['raw']
            elif candidate_scaffold is not None or candidate_core is not None:
                raise ValueError('artifact proof arguments require artifact-derived candidate source')
        if len(marker.get('core_web', {})) != 6:
            raise ValueError('complete internal APP requires seven inputs')
        commit, tree, blobs, git_hash = None, None, {}, 'sha1'
        approved_archives.update(sealed['archive_paths'])
        # Original archive baselines are immutable and independently checked below.
        approved_archives.update(row['baseline_path'] for row in manifest['files']
                                 if row.get('baseline_path') and row['path'].endswith('.tgz'))
    else:
        commit, tree, tracked, blobs, git_hash = application_git(root, manifest, generated_template)
    if MANIFEST not in tracked:
        raise ValueError('application manifest is not committed')
    if manifest.get('protocol') != 'peanut.application-scaffold.v2' or not manifest.get('files'):
        raise ValueError('use the existing edition application generator; missing upgrade baseline')
    files = manifest['files']
    paths = [safe_relative(item['path']) for item in files]
    if len({p.casefold() for p in paths}) != len(paths):
        raise ValueError('duplicate or case-colliding manifest paths')
    required = {'server/composer.json', 'server/composer.lock', 'server/database/install.php',
                'server/public/index.php', 'scripts/upgrade', 'release-versions.json', 'plugins.lock',
                'scripts/scaffold-runtime/ReleaseDependencyIdentity.php', 'scripts/release-dependency-locks.mjs',
                'RELEASE_METADATA.json', 'resources/project-resources.json', 'LICENSE', 'NOTICE',
                'THIRD_PARTY_NOTICES.md', 'RELEASE_SBOM.spdx.json'}
    for client in CLIENTS:
        required.update({f'{client}/package.json', f'{client}/{LOCKS[client]}'})
    if not required.issubset(paths):
        raise ValueError('application manifest omits installation, upgrade or development inputs')
    registry_entries = [entry for entry in files if entry.get('path') == 'resources/project-resources.json']
    if len(registry_entries) != 1 or registry_entries[0].get('classification') != 'app-owned':
        raise ValueError('application must carry its own app-owned resource registry')
    registry = document(root, 'resources/project-resources.json')
    if registry.get('project_id') != manifest.get('application', {}).get('slug') \
            or registry.get('authority', {}).get('role') != 'application':
        raise ValueError('resource registry does not belong to this application')
    for client in CLIENTS:
        if not any(p.startswith(client + '/') and p.endswith(('.vue', '.ts', '.js')) for p in paths):
            raise ValueError(f'frontend development source is missing: {client}')
    versions = document(root, 'release-versions.json') if internal else released_dependencies(root)
    validate_release_metadata(root, manifest, versions)
    if not set(paths).issubset(tracked):
        raise ValueError('application ownership manifest names uncommitted files')
    target.mkdir(parents=True)
    baseline_root = safe_relative(manifest.get('ownership', {}).get('baseline_root', ''))
    if not baseline_root.startswith('.peanut/scaffold-baseline/') or not baseline_root.endswith('/files'):
        raise ValueError('invalid existing baseline root')
    for entry in files:
        relative = entry['path']
        source = regular_file(root, relative)
        source_digest = hashlib.sha256(source.read_bytes()).hexdigest()
        source_mode = 0o755 if source.stat().st_mode & 0o111 else 0o644
        # Generated templates have no independent Git identity. A committed
        # customer APP may change these paths while retaining its adoption
        # manifest and immutable upstream baseline for later conflict detection.
        # Its actual release bytes are checked against Git blobs below.
        if generated_template and (entry.get('sha256') != source_digest or entry.get('mode') != source_mode):
            raise ValueError(f'application manifest source identity changed: {relative}')
        classification = entry.get('classification')
        if classification not in ('managed', 'generated-managed', 'app-owned') or relative.startswith('.peanut/'):
            raise ValueError(f'invalid file ownership: {relative}')
        if entry.get('mode') not in (0o644, 0o755):
            raise ValueError(f'invalid source mode: {relative}')
        if classification in ('managed', 'generated-managed'):
            baseline = entry.get('baseline_path')
            if baseline != baseline_root + '/' + relative or baseline not in tracked:
                raise ValueError(f'invalid source baseline: {relative}')
            copy_checked(root, target, baseline, entry['baseline_sha256'], 0o644,
                         blobs.get(baseline), git_hash, approved_archives)
    # The committed APP tree, rather than the immutable upstream baseline,
    # supplies the actual release bytes, including app-owned additions.
    for relative in tracked:
        if relative.startswith(baseline_root + '/'):
            continue
        source = regular_file(root, relative)
        mode = 0o755 if source.stat().st_mode & 0o111 else 0o644
        expected_digest = (sealed['source_files'][relative]['sha256'] if sealed and relative != '.peanut/internal-candidate.json'
                           else sealed['candidate_marker']['sha256'] if sealed else hashlib.sha256(source.read_bytes()).hexdigest())
        if sealed and relative != '.peanut/internal-candidate.json' and mode != sealed['source_files'][relative]['mode']:
            raise ValueError('internal projected mode changed during snapshot')
        copy_checked(root, target, relative, expected_digest, mode,
                     blobs.get(relative), git_hash, approved_archives)
    if internal:
        if artifact_proof_bytes is not None:
            for relative, content in artifact_proof_bytes.items():
                destination = target / safe_relative(relative)
                if destination.exists() or destination.is_symlink():
                    raise ValueError('candidate proof conflicts with application source')
                with destination.open('xb') as stream:
                    stream.write(content)
                destination.chmod(0o644)
        if artifact_marker is not None:
            destination = target / internal_contract().MARKER
            with destination.open('x') as stream:
                json.dump(artifact_marker, stream, indent=2)
                stream.write('\n')
            destination.chmod(0o644)
        internal_contract().verify(target)
    return manifest, {'kind': 'internal-candidate' if internal else 'generated-template' if generated_template else 'application',
                      'commit': commit, 'tree': tree, 'tracked': tracked}


def public_assets(build: Path, target: Path) -> dict:
    outputs = {'web/dist': 'admin', 'platform/dist': 'platform',
               'pc/.output/public': 'pc', 'uniapp/dist/build/h5': 'mobile'}
    build = reject_symlinked_path(build)
    target = reject_symlinked_path(target)
    if target.exists() and not target.is_dir():
        raise ValueError('browser asset target must be a real directory')
    reject_nested_paths(build, target, 'browser asset target must be outside the build input')
    planned = {}
    copies = []
    hashes = {}
    for relative, name in outputs.items():
        source = reject_symlinked_path(build, relative)
        if not source.is_dir():
            raise ValueError(f'missing browser build: {relative}')
        if not (source / 'index.html').is_file():
            raise ValueError(f'missing browser entry: {relative}')
        reject_nested_paths(source, target, 'browser asset target must be outside browser build output')
        count = 0
        for path in sorted(source.rglob('*')):
            asset = path.relative_to(source).as_posix()
            if path.is_symlink():
                raise ValueError('browser build contains a symlink')
            if path.is_dir():
                if FORBIDDEN.intersection(path.relative_to(source).parts):
                    raise ValueError('browser build contains an installed dependency/cache')
                continue
            # Vite's manifest is server/build-tool metadata. Compression may
            # emit exact gzip/brotli companions; none are browser-public assets.
            # Keep this allowlist exact so arbitrary hidden output still fails closed.
            if asset in {'.vite/manifest.json', '.vite/manifest.json.gz', '.vite/manifest.json.br'}:
                continue
            if not allowed_source(asset) or PurePosixPath(asset).parts[0] in {'storage', 'private', 'server', 'scripts'} \
                    or any(x.startswith('.') for x in PurePosixPath(asset).parts) \
                    or re.search(r'\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh)(?:\.|$)', asset, re.I):
                raise ValueError(f'non-public browser asset: {asset}')
            file = regular_file(source, asset)
            dest = target / 'server/public' / name / asset
            release_path = f'server/public/{name}/{asset}'
            components = PurePosixPath(release_path).parts
            for index in range(1, len(components) + 1):
                prefix = '/'.join(components[:index])
                prior = planned.get(prefix.casefold())
                if prior is not None and prior != prefix:
                    raise ValueError(f'duplicate or case-colliding browser asset: {release_path}')
                planned[prefix.casefold()] = prefix
            ensure_new_release_file(dest)
            copies.append((file, dest, release_path))
            count += 1
        if count == 0:
            raise ValueError(f'empty browser build: {relative}')
    # Validate the full four-client set before copying any public asset.
    for file, dest, release_path in copies:
        ensure_new_release_file(dest)
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(file, dest)
        dest.chmod(0o644)
        hashes[release_path] = hashlib.sha256(dest.read_bytes()).hexdigest()
    return hashes


def project_server_plugins(source: Path, target: Path) -> dict:
    original = document(source, 'plugins.lock')
    if original.get('schema_version') != 1 or not isinstance(original.get('plugins'), list):
        raise ValueError('source plugin lock is invalid')
    projected = {'schema_version': 1, 'protocol': 'peanut.server-plugin-lock.v1', 'plugins': []}
    identities = []
    for entry in original['plugins']:
        key = entry['key']
        if not isinstance(key, str) or not re.fullmatch(r'[a-z][a-z0-9.-]*', key):
            raise ValueError('invalid plugin key in source lock')
        original_manifest_path = safe_relative(entry['manifest'])
        original_manifest = document(source, original_manifest_path)
        original_manifest_sha = hashlib.sha256(regular_file(source, original_manifest_path).read_bytes()).hexdigest()
        if original_manifest_sha != entry['manifest_sha256']:
            raise ValueError(f'plugin manifest differs from source lock: {key}')
        for field in ('key', 'version', 'source', 'trust', 'composer', 'npm', 'frontend', 'modules'):
            if original_manifest.get(field) != entry.get(field):
                raise ValueError(f'plugin manifest identity differs from lock: {key}:{field}')
        roots = []
        for module in entry['modules']:
            upstream_root = safe_relative(module['root'])
            if not upstream_root.startswith('server/app/modules/'):
                raise ValueError(f'plugin module is outside server: {key}')
            roots.append(upstream_root)
        canonical = {}
        for upstream_root in roots:
            module_root = target / upstream_root
            if not module_root.is_dir():
                raise ValueError(f'plugin backend is missing: {upstream_root}')
            for path in module_root.rglob('*'):
                if path.is_dir():
                    continue
                relative = path.relative_to(target).as_posix()
                regular_file(target, relative)
                if relative in canonical:
                    raise ValueError(f'duplicate plugin backend file: {relative}')
                canonical[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
        if not canonical:
            raise ValueError(f'plugin backend is empty: {key}')
        source_sha = hashlib.sha256(''.join(f'{path}\0{canonical[path]}\n' for path in sorted(canonical)).encode()).hexdigest()
        row = dict(entry)
        row['source'] = {**entry['source'], 'sha256': source_sha}
        row['npm'] = []
        row['frontend'] = []
        row['modules'] = [{**module, 'root': module['root'][len('server/'):]} for module in entry['modules']]
        row['manifest'] = f'plugins/{key}/plugin.json'
        manifest_data = {field: row[field] for field in
                         ('schema_version', 'key', 'version', 'source', 'trust', 'composer', 'npm', 'frontend', 'modules')
                         if field in row}
        manifest_data['schema_version'] = 1
        manifest_path = target / 'server' / row['manifest']
        manifest_path.parent.mkdir(parents=True, exist_ok=True)
        manifest_path.write_text(json.dumps(manifest_data, ensure_ascii=False, indent=2) + '\n')
        manifest_path.chmod(0o644)
        row['manifest_sha256'] = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
        projected['plugins'].append(row)
        identities.append({'key': key, 'source_manifest_sha256': original_manifest_sha,
                           'source_sha256': entry['source']['sha256'],
                           'projected_manifest_sha256': row['manifest_sha256'],
                           'projected_source_sha256': source_sha})
    projected_path = target / 'server/plugins.lock'
    if projected_path.exists():
        raise ValueError('server plugin lock conflicts with a source file')
    projected_path.write_text(json.dumps(projected, ensure_ascii=False, indent=2) + '\n')
    projected_path.chmod(0o644)
    return {'source_lock_sha256': hashlib.sha256(regular_file(source, 'plugins.lock').read_bytes()).hexdigest(),
            'projected_lock_sha256': hashlib.sha256(projected_path.read_bytes()).hexdigest(),
            'plugins': identities}


def release_rows(target: Path) -> list[dict]:
    rows = []
    for path in target.rglob('*'):
        if path.is_dir():
            continue
        relative = path.relative_to(target).as_posix()
        regular_file(target, relative)
        rows.append({'path': relative, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
                     'mode': stat.S_IMODE(path.stat().st_mode)})
    rows.sort(key=lambda row: row['path'])
    return rows


def rows_sha256(rows: list[dict]) -> str:
    return hashlib.sha256(json.dumps(rows, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()


def validate_server_release_rows(rows: list[dict]) -> None:
    if not isinstance(rows, list) or not rows:
        raise ValueError('release file list is invalid')
    previous = ''
    for row in rows:
        if not isinstance(row, dict) or list(row) != ['path', 'sha256', 'mode']:
            raise ValueError('release file row is invalid')
        path = row['path']
        if not isinstance(path, str) or not path.startswith('server/'):
            raise ValueError('release path is invalid')
        safe_relative(path)
        if (path <= previous or row['mode'] not in (0o644, 0o755)
                or not re.fullmatch(r'[a-f0-9]{64}', str(row['sha256']))):
            raise ValueError('release file order, mode or digest is invalid')
        previous = path


def application_release_identity(source: Path, target: Path, manifest: dict, git: dict) -> None:
    rows = release_rows(target)
    identity = {
        'schema_version': 1, 'protocol': 'peanut.application-release.v1',
        'application': {
            'kind': git['kind'], 'commit': git['commit'], 'tree': git['tree'],
            'manifest_sha256': hashlib.sha256(regular_file(source, MANIFEST).read_bytes()).hexdigest(),
            'slug': manifest['application']['slug'], 'edition': manifest['application']['edition'],
            'version': manifest['application']['version'],
        },
        'upstream': manifest['generation_source'], 'template': manifest['template'],
        'files': rows, 'files_sha256': rows_sha256(rows),
    }
    if git['kind'] == 'internal-candidate':
        contract = internal_contract()
        marker = document(target, contract.MARKER)
        identity['application'].update({'scope': 'internal-candidate', 'formal_release_eligible': False,
                                        'candidate_marker': {'path': contract.MARKER, 'sha256': contract.digest(regular_file(target, contract.MARKER))},
                                        'projection': marker['projection'], 'selection': marker['selection']})
    path = target / '.peanut/application-release.json'
    if path.exists():
        raise ValueError('application source already contains a release identity')
    path.write_text(json.dumps(identity, ensure_ascii=False, indent=2) + '\n')
    path.chmod(0o644)


def server_identity(source: Path, target: Path, manifest: dict, git: dict, versions: dict,
                    server_only: bool = True) -> None:
    application_rows_sha256 = rows_sha256(release_rows(target))
    server = target / 'server'
    if server_only:
        for child in list(target.iterdir()):
            if child.name != 'server':
                shutil.rmtree(child) if child.is_dir() else child.unlink()
        for relative in ('tests', 'runtime/installation', 'private/installation'):
            unwanted = server / relative
            if unwanted.exists():
                shutil.rmtree(unwanted)
        packaging_helper = server / 'app/common/infrastructure/scaffold/DeterministicEditionArchive.php'
        if packaging_helper.exists():
            regular_file(server, 'app/common/infrastructure/scaffold/DeterministicEditionArchive.php')
            packaging_helper.unlink()
    for relative in ('runtime', 'public/storage', 'private/storage', 'private/resources',
                     'public/uploads', 'docker/mysql', 'docker/secrets'):
        protected = server / relative
        if not protected.exists():
            continue
        if not protected.is_dir() or protected.is_symlink():
            raise ValueError(f'runtime data path is unsafe in server release: {relative}')
        for path in protected.rglob('*'):
            if path.is_symlink():
                raise ValueError(f'runtime data cannot enter server release: {relative}')
            if path.is_file():
                if path.name not in ('.gitkeep', '.gitignore'):
                    raise ValueError(f'runtime data cannot enter server release: {relative}')
                if server_only:
                    path.unlink()
        if server_only:
            shutil.rmtree(protected)
    identity_file = server / '.peanut/release-identity.json'
    if identity_file.exists():
        raise ValueError('application source already contains a server release identity')
    metadata = server / '.peanut/RELEASE_METADATA.json'
    validate_release_metadata(source, manifest, versions)
    application = manifest['application']
    metadata.parent.mkdir(parents=True, exist_ok=True)
    candidate = None
    if git['kind'] == 'internal-candidate':
        if server_only:
            raise ValueError('internal candidate requires its complete APP')
        contract = internal_contract()
        candidate = document(target, contract.MARKER)
        candidate_path = server / contract.MARKER
        if candidate_path.exists() or candidate_path.is_symlink():
            raise ValueError('candidate backend marker conflicts with application source')
        shutil.copyfile(regular_file(target, contract.MARKER), candidate_path)
        candidate_path.chmod(0o644)
    shutil.copyfile(regular_file(source, 'RELEASE_METADATA.json'), metadata)
    metadata.chmod(0o644)
    version_projection = server / '.peanut/release-versions.json'
    if version_projection.exists() or version_projection.is_symlink():
        raise ValueError('server release version projection conflicts with application source')
    shutil.copyfile(regular_file(source, 'release-versions.json'), version_projection)
    version_projection.chmod(0o644)
    for legal in ('LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md', 'RELEASE_SBOM.spdx.json'):
        destination = server / '.peanut' / legal
        shutil.copyfile(regular_file(source, legal), destination)
        destination.chmod(0o644)
    registry = server / 'resources/project-resources.json'
    registry.parent.mkdir(parents=True, exist_ok=True)
    if registry.exists():
        raise ValueError('server resource registry conflicts with application source')
    shutil.copyfile(regular_file(source, 'resources/project-resources.json'), registry)
    registry.chmod(0o644)
    # Native server consumers execute installed tools whose bytes belong to this
    # APP release. Project the existing source programs before the canonical
    # server inventory is calculated; never fetch tools from a target package.
    for origin, (relative, mode) in SERVER_RELEASE_TOOLS.items():
        destination = server / relative
        if destination.exists() or destination.is_symlink():
            raise ValueError(f'native tool projection conflicts with source: {relative}')
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(regular_file(source, origin), destination)
        destination.chmod(mode)
    plugin_projection = project_server_plugins(source, target)
    files = release_rows(target)
    if not server_only:
        # Full APP roots retain development sources and empty runtime placeholders.
        # Only backend program files belong to the existing deployment identity.
        files = [row for row in files if row['path'].startswith('server/')
                 and not any(row['path'].startswith(prefix) for prefix in PROTECTED_RUNTIME_PREFIXES)]
    validate_server_release_rows(files)
    if not any(row['path'] == 'server/database/install.php' for row in files):
        raise ValueError('server release lacks its installer')
    release = {
        'schema_version': 1, 'protocol': 'peanut.server-release.v1',
        'application': {
            'kind': git['kind'],
            'manifest_sha256': hashlib.sha256(regular_file(source, MANIFEST).read_bytes()).hexdigest(),
            'commit': git['commit'], 'tree': git['tree'],
            'slug': application['slug'], 'edition': application['edition'],
            'version': application['version'], 'profile': application['profile'],
            'package_identity': application['package_identity'], 'name': application['name'],
            'source_files_sha256': application_rows_sha256,
        },
        'edition_contract': manifest['edition'],
        'edition_profile_sha256': (manifest.get('last_scaffold_upgrade', {}).get('edition_profile_sha256')
                                   if 'last_scaffold_upgrade' in manifest else
                                   manifest['generation_source']['edition_profile_sha256']),
        'upstream': {
            'commit': manifest['generation_source']['commit'],
            'tree': manifest['generation_source']['tree'],
            'inventory_sha256': manifest['generation_source']['inventory_sha256'],
        },
        'template': manifest['template'],
        'versions': {
            'source_product_version': versions['source_product_version'],
            'release_sequence_version': versions.get('instance_version') or versions['source_product_version'],
            'scaffold_template': versions['scaffold_template'],
        },
        'plugin_projection': plugin_projection,
        'files': files, 'files_sha256': rows_sha256(files),
    }
    if candidate is not None:
        release['application'].update({
            'scope': 'internal-candidate', 'formal_release_eligible': False,
            'candidate_marker': {'path': internal_contract().MARKER,
                                 'sha256': hashlib.sha256(regular_file(target, internal_contract().MARKER).read_bytes()).hexdigest()},
            'projection': candidate['projection'], 'selection': candidate['selection'],
        })
    identity_file.parent.mkdir(parents=True, exist_ok=True)
    identity_file.write_text(json.dumps(release, ensure_ascii=False, indent=2) + '\n')
    identity_file.chmod(0o644)


def verify_archive(archive: Path, expected_sha256: str) -> None:
    if not re.fullmatch(r'[a-f0-9]{64}', expected_sha256):
        raise ValueError('verification needs a trusted external SHA-256')
    with archive.open('rb') as stream:
        digest = hashlib.sha256()
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
        actual_sha256 = digest.hexdigest()
    if actual_sha256 != expected_sha256:
        raise ValueError('release archive differs from trusted SHA-256')
    with tarfile.open(archive, 'r:gz') as payload:
        names = set()
        archive_root = None
        files = {}
        for item in payload:
            path = item.name.rstrip('/')
            parts = path.split('/')
            if (len(parts) < 2 or any(part in ('', '.', '..') for part in parts)
                    or not re.fullmatch(r'[a-z0-9][a-z0-9.-]*', parts[0])
                    or parts[1] != 'server' or path.casefold() in names
                    or not (item.isdir() or item.isfile())):
                raise ValueError('archive contains an invalid or non-server entry')
            if archive_root is None:
                archive_root = parts[0]
            if parts[0] != archive_root:
                raise ValueError('archive has multiple roots')
            names.add(path.casefold())
            if item.isfile():
                file = payload.extractfile(item)
                if file is None:
                    raise ValueError('archive file is unavailable')
                files['/'.join(parts[1:])] = (hashlib.sha256(file.read()).hexdigest(), item.mode)
        identity_key = 'server/.peanut/release-identity.json'
        member = payload.getmember(f'{archive_root}/{identity_key}') if archive_root else None
        if member is None or not member.isfile():
            raise ValueError('archive lacks server release identity')
        source = payload.extractfile(member)
        identity = json.loads(source.read()) if source is not None else None
        if not isinstance(identity, dict) or identity.get('protocol') != 'peanut.server-release.v1':
            raise ValueError('archive server release identity is invalid')
        declared = identity.get('files')
        if not isinstance(declared, list) or hashlib.sha256(json.dumps(
                declared, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest() != identity.get('files_sha256'):
            raise ValueError('archive file list digest is invalid')
        validate_server_release_rows(declared)
        actual = {name: value for name, value in files.items() if name != identity_key}
        expected = {row['path']: (row['sha256'], row['mode']) for row in declared}
        if len(expected) != len(declared) or actual != expected:
            raise ValueError('archive file list or contents differ from server release identity')
        plugin = identity.get('plugin_projection', {})
        if plugin.get('projected_lock_sha256') != actual.get('server/plugins.lock', (None,))[0]:
            raise ValueError('archive plugin projection differs from server release identity')


def internal_projection_context(source: Path, target: Path, admission_path: Path):
    """Consume only the native Runner's current verified internal transaction and exact graph inputs."""
    canonical = lambda value: json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()
    digest = lambda path: hashlib.sha256(path.read_bytes()).hexdigest()
    owner = (source / 'server').stat().st_uid
    def private(path):
        if path.resolve(strict=True) != path or path.is_symlink(): raise ValueError('projection private path is not canonical')
        info = path.stat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid not in (0, owner):
            raise ValueError('projection private record is unsafe')
        return json.loads(path.read_bytes())
    admission = private(admission_path)
    workspace = Path(admission['workspace']); checkpoint = workspace / 'source-runtime.json'
    if admission_path.parent != workspace / 'recovery' or workspace.resolve() != workspace or workspace.stat().st_mode & 0o077:
        raise ValueError('projection workspace is unsafe')
    state = private(checkpoint); recorded = state.pop('state_sha256', None); binding = state['binding']
    if recorded != hashlib.sha256(canonical(state)).hexdigest() or state.get('protocol') != 'peanut.source-runtime-transition.v1' \
            or state['phase'] != 'source-verified' or state['activation_started'] is not False or state['intents'] \
            or binding['instance_root'] != str(source) or binding['workspace'] != str(workspace) \
            or binding.get('source_input_kind') != 'raw-internal-preinstall-scaffold' or 'internal_preinstall' not in binding \
            or target != workspace / 'prepared' or target.exists() or target.is_symlink() \
            or admission.get('protocol') != 'peanut.applied-source-tool-admission.v1' or admission.get('command') != 'runtime-transition' \
            or admission.get('repair') != 'source-verified-projection' or admission.get('maximum_uses') != 1 \
            or admission.get('instance_root') != str(source) or admission.get('candidate') != binding['candidate'] \
            or admission.get('checkpoint_sha256') != digest(checkpoint):
        raise ValueError('projection is not the current verified internal transaction')
    pins = admission['corrected_source']['files']
    if list(pins) != ['scripts/upgrade', 'scripts/scaffold-runtime/ScaffoldUpgradeRunner.php', 'scripts/package-release-files.py'] \
            or Path(__file__).resolve() != Path(admission['corrected_source']['root']) / 'scripts/package-release-files.py' \
            or digest(Path(__file__).resolve()) != pins['scripts/package-release-files.py']['sha256']:
        raise ValueError('projection producer is not the exact admitted third tool')
    # The pipe is an ephemeral result handoff. Its claims are also bound to the existing protected proof and ledger.
    if not stat.S_ISFIFO(os.fstat(0).st_mode): raise ValueError('projection requires the native parent pipe')
    handoff = json.loads(sys.stdin.buffer.read(32 * 1024 * 1024 + 1))
    if handoff.get('parent_pid') != os.getppid() or handoff.get('admission_sha256') != digest(admission_path) \
            or handoff.get('checkpoint_sha256') != digest(checkpoint) or handoff.get('source_proof') != state['source_proof']:
        raise ValueError('projection parent result changed')
    plan_path = Path(binding['plan_path']); plan = document(plan_path.parent, plan_path.name)
    if digest(plan_path) != admission['plan_file_sha256'] or hashlib.sha256(canonical(plan['identity'])).hexdigest() != admission['plan_identity_sha256']:
        raise ValueError('projection plan changed')
    events = [json.loads(line) for line in regular_file(source, '.peanut/upgrades/ledger.ndjson').read_text().splitlines() if line]
    events = [event for event in events if event.get('candidate') == binding['candidate']]
    if [(e.get('operation'), e.get('status')) for e in events] != [('preflight', 'ready'), ('apply', 'started'), ('apply', 'applied'), ('verify', 'verified')]:
        raise ValueError('projection requires the original complete verified history')
    proof = state['source_proof']
    if proof['candidate'] != binding['candidate'] or proof['plan_sha256'] != binding['plan_sha256'] \
            or proof['package_inventory_sha256'] != binding['package_inventory_sha256'] \
            or proof['verify_event_sha256'] != hashlib.sha256(canonical(events[3])).hexdigest() \
            or proof['apply_event_sha256'] != hashlib.sha256(canonical(events[2])).hexdigest() \
            or proof['managed_sha256'] != events[3]['post_sha256'] or events[2]['post_sha256'] != events[3]['post_sha256']:
        raise ValueError('projection verified graph result is not the original source proof')
    projection = plan['identity'].get('plugin_target', {}).get('projection', plan['identity']['plugin_projection'])
    def graph_inputs():
        files = {}; directories = set()
        manifest = document(source, MANIFEST)
        ownership = {'managed': {}, 'app-owned': {}}
        for row in manifest['files']:
            classification = row.get('classification')
            group = 'managed' if classification in ('managed', 'generated-managed') else classification
            if group not in ownership: continue
            relative = safe_relative(row['path']); path = source / relative
            value = {'present': False, 'sha256': None, 'mode': None}
            if path.exists() or path.is_symlink():
                path = regular_file(source, relative)
                value = {'present': True, 'sha256': digest(path), 'mode': stat.S_IMODE(path.stat().st_mode)}
            if relative in ownership[group]: raise ValueError('duplicate projection ownership input')
            ownership[group][relative] = value
        for group, expected in [('managed', proof['managed_sha256']), ('app-owned', proof['app_owned_sha256'])]:
            # PHP's native empty associative accumulator serializes as []; retain that exact digest contract.
            actual = 'sha256:' + hashlib.sha256(canonical(ownership[group] or [])).hexdigest()
            if actual != expected: raise ValueError('projection ownership differs from verified source proof')
        def add(relative):
            path = regular_file(source, relative)
            files[relative] = {'present': True, 'sha256': digest(path), 'mode': stat.S_IMODE(path.stat().st_mode)}
        for relative in set(projection['artifact_paths']) | {'server/composer.json', 'web/package.json', MANIFEST, 'release-versions.json'}: add(relative)
        for relative in projection['root_paths']:
            root = reject_symlinked_path(source, relative)
            if not root.is_dir(): raise ValueError('projection graph root disappeared')
            directories.add(relative)
            for base, names, paths in os.walk(root, followlinks=False, onerror=lambda error: (_ for _ in ()).throw(error)):
                for name in names:
                    path = Path(base) / name
                    if path.is_symlink(): raise ValueError('projection graph directory is linked')
                    directories.add(path.relative_to(source).as_posix())
                for name in paths: add((Path(base) / name).relative_to(source).as_posix())
        if (source / '.local/module-source-adoption/journal.json').exists() or (source / '.local/module-source-adoption/journal.json').is_symlink():
            raise ValueError('projection graph has a recovery journal')
        # Independently bind complete module AND frontend contents to the graph the plan/verified event certified.
        for plugin in projection['plugins'].values():
            roots = plugin['module_roots'] + plugin['frontend_roots']
            contents = {name: row['sha256'] for name, row in files.items() if any(name.startswith(root + '/') for root in roots)}
            value = ''.join(f'{name}\0{contents[name]}\n' for name in sorted(contents)).encode()
            if plugin['source_contract']['type'] != 'canonical-contents' or hashlib.sha256(value).hexdigest() != plugin['source_sha256'] \
                    or files[plugin['manifest']] != plugin['manifest_state']:
                raise ValueError('projection graph contents differ from the certified plan')
        if files['plugins.lock'] != projection['lock_state']: raise ValueError('projection graph lock changed')
        return {'files': dict(sorted(files.items())), 'directories': sorted(directories)}
    def fence():
        if graph_inputs() != handoff['graph_inputs'] or digest(checkpoint) != handoff['checkpoint_sha256'] \
                or digest(admission_path) != handoff['admission_sha256']:
            raise ValueError('projection graph input fence changed')
    fence()
    log_prefix = Path(handoff['log_prefix'])
    if log_prefix.parent != workspace / 'recovery' or re.fullmatch(re.escape(admission['action_id']) + r'-[0-9]{3}-projection', log_prefix.name) is None:
        raise ValueError('projection diagnostic prefix is not bound')
    return fence, log_prefix, workspace / 'trusted'


def development_runtime(source: Path, target: Path, source_admission: Path | None = None) -> None:
    """Opt-in local immutable program projection; never a distributable release."""
    source = reject_symlinked_path(source).resolve(strict=True)
    target = reject_symlinked_path(target)
    reject_nested_paths(source, target, 'development output must be outside the application')
    if target.exists():
        raise ValueError('development output must not exist')
    internal = internal_projection_context(source, target, source_admission) if source_admission is not None else None
    helper_root = internal[2] if internal is not None else Path(__file__).resolve().parent.parent
    def execute(stage, arguments):
        if internal is None: return subprocess.run(arguments, check=True, capture_output=True)
        handles = []
        for suffix in ('stdout', 'stderr'):
            path = Path(str(internal[1]) + '-' + stage + '.' + suffix)
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            handles.append(os.fdopen(descriptor, 'wb'))
        try:
            result = subprocess.run(arguments, stdin=subprocess.DEVNULL, stdout=handles[0], stderr=handles[1])
        finally:
            for handle in handles: handle.flush(); os.fsync(handle.fileno()); handle.close()
        stdout = Path(str(internal[1]) + '-' + stage + '.stdout').read_bytes()
        stderr = Path(str(internal[1]) + '-' + stage + '.stderr').read_bytes()
        if result.returncode: raise ValueError(f'projection subprocess stage={stage} rc={result.returncode} stderr_sha256={hashlib.sha256(stderr).hexdigest()}')
        return subprocess.CompletedProcess(arguments, result.returncode, stdout, stderr)
    manifest = document(source, MANIFEST)
    if manifest.get('protocol') != 'peanut.application-scaffold.v2' or manifest.get('schema_version') != 2:
        raise ValueError('development runtime requires a real generated APP identity')
    versions = document(source, 'release-versions.json')
    if versions.get('schema_version') != 3:
        raise ValueError('development runtime requires the native v3 dependency identity')
    # These existing native readers check Edition and v3 dependency provenance. No business bootstrap.
    identity = execute('identity', ['php', '-r',
        'require $argv[2]."/server/app/common/value/installation/ApplicationSourceIdentity.php"; '
        'require $argv[1]."/server/vendor/autoload.php"; '
        '$s=\\app\\common\\value\\installation\\ApplicationSourceIdentity::loadGeneratedApp($argv[1]."/server"); '
        'echo json_encode(["application"=>$s->applicationIdentity(),"versions"=>$s->versions(),'
        '"tenant_bootstrap"=>$s->tenantBootstrapContract($s->applicationIdentity()["edition"])]);',
        str(source), str(helper_root)])
    declaration = json.loads(identity.stdout)
    lock_arguments = ['node', str(regular_file(source, 'scripts/release-dependency-locks.mjs')), str(source)]
    # Packaged internal runtimes carry fixed archives/assets, not the four clients' development node_modules.
    # Their actual source/locks and archive identities are fenced by the native verified proof and checks below.
    if internal is None: lock_arguments.append('--installed')
    execute('locks', lock_arguments)
    execute('vendor', ['python3', str(regular_file(source, 'server/docker/scripts/vendor-state.py')), 'check', '--server', str(source/'server')])
    vendor_receipt = document(source, 'server/vendor/.peanut-complete.json')
    native_archives = vendor_receipt.get('local_archives', {})
    if not isinstance(native_archives, dict) or len(native_archives) > 1:
        raise ValueError('invalid native Core archive inventory')
    owner_inputs = {}
    for relative, record in native_archives.items():
        relative = safe_relative(relative)
        if re.fullmatch(r'packages/core-php/peanut-admin-core-[0-9A-Za-z.+_-]+\.zip', relative) is None \
                or not isinstance(record, dict) \
                or re.fullmatch(r'[a-f0-9]{64}', str(record.get('sha256', ''))) is None:
            raise ValueError('invalid native Core archive input')
        owner_inputs[relative] = record['sha256']
    if owner_inputs:
        # Native vendor stage/check consumes the canonical APP-root proof and
        # archive. Keep these owner inputs outside the server-only HTTP view;
        # the source vendor itself is still never copied.
        for relative in (MANIFEST, 'release-versions.json'):
            owner_inputs[relative] = hashlib.sha256(regular_file(source, relative).read_bytes()).hexdigest()
    if internal is None:
        subprocess.run(['php', '-r',
                    'require $argv[2]."/server/app/platform/infrastructure/plugin/PluginLockResolver.php"; '
                    'require $argv[1]."/server/vendor/autoload.php"; '
                    '\\app\\platform\\infrastructure\\plugin\\PluginLockResolver::forSourceUpgrade($argv[1]);',
                   str(source), str(helper_root)],
                       check=True, capture_output=True)
    entries = manifest.get('files', [])
    if not isinstance(entries, list) or not entries:
        raise ValueError('development APP source inventory is missing')
    seen = set()
    baseline_root = safe_relative(manifest.get('ownership', {}).get('baseline_root', ''))
    if not baseline_root.startswith('.peanut/scaffold-baseline/') or not baseline_root.endswith('/files'):
        raise ValueError('invalid existing development baseline root')
    for row in entries:
        relative = safe_relative(row['path'])
        if relative.casefold() in seen or row.get('classification') not in ('managed', 'generated-managed', 'app-owned'):
            raise ValueError('invalid development ownership inventory')
        seen.add(relative.casefold())
        if row['classification'] in ('managed', 'generated-managed'):
            if row.get('baseline_path') != baseline_root + '/' + relative:
                raise ValueError('invalid development upstream baseline path')
            baseline = regular_file(source, row['baseline_path'])
            if hashlib.sha256(baseline.read_bytes()).hexdigest() != row['baseline_sha256']:
                raise ValueError('development upstream baseline changed')
    projected = {
        MANIFEST: 'server/.peanut/application-manifest.json',
        'release-versions.json': 'server/.peanut/release-versions.json',
        'RELEASE_METADATA.json': 'server/.peanut/RELEASE_METADATA.json',
        'resources/project-resources.json': 'server/resources/project-resources.json',
        'scripts/scaffold-runtime/ReleaseDependencyIdentity.php':
            'server/docker/scripts/scaffold-runtime/ReleaseDependencyIdentity.php',
    }
    # The runtime's native v3 reader verifies archive bytes relative to its
    # projected release-versions.json, just as it does in the source APP.
    for identity in versions['core_web']['packages'].values():
        if 'archive' not in identity:
            continue
        archive = safe_relative(identity['archive'])
        if re.fullmatch(r'packages/core-web/peanut-admin-[a-z-]+-[0-9A-Za-z.+-]+\.tgz', archive) is None:
            raise ValueError('invalid development Core Web archive path')
        if hashlib.sha256(regular_file(source, archive).read_bytes()).hexdigest() != identity['sha256']:
            raise ValueError('development Core Web archive changed')
        projected[archive] = 'server/.peanut/' + archive
    # Only these exact native outputs are regenerated; unknown APP program paths stay in the scan.
    derived = set(projected.values()) | {'server/plugins.lock', 'server/.peanut/development-runtime.json'}
    source_inputs = set(projected) | set(owner_inputs) | {'plugins.lock'}
    source_plugins = document(source, 'plugins.lock')
    if source_plugins.get('schema_version') != 1 or not isinstance(source_plugins.get('plugins'), list):
        raise ValueError('source plugin lock is invalid')
    for entry in source_plugins['plugins']:
        key = entry.get('key') if isinstance(entry, dict) else None
        if not isinstance(key, str) or not re.fullmatch(r'[a-z][a-z0-9.-]*', key):
            raise ValueError('invalid plugin key in source lock')
        derived.add(f'server/plugins/{key}/plugin.json')
        source_inputs.add(safe_relative(entry['manifest']))
    source_fence = {relative: hashlib.sha256(regular_file(source, relative).read_bytes()).hexdigest()
                    for relative in source_inputs}
    # Native instance configuration exclusions match the application inventory contract.
    runtime_files = {'server/docker/collaboration.env', 'server/.env.collaboration.lock'}
    runtime_prefixes = (*PROTECTED_RUNTIME_PREFIXES, 'server/docker/tls/')
    # Ownership baselines describe upstream files; APP additions are also executable program inputs.
    def program_state() -> dict:
        server = reject_symlinked_path(source, 'server')
        if not server.is_dir():
            raise ValueError('development server source is missing')
        def walk_error(error):
            raise error
        def included(relative: str) -> bool:
            return (relative != 'server/private' and not relative.startswith('server/private/')
                    and relative not in derived
                    and relative not in runtime_files
                    and allowed_source(relative)
                    and not any(relative == prefix.rstrip('/') or relative.startswith(prefix)
                                for prefix in runtime_prefixes))
        files = {}
        names = set()
        for base, directories, filenames in os.walk(server, topdown=True, onerror=walk_error, followlinks=False):
            for name in list(directories):
                path = Path(base) / name
                relative = safe_relative(path.relative_to(source).as_posix())
                if not included(relative):
                    directories.remove(name)
                elif path.is_symlink():
                    raise ValueError(f'symlink is not a development program input: {relative}')
            for name in filenames:
                relative = safe_relative((Path(base) / name).relative_to(source).as_posix())
                if not included(relative):
                    continue
                if relative.casefold() in names:
                    raise ValueError(f'case-colliding development program input: {relative}')
                names.add(relative.casefold())
                path = regular_file(source, relative)
                files[relative] = {'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
                                   'mode': stat.S_IMODE(path.stat().st_mode)}
        return files
    before = program_state()
    # No private instance configuration, receipt, storage or database bytes enter this projection.
    target.mkdir(parents=True)
    for relative, state in before.items():
        mode = 0o755 if state['mode'] & 0o111 else 0o644
        copy_checked(source, target, relative, state['sha256'], mode)
    server = target/'server'
    source_projection = {}
    for origin, relative in projected.items():
        path = regular_file(source, origin)
        source_projection[origin] = hashlib.sha256(path.read_bytes()).hexdigest()
        destination = target/relative
        ensure_new_release_file(destination)
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, destination)
        destination.chmod(0o644)
    for relative, expected_sha in owner_inputs.items():
        path = regular_file(source, relative)
        data = path.read_bytes()
        if source_fence[relative] != expected_sha or hashlib.sha256(data).hexdigest() != expected_sha:
            raise ValueError('native dependency input changed during projection')
        destination = target/relative
        ensure_new_release_file(destination)
        destination.parent.mkdir(parents=True, exist_ok=True)
        with destination.open('xb') as output:
            output.write(data)
        destination.chmod(0o644)
        if hashlib.sha256(regular_file(target, relative).read_bytes()).hexdigest() != expected_sha:
            raise ValueError('native dependency input copy differs from its verified source')
        source_projection[relative] = expected_sha
    plugin = project_server_plugins(source, target)
    # Install this target's dependencies through the original fixed-lock native entry.
    # Source installation is evidence of provenance, never a vendor directory to copy.
    # Only the explicitly verified owner inputs sit outside the HTTP program.
    # Any other unexpected root file still reaches the server-row validator.
    rows = [row for row in release_rows(target) if row['path'] not in owner_inputs]
    validate_server_release_rows(rows)
    value = {'schema_version': 1, 'protocol': 'peanut.development-server-runtime.v1',
             'environment': 'development', **declaration,
             'application_manifest_sha256': source_projection[MANIFEST],
             'source_projection': source_projection, 'plugin_projection': plugin,
             'files': rows, 'files_sha256': rows_sha256(rows),
             'core_php': versions['core_php']}
    if program_state() != before:
        raise ValueError('development program file set, bytes or modes changed during projection')
    for relative, sha in source_fence.items():
        if hashlib.sha256(regular_file(source, relative).read_bytes()).hexdigest() != sha:
            raise ValueError('development root source changed during projection')
    for relative, sha in source_projection.items():
        if hashlib.sha256(regular_file(source, relative).read_bytes()).hexdigest() != sha:
            raise ValueError('development identity changed during projection')
    if (server/'.peanut/release-identity.json').exists():
        raise ValueError('development source cannot already declare a release identity')
    if internal is not None: internal[0]()
    (server/'.peanut/development-runtime.json').write_text(json.dumps(value, ensure_ascii=False, indent=2)+'\n')
    (server/'.peanut/development-runtime.json').chmod(0o644)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('snapshot', 'assemble', 'verify', 'development-runtime'))
    parser.add_argument('--application-root', type=Path)
    parser.add_argument('--target', type=Path)
    parser.add_argument('--build-root', type=Path)
    parser.add_argument('--generated-template', action='store_true')
    parser.add_argument('--internal-candidate', action='store_true')
    parser.add_argument('--candidate-scaffold-manifest', type=Path)
    parser.add_argument('--candidate-core-inputs', type=Path)
    parser.add_argument('--server-only', action='store_true')
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--expected-sha256')
    parser.add_argument('--source-transition-admission', type=Path)
    args = parser.parse_args()
    if args.source_transition_admission is not None and args.action != 'development-runtime':
        raise ValueError('source transition admission is limited to development projection')
    if args.internal_candidate and (args.generated_template or args.server_only or args.action not in ('snapshot', 'assemble')):
        raise ValueError('internal candidate requires complete APP snapshot/assemble')
    if ((args.candidate_scaffold_manifest is None) != (args.candidate_core_inputs is None)
            or (args.candidate_scaffold_manifest is not None and not args.internal_candidate)):
        raise ValueError('candidate proof pair requires explicit internal candidate mode')
    if args.action == 'verify':
        if args.archive is None or args.expected_sha256 is None:
            raise ValueError('verify needs --archive and --expected-sha256 from a trusted channel')
        verify_archive(args.archive, args.expected_sha256)
        print('package-files: archive matches trusted SHA-256 and server file list')
        return
    if args.application_root is None or args.target is None:
        raise ValueError('snapshot/assemble need --application-root and --target')
    if args.application_root.is_symlink():
        raise ValueError('application root may not be a symlink')
    source = args.application_root.resolve(strict=True)
    destination = args.target.resolve()
    if destination == source or source in destination.parents:
        raise ValueError('output must be outside the application source')
    if args.action == 'development-runtime':
        if args.server_only or args.generated_template or args.build_root is not None:
            raise ValueError('development runtime is separate from release packaging')
        development_runtime(source, args.target, args.source_transition_admission)
        print('package-files: explicit development runtime projection complete; not a release')
        return
    manifest, git = snapshot(source, destination, args.generated_template, args.internal_candidate,
                             args.candidate_scaffold_manifest, args.candidate_core_inputs)
    if args.action == 'assemble':
        if args.build_root is None:
            raise ValueError('assemble needs --build-root')
        # Native build scripts may create outputs, but may not change the
        # authoritative application source or its upgrade-baseline metadata.
        if regular_file(args.build_root, MANIFEST).read_bytes() != regular_file(source, MANIFEST).read_bytes():
            raise ValueError('native build changed the application ownership manifest')
        for relative in git['tracked']:
            actual = regular_file(args.build_root, relative).read_bytes()
            if actual != regular_file(source, relative).read_bytes():
                raise ValueError(f'native build changed committed application source: {relative}')
        # The existing lock resolver validates manifest, trust, package and
        # canonical contents before the server-only backend projection is made.
        validation = subprocess.run([
            'php', '-r',
            'require $argv[1]."/server/vendor/autoload.php"; '
            '(new \\app\\platform\\infrastructure\\plugin\\PluginLockResolver('
            '$argv[1]."/server", "../plugins.lock"))->all();',
            str(args.build_root),
        ], capture_output=True)
        if validation.returncode != 0:
            raise ValueError('source plugin lock failed its native identity validation')
        public_assets(args.build_root, destination)
        if (destination / 'release-manifest.txt').exists():
            raise ValueError('application source already contains release output metadata')
        (destination / 'release-manifest.txt').write_text(
            'product=Peanut Admin\nlayout=application-source-with-browser-assets\n'
            f"application_source_kind={git['kind']}\n"
            f"application_source_commit={git['commit'] or 'none'}\n"
            f"upstream_generation_commit={manifest['generation_source']['commit']}\n"
        )
        if args.server_only:
            server_identity(source, destination, manifest, git, released_dependencies(source))
        else:
            # The development archive keeps application source and the upstream
            # baseline for later APP customization and scaffold upgrades.
            versions = document(source, 'release-versions.json') if args.internal_candidate else released_dependencies(source)
            server_identity(source, destination, manifest, git, versions, server_only=False)
            application_release_identity(source, destination, manifest, git)
            if args.internal_candidate:
                internal_contract().verify_assembled(destination)
    print(f'package-files: {args.action} completed; no application/upgrade qualification claimed')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, TypeError) as error:
        raise SystemExit(f'package-files: {error}')
