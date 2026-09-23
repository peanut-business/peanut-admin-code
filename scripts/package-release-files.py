#!/usr/bin/env python3
"""发行包文件装配：复用应用清单/基线，只分发源码和浏览器资产，不复制安装依赖。"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import stat
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


def document(root: Path, relative: str) -> dict:
    value = json.loads(regular_file(root, relative).read_text())
    if not isinstance(value, dict):
        raise ValueError(f'JSON object required: {relative}')
    return value


def safe_relative(value: str) -> str:
    if not isinstance(value, str) or not value or '\\' in value or '\x00' in value:
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


def allowed_source(relative: str) -> bool:
    parts = PurePosixPath(relative).parts
    # Apply the same exclusions inside the existing upgrade-baseline tree.
    logical = relative
    if relative.startswith('.peanut/scaffold-baseline/') and '/files/' in relative:
        logical = relative.split('/files/', 1)[1]
    name = PurePosixPath(logical).name
    if FORBIDDEN.intersection(parts) or name in {'auth.json', '.npmrc', '.DS_Store'}:
        return False
    if name.endswith(('.tgz', '.phar', '.pem', '.key', '.p12', '.pfx', '.log')):
        return False
    if name.startswith('.env') and name != '.env.example' and logical not in CLIENT_ENVS:
        return False
    for storage in ('server/runtime/', 'server/public/storage/', 'server/private/storage/'):
        if logical.startswith(storage) and name not in {'.gitkeep', '.gitignore'}:
            return False
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


def copy_checked(root: Path, target: Path, relative: str, digest: str, mode: int) -> None:
    if not allowed_source(relative):
        raise ValueError(f'installed dependency, secret or runtime data in manifest: {relative}')
    source = regular_file(root, relative)
    data = source.read_bytes()
    if not isinstance(digest, str) or hashlib.sha256(data).hexdigest() != digest:
        raise ValueError(f'source/baseline has changed: {relative}')
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open('xb') as output:
        output.write(data)
    destination.chmod(mode)


def snapshot(root: Path, target: Path) -> dict:
    if target.exists() or target.is_symlink():
        raise ValueError('snapshot destination must not exist')
    root = root.resolve(strict=True)
    manifest = document(root, MANIFEST)
    if manifest.get('protocol') != 'peanut.application-scaffold.v2' or not manifest.get('files'):
        raise ValueError('use the existing edition application generator; missing upgrade baseline')
    files = manifest['files']
    paths = [safe_relative(item['path']) for item in files]
    if len({p.casefold() for p in paths}) != len(paths):
        raise ValueError('duplicate or case-colliding manifest paths')
    required = {'server/composer.json', 'server/composer.lock', 'server/database/install.php',
                'server/public/index.php', 'scripts/upgrade', 'release-versions.json', 'plugins.lock'}
    for client in CLIENTS:
        required.update({f'{client}/package.json', f'{client}/{LOCKS[client]}'})
    if not required.issubset(paths):
        raise ValueError('application manifest omits installation, upgrade or development inputs')
    for client in CLIENTS:
        if not any(p.startswith(client + '/') and p.endswith(('.vue', '.ts', '.js')) for p in paths):
            raise ValueError(f'frontend development source is missing: {client}')
    released_dependencies(root)
    target.mkdir(parents=True)
    baseline_root = safe_relative(manifest.get('ownership', {}).get('baseline_root', ''))
    if not baseline_root.startswith('.peanut/scaffold-baseline/') or not baseline_root.endswith('/files'):
        raise ValueError('invalid existing baseline root')
    for entry in files:
        relative = entry['path']
        classification = entry.get('classification')
        if classification not in ('managed', 'generated-managed', 'app-owned') or relative.startswith('.peanut/'):
            raise ValueError(f'invalid file ownership: {relative}')
        if entry.get('mode') not in (0o644, 0o755):
            raise ValueError(f'invalid source mode: {relative}')
        copy_checked(root, target, relative, entry['sha256'], entry['mode'])
        if classification in ('managed', 'generated-managed'):
            baseline = entry.get('baseline_path')
            if baseline != baseline_root + '/' + relative or entry.get('baseline_sha256') != entry['sha256']:
                raise ValueError(f'invalid source baseline: {relative}')
            copy_checked(root, target, baseline, entry['baseline_sha256'], 0o644)
    metadata = target / MANIFEST
    metadata.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(regular_file(root, MANIFEST), metadata)
    metadata.chmod(0o644)
    return manifest


def public_assets(build: Path, target: Path) -> dict:
    outputs = {'web/dist': 'admin', 'platform/dist': 'platform',
               'pc/.output/public': 'pc', 'uniapp/dist/build/h5': 'mobile'}
    hashes = {}
    for relative, name in outputs.items():
        source = build / relative
        if source.is_symlink() or not source.is_dir():
            raise ValueError(f'missing browser build: {relative}')
        if not (source / 'index.html').is_file():
            raise ValueError(f'missing browser entry: {relative}')
        count = 0
        for path in sorted(source.rglob('*')):
            asset = path.relative_to(source).as_posix()
            if path.is_symlink():
                raise ValueError('browser build contains a symlink')
            if path.is_dir():
                if FORBIDDEN.intersection(path.relative_to(source).parts):
                    raise ValueError('browser build contains an installed dependency/cache')
                continue
            if not allowed_source(asset) or PurePosixPath(asset).parts[0] in {'storage', 'private', 'server', 'scripts'} \
                    or any(x.startswith('.') for x in PurePosixPath(asset).parts) \
                    or re.search(r'\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh)(?:\.|$)', asset, re.I):
                raise ValueError(f'non-public browser asset: {asset}')
            file = regular_file(source, asset)
            dest = target / 'server/public' / name / asset
            if dest.exists():
                raise ValueError(f'browser asset conflicts with managed source: {name}/{asset}')
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(file, dest)
            dest.chmod(0o644)
            hashes[f'server/public/{name}/{asset}'] = hashlib.sha256(dest.read_bytes()).hexdigest()
            count += 1
        if count == 0:
            raise ValueError(f'empty browser build: {relative}')
    return hashes


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('snapshot', 'assemble'))
    parser.add_argument('--application-root', required=True, type=Path)
    parser.add_argument('--target', required=True, type=Path)
    parser.add_argument('--build-root', type=Path)
    args = parser.parse_args()
    if args.application_root.is_symlink():
        raise ValueError('application root may not be a symlink')
    source = args.application_root.resolve(strict=True)
    destination = args.target.resolve()
    if destination == source or source in destination.parents:
        raise ValueError('output must be outside the application source')
    manifest = snapshot(source, destination)
    if args.action == 'assemble':
        if args.build_root is None:
            raise ValueError('assemble needs --build-root')
        # Native build scripts may create outputs, but may not change the
        # authoritative application source or its upgrade-baseline metadata.
        if regular_file(args.build_root, MANIFEST).read_bytes() != regular_file(source, MANIFEST).read_bytes():
            raise ValueError('native build changed the application ownership manifest')
        for entry in manifest['files']:
            actual = regular_file(args.build_root, entry['path']).read_bytes()
            if hashlib.sha256(actual).hexdigest() != entry['sha256']:
                raise ValueError(f'native build changed managed/development source: {entry["path"]}')
        public_assets(args.build_root, destination)
        # Kept outside public. No target-instance credentials or dependency dirs.
        text = ('product=Peanut Admin\nlayout=full-source-browser-assets-no-installed-dependencies\n'
                'qualification=not-performed-by-packaging\ndependencies=install-on-target-from-native-locks\n'
                'pc_runtime=install-dependencies-and-rebuild-SSR-on-target\n'
                'public_layout=admin,platform,pc,mobile\n'
                f"source_commit={manifest['generation_source']['commit']}\n")
        (destination/'release-manifest.txt').write_text(text)
    print(f'package-files: {args.action} completed; no application/upgrade qualification claimed')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, TypeError) as error:
        raise SystemExit(f'package-files: {error}')
