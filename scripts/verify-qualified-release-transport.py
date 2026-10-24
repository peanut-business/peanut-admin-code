#!/usr/bin/env python3
"""Verify original completed qualification bytes; never run groups or publish."""
import argparse
import datetime
import hashlib
import json
import pathlib
import re
import subprocess
import tarfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
MAX_ARCHIVE_BYTES = 512 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def read(path):
    value = json.loads(path.read_bytes())
    require(isinstance(value, dict), 'Expected a native JSON object')
    return value


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args], text=True).strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', required=True)
    parser.add_argument('--transport-sha256', required=True)
    parser.add_argument('--main-integration-sha256', required=True)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--output-root', required=True)
    parser.add_argument('--mode', choices=['ingress', 'publish'], required=True)
    args = parser.parse_args()
    source = args.source_commit
    require(re.fullmatch('[a-f0-9]{40}', source), 'Exact qualification source required')
    for value in (args.transport_sha256, args.main_integration_sha256):
        require(re.fullmatch('[a-f0-9]{64}', value), 'Independent exact SHA-256 required')
    head, tree = git('rev-parse', 'HEAD'), git('rev-parse', 'HEAD^{tree}')
    require(git('rev-parse', source + '^{tree}') == tree, 'Checkout differs from the qualified source tree')
    if args.mode == 'ingress':
        require(head == source, 'Ingress must execute from the exact qualified source')
    elif head != source:
        parents = git('show', '-s', '--format=%P', 'HEAD').split()
        require(len(parents) == 2 and parents[1] == source, 'Main must normally integrate the qualified source')
    archive = pathlib.Path(args.archive)
    output = pathlib.Path(args.output_root)
    require(archive.is_absolute() and archive.resolve() == archive and archive.is_file()
            and not archive.is_symlink() and archive.stat().st_nlink == 1
            and 0 < archive.stat().st_size <= MAX_ARCHIVE_BYTES, 'Unsafe or oversized transport input')
    require(sha(archive) == args.transport_sha256, 'Qualified transport digest differs')
    require(output.is_absolute() and output.parent.resolve() == output.parent and output.parent.is_dir()
            and not output.exists() and not output.is_symlink() and ROOT not in output.parents,
            'Output must be an absent canonical directory outside source')
    groups = read(ROOT / 'server/tests/fixtures/p0e-runtime-qualification/matrix.json')['groups']
    require(len(groups) == 8 and len(set(groups)) == 8, 'Exact eight-group source contract required')
    qualification_files = {'qualification/' + name for name in ['summary.json', 'plan.json', 'checkpoint.json', 'main-integration.json']}
    qualification_files.update('qualification/groups/' + group + '.json' for group in groups)
    with tarfile.open(archive, 'r:gz') as package:
        members = package.getmembers()
        require(0 < len(members) <= 200000 and len({m.name for m in members}) == len(members), 'Invalid transport member set')
        require(sum(m.size for m in members) <= 8 * 1024**3, 'Transport payload too large')
        for member in members:
            path = pathlib.PurePosixPath(member.name)
            require(not path.is_absolute() and '..' not in path.parts and path.parts
                    and path.as_posix() == member.name.rstrip('/') and '\\' not in member.name
                    and path.parts[0] in ('scaffold-templates', 'qualification'), 'Transport path outside admitted roots')
            require((member.isdir() or member.isfile()) and member.mode in (0o600, 0o644, 0o700, 0o755), 'Unsupported transport member')
            if member.isfile() and path.parts[0] == 'qualification':
                require(member.name in qualification_files, 'Undeclared qualification file')
        require(qualification_files <= {m.name for m in members if m.isfile()}, 'Native qualification files missing')
        output.mkdir(mode=0o700)
        package.extractall(output, filter='data')
    qualification = output / 'qualification'
    summary = read(qualification / 'summary.json')
    require(summary.get('status') == 'passed' and summary.get('scope') == 'full'
            and summary.get('candidate') == source and summary.get('candidate_tree') == tree
            and summary.get('planned_groups') == groups and summary.get('full_gate_groups') == groups,
            'A native complete exact-source full qualification is required')
    states = summary.get('groups', {})
    require(set(states) == set(groups) and all(states[g].get('status') == 'passed' for g in groups), 'Qualification incomplete')
    cleanup = summary.get('cleanup', {})
    require(cleanup.get('lease_released') is True and all(cleanup.get(field) == 0 for field in
            ('database_residual_count', 'container_residual_count', 'volume_residual_count',
             'network_residual_count', 'image_residual_count', 'listener_residual_count')), 'Native cleanup incomplete')
    lifecycle = summary.get('lifecycle_inputs', {})
    require(re.fullmatch('[a-f0-9]{64}', lifecycle.get('input_sha256', '')), 'Native lifecycle binding missing')
    for name in ('plan.json', 'checkpoint.json'):
        value = read(qualification / name)
        for key in ('candidate', 'candidate_tree', 'run_id', 'lifecycle_inputs', 'template_artifacts'):
            require(key in summary and value.get(key) == summary[key], 'Native plan/checkpoint inputs differ: ' + key)
        if name == 'plan.json':
            require(value.get('groups') == groups, 'Native plan does not cover all eight groups')
        else:
            require(value.get('groups') == states, 'Native checkpoint group closure differs')
    for group in groups:
        path = qualification / 'groups' / (group + '.json')
        require(sha(path) == states[group].get('receipt', {}).get('sha256'), 'Native group receipt digest differs')
        result = read(path)
        require(result.get('status') == 'passed' and result.get('candidate') == source
                and result.get('candidate_tree') == tree and result.get('lifecycle_input_sha256') == lifecycle['input_sha256'],
                'Native group execution identity differs')
    integration = qualification / 'main-integration.json'
    require(sha(integration) == args.main_integration_sha256, 'Main integration receipt changed')
    receipt = read(integration)
    require(set(receipt) == {'schema_version', 'repository', 'source_commit', 'base_commit', 'ready_for_stable',
                            'readiness_at', 'premerge_observed_at'} and receipt['schema_version'] == 1
            and receipt['repository'] == 'peanut-business/peanut-admin-code'
            and receipt['source_commit'] == source and receipt['ready_for_stable'] is True
            and re.fullmatch('[a-f0-9]{40}', receipt['base_commit']), 'Native premerge source receipt invalid')
    times = []
    for key in ('readiness_at', 'premerge_observed_at'):
        value = receipt[key]
        require(isinstance(value, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z', value), 'Native premerge UTC timestamp invalid')
        times.append(datetime.datetime.fromisoformat(value.replace('Z', '+00:00')))
    require(times[0] <= times[1], 'Native readiness must precede premerge observation')
    subprocess.run(['git', '-C', str(ROOT), 'merge-base', '--is-ancestor', receipt['base_commit'], source], check=True)
    # Reuse the production archive/member/lock/tooling verifier; do not execute
    # qualification code from the transport or reinterpret a claimed PASS alone.
    version = read(ROOT / 'release-versions.json')['scaffold_template']
    checked = subprocess.check_output(['node', str(ROOT / 'scripts/release-template-artifacts.mjs'), 'check',
        str(output / 'scaffold-templates'), version, source, tree,
        sha(ROOT / 'scaffold/application-template-inventory.json'), str(ROOT)], cwd=ROOT, text=True)
    require(json.loads(checked) == summary['template_artifacts'], 'Original qualified template bytes or production inputs differ')
    require(sha(archive) == args.transport_sha256, 'Transport changed during validation')
    print(json.dumps({'status': 'transport-verified', 'candidate': source, 'candidate_tree': tree,
        'transport_sha256': args.transport_sha256, 'main_integration_sha256': args.main_integration_sha256,
        'qualification': 'original-native-full-eight-group-evidence', 'publication_authorized': False}))


if __name__ == '__main__':
    main()
