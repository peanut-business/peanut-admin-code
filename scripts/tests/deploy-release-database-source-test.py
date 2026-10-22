#!/usr/bin/env python3
"""Synthetic canonical archive/dry-run and native configuration component contracts.

Requires explicit PEANUT_CONTRACT_PHP and, outside a prepared checkout,
PEANUT_CONTRACT_AUTOLOAD. No SSH, Docker, DB, real mount/HTTP or runtime admission.
"""
import copy
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest

sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = (ROOT / 'scripts/deploy-release').read_text()
PLAN = re.search(r'<<\x27PY\x27\n(import hashlib,json.*?)\nPY', SCRIPT, re.S).group(1)
ENVIRONMENT = re.search(r'python3 - "\$root" "\$plan" <<\x27PY\x27\n(.*?)\nPY', SCRIPT, re.S).group(1)
PUBLICATION = re.search(r'python3 - "\$parent" "\$root" "\$stage" <<\x27PY\x27\n(.*?)\nPY', SCRIPT, re.S).group(1)
IMAGE = 'sha256:' + '1' * 64


class DeploymentDatabaseSourceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='peanut-deploy-contract-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()

    def native_environment(self):
        environment = dict(os.environ, PEANUT_CONTRACT_PYTHON=sys.executable)
        # Only child synthetic tests: APP selectors must never inherit the caller's
        # maintainer registry. No actual registry/credential is read or changed.
        environment.pop('PEANUT_RESOURCE_REGISTRY', None)
        return environment

    def php(self):
        value = os.environ.get('PEANUT_CONTRACT_PHP')
        self.assertTrue(value and Path(value).is_absolute(), 'set PEANUT_CONTRACT_PHP to the registered PHP executable')
        return value

    def fixture(self, source):
        common = {'fallback': 'none', 'environments': ['production', 'production-candidate'], 'host': 'fixture-host',
                  'compose_project': 'fixture-app', 'compose_file': 'server/docker/compose.yaml'}
        rows = {key: dict(common, stable_resource_id=key) for key in ('deploy', 'app', 'http', 'db', 'mysql-runtime')}
        d, a, h, b, m = [rows[k] for k in rows]
        endpoint = {'endpoint_id': 'fixture-container', 'host': 'mysql' if source == 'bundled' else 'db.example.invalid',
                    'port': 3306, 'consumers': ['container']}
        env = {'HTTP_PORT': 18888, 'PEANUT_DATABASE_SOURCE': source, 'PEANUT_DATABASE_RESOURCE_ID': 'db',
               'DB_NAME': 'fixture_db', 'DEPLOYMENT_MODE': 'standalone', 'PEANUT_DATABASE_CONSUMER': 'container',
               'PEANUT_DATABASE_ENDPOINT_ID': endpoint['endpoint_id'], 'DB_HOST': endpoint['host'], 'DB_PORT': 3306}
        d.update(application_resource_id='app', http_resource_id='http', database_resource_id='db',
                 deployment_root='/fixture/app', ssh_alias='fixture-host', pc_render_mode='spa',
                 application_package_identity='fixture/app', application_slug='fixture-app',
                 runtime_images={'php': IMAGE, 'nginx': IMAGE}, required_non_secret_environment=env)
        a.update(database_resource_id='db', http_resource_id='http', published_endpoint={'bind': '127.0.0.1', 'port': 18888})
        h.update(ssh_alias='fixture-host', bind='127.0.0.1', port=18888)
        b.update(service_type='mysql', application_runtime=True, database='fixture_db', namespace='fixture_db',
                 container_endpoint=endpoint, deployment_modes=['standalone'])
        if source == 'bundled':
            d['runtime_images']['mysql'] = IMAGE
            b.update(runtime_resource_id='mysql-runtime', image=IMAGE, namespace='/fixture/app/server/docker/mysql')
            m.update(image=IMAGE, database_resource_id='db', namespace=b['namespace'])
        else:
            del rows['mysql-runtime']
        app = {'kind': 'application', 'version': '1.0.0', 'commit': 'a' * 40, 'tree': 'b' * 40,
               'package_identity': 'fixture/app', 'slug': 'fixture-app', 'edition': 'standalone',
               'name': 'Fixture App', 'profile': 'minimal', 'manifest_sha256': 'd' * 64, 'source_files_sha256': 'e' * 64}
        return rows, app

    def plan(self, rows, app, projection_change=None, missing_identity=False, expected_commit='a' * 40, expected_tree='b' * 40):
        registry = self.root / 'registry.json'
        registry.write_text(json.dumps(list(rows.values())))
        projection = {'schema_version': 1, 'project_id': app['slug'], 'authority': {'role': 'application'},
                      'resources': {'databases': [copy.deepcopy(rows['db'])]}}
        if projection_change: projection_change(projection)
        projection_path = self.root / 'public-projection.json'
        projection_path.write_text(json.dumps(projection))
        app_path = self.root / 'fixture-application.json'; app_path.write_text(json.dumps(app))
        prepared = self.root / 'release-fixture'
        if prepared.exists(): shutil.rmtree(prepared)  # Own TemporaryDirectory only.
        native = ROOT / 'server/tests/Productization/InstallationConfigurationHostTest.php'
        exported = subprocess.run([self.php(), str(native), 'export-release', str(prepared), str(app_path), str(projection_path)],
                                  env=self.native_environment(), text=True, capture_output=True)
        self.assertEqual(exported.returncode, 0, exported.stdout + exported.stderr)
        identity = json.loads(exported.stdout)
        app.update(identity['application'])
        archive = self.root / 'fixture.tar.gz'
        with tarfile.open(archive, 'w:gz') as package:
            for path in sorted((prepared / 'server').rglob('*')):
                if not path.is_file(): continue
                if missing_identity and path.name == 'release-identity.json': continue
                package.add(path, arcname='app/' + str(path.relative_to(prepared)), recursive=False)
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        output = self.root / 'plan.json'
        result = subprocess.run([sys.executable, '-', str(registry), 'deploy', 'production', 'v1.0.0',
                                 expected_commit, expected_tree, str(archive), 'install', digest, str(output)],
                                input=PLAN, text=True, capture_output=True)
        return result, json.loads(output.read_text()) if result.returncode == 0 else None

    def test_valid_modes_and_frozen_selection(self):
        for source in ('bundled', 'registered'):
            with self.subTest(source=source):
                result, plan = self.plan(*self.fixture(source))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(plan['database_source'], source)
                self.assertEqual(plan['database_endpoint']['consumers'], ['container'])
        rows, app = self.fixture('bundled')
        rows['deploy']['required_non_secret_environment'].pop('PEANUT_DATABASE_SOURCE')
        result, plan = self.plan(rows, app)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(plan['database_source'], 'bundled')

    def test_reject_invalid_registered_contracts(self):
        baseline, _ = self.plan(*self.fixture('registered'))
        self.assertEqual(baseline.returncode, 0, baseline.stderr)
        mutations = {
            'permission': (lambda r: r['db']['container_endpoint'].update(consumers=['host']), 'invalid registered MySQL endpoint'),
            'relationship': (lambda r: r['deploy'].update(database_resource_id='missing-db'), 'missing/ambiguous resource: missing-db'),
            'mode conflict': (lambda r: r['deploy'].update(database_source='bundled'), 'database source declarations conflict'),
            'source missing': (lambda r: r['deploy']['required_non_secret_environment'].pop('PEANUT_DATABASE_SOURCE'), 'missing/ambiguous resource: None'),
            'image': (lambda r: r['deploy']['runtime_images'].update(mysql=IMAGE), 'immutable runtime image set required'),
            'mutable image': (lambda r: r['deploy']['runtime_images'].update(php='php:latest'), 'immutable runtime image set required'),
            'endpoint drift': (lambda r: r['deploy']['required_non_secret_environment'].update(DB_HOST='other.invalid'), 'registered container endpoint configuration differs'),
            'bundled profile': (lambda r: r['deploy']['required_non_secret_environment'].update(COMPOSE_PROFILES='bundled-database'), 'registered external database relationship differs'),
            'APP relationship': (lambda r: r['app'].update(database_resource_id='other'), 'resource relationship differs'),
            'Edition permission': (lambda r: r['db'].update(deployment_modes=['multi-tenant']), 'registered database APP projection/Edition permission required'),
        }
        for name, (mutate, reason) in mutations.items():
            with self.subTest(name=name):
                rows, app = self.fixture('registered'); mutate(rows)
                result, _ = self.plan(rows, app)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('deploy-release: ' + reason, result.stderr)

    def test_archive_and_application_identity_drift(self):
        for missing, changed in ((True, False), (False, True)):
            rows, app = self.fixture('registered')
            if changed: app['slug'] = 'foreign-app'
            result, _ = self.plan(rows, app, missing_identity=missing)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('ambiguous archive identity' if missing else 'APP identity/Edition differs from target', result.stderr)
        rows, app = self.fixture('registered')
        result, _ = self.plan(rows, app, lambda p: p['resources']['databases'][0]['container_endpoint'].update(port=3307))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('archive database projection differs', result.stderr)

    def test_temporary_unallocated_template_is_rejected_before_publication(self):
        baseline, _ = self.plan(*self.fixture('bundled'))
        self.assertEqual(baseline.returncode, 0, baseline.stderr)
        (self.root / 'plan.json').unlink()
        rows, app = self.fixture('bundled')
        rows['deploy'].update(deployment_kind='temporary-multi-tenant-source',
                              source_commit='c' * 40, source_tree='d' * 40,
                              expires_at='2099-01-01T00:00:00Z', replace_owner='synthetic fixture owner')
        rows['deploy']['required_non_secret_environment']['DEPLOYMENT_MODE'] = 'multi-tenant'
        rows['db']['deployment_modes'] = ['multi-tenant']
        app.update(kind='generated-template', commit=None, tree=None, edition='multi-tenant')
        result, rejected_plan = self.plan(rows, app, lambda p: p['resources'].update(databases=[]),
                                         expected_commit='c' * 40, expected_tree='d' * 40)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stderr.strip(), 'deploy-release: independent APP deployment kind required')
        self.assertIsNone(rejected_plan)
        self.assertFalse((self.root / 'plan.json').exists())

    def test_canonical_archive_digest_guard(self):
        result, plan = self.plan(*self.fixture('registered'))
        self.assertEqual(result.returncode, 0, result.stderr)
        command = [sys.executable, str(ROOT / 'scripts/package-release-files.py'), 'verify',
                   '--archive=' + str(self.root / 'fixture.tar.gz')]
        valid = subprocess.run(command + ['--expected-sha256=' + plan['archive_sha256']], text=True, capture_output=True)
        self.assertEqual(valid.returncode, 0, valid.stderr)
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/package-release-files.py'), 'verify',
                                 '--archive=' + str(self.root / 'fixture.tar.gz'), '--expected-sha256=' + '0' * 64],
                                text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('SHA-256', result.stderr)
        self.assertIn('package-release-files.py" verify --archive="$archive" --expected-sha256="$digest"', SCRIPT)

    def test_bundled_restrictions(self):
        for field, value in (('namespace', 'fixture_db'), ('image', 'mysql:latest')):
            rows, app = self.fixture('bundled'); rows['db'][field] = value
            result, _ = self.plan(rows, app)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('MySQL directory differs' if field == 'namespace' else 'MySQL image differs', result.stderr)

    def test_runtime_settings_and_official_overlay(self):
        for source in ('bundled', 'registered'):
            rows, app = self.fixture(source)
            template = json.loads((ROOT / 'server/docker/runtime-configuration.example.json').read_text())
            rows['deploy']['runtime_images']['nginx'] = template['NGINX_IMAGE']
            if source == 'bundled':
                rows['deploy']['runtime_images']['mysql'] = template['MYSQL_IMAGE']
                rows['db']['image'] = rows['mysql-runtime']['image'] = template['MYSQL_IMAGE']
            result, plan = self.plan(rows, app)
            self.assertEqual(result.returncode, 0, result.stderr)
            server = self.root / source / 'server'; docker = server / 'docker'; docker.mkdir(parents=True)
            (docker / 'runtime-configuration.example.json').write_bytes((ROOT / 'server/docker/runtime-configuration.example.json').read_bytes())
            command = [sys.executable, str(ROOT / 'server/docker/scripts/configure-runtime.py'), '--docker-root', str(docker),
                       '--php-image', IMAGE, '--project', 'fixture-app', '--http-bind', '127.0.0.1', '--http-port', '18888',
                       '--database-source', source]
            result = subprocess.run(command, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            # Keep actual public template pins, including the generated settings.
            env = docker / '.env'
            content = env.read_text()
            plan_path = self.root / 'runtime-plan.json'; plan_path.write_text(json.dumps(plan))
            result = subprocess.run([sys.executable, '-', str(server.parent), str(plan_path)], input=ENVIRONMENT, text=True, capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            if source == 'registered':
                self.assertNotIn('MYSQL_', content)
                backend = server / '.env'
                settings = rows['deploy']['required_non_secret_environment']
                backend.write_text(''.join(key + '=' + json.dumps(str(value)) + '\n' for key, value in settings.items()))
                backend.chmod(0o600)
                private_registry = server / 'private/resources/project-resources.json'
                private_registry.parent.mkdir(parents=True)
                private_registry.write_text(json.dumps({'schema_version': 1, 'project_id': app['slug'], 'authority': {'role': 'application'},
                                                        'resources': {'databases': [rows['db']]}}))
                private_registry.chmod(0o600)
                result = subprocess.run([sys.executable, '-', str(server.parent), str(plan_path)], input=ENVIRONMENT, text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                backend.write_text(backend.read_text().replace('db.example.invalid', 'foreign.invalid'))
                result = subprocess.run([sys.executable, '-', str(server.parent), str(plan_path)], input=ENVIRONMENT, text=True, capture_output=True)
                self.assertNotEqual(result.returncode, 0)
                backend.unlink()
                env.write_text(content + 'MYSQL_IMAGE=' + IMAGE + '\n')
                result = subprocess.run([sys.executable, '-', str(server.parent), str(plan_path)], input=ENVIRONMENT, text=True, capture_output=True)
                self.assertNotEqual(result.returncode, 0)
        self.assertIn('sh server/docker/scripts/start.sh -f compose.registered-database.yaml', SCRIPT)
        spec = importlib.util.spec_from_file_location('compose_contract', ROOT / 'server/docker/scripts/configure-collaboration.py')
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        self.assertEqual(module.registered_database_services(ROOT / 'server', ['-f', 'compose.registered-database.yaml']), ['php', 'nginx'])

    def test_nonempty_installation_root_is_preserved(self):
        root = self.root / 'app'; root.mkdir(); (root / 'keep').write_text('preserve')
        stage = self.root / 'stage'; (stage / 'server').mkdir(parents=True)
        result = subprocess.run([sys.executable, '-', str(self.root), str(root), str(stage)], input=PUBLICATION, text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((root / 'keep').read_text(), 'preserve')
        self.assertFalse((root / 'server').exists())

    def test_native_first_configuration_consumer(self):
        php = self.php()
        native = ROOT / 'server/tests/Productization/InstallationConfigurationHostTest.php'
        environment = self.native_environment()
        result = subprocess.run([php, str(native)], env=environment, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        for source in ('bundled', 'registered'):
            with self.subTest(source=source):
                rows, app = self.fixture(source)
                if source == 'registered':
                    rows['db']['upstream_endpoint'] = dict(rows['db']['container_endpoint'], endpoint_id='fixture-host',
                                                           host='host-db.example.invalid', consumers=['host'])
                def alternatives(projection):
                    if source == 'registered':
                        secondary = copy.deepcopy(rows['db'])
                        secondary.update(stable_resource_id='fixture-secondary-db', database='fixture_secondary', namespace='fixture_secondary')
                        secondary['container_endpoint'].update(endpoint_id='fixture-secondary-container', host='secondary.example.invalid')
                        projection['resources']['databases'].append(secondary)
                result, _ = self.plan(rows, app, alternatives)
                self.assertEqual(result.returncode, 0, result.stderr)
                projection_path = self.root / 'public-projection.json'
                result = subprocess.run([php, str(native), 'mounted', str(self.root / 'plan.json'), str(projection_path)],
                                        env=environment, text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_wrapper_dry_run_with_canonical_archive(self):
        for source in ('bundled', 'registered'):
            result, plan = self.plan(*self.fixture(source))
            self.assertEqual(result.returncode, 0, result.stderr)
            environment = self.native_environment()
            environment['PEANUT_RESOURCE_REGISTRY'] = str(self.root / 'registry.json')
            result = subprocess.run(['bash', str(ROOT / 'scripts/deploy-release'), 'v1.0.0', '--target', 'production',
                '--deployment-resource', 'deploy', '--expected-commit', 'a' * 40, '--expected-tree', 'b' * 40, '--install',
                '--server-package', str(self.root / 'fixture.tar.gz'), '--expected-sha256', plan['archive_sha256'], '--dry-run'],
                env=environment, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()
