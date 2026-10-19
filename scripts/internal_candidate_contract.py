#!/usr/bin/env python3
"""Integrity of explicitly internal, source-preserving candidate projections."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import re
import stat
import sys
from pathlib import Path, PurePosixPath

PROTOCOL = "peanut.internal-edition-candidate.v1"
MARKER = ".peanut/internal-candidate.json"
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


def tree(root: Path, archive_paths: set[str] | None = None) -> dict:
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


def verify(root: Path, expected: dict | None = None) -> dict:
    marker_path = file(root, MARKER)
    marker = document(marker_path)
    require(marker.get("protocol") == PROTOCOL and marker.get("scope") == "internal-candidate"
            and marker.get("formal_release_eligible") is False, "MARKER_IDENTITY_INVALID")
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
    current = tree(root, set(inputs))
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
    require(core_path in seen, "CORE_ADDITION_REQUIRED")
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
    for name in set(actual) - set(sealed) - backend_outputs - {MARKER, "release-manifest.txt"}:
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
        for name in [*sealed, MARKER]:
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
