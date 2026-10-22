#!/usr/bin/env python3
"""Offline direct contracts for deploy-release; no SSH, Docker or database calls."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import re
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

    def fixture(self, source):
        common = {'fallback': 'none', 'environments': ['production'], 'host': 'fixture-host',
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
               'package_identity': 'fixture/app', 'slug': 'fixture-app', 'edition': 'standalone'}
        return rows, app

    def plan(self, rows, app, projection_change=None, missing_identity=False):
        registry = self.root / 'registry.json'
        registry.write_text(json.dumps(list(rows.values())))
        projection = {'project_id': 'fixture-app', 'authority': {'role': 'application'},
                      'resources': {'databases': [copy.deepcopy(rows['db'])]}}
        if projection_change: projection_change(projection)
        archive = self.root / 'fixture.tar.gz'
        with tarfile.open(archive, 'w:gz') as package:
            entries = {'app/server/resources/project-resources.json': projection}
            if not missing_identity: entries['app/server/.peanut/release-identity.json'] = {'application': app}
            for name, value in entries.items():
                content = json.dumps(value).encode()
                member = tarfile.TarInfo(name); member.size = len(content)
                package.addfile(member, io.BytesIO(content))
        output = self.root / 'plan.json'
        result = subprocess.run([sys.executable, '-', str(registry), 'deploy', 'production', 'v1.0.0',
                                 'a' * 40, 'b' * 40, str(archive), 'install', 'c' * 64, str(output)],
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
        mutations = {
            'permission': lambda r: r['db']['container_endpoint'].update(consumers=['host']),
            'relationship': lambda r: r['deploy'].update(database_resource_id='missing-db'),
            'mode conflict': lambda r: r['deploy'].update(database_source='bundled'),
            'source missing': lambda r: r['deploy']['required_non_secret_environment'].pop('PEANUT_DATABASE_SOURCE'),
            'image': lambda r: r['deploy']['runtime_images'].update(mysql=IMAGE),
            'mutable image': lambda r: r['deploy']['runtime_images'].update(php='php:latest'),
            'endpoint drift': lambda r: r['deploy']['required_non_secret_environment'].update(DB_HOST='other.invalid'),
            'bundled profile': lambda r: r['deploy']['required_non_secret_environment'].update(COMPOSE_PROFILES='bundled-database'),
            'APP relationship': lambda r: r['app'].update(database_resource_id='other'),
            'Edition permission': lambda r: r['db'].update(deployment_modes=['multi-tenant']),
        }
        for name, mutate in mutations.items():
            with self.subTest(name=name):
                rows, app = self.fixture('registered'); mutate(rows)
                result, _ = self.plan(rows, app)
                self.assertNotEqual(result.returncode, 0)

    def test_archive_and_application_identity_drift(self):
        for missing, changed in ((True, False), (False, True)):
            rows, app = self.fixture('registered')
            if changed: app['slug'] = 'foreign-app'
            result, _ = self.plan(rows, app, missing_identity=missing)
            self.assertNotEqual(result.returncode, 0)
        rows, app = self.fixture('registered')
        result, _ = self.plan(rows, app, lambda p: p['resources']['databases'][0]['container_endpoint'].update(port=3307))
        self.assertNotEqual(result.returncode, 0)

    def test_archive_digest_guard(self):
        self.plan(*self.fixture('registered'))
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/package-release-files.py'), 'verify',
                                 '--archive=' + str(self.root / 'fixture.tar.gz'), '--expected-sha256=' + '0' * 64],
                                text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('package-release-files.py" verify --archive="$archive" --expected-sha256="$digest"', SCRIPT)

    def test_bundled_restrictions(self):
        for field, value in (('namespace', 'fixture_db'), ('image', 'mysql:latest')):
            rows, app = self.fixture('bundled'); rows['db'][field] = value
            result, _ = self.plan(rows, app)
            self.assertNotEqual(result.returncode, 0)

    def test_runtime_settings_and_official_overlay(self):
        for source in ('bundled', 'registered'):
            rows, app = self.fixture(source)
            result, plan = self.plan(rows, app)
            self.assertEqual(result.returncode, 0, result.stderr)
            server = self.root / source / 'server'; docker = server / 'docker'; docker.mkdir(parents=True)
            (docker / 'runtime-configuration.example.json').write_bytes((ROOT / 'server/docker/runtime-configuration.example.json').read_bytes())
            command = [sys.executable, str(ROOT / 'server/docker/scripts/configure-runtime.py'), '--docker-root', str(docker),
                       '--php-image', IMAGE, '--project', 'fixture-app', '--http-bind', '127.0.0.1', '--http-port', '18888',
                       '--database-source', source]
            result = subprocess.run(command, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            # Template image pins are checked, never silently rewritten by the wrapper.
            env = docker / '.env'
            content = env.read_text()
            content = re.sub(r'^NGINX_IMAGE=.*$', 'NGINX_IMAGE=' + IMAGE, content, flags=re.M)
            if source == 'bundled': content = re.sub(r'^MYSQL_IMAGE=.*$', 'MYSQL_IMAGE=' + IMAGE, content, flags=re.M)
            env.write_text(content)
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
                private_registry.write_text(json.dumps({'project_id': app['slug'], 'authority': {'role': 'application'},
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


if __name__ == '__main__':
    unittest.main()
