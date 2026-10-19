#!/usr/bin/env python3
"""Integrity of explicitly internal, source-preserving candidate projections."""
from __future__ import annotations

import hashlib
import base64
import io
import importlib.util
import json
import os
import re
import stat
import sys
import subprocess
import tempfile
import zipfile
from pathlib import Path, PurePosixPath

PROTOCOL = "peanut.internal-edition-candidate.v1"
MARKER = ".peanut/internal-candidate.json"
ARTIFACT_PROOFS = ('.peanut/scaffold-candidate-manifest.json', '.peanut/core-candidate-inputs.json')
ARTIFACT_TEMPLATES = '.peanut/scaffold-candidate-templates.zip'
INSTALLED_DIRECTORIES = {'server/vendor', *(client + '/node_modules' for client in ('web', 'platform', 'pc', 'uniapp'))}
ROOT = Path(__file__).resolve().parent
sys.dont_write_bytecode = True
_spec = importlib.util.spec_from_file_location("release_file_policy", ROOT / "package-release-files.py")
_policy = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_policy)


def require(value: bool, message: str) -> None:
    if not value:
        raise ValueError("INTERNAL_CANDIDATE_" + message)


def digest(path: Path) -> str:
    with path.open("rb") as source:
        return hashlib.file_digest(source, "sha256").hexdigest()


def relative(value: object) -> str:
    require(isinstance(value, str) and value != "" and "\\" not in value, "PATH_INVALID")
    p = PurePosixPath(value)
    require(not p.is_absolute() and value == p.as_posix()
            and all(x not in {"", ".", ".."} for x in p.parts), "PATH_INVALID")
    return value


def file(root: Path, name: object) -> Path:
    name = relative(name)
    path = root
    for part in PurePosixPath(name).parts:
        path /= part
        require(not path.is_symlink(), "SYMLINK_REJECTED")
    info = path.stat()
    require(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "FILE_INVALID")
    require(path.resolve().is_relative_to(root.resolve()), "PATH_OUTSIDE_ROOT")
    return path


def document(path: Path) -> dict:
    value = json.loads(path.read_text())
    require(isinstance(value, dict), "JSON_OBJECT_REQUIRED")
    return value


def tree(root: Path, archive_paths: set[str] | None = None, *, proof_files: dict[str, str] | None = None) -> dict:
    require(root.is_dir() and not root.is_symlink(), "ROOT_INVALID")
    result = {}
    original_manifest = root / ".peanut/application-manifest.json"
    declared = {}
    if original_manifest.is_file() and not original_manifest.is_symlink():
        declared = {r.get("path"): r.get("sha256") for r in document(original_manifest).get("files", []) if isinstance(r, dict)}
    for path in sorted(root.rglob("*")):
        name = path.relative_to(root).as_posix()
        require(not path.is_symlink(), "SYMLINK_REJECTED")
        if path.is_dir():
            require(not {".git", ".local", "vendor", "node_modules"}.intersection(path.relative_to(root).parts), "FORBIDDEN_DIRECTORY")
            continue
        source = file(root, name)
        if proof_files is not None and name in proof_files:
            require(digest(source) == proof_files[name], 'ARTIFACT_PROOF_CHANGED')
            continue
        logical = name.split("/files/", 1)[1] if name.startswith(".peanut/scaffold-baseline/") and "/files/" in name else name
        # Baseline archives retain their original digest. Projected archives
        # are accepted only at the exact marker-bound paths, never by suffix.
        original_frontend_archive = bool(re.fullmatch(
            r"packages/core-web/peanut-admin-(client|vue|ui-vue|nuxt|uniapp|testing)-[0-9A-Za-z.+-]+\.tgz", logical
        )) and declared.get(logical) == digest(source)
        require(name in (archive_paths or set()) or _policy.allowed_source(name) or original_frontend_archive,
                "FORBIDDEN_SOURCE:" + name)
        if name != MARKER:
            result[name] = {"sha256": digest(source), "mode": stat.S_IMODE(source.stat().st_mode)}
    return result


def tree_digest(value: dict) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def allowed_projection(name: str) -> bool:
    return name in {"server/composer.json", "server/composer.lock", "plugins.lock", "release-versions.json",
                   *[f"{client}/{filename}" for client, filename in
                     (("web", "pnpm-lock.yaml"), ("platform", "package-lock.json"),
                      ("pc", "package-lock.json"), ("uniapp", "package-lock.json"))],
                   *[f"{client}/package.json" for client in ("web", "platform", "pc", "uniapp")]} or bool(
        re.fullmatch(r"server/app/modules/[a-z0-9_-]+/[a-z0-9_-]+/composer\.json", name)
        or re.fullmatch(r"plugins/[a-z0-9._-]+/plugin\.json", name)
    )


def archives(marker: dict) -> dict[str, dict]:
    core = marker.get("core")
    require(isinstance(core, dict), "CORE_REQUIRED")
    result = {relative(core.get("path_in_archive")): core}
    web = marker.get("core_web")
    if web is not None:
        require(isinstance(web, dict) and len(web) == 6, "WEB_INPUTS_REQUIRED")
        for suffix in ("client", "vue", "ui-vue", "nuxt", "uniapp", "testing"):
            row = web.get("@peanut-admin/" + suffix)
            require(isinstance(row, dict), "WEB_PACKAGE_MISSING")
            name = relative(row.get("archive"))
            require(name == f"packages/core-web/peanut-admin-{suffix}-{row.get('version')}.tgz"
                    and re.fullmatch(r"[0-9a-f]{64}", str(row.get("sha256", ""))) is not None,
                    "WEB_ARCHIVE_INVALID")
            result[name] = row
    return result


