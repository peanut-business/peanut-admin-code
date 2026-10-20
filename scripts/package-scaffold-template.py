#!/usr/bin/env python3
"""Serialize the native scaffold projection; never install, start or regenerate it."""
import argparse
import gzip
import hashlib
import io
import json
import pathlib
import re
import tarfile


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=4) + '\n').encode()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--application', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    app = pathlib.Path(args.application).resolve(strict=True)
    output = pathlib.Path(args.output).resolve(strict=True)
    release_bytes = (output / 'scaffold-manifest.json').read_bytes()
    release = json.loads(release_bytes)
    manifest = json.loads((app / '.peanut/application-manifest.json').read_bytes())
    if release['protocol'] != 'peanut.scaffold-release.v3' or 'internal_core_projection' in release:
        raise ValueError('TEMPLATE_PUBLIC_RELEASE_REQUIRED')
    tokens = release['release']['tokens']
    members = {'scaffold-manifest.json': (release_bytes, 0o644)}
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
               'scaffold_manifest_sha256': digest(release_bytes)}
    (output / (name + '.json')).write_bytes(encoded(package))


if __name__ == '__main__':
    main()
