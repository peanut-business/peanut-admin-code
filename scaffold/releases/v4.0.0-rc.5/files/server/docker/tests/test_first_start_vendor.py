#!/usr/bin/env python3
"""Real file lifecycle and a Docker command boundary sentinel; no daemon needed."""

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
IMAGE = "local/php:prepared@sha256:" + "a" * 64


def write(path, data, mode=0o644):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    path.chmod(mode)


class FirstStartVendorTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-first-start-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.server = self.root / "server"
        self.bin = self.root / "bin"
        self.bin.mkdir()
        for name in ("start.sh", "prepare-vendor.sh", "vendor-state.py"):
            target = self.server / "docker/scripts" / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(SCRIPTS / name, target)
        write(self.server / "docker/.env", f"PHP_IMAGE={IMAGE}\nNGINX_IMAGE=sha256:{'b'*64}\nMYSQL_IMAGE=sha256:{'c'*64}\nMYSQL_ROOT_PASSWORD={'d'*64}\n".encode(), 0o600)
        write(self.server / ".env.example", b"APP_DEBUG=false\n")
        write(self.server / "composer.json", b'{"name":"fixture/app"}\n')
        write(self.server / "composer.lock", b'{"content-hash":"' + b"0" * 32 + b'","packages":[]}\n')
        write(self.server / "think", b'<?php echo "fixture";\n', 0o755)
        self.identity()
        sentinel = self.bin / "docker"
        write(sentinel, b'''#!/usr/bin/env python3
import json, os, pathlib, sys
args=sys.argv[1:]
with open(os.environ["DOCKER_SENTINEL_LOG"], "a") as out: out.write(json.dumps(args)+"\\n")
if args[:1] == ["run"]:
    if os.environ.get("DOCKER_SENTINEL_FAIL"): sys.exit(42)
    mounts=[a for a in args if a.startswith("type=bind,src=") and a.endswith(",dst=/srv")]
    if len(mounts)!=1: sys.exit(43)
    envs=[args[i+1] for i,a in enumerate(args[:-1]) if a == "--env"]
    if envs != ["PEANUT_SERVER_ENV_FILE=/srv/.env.vendor-bootstrap"]: sys.exit(44)
    work=pathlib.Path(mounts[0].split("src=",1)[1].split(",dst=",1)[0])
    bootstrap=work/".env.vendor-bootstrap"
    if not bootstrap.is_file() or bootstrap.stat().st_mode & 0o777 != 0o600: sys.exit(45)
    if bootstrap.read_text() != "APP_DEBUG=false\\n": sys.exit(46)
    (work/"vendor/composer").mkdir(parents=True)
    (work/"vendor/autoload.php").write_text("<?php\\n")
    (work/"vendor/composer/installed.json").write_text('{"packages":[]}')
    (work/"vendor/services.php").write_text("<?php\\n")
sys.exit(0)
''', 0o755)
        self.log = self.root / "docker-log.jsonl"
        self.env = os.environ.copy()
        self.env.update(PATH=str(self.bin) + os.pathsep + self.env["PATH"], DOCKER_SENTINEL_LOG=str(self.log))

    def identity(self, extra=None):
        names = ("composer.json", "composer.lock", "think")
        rows = [{"path": "server/" + name, "sha256": hashlib.sha256((self.server / name).read_bytes()).hexdigest(),
                 "mode": (self.server / name).stat().st_mode & 0o777} for name in names]
        rows.extend(extra or [])
        rows.sort(key=lambda item: item["path"])
        identity = {"schema_version": 1, "protocol": "peanut.server-release.v1", "application": {"slug": "fixture"},
                    "files": rows, "files_sha256": hashlib.sha256(json.dumps(rows, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()}
        write(self.server / ".peanut/release-identity.json", json.dumps(identity).encode())

    def run_start(self, fail=False):
        env = dict(self.env)
        if fail:
            env["DOCKER_SENTINEL_FAIL"] = "1"
        return subprocess.run([str(self.server / "docker/scripts/start.sh")], env=env, text=True, capture_output=True)

    def commands(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def complete_vendor(self):
        write(self.server / "vendor/autoload.php", b"<?php\n")
        write(self.server / "vendor/composer/installed.json", b'{"packages":[]}')
        result = subprocess.run(["python3", str(self.server / "docker/scripts/vendor-state.py"), "seal", "--server", str(self.server)], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_first_missing_vendor_prepares_and_starts(self):
        result = self.run_start()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((self.server / "vendor/.peanut-complete.json").is_file())
        self.assertFalse(any((self.server / "runtime").glob("vendor-stage-*/.env.vendor-bootstrap")))
        self.assertTrue((self.server / "docker/secrets/install-token").is_file())
        self.assertFalse((self.server / "docker/secrets/mysql-root-password").exists())
        calls = self.commands()
        self.assertEqual(sum(c[0] == "run" for c in calls), 1)
        self.assertEqual(sum(c[0] == "compose" and "up" in c for c in calls), 1)
        shell = next(c[-1] for c in calls if c[0] == "run")
        for command in ("composer install", "service:discover", "vendor:publish", "check-platform-reqs"):
            self.assertIn(command, shell)

    def test_complete_vendor_uses_no_composer_or_container(self):
        self.complete_vendor()
        before = (self.server / "vendor/.peanut-complete.json").read_bytes()
        result = self.run_start()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any(c[0] == "run" for c in self.commands()))
        self.assertEqual((self.server / "vendor/.peanut-complete.json").read_bytes(), before)

    def test_preparation_failure_leaves_old_vendor_and_no_receipt(self):
        write(self.server / "vendor/broken.php", b"old bytes")
        result = self.run_start(fail=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.server / "vendor/broken.php").read_bytes(), b"old bytes")
        self.assertFalse((self.server / "vendor/.peanut-complete.json").exists())
        self.assertFalse(any(c[0] == "compose" for c in self.commands()))
        self.assertTrue(any(p.name.startswith("vendor-stage-") for p in (self.server / "runtime").iterdir()))
        self.assertFalse(any((self.server / "runtime").glob("vendor-stage-*/.env.vendor-bootstrap")))

    def test_installed_damaged_vendor_cannot_be_renamed(self):
        write(self.server / "private/installation/installed.json", b"original")
        write(self.server / "vendor/broken.php", b"old bytes")
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("maintenance", result.stderr)
        self.assertEqual((self.server / "vendor/broken.php").read_bytes(), b"old bytes")
        self.assertEqual(self.commands(), [])

    def test_existing_database_without_root_password_stops_before_dependency_work(self):
        env = self.server / "docker/.env"
        env.write_text("\n".join(line for line in env.read_text().splitlines() if not line.startswith("MYSQL_ROOT_PASSWORD=")) + "\n")
        write(self.server / "docker/mysql/ibdata1", b"existing-db")
        write(self.server / "vendor/broken.php", b"old bytes")
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("missing MYSQL_ROOT_PASSWORD", result.stderr)
        self.assertEqual(self.commands(), [])
        self.assertEqual((self.server / "vendor/broken.php").read_bytes(), b"old bytes")
        self.assertFalse((self.server / "runtime").exists())

    def test_dangling_backend_environment_stops_before_dependency_work(self):
        (self.server / ".env").symlink_to("missing-backend-environment")
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("server/.env is unsafe", result.stderr)
        self.assertEqual(self.commands(), [])
        self.assertTrue((self.server / ".env").is_symlink())

    def test_protected_and_linked_paths_are_rejected(self):
        extra = [{"path": "server/private/installation/secret.json", "sha256": "0" * 64, "mode": 0o644}]
        self.identity(extra)
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.server / "vendor").exists())
        self.identity()
        (self.server / "public").mkdir(exist_ok=True)
        (self.server / "public/storage").symlink_to(self.root)
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("protected directory", result.stderr)

    def test_dangling_install_lock_and_missing_root_password_fail_closed(self):
        lock = self.server / "private/installation/installed.json"
        lock.parent.mkdir(parents=True)
        lock.symlink_to("absent.json")
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.server / "docker/secrets/install-token").exists())
        lock.unlink()
        self.complete_vendor()
        env = self.server / "docker/.env"
        env.write_text("\n".join(line for line in env.read_text().splitlines() if not line.startswith("MYSQL_ROOT_PASSWORD=")) + "\n")
        write(self.server / "docker/mysql/ibdata1", b"existing-db")
        result = self.run_start()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("missing MYSQL_ROOT_PASSWORD", result.stderr)
        self.assertFalse((self.server / "docker/secrets/mysql-root-password").exists())

    def test_legacy_root_file_migrates_once_to_docker_environment(self):
        env = self.server / "docker/.env"
        env.write_text("\n".join(line for line in env.read_text().splitlines() if not line.startswith("MYSQL_ROOT_PASSWORD=")) + "\n")
        write(self.server / "docker/secrets/mysql-root-password", b"e" * 64 + b"\n", 0o600)
        result = self.run_start()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("MYSQL_ROOT_PASSWORD=" + "e" * 64, env.read_text())
        self.assertFalse((self.server / "docker/secrets/mysql-root-password").exists())


if __name__ == "__main__":
    unittest.main()