def artifact_proofs(root: Path, scaffold_path: Path | None = None, core_path: Path | None = None) -> dict:
    """Authenticate the existing artifact provenance; never rewrite an APP or a lock."""
    manifest = document(file(root, '.peanut/application-manifest.json'))
    candidate = manifest.get('scaffold_candidate', {})
    require(isinstance(candidate, dict) and candidate.get('formal_release_eligible') is False,
            'ARTIFACT_CANDIDATE_REQUIRED')
    require((scaffold_path is None) == (core_path is None), 'ARTIFACT_PROOF_PAIR_REQUIRED')
    paths = [file(root, name) for name in ARTIFACT_PROOFS] if scaffold_path is None else [scaffold_path, core_path]
    if scaffold_path is None:
        require(all(stat.S_IMODE(path.stat().st_mode) == 0o644 for path in paths), 'ARTIFACT_PROOF_MODE_INVALID')
    raw = {}
    for name, path in zip(ARTIFACT_PROOFS, paths):
        require(path.is_absolute() and path.resolve() == path, 'ARTIFACT_PROOF_PATH_INVALID')
        raw[name] = file(path.parent, path.name).read_bytes()
    require(hashlib.sha256(raw[ARTIFACT_PROOFS[0]]).hexdigest() == candidate.get('manifest_sha256')
            and hashlib.sha256(raw[ARTIFACT_PROOFS[1]]).hexdigest() == candidate.get('core_inputs_sha256'),
            'ARTIFACT_PROOF_DIGEST_MISMATCH')
    scaffold, receipt = (json.loads(raw[name]) for name in ARTIFACT_PROOFS)

    def portable(value):
        if isinstance(value, dict):
            for child in value.values():
                portable(child)
        elif isinstance(value, list):
            for child in value:
                portable(child)
        elif isinstance(value, str):
            require(re.match(r'^(?:/|[A-Za-z]:[\\/]|file:)', value) is None, 'ARTIFACT_PROOF_HOST_PATH')

    portable(scaffold); portable(receipt)
    require(isinstance(scaffold, dict) and scaffold.get('schema_version') == 3
            and scaffold.get('protocol') == 'peanut.scaffold-release.v3'
            and isinstance(receipt, dict) and receipt.get('schema_version') == 1
            and receipt.get('status') == 'frozen-core-build-inputs-not-product-release', 'ARTIFACT_PROOF_PROTOCOL_INVALID')
    release = scaffold.get('release', {})
    projection = scaffold.get('internal_core_projection', {})
    generation = manifest.get('generation_source', {})
    template = manifest.get('template', {})
    require(all(isinstance(value, dict) for value in (release, projection, generation, template)), 'ARTIFACT_SOURCE_INVALID')
    require(manifest.get('protocol') == 'peanut.application-scaffold.v2'
            and manifest.get('schema_version') == 2
            and projection.get('protocol') == 'peanut.internal-core-scaffold-projection.v1'
            and projection.get('scope') == 'internal-upgrade-target-not-formal-release'
            and projection.get('core_inputs_sha256') == candidate['core_inputs_sha256']
            and release.get('workflow') == 'full' and manifest.get('application', {}).get('profile') == 'full'
            and manifest.get('edition') == scaffold.get('edition')
            and generation.get('channel') == 'development' and generation.get('release_version') is None
            and template.get('version') == release.get('version') == release.get('inventory_template_version'), 'ARTIFACT_SOURCE_INVALID')
    for name, field, length in [('commit', 'source_commit', 40), ('tree', 'source_tree', 40), ('inventory_sha256', 'inventory_sha256', 64)]:
        value = generation.get(name)
        require(isinstance(value, str) and re.fullmatch('[0-9a-f]{' + str(length) + '}', value) is not None
                and value == release.get(field) and value == template.get(field if name != 'inventory_sha256' else name),
                'ARTIFACT_SOURCE_BINDING_MISMATCH')
    require(projection.get('source') == {key: generation[key] for key in ('commit', 'tree')}
            and projection.get('managed_tree_sha256', {}).get('projected') == release.get('managed_tree_sha256')
            and generation.get('edition_profile_sha256') == scaffold.get('edition', {}).get('source_sha256'),
            'ARTIFACT_SOURCE_PROJECTION_MISMATCH')
    core = receipt.get('core_php', {})
    archive = receipt.get('core_php_archive', {})
    php = projection.get('core', {})
    require(all(isinstance(value, dict) for value in (core, archive, php, receipt.get('sources', {}))), 'ARTIFACT_PHP_INPUT_MISMATCH')
    require(core.get('package') == 'peanut-admin/core' and core.get('resolved_version') == release.get('version')
            and core.get('constraint') == release.get('version')
            and re.fullmatch(r'[0-9a-f]{40}', str(core.get('source_reference', ''))) is not None
            and re.fullmatch(r'[0-9a-f]{64}', str(archive.get('sha256', ''))) is not None
            and re.fullmatch(r'[0-9a-f]{40}', str(archive.get('sha1', ''))) is not None
            and receipt.get('sources', {}).get('php', {}).get('commit') == core.get('source_reference')
            and php == {'package': core['package'], 'resolved_version': core['resolved_version'],
                        'source_reference': core['source_reference'], 'archive_path': archive.get('path'),
                        'archive_sha256': archive.get('sha256'), 'archive_sha1': archive.get('sha1')},
            'ARTIFACT_PHP_INPUT_MISMATCH')
    versions = document(file(root, 'release-versions.json'))
    require(versions.get('schema_version') == 3 and versions.get('core_php') == core
            and versions.get('core_web') == receipt.get('core_web')
            and projection.get('web_source_reference') == receipt.get('core_web', {}).get('source_reference')
            and projection.get('web_source_reference') == receipt.get('sources', {}).get('web', {}).get('commit')
            and re.fullmatch(r'[0-9a-f]{40}', str(projection.get('web_source_reference', ''))) is not None,
            'ARTIFACT_VERSION_INPUT_MISMATCH')
    web = projection.get('core_web')
    require(isinstance(web, dict) and len(web) == 6, 'ARTIFACT_WEB_INPUTS_REQUIRED')
    for package, record in web.items():
        require(isinstance(record, dict) and record.get('version') == release.get('version')
                and receipt.get('core_web', {}).get('packages', {}).get(package) ==
                {key: record.get(key) for key in ('version', 'archive', 'sha256')}, 'ARTIFACT_WEB_INPUT_MISMATCH')
    app_rows = manifest.get('files')
    scaffold_rows = scaffold.get('files')
    require(isinstance(app_rows, list) and isinstance(scaffold_rows, list), 'ARTIFACT_FILES_REQUIRED')
    require(all(isinstance(row, dict) for row in [*app_rows, *scaffold_rows]), 'ARTIFACT_FILE_INVALID')
    app_index = {relative(row.get('path')): row for row in app_rows}
    scaffold_index = {relative(row.get('path')): row for row in scaffold_rows}
    require(len(app_index) == len(app_rows) and len(scaffold_index) == len(scaffold_rows)
            and set(app_index) == set(scaffold_index) | {'SECURITY.md', 'resources/project-resources.json',
                'scripts/seed-demo-data', 'server/config/peanut.php', 'web/src/peanut.overrides.ts'}, 'ARTIFACT_FILE_SET_MISMATCH')
    for name, row in scaffold_index.items():
        require(app_index[name].get('classification') == row.get('classification')
                and app_index[name].get('mode') == row.get('mode'), 'ARTIFACT_OWNERSHIP_MISMATCH:' + name)
    descriptor = {'core': {'package': core['package'], 'resolved_version': core['resolved_version'],
                          'source_reference': core['source_reference'], 'path_in_archive': relative(archive.get('path')),
                          'sha256': archive.get('sha256'), 'sha1': archive.get('sha1')},
                  'core_web': web, 'web_source_reference': projection['web_source_reference'],
                  'core_inputs_sha256': candidate['core_inputs_sha256']}
    archives(descriptor)
    raw[ARTIFACT_TEMPLATES] = artifact_contents(root, manifest, scaffold, scaffold_path)
    return {'manifest': manifest, 'raw': raw, 'descriptor': descriptor}


