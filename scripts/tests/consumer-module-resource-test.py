#!/usr/bin/env python3
"""Exercise the same schema and endpoint selector used to admit installed APPs."""
import hashlib
import json
from pathlib import Path
import re
import tempfile
import unittest

REGISTRY_PATH = Path(__file__).resolve().parents[2] / 'resources/project-resources.json'
REGISTRY_TOOL = Path(__file__).resolve().parents[1] / 'project-resource-registry'


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding='utf-8'))


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class GateError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise GateError(message)


def database_registration(runtime: dict) -> dict:
    matches = [r for r in read_json(REGISTRY_PATH)['resources']['databases']
               if r.get('stable_resource_id') == runtime['resource_id']]
    if len(matches) != 1:
        fail('database registration is missing or ambiguous')
    return matches[0]


def database_resources(runtime: dict) -> list[tuple[str, str]]:
    registered = database_registration(runtime)
    endpoint = registered['upstream_endpoint']
    policy = registered.get('lease_policy', {})
    if (policy.get('mode') != 'schema' or registered.get('database') != runtime['database']
            or endpoint.get('endpoint_id') != runtime['endpoint_id'] or not registered.get('lease_gate')
            or re.search('qualification|performance|recovery', registered['lease_gate'])):
        fail('runtime requires its registered ordinary schema gate; service/performance qualification remains exclusive')
    schema = f"{endpoint['host']}:{endpoint['port']}/{runtime['database']}"
    resources = [('resource-id', runtime['resource_id']), ('database-resource', runtime['resource_id']),
                 ('endpoint', runtime['endpoint_id']), ('database', runtime['database']),
                 ('mysql-db', runtime['database']), ('mysql-schema', schema),
                 ('registry-sha256', sha256(REGISTRY_PATH)), ('consumer', 'host'), ('environment', 'development')]
    resources.append(('registry-tool-sha256', sha256(REGISTRY_TOOL)))
    if registered.get('resource_scope'):
        resources.append(('resource-scope', registered['resource_scope']))
    elif registered.get('container_name'):
        resources.append(('container', registered['container_name']))
    else:
        resources.append(('resource-scope', registered['namespace']))
    if policy.get('shared_access') is not None:
        resources.append(('mysql-access', json.dumps({'schema': schema, 'operation': 'exclusive',
            'complete': True, 'tables': []}, separators=(',', ':'))))
    return resources


contract = {'GateError': GateError, 'database_resources': database_resources}
select = database_resources


class NativeSchemaSelectionTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.registry = Path(tmp.name) / 'registry.json'
        self.selectors = {'resource_id': 'test-db', 'endpoint_id': 'test-host', 'database': 'fixture'}
        self.database = {'stable_resource_id': 'test-db', 'database': 'fixture', 'namespace': 'fixture',
                         'upstream_endpoint': {'endpoint_id': 'test-host', 'host': '127.0.0.1', 'port': 23306},
                         'lease_gate': 'local-development', 'lease_policy': {'mode': 'schema'}}
        select.__globals__['REGISTRY_PATH'] = self.registry

    def register(self, *databases):
        self.registry.write_text(json.dumps({'resources': {'databases': databases}}))

    def test_registered_schema_and_endpoint(self):
        self.register(self.database)
        result = dict(select(self.selectors))
        self.assertEqual(result['mysql-schema'], '127.0.0.1:23306/fixture')
        self.assertEqual(result['endpoint'], 'test-host')
        self.assertEqual(result['resource-scope'], 'fixture')
        self.assertNotIn('mysql-access', result)
        self.database['lease_policy']['shared_access'] = {}
        self.register(self.database)
        access = json.loads(dict(select(self.selectors))['mysql-access'])
        self.assertEqual(access, {'schema': result['mysql-schema'], 'operation': 'exclusive',
                                  'complete': True, 'tables': []})

    def test_reject_missing_duplicate_or_mismatched_resource(self):
        for entries in ([], [self.database, self.database]):
            self.register(*entries)
            with self.assertRaisesRegex(contract['GateError'], 'missing or ambiguous'):
                select(self.selectors)
        self.register(self.database)
        for field in ('resource_id', 'database', 'endpoint_id'):
            with self.subTest(field=field), self.assertRaises(contract['GateError']):
                select({**self.selectors, field: 'other'})

    def test_reject_unqualified_scope_or_special_gate(self):
        for override in ({'lease_policy': {'mode': 'service'}}, {'lease_gate': ''},
                         {'lease_gate': 'performance'}, {'lease_gate': 'qualification'},
                         {'lease_gate': 'recovery'}):
            self.register({**self.database, **override})
            with self.subTest(override=override), self.assertRaises(contract['GateError']):
                select(self.selectors)


if __name__ == '__main__':
    result = unittest.main(exit=False).result
    if not result.wasSuccessful():
        raise SystemExit(1)
    print(f'CONSUMER-RESOURCE-CONTRACT-001 passed ({result.testsRun} tests)')
