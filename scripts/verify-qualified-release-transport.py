#!/usr/bin/env python3
"""Verify the successful native candidate transport without rebuilding or running tests."""
import argparse
import hashlib
import json
import pathlib
import re
import subprocess
import tarfile

ROOT = pathlib.Path(__file__).resolve().parent.parent


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def read(path):
    value = json.loads(path.read_bytes())
    require(isinstance(value, dict), "Expected a native JSON object")
    return value


def git(*args):
    return subprocess.check_output(["git", "-C", str(ROOT), *args], text=True).strip()


def source_bytes(source, path):
    return subprocess.check_output(["git", "-C", str(ROOT), "show", source + ":" + path])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", required=True)
    parser.add_argument("--transport-sha256", required=True)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--output-root", required=True)
    args = parser.parse_args()
    source = args.source_commit
    require(re.fullmatch("[a-f0-9]{40}", source), "Exact candidate source required")
    require(re.fullmatch("[a-f0-9]{64}", args.transport_sha256), "Independent transport SHA-256 required")
    tree = git("rev-parse", source + "^{tree}")
    archive, output = pathlib.Path(args.archive), pathlib.Path(args.output_root)
    require(archive.is_absolute() and archive.resolve() == archive and archive.is_file()
            and not archive.is_symlink() and archive.stat().st_nlink == 1
            and 0 < archive.stat().st_size <= 512 * 1024**2, "Unsafe or oversized candidate transport")
    require(sha(archive) == args.transport_sha256, "Candidate transport digest differs")
    require(output.is_absolute() and output.parent.resolve() == output.parent and output.parent.is_dir()
            and not output.exists() and ROOT not in output.parents, "Output must be an absent external canonical directory")
    roots = {"scaffold-templates", "template-qualification", "candidate-inputs.json",
             "producer-tools.json", "producer-tools-actual.json"}
    with tarfile.open(archive, "r:gz") as package:
        members = package.getmembers()
        require(0 < len(members) <= 200000 and len({m.name for m in members}) == len(members), "Invalid transport member set")
        require(sum(m.size for m in members) <= 8 * 1024**3, "Candidate payload too large")
        for member in members:
            path = pathlib.PurePosixPath(member.name)
            require(not path.is_absolute() and ".." not in path.parts and path.parts
                    and path.as_posix() == member.name.rstrip("/") and "\\" not in member.name
                    and path.parts[0] in roots, "Path outside native candidate roots")
            require((member.isdir() or member.isfile()) and member.mode in (0o600, 0o644, 0o700, 0o755),
                    "Unsupported candidate member")
        output.mkdir(mode=0o700)
        package.extractall(output, filter="data")
    stage = output / "template-qualification"
    receipt = read(stage / "candidate-stage.json")
    require(receipt.get("protocol") == "peanut.p0e-candidate-stage.v1"
            and receipt.get("status") == "candidate-passed" and receipt.get("scope") == "candidate"
            and receipt.get("candidate") == source and receipt.get("candidate_tree") == tree,
            "A successful exact-source native candidate stage is required")
    candidate_input = output / "candidate-inputs.json"
    selected = read(candidate_input)
    require(sha(candidate_input) == receipt.get("candidate_input", {}).get("sha256")
            and selected.get("source") == {"commit": source, "tree": tree}
            and all(selected.get("cli", {}).get(key) == receipt.get("cli", {}).get(key)
                    for key in ("root", "commit", "tree")), "Original Code or independent CLI input differs")
    native = receipt.get("native_check", {})
    require(native.get("rc") == 0 and native.get("log") == "native-template-check.log"
            and sha(stage / native["log"]) == native.get("log_sha256"), "Original native artifact check differs")
    producer = receipt.get("producer", {})
    require(producer.get("path") == "scripts/p0e-runtime-qualification"
            and producer.get("sha256") == hashlib.sha256(source_bytes(source, producer["path"])).hexdigest()
            and producer.get("verifier_sha256") == hashlib.sha256(source_bytes(source, "scripts/release-template-artifacts.mjs")).hexdigest(),
            "Candidate producer differs from the exact source")
    components = receipt.get("groups", {}).get("generated-application", {}).get("components", {})
    require(components.get("native_template_contract") == "passed"
            and components.get("cli_create_and_app_identity") == "passed", "Native candidate components are incomplete")
    version = json.loads(source_bytes(source, "release-versions.json"))["scaffold_template"]
    checked = json.loads(subprocess.check_output(["node", str(ROOT / "scripts/release-template-artifacts.mjs"), "read",
        str(output / "scaffold-templates"), version, source, tree,
        hashlib.sha256(source_bytes(source, "scaffold/application-template-inventory.json")).hexdigest()], cwd=ROOT, text=True))
    require(checked == receipt.get("template_artifacts"), "Native template bytes or production inputs differ")
    created = receipt.get("cli_created_applications", {})
    require(set(created) == {"standalone", "multi-tenant"}, "Both native CLI creation results are required")
    for edition, result in created.items():
        root = stage / ("cli-create-" + edition)
        manifest = root / ".peanut/application-manifest.json"
        identity = read(manifest)
        archive_name = checked["editions"][edition]["archive"]
        require(sha(manifest) == result.get("manifest_sha256")
                and result.get("cli_result", {}).get("status") == "created"
                and identity.get("application", {}).get("edition") == edition
                and identity.get("edition", {}).get("name") == edition
                and identity.get("template", {}).get("source_commit") == source
                and identity.get("template", {}).get("source_tree") == tree
                and identity.get("template_package", {}).get("archive_sha256") == checked["assets"][archive_name]["sha256"],
                "Created APP identity differs: " + edition)
        require(not any((root / name).exists() for name in (".git", "node_modules", "server/vendor",
                "web/node_modules", "platform/node_modules", "pc/node_modules", "uniapp/node_modules")),
                "Candidate creation imported Git or dependencies")
    require(sha(archive) == args.transport_sha256, "Candidate transport changed during validation")
    print(json.dumps({"status": "transport-verified", "candidate": source, "candidate_tree": tree,
        "execution_source": {"commit": git("rev-parse", "HEAD"), "tree": git("rev-parse", "HEAD^{tree}")},
        "transport_sha256": args.transport_sha256, "acceptance_scope": "native-template-and-cli-candidate",
        "full8": "not_required; no complete-suite pass claimed", "publication_authorized": False}))


if __name__ == "__main__":
    main()