def artifact_contents(root: Path, manifest: dict, scaffold: dict, scaffold_path: Path | None) -> bytes:
    """Bind final managed bytes to fixed templates and their native derived consumers."""
    rows = {relative(row['path']): row for row in manifest['files']}
    released = {relative(row['path']): row for row in scaffold['files']}
    baseline_root = relative(manifest['ownership']['baseline_root'])
    require(baseline_root == '.peanut/scaffold-baseline/' + manifest['template']['version'] + '/files',
            'ARTIFACT_BASELINE_ROOT_INVALID')
    expected = {'.peanut/application-manifest.json', *rows}
    baseline_bytes = {}
    for name, row in rows.items():
        source = file(root, name)
        require(digest(source) == row.get('sha256') and stat.S_IMODE(source.stat().st_mode) == row.get('mode'),
                'ARTIFACT_SOURCE_CHANGED:' + name)
        if name not in released:
            require(row.get('classification') == 'app-owned', 'ARTIFACT_APP_OWNERSHIP_INVALID')
            continue
        require(row.get('classification') in ('managed', 'generated-managed')
                and row.get('baseline_path') == baseline_root + '/' + name, 'ARTIFACT_BASELINE_PATH_INVALID')
        baseline = file(root, row['baseline_path'])
        data = baseline.read_bytes()
        require(hashlib.sha256(data).hexdigest() == row.get('baseline_sha256') == row.get('sha256')
                and stat.S_IMODE(baseline.stat().st_mode) == 0o644, 'ARTIFACT_BASELINE_CHANGED:' + name)
        expected.add(row['baseline_path'])
        baseline_bytes[name] = data
    # Authenticate every executable input before invoking the existing read-only producers.
    proof_names = {*ARTIFACT_PROOFS, ARTIFACT_TEMPLATES}
    has_marker = (root / MARKER).exists() or (root / MARKER).is_symlink()
    if has_marker:
        expected.update({MARKER, *proof_names})
    for directory, children, names in os.walk(root, followlinks=False):
        parent = Path(directory)
        kept = []
        for child in children:
            path = parent / child
            name = path.relative_to(root).as_posix()
            if not has_marker and scaffold_path is not None and name in INSTALLED_DIRECTORIES:
                continue
            require(not path.is_symlink(), 'ARTIFACT_SOURCE_SYMLINK')
            kept.append(child)
        children[:] = kept
        for child in names:
            name = (parent / child).relative_to(root).as_posix()
            require(name in expected, 'ARTIFACT_SOURCE_EXTRA:' + name)
            file(root, name)
    require(len({name.casefold() for name in expected}) == len(expected), 'ARTIFACT_CASE_COLLISION')

    def immutable(name):
        return (name.startswith(('server/database/baselines/', 'server/database/migrations/'))
                or re.fullmatch(r'packages/core-(?:php|web)/[^/]+\.(?:zip|tgz)', name) is not None)

    def canonical(name):
        return name == 'server/generated/api-catalog.json' or re.fullmatch(r'plugins/[a-z][a-z0-9.-]*/plugin\.json', name) is not None

    needed = set()
    for name, row in released.items():
        require(row.get('source') == 'files/' + name
                and re.fullmatch(r'[0-9a-f]{64}', str(row.get('template_sha256', ''))) is not None,
                'ARTIFACT_TEMPLATE_INVALID:' + name)
        if immutable(name):
            require(hashlib.sha256(baseline_bytes[name]).hexdigest() == row['template_sha256'],
                    'ARTIFACT_IMMUTABLE_TEMPLATE_MISMATCH:' + name)
        elif not canonical(name) and hashlib.sha256(baseline_bytes[name]).hexdigest() != row['template_sha256']:
            needed.add(name)
    if scaffold_path is not None:
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
            for name in sorted(needed):
                data = file(scaffold_path.parent, released[name]['source']).read_bytes()
                require(hashlib.sha256(data).hexdigest() == released[name]['template_sha256'],
                        'ARTIFACT_TEMPLATE_DIGEST_MISMATCH:' + name)
                entry = zipfile.ZipInfo(name)
                entry.compress_type = zipfile.ZIP_DEFLATED
                entry.external_attr = 0o100644 << 16
                archive.writestr(entry, data)
        witness = buffer.getvalue()
    else:
        path = file(root, ARTIFACT_TEMPLATES)
        require(stat.S_IMODE(path.stat().st_mode) == 0o644, 'ARTIFACT_PROOF_MODE_INVALID')
        witness = path.read_bytes()
    templates = {}
    with zipfile.ZipFile(io.BytesIO(witness)) as archive:
        members = archive.infolist()
        require(len(members) == len(needed) and {row.filename for row in members} == needed,
                'ARTIFACT_TEMPLATE_SET_MISMATCH')
        for entry in members:
            name = relative(entry.filename)
            require(entry.external_attr >> 16 == 0o100644 and not entry.is_dir(), 'ARTIFACT_TEMPLATE_MODE_INVALID')
            data = archive.read(entry)
            require(hashlib.sha256(data).hexdigest() == released[name]['template_sha256'],
                    'ARTIFACT_TEMPLATE_DIGEST_MISMATCH:' + name)
            templates[name] = data
    tokens = scaffold['release']['tokens']
    application = manifest['application']
    values = {'product_name': application['name'], 'slug': application['slug'],
              'package_identity': application['package_identity'], 'application_version': application['version']}
    require(isinstance(tokens, dict) and list(tokens) == list(values)
            and all(isinstance(value, str) and value for value in [*tokens.values(), *values.values()]),
            'ARTIFACT_RENDER_PARAMETERS_INVALID')
    pending = {}
    for name, row in released.items():
        if canonical(name):
            continue
        data = templates.get(name, baseline_bytes[name])
        require(hashlib.sha256(data).hexdigest() == row['template_sha256'], 'ARTIFACT_TEMPLATE_DIGEST_MISMATCH:' + name)
        # Identical authenticated bytes without tokens need no renderer invocation.
        if name == 'plugins.lock' or (not immutable(name) and any(token.encode() in data for token in tokens.values())) or name in needed:
            pending[name] = {'raw': base64.b64encode(data).decode(), 'transform': row['transform']}
        else:
            require(data == baseline_bytes[name], 'ARTIFACT_RENDER_MISMATCH:' + name)
    composer = templates.get('server/composer.json', baseline_bytes['server/composer.json'])
    payload = {'tokens': tokens, 'values': values, 'files': pending,
               'composer': base64.b64encode(composer).decode()}
    native_root = ROOT.parent
    for name in ('scripts/scaffold-runtime/ScaffoldManifest.php',
                 'server/app/platform/exception/plugin/PluginArtifactToolException.php',
                 'server/app/platform/value/plugin/ModuleFrontendLayout.php',
                 'server/app/platform/infrastructure/plugin/PluginArtifactWriter.php'):
        # These unbranded native tools bootstrap proof verification. Never execute
        # APP-supplied replacements before their original template bytes are bound.
        require(name in released and digest(file(root, name)) == released[name]['template_sha256']
                and file(native_root, name).read_bytes() == file(root, name).read_bytes(),
                'ARTIFACT_NATIVE_TOOL_MISMATCH:' + name)
    code = r'''
require $argv[1].'/scripts/scaffold-runtime/ScaffoldManifest.php';
$p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $hashes=[]; $lock=null;
foreach($p['files'] as $name=>$row){
    $content=\app\common\value\scaffold\ScaffoldManifest::renderArtifactContent(
        base64_decode($row['raw'],true),$name,$row['transform'],$p['tokens'],$p['values'],base64_decode($p['composer'],true));
    if($name==='plugins.lock'){$lock=base64_encode($content);}
    else{$hashes[$name]=hash('sha256',$content);}
}
echo json_encode(['hashes'=>$hashes,'lock'=>$lock],JSON_THROW_ON_ERROR);
'''
    result = subprocess.run(['php', '-r', code, str(native_root), str(root)],
                            input=json.dumps(payload).encode(), capture_output=True, check=True)
    response = json.loads(result.stdout)
    rendered = response['hashes']
    require(set(rendered) == set(pending) - {'plugins.lock'}, 'ARTIFACT_RENDER_SET_MISMATCH')
    for name, value in rendered.items():
        require(value == rows[name]['baseline_sha256'], 'ARTIFACT_RENDER_MISMATCH:' + name)
    # All ordinary source is now authenticated. Reuse native derived producers
    # only after this point; neither can endorse an unproven ordinary source file.
    plugin_code = r'''
require $argv[1].'/server/app/platform/exception/plugin/PluginArtifactToolException.php';
require $argv[1].'/server/app/platform/value/plugin/ModuleFrontendLayout.php';
require $argv[1].'/server/app/platform/infrastructure/plugin/PluginArtifactWriter.php';
$lock=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
(new \app\platform\infrastructure\plugin\PluginArtifactWriter($argv[2].'/server',false))->assertScaffoldRendering($lock);
'''
    subprocess.run(['php', '-r', plugin_code, str(native_root), str(root)],
                   input=base64.b64decode(response['lock'], validate=True), check=True, capture_output=True)
    with tempfile.TemporaryDirectory(prefix='peanut-candidate-catalog-') as temporary:
        output = Path(temporary) / 'api-catalog.json'
        subprocess.run(['php', str(file(root, 'scripts/generate-api-contracts.php')),
                        '--application-root=' + str(root), '--check', '--catalog-output=' + str(output)],
                       check=True, capture_output=True)
        require(output.read_bytes() == baseline_bytes['server/generated/api-catalog.json'], 'ARTIFACT_CATALOG_MISMATCH')
    return witness


