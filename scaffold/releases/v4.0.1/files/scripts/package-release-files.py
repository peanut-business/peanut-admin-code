#!/usr/bin/env python3
"""发行包文件装配：复用应用清单/基线，只分发源码和浏览器资产，不复制安装依赖。"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import stat
import subprocess
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
    return logical in RELEASE_OUTPUTS or any(logical.startswith(prefix) for prefix in BROWSER_RELEASE_PREFIXES)


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
                 git_blob: str | None = None, git_hash: str = 'sha1') -> None:
    if not allowed_source(relative):
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


def snapshot(root: Path, target: Path, generated_template: bool) -> tuple[dict, dict]:
    if target.exists() or target.is_symlink():
        raise ValueError('snapshot destination must not exist')
    root = root.resolve(strict=True)
    manifest = document(root, MANIFEST)
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
    versions = released_dependencies(root)
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
                         blobs.get(baseline), git_hash)
    # The committed APP tree, rather than the immutable upstream baseline,
    # supplies the actual release bytes, including app-owned additions.
    for relative in tracked:
        if relative.startswith(baseline_root + '/'):
            continue
        source = regular_file(root, relative)
        mode = 0o755 if source.stat().st_mode & 0o111 else 0o644
        copy_checked(root, target, relative, hashlib.sha256(source.read_bytes()).hexdigest(), mode,
                     blobs.get(relative), git_hash)
    return manifest, {'kind': 'generated-template' if generated_template else 'application',
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
    path = target / '.peanut/application-release.json'
    if path.exists():
        raise ValueError('application source already contains a release identity')
    path.write_text(json.dumps(identity, ensure_ascii=False, indent=2) + '\n')
    path.chmod(0o644)


def server_identity(source: Path, target: Path, manifest: dict, git: dict, versions: dict) -> None:
    application_rows_sha256 = rows_sha256(release_rows(target))
    server = target / 'server'
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
                path.unlink()
        shutil.rmtree(protected)
    identity_file = server / '.peanut/release-identity.json'
    if identity_file.exists():
        raise ValueError('application source already contains a server release identity')
    metadata = server / '.peanut/RELEASE_METADATA.json'
    validate_release_metadata(source, manifest, versions)
    application = manifest['application']
    metadata.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(regular_file(source, 'RELEASE_METADATA.json'), metadata)
    metadata.chmod(0o644)
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
    native_tools = {
        'scripts/upgrade': ('docker/scripts/upgrade', 0o755),
        'scripts/product-upgrade-host': ('docker/scripts/product-upgrade-host', 0o755),
        'scripts/scaffold-runtime/ScaffoldPathGuard.php':
            ('docker/scripts/scaffold-runtime/ScaffoldPathGuard.php', 0o644),
        'scripts/ops-backup-worker': ('docker/scripts/ops-backup-worker', 0o755),
        'scripts/ops-restore-worker': ('docker/scripts/ops-restore-worker', 0o755),
    }
    for origin, (relative, mode) in native_tools.items():
        destination = server / relative
        if destination.exists() or destination.is_symlink():
            raise ValueError(f'native tool projection conflicts with source: {relative}')
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(regular_file(source, origin), destination)
        destination.chmod(mode)
    plugin_projection = project_server_plugins(source, target)
    files = release_rows(target)
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


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('snapshot', 'assemble', 'verify'))
    parser.add_argument('--application-root', type=Path)
    parser.add_argument('--target', type=Path)
    parser.add_argument('--build-root', type=Path)
    parser.add_argument('--generated-template', action='store_true')
    parser.add_argument('--server-only', action='store_true')
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--expected-sha256')
    args = parser.parse_args()
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
    manifest, git = snapshot(source, destination, args.generated_template)
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
            'product=Scaffold Product Token\nlayout=application-source-with-browser-assets\n'
            f"application_source_kind={git['kind']}\n"
            f"application_source_commit={git['commit'] or 'none'}\n"
            f"upstream_generation_commit={manifest['generation_source']['commit']}\n"
        )
        if args.server_only:
            server_identity(source, destination, manifest, git, released_dependencies(source))
        else:
            # The development archive keeps application source and the upstream
            # baseline for later APP customization and scaffold upgrades.
            application_release_identity(source, destination, manifest, git)
    print(f'package-files: {args.action} completed; no application/upgrade qualification claimed')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, TypeError) as error:
        raise SystemExit(f'package-files: {error}')
