#!/usr/bin/env python3
"""Real Git/file continuation with clearly substituted external commands; no network."""
from __future__ import annotations

import base64
import fcntl
import hashlib
import importlib.machinery
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "continue-product-release"
loader = importlib.machinery.SourceFileLoader("continue_product_release", str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
release = importlib.util.module_from_spec(spec)
loader.exec_module(release)


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(["git", "-C", str(root), *args], text=True).strip()


def write_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2) + "\n")


def tiny_archive(root: str) -> bytes:
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode="w:gz") as archive:
        data = b"fixture-content"
        item = tarfile.TarInfo(f"{root}/server/fixture.txt")
        item.size = len(data)
        archive.addfile(item, io.BytesIO(data))
    return stream.getvalue()


class ContinuationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.repo("code", "peanut-admin-code")
        self.php = self.repo("php", "peanut-admin-core-php")
        self.web = self.repo("web", "peanut-admin-core-web")
        self.candidate = self.root / "candidate"
        git(self.source, "worktree", "add", "--detach", str(self.candidate), "HEAD")
        self.output = self.root / "output"
        self.calls: list[str] = []
        self.original_command = release.command
        self.core_failure: str | None = None
        self.changed_digest = False
        self.qualification_ok = True
        self.remote_release: dict | None = None
        self.remote_bad_digest = False
        self.publish_calls = 0
        self.publisher_unknown_once = False
        self.deps_fail = False

    def repo(self, name: str, remote: str) -> Path:
        path = self.root / name
        path.mkdir()
        subprocess.run(["git", "init", "-q", "-b", "main", str(path)], check=True)
        git(path, "config", "user.name", "Fixture")
        git(path, "config", "user.email", "fixture@example.test")
        git(path, "remote", "add", "origin", f"https://github.com/peanut-business/{remote}.git")
        (path / "payload.txt").write_text("source\n")
        if name == "code":
            (path / ".gitignore").write_text("server/vendor/\n")
            write_json(path / "release-versions.json", {"schema_version": 3, "source_product_version": "4.0.0-dev.1"})
            write_json(path / "server/composer.lock", {"packages": []})
            write_json(path / "scaffold/application-template-inventory.json", {"schema_version": 2, "version": "old"})
            write_json(path / "server/tests/fixtures/p0e-runtime-qualification/matrix.json", {"target_release": {"version": "old"}})
        if name == "php":
            (path / ".github/workflows").mkdir(parents=True)
            (path / ".github/workflows/release.yml").write_text("name: fixture release\n")
            write_json(path / "composer.json", {"name": "peanut-admin/core"})
        git(path, "add", ".")
        git(path, "commit", "-qm", "fixture")
        return path

    def argv(self, *extra: str) -> list[str]:
        return ["continue-product-release", "--source", str(self.source), "--candidate", str(self.candidate),
                "--core-php-root", str(self.php), "--core-web-root", str(self.web),
                "--output", str(self.output), "--source-commit", git(self.source, "rev-parse", "HEAD"),
                "--product-version", "4.0.0-rc.10", "--core-php-version", "4.0.0-rc.3",
                "--core-php-reference", git(self.php, "rev-parse", "HEAD"),
                "--core-web-version", "4.0.0-rc.4", "--core-web-reference", git(self.web, "rev-parse", "HEAD"),
                "--baseline", *extra]

    def published(self, kind: str, version: str, reference: str, root: Path) -> dict:
        if self.core_failure == kind:
            raise release.Stop(f"core-{kind}", "public package missing or source mismatch")
        return {"tag_object": kind + "-tag", "commit": reference, "release_id": kind + "-release",
                "digest": kind + ("-changed" if self.changed_digest else "-same")}

    def refs(self, root: Path, tag: str) -> tuple[str, str, str]:
        commit = git(self.candidate, "rev-parse", "HEAD")
        return commit, git(self.candidate, "rev-parse", f"{tag}^{{tag}}"), commit

    def create_artifacts(self) -> None:
        directory = self.output / "edition-artifacts"
        directory.mkdir(exist_ok=True)
        commit = git(self.candidate, "rev-parse", "HEAD")
        tree = git(self.candidate, "rev-parse", "HEAD^{tree}")
        lines = []
        for edition in ("standalone", "multi-tenant"):
            base = f"peanut-admin-4.0.0-rc.10-{edition}.tar.gz"
            server = f"peanut-admin-4.0.0-rc.10-{edition}-server.tar.gz"
            manifest = {"schema_version": 1, "protocol": "peanut.edition-installer.v1",
                        "product": {"version": "4.0.0-rc.10"}, "edition": {"name": edition},
                        "source": {"commit": commit, "tree": tree}}
            for name, field in ((base, "archive"), (server, "server_archive")):
                root = name.removesuffix(".tar.gz")
                content = tiny_archive(root)
                (directory / name).write_bytes(content)
                sha = hashlib.sha256(content).hexdigest()
                manifest[field] = {"filename": name, "root": root, "format": "tar.gz",
                                   "layout": "server-only" if field == "server_archive" else "application-development-source",
                                   "bytes": len(content), "sha256": sha}
                lines.append(f"{sha}  {name}\n")
            write_json(directory / (base + ".manifest.json"), manifest)
        (directory / "SHA256SUMS").write_text("".join(lines))

    def external(self, argv: list[str], *, cwd: Path | None = None, phase: str, allow_missing: bool = False) -> str:
        if argv[0] == "git":
            return self.original_command(argv, cwd=cwd, phase=phase)
        self.calls.append(phase)
        if phase == "prepare":
            write_json(self.candidate / "release-versions.json", {"schema_version": 3, "source_product_version": "4.0.0-rc.10"})
        elif phase == "tool-dependencies":
            if self.deps_fail:
                raise release.Stop("tool-dependencies", "candidate Composer install failed", 8)
            if "install" in argv:
                autoload = self.candidate / "server/vendor/autoload.php"
                autoload.parent.mkdir(parents=True, exist_ok=True)
                autoload.write_text("<?php // installed by external Composer stand-in\n")
        elif phase == "inventory":
            if "--check" not in argv:
                write_json(self.candidate / "scaffold/application-template-inventory.json", {"schema_version": 2, "version": "4.0.0-rc.10"})
        elif phase == "scaffold":
            destination = Path(next(value.split("=", 1)[1] for value in argv if value.startswith("--output=")))
            destination.mkdir(parents=True)
            commit = git(self.candidate, "rev-parse", "HEAD")
            write_json(destination / "scaffold-manifest.json", {"release": {
                "version": "4.0.0-rc.10", "source_commit": commit,
                "source_tree": git(self.candidate, "rev-parse", "HEAD^{tree}"),
                "inventory_sha256": hashlib.sha256((self.candidate / "scaffold/application-template-inventory.json").read_bytes()).hexdigest(),
                "managed_tree_sha256": "a" * 64}, "files": []})
        elif phase == "installers" and "build-edition-installers" in argv[1]:
            self.create_artifacts()
        elif phase == "installers" and argv[2] == "verify":
            archive = Path(next(value.split("=", 1)[1] for value in argv if value.startswith("--archive=")))
            expected = next(value.split("=", 1)[1] for value in argv if value.startswith("--expected-sha256="))
            self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), expected)
            with tarfile.open(archive, "r:gz") as stream:
                self.assertEqual(stream.getnames(), [f"{archive.name.removesuffix('.tar.gz')}/server/fixture.txt"])
        elif phase == "qualification":
            if not self.qualification_ok:
                raise release.Stop("qualification", "existing validator rejected summary", 7)
        elif phase == "publish" and argv[:2] == ["gh", "api"]:
            return json.dumps(self.remote_release) if self.remote_release else ""
        elif phase == "publish" and "publish-github-release" in argv[0]:
            self.publish_calls += 1
            publication = Path(argv[argv.index("--output") + 1])
            publication.mkdir()
            for name in release.publication_assets("4.0.0-rc.10", True) | {"release-notes.md"}:
                (publication / name).write_bytes(name.encode())
            assets = [{"name": name, "size": (publication / name).stat().st_size,
                       "digest": "sha256:" + hashlib.sha256((publication / name).read_bytes()).hexdigest()}
                      for name in release.publication_assets("4.0.0-rc.10", True)]
            self.remote_release = {"id": 27, "tag_name": "v4.0.0-rc.10", "draft": False,
                                   "prerelease": True, "assets": assets}
            if self.publisher_unknown_once:
                self.publisher_unknown_once = False
                raise release.Stop("publish", "external result unknown after upload", 124)
        else:
            raise AssertionError(f"unexpected external command: {argv}")
        return ""

    def run_flow(self, *extra: str) -> None:
        with patch.object(sys, "argv", self.argv(*extra)), patch.object(release, "command", self.external), \
                patch.object(release, "public_core", self.published), patch.object(release, "remote_refs", self.refs):
            release.main()

    def state(self) -> dict:
        return json.loads((self.output / "phase-state.json").read_text())

    def prepare_final_commit(self) -> str:
        with self.assertRaisesRegex(release.Stop, "inventory"):
            self.run_flow("--apply")
        with self.assertRaisesRegex(release.Stop, "seal source"):
            self.run_flow("--apply", "--generate-inventory")
        git(self.candidate, "add", ".")
        git(self.candidate, "commit", "-qm", "sealed source")
        seal = git(self.candidate, "rev-parse", "HEAD")
        with self.assertRaisesRegex(release.Stop, "final product source"):
            self.run_flow("--apply", "--seal-source-commit", seal)
        git(self.candidate, "add", ".")
        git(self.candidate, "commit", "-qm", "final product")
        return git(self.candidate, "rev-parse", "HEAD")

    def test_dry_run_no_writes(self) -> None:
        self.run_flow()
        self.assertFalse(self.output.exists())
        self.assertEqual(self.calls, [])

    def test_core_failure_and_source_change_release_lock(self) -> None:
        self.core_failure = "web"
        with self.assertRaises(release.Stop):
            self.run_flow("--apply")
        self.assertEqual(list(self.state()["stages"]), ["core-php"])
        self.core_failure = None
        args = self.argv("--apply")
        args[args.index("--product-version") + 1] = "4.0.0-rc.11"
        with patch.object(sys, "argv", args), patch.object(release, "public_core", self.published):
            with self.assertRaisesRegex(release.Stop, "fixed inputs/source differ"):
                release.main()
        with self.assertRaisesRegex(release.Stop, "inventory"):
            self.run_flow("--apply")
        (self.source / "payload.txt").write_text("changed\n")
        with self.assertRaisesRegex(release.Stop, "dirty repository"):
            self.run_flow("--apply")

    def test_output_lock_contention_releases_for_next_call(self) -> None:
        self.output.mkdir()
        with (self.output / ".phase-lock").open("w") as lock_file:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(release.Stop, "another coordinator"):
                self.run_flow("--apply")
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
        with self.assertRaisesRegex(release.Stop, "inventory"):
            self.run_flow("--apply")

    def test_nested_output_is_rejected_before_state_write(self) -> None:
        args = self.argv("--apply")
        args[args.index("--output") + 1] = str(self.php / "nested-output")
        with patch.object(sys, "argv", args):
            with self.assertRaisesRegex(release.Stop, "must not overlap"):
                release.main()
        self.assertFalse((self.php / "nested-output").exists())

    def test_tool_dependency_failure_keeps_core_and_native_lock(self) -> None:
        with self.assertRaisesRegex(release.Stop, "inventory"):
            self.run_flow("--apply")
        self.deps_fail = True
        with self.assertRaisesRegex(release.Stop, "candidate Composer install failed"):
            self.run_flow("--apply", "--generate-inventory")
        self.assertEqual(self.state()["failure"]["exit_code"], 8)
        self.assertIn("prepare", self.state()["stages"])
        self.assertNotIn("inventory", self.state()["stages"])

    def test_full_two_commit_chain_qualification_and_resume(self) -> None:
        final = self.prepare_final_commit()
        qualification = self.root / "qualification.json"
        write_json(qualification, {"status": "passed"})
        with self.assertRaisesRegex(release.Stop, "pass --publish"):
            self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification))
        self.assertTrue({"prepared-commit", "installers", "qualification"}.issubset(self.state()["stages"]))
        self.assertEqual(self.calls.count("prepare"), 1)
        self.assertEqual(self.calls.count("scaffold"), 1)
        self.assertEqual(self.calls.count("qualification"), 1)
        git(self.candidate, "tag", "-a", "v4.0.0-rc.10", "-m", "release")
        self.publisher_unknown_once = True
        with self.assertRaisesRegex(release.Stop, "external result unknown"):
            self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification), "--publish")
        self.assertNotIn("publish", self.state()["stages"])
        self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification), "--publish")
        self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification), "--publish")
        self.assertEqual(self.publish_calls, 1)
        self.assertIn("publish", self.state()["stages"])

    def test_missing_server_archive_preserves_partial_output(self) -> None:
        final = self.prepare_final_commit()
        original = self.create_artifacts
        def incomplete() -> None:
            original()
            (self.output / "edition-artifacts/peanut-admin-4.0.0-rc.10-standalone-server.tar.gz").unlink()
        self.create_artifacts = incomplete
        with self.assertRaisesRegex(release.Stop, "archive digest"):
            self.run_flow("--apply", "--prepared-commit", final, "--build")
        self.assertNotIn("installers", self.state()["stages"])
        with self.assertRaisesRegex(release.Stop, "partial installer output"):
            self.run_flow("--apply", "--prepared-commit", final, "--build")

    def test_qualification_failure_never_records_passed_stage(self) -> None:
        final = self.prepare_final_commit()
        qualification = self.root / "qualification.json"
        write_json(qualification, {})
        self.qualification_ok = False
        with self.assertRaisesRegex(release.Stop, "validator rejected"):
            self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification))
        self.assertNotIn("qualification", self.state()["stages"])

    def test_remote_wrong_digest_never_republishes(self) -> None:
        final = self.prepare_final_commit()
        qualification = self.root / "qualification.json"
        write_json(qualification, {"status": "passed"})
        git(self.candidate, "tag", "-a", "v4.0.0-rc.10", "-m", "release")
        self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification), "--publish")
        self.remote_release["assets"][0]["digest"] = "sha256:" + "0" * 64
        with self.assertRaisesRegex(release.Stop, "remote asset size/digest"):
            self.run_flow("--apply", "--prepared-commit", final, "--build", "--qualification", str(qualification), "--publish")
        self.assertEqual(self.publish_calls, 1)

    def test_completed_artifact_symlink_is_rejected(self) -> None:
        final = self.prepare_final_commit()
        with self.assertRaisesRegex(release.Stop, "qualification"):
            self.run_flow("--apply", "--prepared-commit", final, "--build")
        artifact = self.output / "edition-artifacts/peanut-admin-4.0.0-rc.10-standalone.tar.gz"
        backup = self.root / "original.tar.gz"
        artifact.replace(backup)
        artifact.symlink_to(backup)
        with self.assertRaisesRegex(release.Stop, "completed artifact changed"):
            self.run_flow("--apply", "--prepared-commit", final, "--build")

    def test_public_npm_git_head_and_stable_core(self) -> None:
        reference = git(self.web, "rev-parse", "HEAD")
        sri = "sha512-" + base64.b64encode(hashlib.sha512(b"fixture").digest()).decode()
        missing_head = True
        def fake(argv: list[str], *, cwd: Path | None = None, phase: str, allow_missing: bool = False) -> str:
            if argv[0] == "gh" and "/actions/runs?" in argv[2]:
                return json.dumps({"workflow_runs": [{"path": ".github/workflows/release.yml",
                    "head_branch": "v4.0.0", "head_sha": reference, "conclusion": "success", "id": 12}]})
            if argv[0] == "gh":
                return json.dumps({"tag_name": "v4.0.0", "draft": False, "prerelease": False, "id": 14})
            if argv[0] == "npm":
                return json.dumps({"version": "4.0.0", "repository.url":
                    "git+https://github.com/peanut-business/peanut-admin-core-web.git",
                    "gitHead": None if missing_head and argv[2].startswith("@peanut-admin/vue@") else reference,
                    "dist.integrity": sri, "dist.tarball": "https://registry.npmjs.org/example.tgz"})
            raise AssertionError(argv)
        with patch.object(release, "remote_refs", return_value=(reference, "tag-object", reference)), \
                patch.object(release, "command", fake):
            with self.assertRaisesRegex(release.Stop, "gitHead"):
                release.public_core("web", "4.0.0", reference, self.web)
            missing_head = False
            evidence = release.public_core("web", "4.0.0", reference, self.web)
            self.assertEqual(len(evidence["packages"]), 6)

    def test_new_core_tag_requires_successful_main_ci_then_uses_annotated_git_tag(self) -> None:
        reference = git(self.php, "rev-parse", "HEAD")
        def fake(argv: list[str], *, cwd: Path | None = None, phase: str, allow_missing: bool = False) -> str:
            if argv[:2] == ["gh", "api"]:
                return json.dumps({"workflow_runs": [{"path": ".github/workflows/ci.yml",
                    "head_sha": reference, "head_branch": "main", "conclusion": "success"}]})
            if argv[0] == "git" and "push" in argv:
                self.calls.append("tag-push-stand-in")
                return ""
            return self.original_command(argv, cwd=cwd, phase=phase)
        with patch.object(release, "remote_refs", return_value=(reference, None, None)), \
                patch.object(release, "command", fake):
            release.tag_core("php", "4.0.0-rc.11", reference, self.php)
        self.assertEqual(git(self.php, "cat-file", "-t", "v4.0.0-rc.11"), "tag")
        self.assertEqual(self.calls, ["tag-push-stand-in"])

    def test_partial_web_action_remains_waiting_with_exact_existing_run(self) -> None:
        reference = git(self.web, "rev-parse", "HEAD")
        def fake(argv: list[str], *, cwd: Path | None = None, phase: str, allow_missing: bool = False) -> str:
            self.assertEqual(argv[:2], ["gh", "api"])
            return json.dumps({"workflow_runs": [{"path": ".github/workflows/release.yml",
                "head_sha": reference, "head_branch": "v4.0.0-rc.4", "conclusion": "failure", "id": 42}]})
        with patch.object(release, "remote_refs", return_value=(reference, "tag-object", reference)), \
                patch.object(release, "command", fake):
            with self.assertRaisesRegex(release.Stop, r"gh run rerun 42 --repo peanut-business/peanut-admin-core-web; waiting"):
                release.public_core("web", "4.0.0-rc.4", reference, self.web)


if __name__ == "__main__":
    unittest.main()