def artifact_source(root: Path, scaffold_path: Path, core_path: Path) -> tuple[dict, dict[str, bytes]]:
    """Freeze the real derived APP source; only its five known installs are omitted."""
    require(root.resolve() == Path(__file__).resolve().parent.parent, 'ARTIFACT_PACKER_SOURCE_MISMATCH')
    proof = artifact_proofs(root, scaffold_path, core_path)
    manifest = proof['manifest']
    expected = {'.peanut/application-manifest.json': digest(file(root, '.peanut/application-manifest.json'))}
    for row in manifest['files']:
        name = relative(row['path'])
        require(row.get('mode') in (0o644, 0o755), 'ARTIFACT_SOURCE_MODE_INVALID')
        expected[name] = row.get('sha256')
        if row.get('classification') in ('managed', 'generated-managed'):
            baseline = relative(row.get('baseline_path'))
            require(baseline == manifest['ownership']['baseline_root'] + '/' + name, 'ARTIFACT_BASELINE_PATH_INVALID')
            expected[baseline] = row.get('baseline_sha256')
    source = {}
    for directory, children, names in os.walk(root, followlinks=False):
        parent = Path(directory)
        kept = []
        for child in children:
            path = parent / child
            name = path.relative_to(root).as_posix()
            if name in INSTALLED_DIRECTORIES:
                continue
            require(not path.is_symlink(), 'ARTIFACT_SOURCE_SYMLINK')
            kept.append(child)
        children[:] = kept
        for child in names:
            name = (parent / child).relative_to(root).as_posix()
            require(name in expected, 'ARTIFACT_SOURCE_EXTRA:' + name)
            path = file(root, name)
            mode = stat.S_IMODE(path.stat().st_mode)
            require(mode in (0o644, 0o755) and digest(path) == expected[name], 'ARTIFACT_SOURCE_CHANGED:' + name)
            source[name] = {'sha256': expected[name], 'mode': mode}
    require(set(source) == set(expected), 'ARTIFACT_SOURCE_MISSING')
    for row in manifest['files']:
        require(source[row['path']]['mode'] == row['mode'], 'ARTIFACT_SOURCE_MODE_CHANGED')
    source = dict(sorted(source.items()))
    marker = {'schema_version': 1, 'protocol': PROTOCOL, 'scope': 'internal-candidate', 'formal_release_eligible': False,
              'generation_source': manifest['generation_source'], 'original_manifest_sha256': expected['.peanut/application-manifest.json'],
              **proof['descriptor'], 'projection': {'original_tree_sha256': tree_digest(source),
                  'projected_tree_sha256': tree_digest(source), 'changes': [], 'additions': []},
              'selection': {'edition': manifest['application']['edition'], 'profile': 'full', 'workflow': 'full',
                            'clients': ['Admin', 'Platform', 'PC SPA', 'UniApp H5']}, 'source_files': source}
    return marker, proof['raw']


