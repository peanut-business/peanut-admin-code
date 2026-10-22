#!/usr/bin/env python3
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class ScaffoldRecipeOwnershipTransitionTest(unittest.TestCase):
    def test_legacy_ci_removal_requires_explicit_recipe_transition(self):
        with tempfile.TemporaryDirectory(prefix="peanut-scaffold-recipe-transition-") as temporary:
            temp = Path(temporary)
            app = temp / "app"
            workflow = app / ".github/workflows/ci.yml"
            workflow.parent.mkdir(parents=True)
            workflow.write_text("existing application CI\n")

            release = {
                "schema_version": 2,
                "protocol": "peanut.scaffold-release.v2",
                "release": {
                    "version": "1.0.0",
                    "source_commit": "a" * 40,
                    "source_tree": "b" * 40,
                    "inventory_sha256": "c" * 64,
                    "inventory_template_version": "1.0.0",
                    "tokens": {
                        "product_name": "product-token",
                        "slug": "slug-token",
                        "package_identity": "package-token",
                    },
                },
                "files": [{
                    "path": ".github/workflows/ci.yml",
                    "owner": "host",
                    "policy": "managed",
                    "classification": "managed",
                    "transform": "tokens",
                    "mode": 420,
                    "template_sha256": "d" * 64,
                }],
            }
            from_path = temp / "from.json"
            to_path = temp / "to.json"
            from_path.write_text(json.dumps(release))
            release["release"]["version"] = "1.0.1"
            release["files"] = []
            to_path.write_text(json.dumps(release))

            code = r"""
foreach (['ScaffoldManifest', 'ScaffoldPathGuard', 'Semver', 'ScaffoldUpgradeRunner'] as $class) {
    require $argv[1] . '/scripts/scaffold-runtime/' . $class . '.php';
}
use app\common\value\scaffold\ScaffoldManifest;
use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
$decisions = [ScaffoldManifest::additionDecision(false, false),
    ScaffoldManifest::additionDecision(true, true), ScaffoldManifest::additionDecision(true, false)];
if (array_column($decisions, 'action') !== ['create', 'preserve', 'conflict']) {
    throw new RuntimeException('new-path decisions changed');
}
$method = new ReflectionMethod(ScaffoldUpgradeRunner::class, 'classify');
$actions = $method->invoke(new ScaffoldUpgradeRunner(), $argv[2],
    ['files' => [['path' => '.github/workflows/ci.yml', 'classification' => 'managed']]],
    ScaffoldManifest::load($argv[3]), ScaffoldManifest::load($argv[4]), [], [], []);
if ($actions[0]['action'] !== 'conflict' || $actions[0]['reason'] !== 'recipe_ownership_transition_required') {
    throw new RuntimeException('legacy CI deletion was allowed');
}
echo "shared decisions and legacy ownership guard passed\n";
"""
            result = subprocess.run(
                ["php", "-r", code, str(ROOT), str(app), str(from_path), str(to_path)],
                text=True,
                capture_output=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(workflow.read_text(), "existing application CI\n")


    def test_fixed_template_cache_reauthentication(self):
        # Small protocol fixtures only: no APP creation, tooling execution, DB or service.
        import gzip
        import hashlib
        import io
        import tarfile

        digest = lambda value: hashlib.sha256(value).hexdigest()
        encode = lambda value: json.dumps(value, sort_keys=True).encode()
        for variant in ("valid", "cache-drift", "duplicate", "traversal", "link", "trailer", "pax", "tooling-lock"):
            with self.subTest(variant=variant), tempfile.TemporaryDirectory(prefix="peanut-template-auth-") as temporary:
                base = Path(temporary)
                members = {"files/a.txt": b"managed source\n", "tooling/composer.json": b"{}",
                           "tooling/composer.lock": encode({"packages": []}),
                           "tooling/vendor/autoload.php": b"<?php\n",
                           "tooling/vendor/composer/installed.json": encode({"dev": False, "packages": []})}
                if variant == "tooling-lock":
                    members["tooling/vendor/composer/installed.json"] = encode({"dev": False,
                        "packages": [{"name": "fixture/drift", "version": "1.0.0"}]})
                source = {"repository": "peanut-business/peanut-admin-code", "commit": "a" * 40,
                          "tree": "b" * 40, "inventory_sha256": "c" * 64}
                release = {"protocol": "peanut.scaffold-release.v3",
                    "release": {"version": "5.0.2", "source_commit": source["commit"],
                                "source_tree": source["tree"], "inventory_sha256": source["inventory_sha256"]},
                    "edition": {"name": "multi-tenant"},
                    "files": [{"path": "a.txt", "source": "files/a.txt", "classification": "managed",
                               "template_sha256": digest(members["files/a.txt"]), "mode": 0o644}]}
                members["scaffold-manifest.json"] = encode(release)
                create = {"protocol": "peanut.template-create.v1",
                    "scaffold_manifest_sha256": digest(members["scaffold-manifest.json"]),
                    "files": [{"path": "a.txt", "source": "files/a.txt", "classification": "managed",
                               "sha256": digest(members["files/a.txt"]), "mode": 0o644}]}
                members["template-create.json"] = encode(create)
                tooling = {"protocol": "peanut.scaffold-upgrade-tooling.v1",
                    "autoload": "tooling/vendor/autoload.php", "packages": [],
                    "composer_lock_sha256": digest(members["tooling/composer.lock"]),
                    "files": [{"path": name, "sha256": digest(value), "mode": 0o644}
                              for name, value in members.items() if name.startswith("tooling/")]}
                members["upgrade-tooling.json"] = encode(tooling)
                raw = io.BytesIO()
                with tarfile.open(fileobj=raw, mode="w:gz", format=tarfile.USTAR_FORMAT
                                  if variant != "pax" else tarfile.PAX_FORMAT) as archive:
                    rows = list(members.items())
                    if variant == "duplicate":
                        rows.append(("files/a.txt", members["files/a.txt"]))
                    if variant == "traversal":
                        rows.append(("../escape", b"forbidden"))
                    for name, value in rows:
                        item = tarfile.TarInfo(name)
                        item.size, item.mode = len(value), 0o644
                        if variant == "pax":
                            item.pax_headers = {"comment": "extension"}
                        archive.addfile(item, io.BytesIO(value))
                archive_bytes = raw.getvalue()
                if variant == "trailer":
                    archive_bytes = gzip.compress(gzip.decompress(archive_bytes) + b"trailing data")
                archive_name = "peanut-scaffold-multi-tenant.tgz"
                cache = base / digest(archive_bytes)
                cache.mkdir()
                for name, value in members.items():
                    path = cache / name
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(value)
                    path.chmod(0o644)
                (cache / archive_name).write_bytes(archive_bytes)
                metadata = {"schema_version": 1, "protocol": "peanut.template-package.v1",
                    "kind": "scaffold-template", "edition": "multi-tenant", "version": "5.0.2", "source": source,
                    "archive": {"name": archive_name, "bytes": len(archive_bytes), "sha256": digest(archive_bytes)},
                    "create_manifest_sha256": digest(members["template-create.json"]),
                    "scaffold_manifest_sha256": digest(members["scaffold-manifest.json"]),
                    "upgrade_tooling_sha256": digest(members["upgrade-tooling.json"])}
                (cache / "template-package.json").write_bytes(encode(metadata))
                if variant == "cache-drift":
                    (cache / "files/a.txt").write_bytes(b"modified")
                if variant == "link":
                    outside = base / "outside"
                    outside.write_bytes(members["files/a.txt"])
                    (cache / "files/a.txt").unlink()
                    (cache / "files/a.txt").symlink_to(outside)
                result = subprocess.run(["python3", "-B", str(ROOT / "scripts/product-upgrade-host"),
                                         "source-template-check", "--cache=" + str(cache)],
                                        text=True, capture_output=True, timeout=20)
                if variant == "valid":
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual(json.loads(result.stdout)["status"], "verified")
                else:
                    self.assertNotEqual(result.returncode, 0, variant)
                    self.assertFalse((base / "escape").exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)
