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


def tree(root: Path, core_path: str | None = None) -> dict:
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
        # Internal backend qualification preserves immutable declared frontend
        # inputs, but does not install them or claim a formal browser package.
        original_frontend_archive = bool(re.fullmatch(
            r"packages/core-web/peanut-admin-(client|vue|ui-vue|nuxt|uniapp|testing)-[0-9A-Za-z.+-]+\.tgz", logical
        )) and declared.get(logical) == digest(source)
        require(name == core_path or _policy.allowed_source(name) or original_frontend_archive,
                "FORBIDDEN_SOURCE:" + name)
        if name != MARKER:
            result[name] = {"sha256": digest(source), "mode": stat.S_IMODE(source.stat().st_mode)}
    return result


def tree_digest(value: dict) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def allowed_projection(name: str) -> bool:
    return name in {"server/composer.json", "server/composer.lock", "plugins.lock"} or bool(
        re.fullmatch(r"server/app/modules/[a-z0-9_-]+/[a-z0-9_-]+/composer\.json", name)
        or re.fullmatch(r"plugins/[a-z0-9._-]+/plugin\.json", name)
    )


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
    current = tree(root, core_path)
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
        require(name not in seen and allowed_projection(name), "PROJECTION_PATH_INVALID")
        seen.add(name)
        require(current.get(name) == row.get("after") and isinstance(row.get("before"), dict), "PROJECTION_DIGEST_MISMATCH")
        original[name] = row["before"]
    require(len(additions) == 1 and additions[0].get("path") == core_path, "ADDITIONS_INVALID")
    require(current.get(core_path) == additions[0].get("after") and core_path not in seen, "ADDITION_DIGEST_MISMATCH")
    original.pop(core_path)
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
        require(expected.get("source", {}).get("commit") == marker["generation_source"].get("commit")
                and expected.get("source", {}).get("tree") == marker["generation_source"].get("tree")
                and expected.get("edition") == manifest.get("edition"), "EXTERNAL_SOURCE_MISMATCH")
        for name, value in expected.get("dependency_locks", {}).items():
            if name == "third_party_composer_rows_sha256":
                continue
            require(digest(file(root, name)) == value, "LOCK_DIGEST_MISMATCH")
    return {"status": "verified-internal-candidate", "candidate_marker": {"path": MARKER, "sha256": digest(marker_path)},
            "projection": projection, "source_file_count": len(rows), "nested_core_manifests": modules}


def seal(root: Path, captured: Path, core_file: Path) -> dict:
    before = document(captured)
    core = document(core_file)
    name = relative(core.get("path_in_archive"))
    after = tree(root, name)
    require(set(before) <= set(after), "SOURCE_REMOVAL_REJECTED")
    require(set(after) - set(before) == {name}, "UNEXPECTED_ADDITION")
    changes = []
    for path, previous in before.items():
        if previous != after[path]:
            require(allowed_projection(path), "UNEXPECTED_PROJECTION:" + path)
            changes.append({"path": path, "before": previous, "after": after[path]})
    manifest_file = file(root, ".peanut/application-manifest.json")
    marker = {"schema_version": 1, "protocol": PROTOCOL, "scope": "internal-candidate", "formal_release_eligible": False,
              "generation_source": document(manifest_file)["generation_source"], "original_manifest_sha256": digest(manifest_file),
              "core": core, "projection": {"original_tree_sha256": tree_digest(before), "projected_tree_sha256": tree_digest(after),
              "changes": changes, "additions": [{"path": name, "after": after[name]}]}}
    path = root / MARKER
    with path.open("x") as target:
        json.dump(marker, target, ensure_ascii=False, indent=2)
        target.write("\n")
    path.chmod(0o644)
    return verify(root)


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
        else:
            raise ValueError("INTERNAL_CANDIDATE_COMMAND_INVALID")
    except (ValueError, OSError, KeyError, TypeError) as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1)