def verify(root: Path, expected: dict | None = None) -> dict:
    marker_path = file(root, MARKER)
    marker = document(marker_path)
    require(marker.get("protocol") == PROTOCOL and marker.get("scope") == "internal-candidate"
            and marker.get("formal_release_eligible") is False, "MARKER_IDENTITY_INVALID")
    projection = marker.get('projection')
    artifact = None
    if isinstance(projection, dict) and projection.get('changes') == [] and projection.get('additions') == []:
        artifact = artifact_proofs(root)
        require(all(marker.get(key) == value for key, value in artifact['descriptor'].items()), 'ARTIFACT_MARKER_INPUT_MISMATCH')
    core = marker.get("core")
    require(isinstance(core, dict), "CORE_REQUIRED")
    core_path = relative(core.get("path_in_archive"))
    require(core_path.startswith("packages/core-php/") and core_path.endswith(".zip"), "CORE_PATH_INVALID")
    require(core.get("package") == "peanut-admin/core"
            and isinstance(core.get("resolved_version"), str)
            and re.fullmatch(r"[0-9a-f]{40}", str(core.get("source_reference", ""))) is not None, "CORE_IDENTITY_INVALID")
    require(digest(file(root, core_path)) == core.get("sha256"), "CORE_DIGEST_MISMATCH")
    inputs = archives(marker)
    for name, row in inputs.items():
        require(digest(file(root, name)) == row.get("sha256"), "ARCHIVE_DIGEST_MISMATCH:" + name)
        if name.endswith(".tgz"):
            import base64
            expected_sri = "sha512-" + base64.b64encode(hashlib.sha512(file(root, name).read_bytes()).digest()).decode()
            require(row.get("integrity") == expected_sri, "ARCHIVE_SRI_MISMATCH")
    proof_files = ({name: hashlib.sha256(raw).hexdigest() for name, raw in artifact['raw'].items()}
                   if artifact is not None else None)
    current = tree(root, set(inputs), proof_files=proof_files)
    projection = marker.get("projection")
    require(isinstance(projection, dict), "PROJECTION_REQUIRED")
    require(tree_digest(current) == projection.get("projected_tree_sha256"), "PROJECTED_TREE_MISMATCH")
    original = dict(current)
    changes = projection.get("changes")
    additions = projection.get("additions")
    require(isinstance(changes, list) and isinstance(additions, list), "PROJECTION_ROWS_INVALID")
    seen = set()
    for row in changes:
        require(isinstance(row, dict), "PROJECTION_ROW_INVALID")
        name = relative(row.get("path"))
        require(name not in seen and (allowed_projection(name) or name in inputs), "PROJECTION_PATH_INVALID")
        seen.add(name)
        require(current.get(name) == row.get("after") and isinstance(row.get("before"), dict), "PROJECTION_DIGEST_MISMATCH")
        original[name] = row["before"]
    for row in additions:
        require(isinstance(row, dict), "ADDITION_INVALID")
        name = relative(row.get("path"))
        require(name in inputs and name not in seen and current.get(name) == row.get("after"), "ADDITION_DIGEST_MISMATCH")
        seen.add(name)
        original.pop(name)
    require(core_path in seen or (artifact is not None and projection.get('original_tree_sha256') == projection.get('projected_tree_sha256')
                                 and current.get(core_path, {}).get('sha256') == core.get('sha256')), "CORE_ADDITION_REQUIRED")
    require(tree_digest(original) == projection.get("original_tree_sha256"), "ORIGINAL_TREE_MISMATCH")
    manifest_path = file(root, ".peanut/application-manifest.json")
    require(digest(manifest_path) == marker.get("original_manifest_sha256"), "ORIGINAL_MANIFEST_MISMATCH")
    manifest = document(manifest_path)
    require(manifest.get("generation_source") == marker.get("generation_source"), "SOURCE_IDENTITY_MISMATCH")
    rows = manifest.get("files")
    require(isinstance(rows, list) and len(rows) > 0, "SOURCE_FILES_REQUIRED")
    for row in rows:
        name = relative(row.get("path"))
        require(original.get(name) == {"sha256": row.get("sha256"), "mode": row.get("mode")}, "SOURCE_BASELINE_MISMATCH:" + name)
        if row.get("classification") in ("managed", "generated-managed"):
            baseline = relative(row.get("baseline_path"))
            require(digest(file(root, baseline)) == row.get("baseline_sha256"), "IMMUTABLE_BASELINE_MISMATCH:" + name)
    if marker.get("core_web") is not None:
        require(marker.get("source_files") == current, "SEALED_SOURCE_INVENTORY_MISMATCH")
        selection = marker.get("selection", {})
        require(selection == {"edition": manifest.get("application", {}).get("edition"), "profile": "full", "workflow": "full",
                              "clients": ["Admin", "Platform", "PC SPA", "UniApp H5"]}, "SELECTION_INVALID")
        require(re.fullmatch(r"[0-9a-f]{64}", str(marker.get("core_inputs_sha256", ""))) is not None, "INPUT_RECEIPT_DIGEST_INVALID")
        versions = document(file(root, "release-versions.json"))
        require(versions.get("core_php", {}).get("source_reference") == core["source_reference"]
                and versions.get("core_web", {}).get("source_reference") == marker.get("web_source_reference"), "PROJECTED_VERSIONS_MISMATCH")
        for package, row in marker["core_web"].items():
            require(versions.get("core_web", {}).get("packages", {}).get(package) ==
                    {k: row[k] for k in ("version", "archive", "sha256")}, "WEB_VERSION_IDENTITY_MISMATCH")
    composer = document(file(root, "server/composer.json"))
    lock = document(file(root, "server/composer.lock"))
    require(composer.get("require", {}).get("peanut-admin/core") == core["resolved_version"], "CORE_REQUIRE_MISMATCH")
    packages = [p for p in lock.get("packages", []) if p.get("name") == "peanut-admin/core"]
    require(len(packages) == 1, "CORE_LOCK_MISSING")
    package = packages[0]
    require(package.get("version") == core["resolved_version"]
            and package.get("source", {}).get("reference") == core["source_reference"]
            and package.get("dist", {}).get("reference") == core["source_reference"]
            and package.get("dist", {}).get("url") == "../" + core_path
            and package.get("dist", {}).get("shasum") == core.get("sha1"), "CORE_LOCK_MISMATCH")
    modules = []
    for path in sorted((root / "server/app/modules").glob("*/*/composer.json")):
        value = document(path).get("require", {}).get("peanut-admin/core")
        if value is not None:
            require(value == core["resolved_version"], "NESTED_CORE_REQUIRE_MISMATCH")
            modules.append(path.relative_to(root).as_posix())
    if expected is not None:
        require(expected.get("protocol") == PROTOCOL and expected.get("scope") == "internal-candidate", "EXTERNAL_SCOPE_MISMATCH")
        require(expected.get("candidate_marker") == {"path": MARKER, "sha256": digest(marker_path)}, "EXTERNAL_MARKER_MISMATCH")
        require(expected.get("projection") == projection and expected.get("core_php_archive") == core, "EXTERNAL_PROJECTION_MISMATCH")
        if marker.get("core_web") is not None:
            require(expected.get("core_web_archives") == marker["core_web"]
                    and expected.get("selection") == marker["selection"]
                    and expected.get("build_tool_inputs", {}).get("core_receipt_sha256") == marker["core_inputs_sha256"], "EXTERNAL_SEVEN_INPUT_MISMATCH")
        require(expected.get("source", {}).get("commit") == marker["generation_source"].get("commit")
                and expected.get("source", {}).get("tree") == marker["generation_source"].get("tree")
                and expected.get("edition") == manifest.get("edition"), "EXTERNAL_SOURCE_MISMATCH")
        for name, value in expected.get("dependency_locks", {}).items():
            if name.startswith("third_party_"):
                continue
            require(digest(file(root, name)) == value, "LOCK_DIGEST_MISMATCH")
    return {"status": "verified-internal-candidate", "candidate_marker": {"path": MARKER, "sha256": digest(marker_path)},
            "projection": projection, "source_file_count": len(rows), "nested_core_manifests": modules,
            "source_files": current, "archive_paths": sorted(inputs)}


