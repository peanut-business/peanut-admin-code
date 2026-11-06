#!/usr/bin/env python3
"""Exercise the same schema and endpoint selector used to admit installed APPs."""
import json
from pathlib import Path
import runpy
import tempfile
import unittest

tool = Path(__file__).resolve().parents[1] / 'p0e-runtime-qualification'
contract = runpy.run_path(str(tool))
select = contract['database_resources']


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
