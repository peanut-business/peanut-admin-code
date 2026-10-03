#!/usr/bin/env python3
"""Seal and verify a Composer installation made from this server's fixed lock."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys


RECEIPT = ".peanut-complete.json"


def digest(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def ordinary(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
        raise ValueError(f"missing or unsafe file: {path}")


PROTECTED = ("runtime/", "public/storage/", "public/uploads/", "private/storage/",
             "private/installation/", "private/resources/", "docker/mysql/", "docker/secrets/",
             "vendor/", ".git/")
PROTECTED_FILES = {".env", "docker/.env"}


def release_path(value):
    if not isinstance(value, str) or not value.startswith("server/"):
        raise ValueError("release path is invalid")
    parts = value.split("/")
    if len(parts) < 2 or any(part in ("", ".", "..") for part in parts) or "\\" in value or any(ord(c) < 32 for c in value):
        raise ValueError("release path is ambiguous or unsafe")
    relative = value[len("server/"):]
    if relative in PROTECTED_FILES or any(relative == prefix[:-1] or relative.startswith(prefix) for prefix in PROTECTED):
        raise ValueError("release path enters protected instance data")
    return relative


def ordinary_parents(root, relative):
    cursor = root
    for part in Path(relative).parts[:-1]:
        cursor /= part
        if cursor.is_symlink() or (cursor.exists() and not cursor.is_dir()):
            raise ValueError("release parent is linked or not a directory")


def inventory(vendor):
    if vendor.is_symlink() or not vendor.is_dir():
        raise ValueError("vendor directory is missing or unsafe")
    result = {}
    for base, dirs, files in os.walk(vendor, followlinks=False, onerror=lambda error: (_ for _ in ()).throw(error)):
        for name in dirs:
            child = Path(base) / name
            if child.is_symlink() or not child.is_dir():
                raise ValueError("vendor contains a linked directory")
        for name in files:
            path = Path(base) / name
            if path == vendor / RECEIPT:
                continue
            ordinary(path)
            relative = path.relative_to(vendor).as_posix()
            result[relative] = [digest(path), stat.S_IMODE(path.stat().st_mode)]
    return dict(sorted(result.items()))


def check_lock(server):
    composer, lock = server / "composer.json", server / "composer.lock"
    ordinary(composer)
    ordinary(lock)
    data = json.loads(lock.read_text())
    if not isinstance(data.get("packages"), list) or not re.fullmatch(r"[a-f0-9]{32}", data.get("content-hash", "")):
        raise ValueError("composer.lock is invalid")
    for package in data["packages"]:
        if not re.fullmatch(r"[a-z0-9_.-]+/[a-z0-9_.-]+", package.get("name", "")):
            raise ValueError("locked package identity is invalid")
        for source in ("dist", "source"):
            value = package.get(source)
            if value and not str(value.get("url", "")).startswith("https://"):
                raise ValueError("locked package has a non-HTTPS source")
    return digest(composer), digest(lock), {p["name"]: p["version"] for p in data["packages"]}


def check_installed(server, expected):
    vendor = server / "vendor"
    ordinary(vendor / "autoload.php")
    installed_path = vendor / "composer/installed.json"
    ordinary(installed_path)
    data = json.loads(installed_path.read_text())
    packages = data.get("packages") if isinstance(data, dict) else data
    if not isinstance(packages, list):
        raise ValueError("Composer installed metadata is invalid")
    actual = {p.get("name"): p.get("version") for p in packages}
    if len(actual) != len(packages) or actual != expected:
        raise ValueError("installed Composer packages differ from composer.lock")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("seal", "check", "stage"))
    parser.add_argument("--server", type=Path, required=True)
    parser.add_argument("--destination", type=Path)
    args = parser.parse_args()
    server = args.server
    if server.is_symlink() or not server.is_dir():
        raise ValueError("server root is missing or unsafe")
    if args.operation == "stage":
        destination = args.destination
        if destination is None or not destination.is_absolute() or destination.exists() or destination.is_symlink():
            raise ValueError("new ordinary staging directory is required")
        if destination.parent.is_symlink() or not destination.parent.is_dir() or destination.parent.resolve() != destination.parent:
            raise ValueError("staging parent is unsafe")
        identity_path = server / ".peanut/release-identity.json"
        ordinary_parents(server, ".peanut/release-identity.json")
        ordinary(identity_path)
        identity = json.loads(identity_path.read_text())
        if identity.get("schema_version") != 1 or identity.get("protocol") != "peanut.server-release.v1" or not isinstance(identity.get("application"), dict):
            raise ValueError("server release identity is invalid")
        files = identity.get("files")
        if not isinstance(files, list) or not files:
            raise ValueError("release file list is invalid")
        canonical = json.dumps(files, ensure_ascii=False, separators=(",", ":")).encode()
        if hashlib.sha256(canonical).hexdigest() != identity.get("files_sha256"):
            raise ValueError("release file list digest is invalid")
        previous = ""
        checked = []
        for row in files:
            if not isinstance(row, dict) or list(row) != ["path", "sha256", "mode"]:
                raise ValueError("release file row is invalid")
            relative = release_path(row["path"])
            if row["path"] <= previous or row["mode"] not in (0o644, 0o755) or not re.fullmatch(r"[a-f0-9]{64}", str(row["sha256"])):
                raise ValueError("release file order, mode or digest is invalid")
            previous = row["path"]
            source = server / relative
            ordinary_parents(server, relative)
            ordinary(source)
            if stat.S_IMODE(source.stat().st_mode) != row["mode"] or digest(source) != row["sha256"]:
                raise ValueError("release program file changed")
            checked.append((relative, source, row["mode"], row["sha256"]))
        destination.mkdir(mode=0o700)
        for relative, source, mode, expected_sha in checked:
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
            os.chmod(target, mode)
            if digest(target) != expected_sha:
                raise ValueError("release program changed during staging")
        target = destination / ".peanut/release-identity.json"
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(identity_path, target)
        check_lock(destination)
        print(json.dumps({"status": "staged", "file_count": len(files)}))
        return
    composer_sha, lock_sha, packages = check_lock(server)
    check_installed(server, packages)
    vendor = server / "vendor"
    manifest = inventory(vendor)
    receipt_path = vendor / RECEIPT
    if args.operation == "seal":
        if receipt_path.exists() or receipt_path.is_symlink():
            raise ValueError("vendor already has a receipt; prepare into an empty directory")
        payload = {"protocol": "peanut.composer-install.v1", "composer_sha256": composer_sha,
                   "lock_sha256": lock_sha, "files": manifest}
        temp = vendor / (RECEIPT + ".tmp")
        descriptor = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w") as output:
            json.dump(payload, output, sort_keys=True, separators=(",", ":"))
            output.flush()
            os.fsync(output.fileno())
        os.replace(temp, receipt_path)
        fd = os.open(vendor, os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    else:
        ordinary(receipt_path)
        payload = json.loads(receipt_path.read_text())
        if payload != {"protocol": "peanut.composer-install.v1", "composer_sha256": composer_sha,
                       "lock_sha256": lock_sha, "files": manifest}:
            raise ValueError("vendor installation is incomplete or changed")
    print(json.dumps({"status": "complete", "lock_sha256": lock_sha, "file_count": len(manifest)}))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, json.JSONDecodeError) as error:
        print(f"vendor-state: {error}", file=sys.stderr)
        sys.exit(1)