def seal(root: Path, captured: Path, core_file: Path) -> dict:
    before = document(captured)
    descriptor = document(core_file)
    core = descriptor.get("core", descriptor)
    name = relative(core.get("path_in_archive"))
    inputs = archives(descriptor if "core" in descriptor else {"core": core})
    after = tree(root, set(inputs))
    require(set(before) <= set(after), "SOURCE_REMOVAL_REJECTED")
    require(set(after) - set(before) <= set(inputs) and name in set(after) - set(before), "UNEXPECTED_ADDITION")
    changes = []
    for path, previous in before.items():
        if previous != after[path]:
            require(allowed_projection(path) or path in inputs, "UNEXPECTED_PROJECTION:" + path)
            changes.append({"path": path, "before": previous, "after": after[path]})
    manifest_file = file(root, ".peanut/application-manifest.json")
    marker = {"schema_version": 1, "protocol": PROTOCOL, "scope": "internal-candidate", "formal_release_eligible": False,
              "generation_source": document(manifest_file)["generation_source"], "original_manifest_sha256": digest(manifest_file),
              "core": core, "projection": {"original_tree_sha256": tree_digest(before), "projected_tree_sha256": tree_digest(after),
              "changes": changes, "additions": [{"path": p, "after": after[p]} for p in sorted(set(after) - set(before))]}}
    if "core_web" in descriptor:
        marker.update({k: descriptor[k] for k in ("core_web", "web_source_reference", "core_inputs_sha256", "selection")})
        marker["source_files"] = after
    path = root / MARKER
    with path.open("x") as target:
        json.dump(marker, target, ensure_ascii=False, indent=2)
        target.write("\n")
    path.chmod(0o644)
    return verify(root)


