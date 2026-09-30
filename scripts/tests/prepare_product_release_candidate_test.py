#!/usr/bin/env python3
from __future__ import annotations

import base64
import hashlib
import importlib.machinery
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader(
    "prepare_product_release_candidate",
    str(ROOT / "scripts/prepare-product-release-candidate"),
)
spec = importlib.util.spec_from_loader(loader.name, loader)
candidate = importlib.util.module_from_spec(spec)
loader.exec_module(candidate)


def sri(seed: str) -> str:
    return "sha512-" + base64.b64encode(hashlib.sha512(seed.encode()).digest()).decode()


class PrepareProductReleaseCandidateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.old_root = candidate.ROOT
        self.old_registry = candidate.npm_registry_package
        candidate.ROOT = self.root
        self.addCleanup(lambda: setattr(candidate, "ROOT", self.old_root))
        self.addCleanup(lambda: setattr(candidate, "npm_registry_package", self.old_registry))
        self.evidence_counter = 0
        for directory in ["server", "web", "platform", "pc", "uniapp"]:
            (self.root / directory).mkdir(parents=True)

    def write_json(self, path: str, value: object) -> None:
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(value) + "\n", encoding="utf-8")

    def write_native_locks(self) -> None:
        self.write_json(
            "server/composer.lock",
            {
                "packages": [
                    {
                        "name": "peanut-admin/core",
                        "version": "v4.0.0-rc.3",
                        "source": {
                            "type": "git",
                            "url": "https://github.com/peanut-business/peanut-admin-core-php.git",
                            "reference": "752476a811a5d16ea816d5206e3c03814a81fe6a",
                        },
                    }
                ]
            },
        )
        versions = {
            "@peanut-admin/client": "client",
            "@peanut-admin/vue": "vue",
            "@peanut-admin/ui-vue": "ui-vue",
            "@peanut-admin/nuxt": "nuxt",
            "@peanut-admin/uniapp": "uniapp",
            "@peanut-admin/testing": "testing",
        }
        for client, names in candidate.CLIENT_CORE_PACKAGES.items():
            if client == "web":
                continue
            self.write_json(
                f"{client}/package-lock.json",
                {
                    "lockfileVersion": 3,
                    "packages": {
                        "": {"dependencies": {name: "4.0.0-rc.4" for name in names}},
                        **{
                            f"node_modules/{name}": {
                                "version": "4.0.0-rc.4",
                                "resolved": f"https://registry.npmjs.org/{name.replace('/', '%2f')}/-/{versions[name]}-4.0.0-rc.4.tgz",
                                "integrity": sri(name),
                            }
                            for name in names
                        },
                    },
                },
            )
        pnpm_packages = "\n".join(
            f"  '{name}@4.0.0-rc.4':\n"
            f"    resolution: {{integrity: '{sri(name)}'}}"
            for name in ("@peanut-admin/vue", "@peanut-admin/ui-vue")
        )
        importer = "".join(
            f"      '{name}':\n        specifier: 4.0.0-rc.4\n        version: 4.0.0-rc.4\n"
            for name in ("@peanut-admin/vue", "@peanut-admin/ui-vue")
        )
        (self.root / "web/pnpm-lock.yaml").write_text("importers:\n  .:\n    dependencies:\n" + importer + "packages:\n" + pnpm_packages + "\n", encoding="utf-8")
        self.registry = {
            name: {
                "version": "4.0.0-rc.4",
                "resolved": f"https://registry.npmjs.org/{name.replace('/', '%2f') if name not in ('@peanut-admin/vue', '@peanut-admin/ui-vue') else name}/-/{short}-4.0.0-rc.4.tgz",
                "integrity": sri(name),
                "repository": "git+https://github.com/peanut-business/peanut-admin-core-web.git",
                "gitHead": "e7e00110b999d5f91ecf7b5861415810a5fcb14c",
            }
            for name, short in versions.items()
        }
        candidate.npm_registry_package = lambda name, version: self.registry[name]
        for client, names in candidate.CLIENT_CORE_PACKAGES.items():
            if client == "web":
                continue
            lock = candidate.read_json(self.root / client / "package-lock.json")
            for name in names:
                lock["packages"][f"node_modules/{name}"]["resolved"] = self.registry[name]["resolved"]
            self.write_json(f"{client}/package-lock.json", lock)

    def write_web_evidence(self, mutate=None) -> tuple[Path, str]:
        self.evidence_counter += 1
        reference = "e7e00110b999d5f91ecf7b5861415810a5fcb14c"
        packages = {
            name: {
                "version": row["version"],
                "repository": row["repository"],
                "source_reference": reference,
                "gitHead": row["gitHead"],
                "tarball": row["resolved"],
                "integrity": row["integrity"],
            }
            for name, row in self.registry.items()
        }
        value = {
            "schema_version": 1,
            "protocol": "peanut.web-core-package-evidence.v1",
            "repository": "peanut-business/peanut-admin-core-web",
            "tag": "v4.0.0-rc.4",
            "version": "4.0.0-rc.4",
            "source_reference": reference,
            "release_id": 399110962,
            "action_run": 36565142424,
            "packages": packages,
        }
        if mutate is not None:
            mutate(value)
        path = self.root / f"web-package-evidence-{self.evidence_counter}.json"
        path.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")
        path.chmod(0o444)
        return path, hashlib.sha256(path.read_bytes()).hexdigest()

    def test_bound_web_evidence_supplies_registry_metadata_without_npm_view(self) -> None:
        self.write_native_locks()
        path, evidence_sha = self.write_web_evidence()
        registry = candidate.read_web_package_evidence(
            path, evidence_sha, "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c"
        )
        with patch.object(candidate, "npm_registry_package", side_effect=AssertionError("npm view must not run")):
            result = candidate.read_core_web(
                "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c", registry
            )
        self.assertEqual(set(result["packages"]), set(candidate.CORE_WEB_PACKAGES))
        self.assertEqual(
            result["packages"]["@peanut-admin/client"]["resolved"],
            self.registry["@peanut-admin/client"]["resolved"],
        )

    def test_bound_web_evidence_rejects_relative_and_linked_paths(self) -> None:
        self.write_native_locks()
        path, evidence_sha = self.write_web_evidence()
        with self.assertRaisesRegex(SystemExit, "absolute real regular file"):
            candidate.read_web_package_evidence(
                Path(path.name), evidence_sha, "4.0.0-rc.4",
                "e7e00110b999d5f91ecf7b5861415810a5fcb14c",
            )
        linked = self.root / "web-package-evidence-link.json"
        linked.symlink_to(path)
        with self.assertRaisesRegex(SystemExit, "absolute real regular file"):
            candidate.read_web_package_evidence(
                linked, evidence_sha, "4.0.0-rc.4",
                "e7e00110b999d5f91ecf7b5861415810a5fcb14c",
            )

    def test_bound_web_evidence_rejects_writable_hash_package_source_and_integrity_changes(self) -> None:
        self.write_native_locks()
        path, evidence_sha = self.write_web_evidence()
        path.chmod(0o644)
        with self.assertRaisesRegex(SystemExit, "read-only"):
            candidate.read_web_package_evidence(
                path, evidence_sha, "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c"
            )

        path, evidence_sha = self.write_web_evidence()
        with self.assertRaisesRegex(SystemExit, "bytes changed"):
            candidate.read_web_package_evidence(
                path, "0" * 64, "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c"
            )

        mutations = [
            ("package set differs", lambda value: value["packages"].pop("@peanut-admin/testing")),
            ("package set differs", lambda value: value["packages"].__setitem__(
                "@peanut-admin/extra", dict(value["packages"]["@peanut-admin/testing"]))),
            ("metadata differs", lambda value: value["packages"]["@peanut-admin/vue"].__setitem__(
                "source_reference", "0" * 40)),
            ("metadata differs", lambda value: value["packages"]["@peanut-admin/vue"].__setitem__(
                "repository", "git+https://github.com/other/core-web.git")),
            ("integrity", lambda value: value["packages"]["@peanut-admin/vue"].__setitem__(
                "integrity", "sha512-invalid")),
        ]
        for expected, mutation in mutations:
            with self.subTest(expected=expected):
                path, evidence_sha = self.write_web_evidence(mutation)
                with self.assertRaisesRegex(SystemExit, expected):
                    candidate.read_web_package_evidence(
                        path, evidence_sha, "4.0.0-rc.4",
                        "e7e00110b999d5f91ecf7b5861415810a5fcb14c",
                    )

    def test_bound_web_evidence_still_rejects_native_lock_disagreement(self) -> None:
        self.write_native_locks()
        path, evidence_sha = self.write_web_evidence()
        registry = candidate.read_web_package_evidence(
            path, evidence_sha, "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c"
        )
        lock = candidate.read_json(self.root / "platform/package-lock.json")
        lock["packages"]["node_modules/@peanut-admin/vue"]["integrity"] = sri("different")
        self.write_json("platform/package-lock.json", lock)
        with self.assertRaisesRegex(SystemExit, "native locks disagree"):
            candidate.read_core_web(
                "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c", registry
            )

    def test_reads_registry_identity_from_native_locks(self) -> None:
        self.write_native_locks()
        core_php = candidate.read_core_php("4.0.0-rc.3", "752476a811a5d16ea816d5206e3c03814a81fe6a")
        core_web = candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")
        self.assertEqual(core_php["resolved_version"], "v4.0.0-rc.3")
        self.assertEqual(core_php["source_reference"], "752476a811a5d16ea816d5206e3c03814a81fe6a")
        self.assertEqual(core_web["source_reference"], "e7e00110b999d5f91ecf7b5861415810a5fcb14c")
        self.assertEqual(core_web["packages"]["@peanut-admin/client"]["version"], "4.0.0-rc.4")
        self.assertTrue(core_web["packages"]["@peanut-admin/ui-vue"]["resolved"].startswith("https://registry.npmjs.org/"))

    def test_prepare_uses_bound_evidence_after_native_lock_generation(self) -> None:
        self.write_manifests()
        self.write_native_locks()
        path, evidence_sha = self.write_web_evidence()
        with patch.object(candidate, "run_native_lock_updates", return_value=None), \
                patch.object(candidate, "npm_registry_package", side_effect=AssertionError("npm view must not run")):
            candidate.prepare(
                "4.0.0-rc.5",
                "4.0.0-rc.3",
                "752476a811a5d16ea816d5206e3c03814a81fe6a",
                "4.0.0-rc.4",
                "e7e00110b999d5f91ecf7b5861415810a5fcb14c",
                (path, evidence_sha),
            )
        versions = candidate.read_json(self.root / "release-versions.json")
        self.assertEqual(versions["source_product_version"], "4.0.0-rc.5")
        self.assertEqual(
            versions["core_web"]["packages"]["@peanut-admin/client"]["resolved"],
            self.registry["@peanut-admin/client"]["resolved"],
        )

    def test_rejects_native_lock_disagreement(self) -> None:
        self.write_native_locks()
        lock = json.loads((self.root / "platform/package-lock.json").read_text(encoding="utf-8"))
        lock["packages"]["node_modules/@peanut-admin/vue"]["integrity"] = sri("different")
        self.write_json("platform/package-lock.json", lock)
        with self.assertRaisesRegex(SystemExit, "native locks disagree with npm registry"):
            candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")

    def write_manifests(self) -> None:
        self.write_json("server/composer.json", {"require": {"peanut-admin/core": "dev-dev"}})
        for client, names in candidate.CLIENT_CORE_PACKAGES.items():
            self.write_json(f"{client}/package.json", {
                "name": f"app-{client}", "version": "0.1.0",
                "dependencies": {name: "file:../local.tgz" for name in names},
            })
        self.write_json("release-versions.json", {
            "schema_version": 3, "protocol": "peanut.release-versions.v3",
            "source_product_version": "4.0.0-dev.14", "instance_version": None,
            "scaffold_template": "4.0.0-dev.14", "generated_instance_default": "0.1.0",
        })
        self.write_json("RELEASE_METADATA.json", {"technical_qualification": {"result": "pending"}})

    def test_product_and_app_versions_remain_distinct(self) -> None:
        self.write_manifests()
        self.write_native_locks()
        with patch.object(candidate, "run_native_lock_updates", return_value=None):
            candidate.prepare("4.0.0-rc.5", "4.0.0-rc.3", "752476a811a5d16ea816d5206e3c03814a81fe6a",
                              "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")
        self.assertEqual(candidate.read_json(self.root / "web/package.json")["version"], "4.0.0-rc.5")
        self.assertEqual(candidate.read_json(self.root / "platform/package.json")["version"], "4.0.0-rc.5")
        versions = candidate.read_json(self.root / "release-versions.json")
        self.assertEqual(versions["generated_instance_default"], "0.1.0")
        self.assertEqual(candidate.read_json(self.root / "RELEASE_METADATA.json")["technical_qualification"]["application_initial_version"], "0.1.0")

    def test_failure_restores_all_version_and_lock_bytes(self) -> None:
        self.write_manifests()
        self.write_native_locks()
        originals = {path: (self.root / path).read_bytes() for path in candidate.PREPARED_FILES}
        def failed_update(_dry_run: bool) -> None:
            (self.root / "server/composer.lock").write_text("partial", encoding="utf-8")
            raise RuntimeError("native command failed")
        with patch.object(candidate, "run_native_lock_updates", side_effect=failed_update):
            with self.assertRaisesRegex(RuntimeError, "native command failed"):
                candidate.prepare("4.0.0-rc.5", "4.0.0-rc.3", "752476a811a5d16ea816d5206e3c03814a81fe6a",
                                  "4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")
        self.assertEqual(originals, {path: (self.root / path).read_bytes() for path in candidate.PREPARED_FILES})

    def test_rejects_fake_registry_host_and_stale_importer(self) -> None:
        self.write_native_locks()
        lock = candidate.read_json(self.root / "pc/package-lock.json")
        lock["packages"]["node_modules/@peanut-admin/client"]["resolved"] = "https://registry.npmjs.org.evil.test/client.tgz"
        self.write_json("pc/package-lock.json", lock)
        with self.assertRaisesRegex(SystemExit, "registry tarball"):
            candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")
        self.write_native_locks()
        target = self.root / "web/pnpm-lock.yaml"
        target.write_text(target.read_text().replace("specifier: 4.0.0-rc.4", "specifier: file:../local.tgz", 1))
        with self.assertRaisesRegex(SystemExit, "importer does not pin"):
            candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")

    def test_pnpm_without_tarball_uses_registry_url(self) -> None:
        self.write_native_locks()
        observed = candidate.pnpm_lock_package("@peanut-admin/vue", "4.0.0-rc.4")
        self.assertNotIn("resolved", observed)
        result = candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")
        self.assertEqual(result["packages"]["@peanut-admin/vue"]["resolved"],
                         "https://registry.npmjs.org/@peanut-admin/vue/-/vue-4.0.0-rc.4.tgz")

    def test_pnpm_explicit_tarball_and_integrity_must_match_registry(self) -> None:
        for replacement, message in (
            ("tarball: 'https://registry.npmjs.org/@peanut-admin/vue/-/wrong-4.0.0-rc.4.tgz'", "tarball disagrees"),
            (f"integrity: '{sri('wrong')}'", "disagree with npm registry"),
        ):
            with self.subTest(replacement=replacement):
                self.write_native_locks()
                target = self.root / "web/pnpm-lock.yaml"
                original = f"integrity: '{sri('@peanut-admin/vue')}'"
                text = target.read_text()
                if replacement.startswith("tarball"):
                    text = text.replace(original, original + ", " + replacement, 1)
                else:
                    text = text.replace(original, replacement, 1)
                target.write_text(text)
                with self.assertRaisesRegex(SystemExit, message):
                    candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")

    def test_all_six_registry_packages_require_exact_source(self) -> None:
        for name in candidate.CORE_WEB_PACKAGES:
            for field, replacement, message in (
                ("gitHead", "0" * 40, "gitHead"),
                ("repository", "git+https://github.com/other/core-web.git", "repository"),
                ("gitHead", None, "gitHead"),
                ("repository", None, "repository"),
            ):
                with self.subTest(name=name, field=field, replacement=replacement):
                    self.write_native_locks()
                    self.registry[name][field] = replacement
                    with self.assertRaisesRegex(SystemExit, message):
                        candidate.read_core_web("4.0.0-rc.4", "e7e00110b999d5f91ecf7b5861415810a5fcb14c")

    def test_registry_command_forces_public_registry_and_reads_source(self) -> None:
        data = {"version": "4.0.0-rc.4", "dist.tarball": "https://registry.npmjs.org/@peanut-admin/vue/-/vue-4.0.0-rc.4.tgz",
                "dist.integrity": sri("@peanut-admin/vue"),
                "repository.url": "git+https://github.com/peanut-business/peanut-admin-core-web.git",
                "gitHead": "e7e00110b999d5f91ecf7b5861415810a5fcb14c"}
        with patch.object(candidate, "npm_command", return_value="npm"), patch.object(candidate.subprocess, "check_output", return_value=json.dumps(data)) as command:
            result = candidate.npm_registry_package("@peanut-admin/vue", "4.0.0-rc.4")
        self.assertEqual(result["gitHead"], data["gitHead"])
        self.assertEqual(result["repository"], data["repository.url"])
        self.assertIn("--registry=https://registry.npmjs.org", command.call_args.args[0])
        self.assertIn("repository.url", command.call_args.args[0])

    def test_rejects_existing_candidate_and_local_repository_before_writes(self) -> None:
        self.write_manifests()
        with self.assertRaisesRegex(SystemExit, "must be new"):
            candidate.check_inputs("4.0.0-dev.14")
        composer = candidate.read_json(self.root / "server/composer.json")
        composer["repositories"] = [{"type": "path", "url": "../core"}]
        self.write_json("server/composer.json", composer)
        original = (self.root / "server/composer.json").read_bytes()
        with self.assertRaisesRegex(SystemExit, "local or synthetic"):
            candidate.check_inputs("4.0.0-rc.5")
        self.assertEqual((self.root / "server/composer.json").read_bytes(), original)


if __name__ == "__main__":
    unittest.main(verbosity=2)