def verify_assembled(root: Path, expected: dict | None = None) -> dict:
    """A complete APP has browser outputs; its sealed source is still exact."""
    marker = document(file(root, MARKER))
    # The complete package has generated backend/browser outputs. Authenticate
    # its finite proof metadata only in the strict source-only view below.
    artifact = (marker.get('projection', {}).get('changes') == []
                and marker.get('projection', {}).get('additions') == [])
    proof_names = {*ARTIFACT_PROOFS, ARTIFACT_TEMPLATES} if artifact else set()
    identity_name = ".peanut/application-release.json"
    identity = document(file(root, identity_name))
    require(identity.get("protocol") == "peanut.application-release.v1"
            and identity.get("application", {}).get("scope") == "internal-candidate"
            and identity["application"].get("formal_release_eligible") is False, "ASSEMBLED_IDENTITY_INVALID")
    paths = set()
    for path in root.rglob("*"):
        require(not path.is_symlink(), "ASSEMBLED_SYMLINK_REJECTED")
        name = path.relative_to(root).as_posix()
        require(name.casefold() not in paths, "ASSEMBLED_CASE_COLLISION")
        paths.add(name.casefold())
    rows = _policy.release_rows(root)
    rows = [row for row in rows if row["path"] != identity_name]
    require(rows == identity.get("files") and _policy.rows_sha256(rows) == identity.get("files_sha256"), "ASSEMBLED_TREE_MISMATCH")
    actual = {r["path"]: {k: r[k] for k in ("sha256", "mode")} for r in rows}
    backend_name = 'server/.peanut/release-identity.json'
    backend = document(file(root, backend_name))
    backend_application = backend.get('application', {})
    require(backend.get('protocol') == 'peanut.server-release.v1'
            and backend.get('schema_version') == 1
            and backend_application.get('kind') == 'internal-candidate'
            and backend_application.get('scope') == 'internal-candidate'
            and backend_application.get('formal_release_eligible') is False
            and backend_application.get('candidate_marker') == {'path': MARKER, 'sha256': digest(file(root, MARKER))}
            and actual.get(backend_name, {}).get('mode') == 0o644
            and actual.get('server/' + MARKER) == {'sha256': digest(file(root, MARKER)), 'mode': 0o644}
            and file(root, 'server/' + MARKER).read_bytes() == file(root, MARKER).read_bytes(),
            'ASSEMBLED_BACKEND_CANDIDATE_INVALID')
    backend_rows = [row for row in rows if row['path'].startswith('server/') and row['path'] != backend_name
                    and not any(row['path'].startswith(prefix) for prefix in _policy.PROTECTED_RUNTIME_PREFIXES)]
    _policy.validate_server_release_rows(backend_rows)
    require(backend.get('files') == backend_rows and backend.get('files_sha256') == _policy.rows_sha256(backend_rows),
            'ASSEMBLED_BACKEND_FILES_MISMATCH')
    sealed = marker.get("source_files")
    require(isinstance(sealed, dict) and sealed, "SEALED_SOURCE_REQUIRED")
    for name, row in sealed.items():
        require(actual.get(name) == row, "ASSEMBLED_SOURCE_CHANGED:" + name)
    plugins = document(file(root, 'plugins.lock'))['plugins']
    backend_outputs = _policy.SERVER_RELEASE_OUTPUTS | {f"server/plugins/{entry['key']}/plugin.json" for entry in plugins}
    require(backend_outputs <= set(actual), 'ASSEMBLED_BACKEND_METADATA_MISSING')
    require(backend_application.get('source_files_sha256') == _policy.rows_sha256(
        [row for row in rows if row['path'] not in backend_outputs]), 'ASSEMBLED_BACKEND_INPUT_MISMATCH')
    prefixes = ("server/public/admin/", "server/public/platform/", "server/public/pc/", "server/public/mobile/")
    for name in set(actual) - set(sealed) - backend_outputs - proof_names - {MARKER, "release-manifest.txt"}:
        prefix = next((p for p in prefixes if name.startswith(p)), None)
        asset = name[len(prefix):] if prefix else ""
        require(bool(asset) and _policy.allowed_source(asset)
                and PurePosixPath(asset).parts[0] not in {"storage", "private", "server", "scripts"}
                and not any(part.startswith(".") for part in PurePosixPath(asset).parts)
                and re.search(r"\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh)(?:\.|$)", asset, re.I) is None,
                "ASSEMBLED_EXTRA_FILE:" + name)
    for prefix in prefixes:
        require(prefix + "index.html" in actual, "BROWSER_ENTRY_MISSING:" + prefix)
    require(identity["application"].get("candidate_marker") == {"path": MARKER, "sha256": digest(file(root, MARKER))}, "ASSEMBLED_MARKER_MISMATCH")
    require(identity["application"].get("manifest_sha256") == marker.get("original_manifest_sha256")
            and identity["application"].get("projection") == marker.get("projection")
            and identity["application"].get("selection") == marker.get("selection"), "ASSEMBLED_ORIGINAL_IDENTITY_MISMATCH")
    if expected is not None:
        require(expected.get("application", {}).get("release_identity_sha256") == digest(file(root, identity_name)), "EXTERNAL_ASSEMBLED_IDENTITY_MISMATCH")
    # Verify the source contract in a source-only view without weakening tree().
    import tempfile
    import shutil
    with tempfile.TemporaryDirectory(prefix="peanut-internal-verify-") as temporary:
        source = Path(temporary)
        for name in [*sealed, MARKER, *sorted(proof_names)]:
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(file(root, name), target)
            target.chmod(actual[name]["mode"])
        result = verify(source, expected)
        manifest = document(file(source, '.peanut/application-manifest.json'))
        versions = document(file(source, 'release-versions.json'))
        require(all(backend_application.get(key) == manifest['application'][key]
                    for key in ('name', 'slug', 'edition', 'version', 'profile', 'package_identity'))
                and backend_application.get('manifest_sha256') == marker['original_manifest_sha256']
                and backend_application.get('projection') == marker['projection']
                and backend_application.get('selection') == marker['selection']
                and backend_application.get('commit') is None and backend_application.get('tree') is None
                and backend.get('edition_contract') == manifest['edition']
                and backend.get('edition_profile_sha256') == manifest['edition']['source_sha256']
                and backend.get('template') == manifest['template']
                and backend.get('upstream') == {key: marker['generation_source'][key]
                                                for key in ('commit', 'tree', 'inventory_sha256')}
                and backend.get('versions') == {
                    'source_product_version': versions['source_product_version'],
                    'release_sequence_version': versions.get('instance_version') or versions['source_product_version'],
                    'scaffold_template': versions['scaffold_template']}, 'ASSEMBLED_BACKEND_SOURCE_MISMATCH')
        copies = {f'server/.peanut/{name}': name for name in (
            'RELEASE_METADATA.json', 'release-versions.json', 'LICENSE', 'NOTICE',
            'THIRD_PARTY_NOTICES.md', 'RELEASE_SBOM.spdx.json')}
        copies['server/resources/project-resources.json'] = 'resources/project-resources.json'
        for target, origin in copies.items():
            require(actual[target] == {'sha256': digest(file(source, origin)), 'mode': 0o644},
                    'ASSEMBLED_BACKEND_COPY_MISMATCH:' + target)
        for origin, (target, mode) in _policy.SERVER_RELEASE_TOOLS.items():
            require(actual['server/' + target] == {'sha256': digest(file(source, origin)), 'mode': mode},
                    'ASSEMBLED_BACKEND_TOOL_MISMATCH:' + target)
        # Reuse the native backend plugin derivation on the verified source-only
        # view; accepting arbitrary extra files is never a metadata proof.
        projection = _policy.project_server_plugins(source, source)
        require(backend.get('plugin_projection') == projection, 'ASSEMBLED_BACKEND_PLUGIN_PROJECTION_MISMATCH')
        for name in ['server/plugins.lock', *(f"server/plugins/{entry['key']}/plugin.json" for entry in plugins)]:
            require(actual[name] == {'sha256': digest(file(source, name)), 'mode': 0o644},
                    'ASSEMBLED_BACKEND_PLUGIN_MISMATCH:' + name)
    return {**result, "status": "verified-complete-internal-app", "application_release_sha256": digest(file(root, identity_name))}


if __name__ == "__main__":
    try:
        command, root_arg, *arguments = sys.argv[1:]
        root = Path(root_arg)
        if command == "capture" and len(arguments) == 1:
            with Path(arguments[0]).open("x") as out:
                json.dump(tree(root), out, sort_keys=True)
            print('{"status":"captured"}')
        elif command == "seal" and len(arguments) == 2:
            print(json.dumps(seal(root, Path(arguments[0]), Path(arguments[1]))))
        elif command == "verify" and len(arguments) <= 1:
            print(json.dumps(verify(root, document(Path(arguments[0])) if arguments else None)))
        elif command == "verify-assembled" and len(arguments) <= 1:
            print(json.dumps(verify_assembled(root, document(Path(arguments[0])) if arguments else None)))
        else:
            raise ValueError("INTERNAL_CANDIDATE_COMMAND_INVALID")
    except (ValueError, OSError, KeyError, TypeError) as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1)
