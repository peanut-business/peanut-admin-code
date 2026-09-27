"""Native instance records need per-model evidence, never a base-class exemption.

Only synthetic files are changed. Audited application PHP is never executed.
The ownership label is not an authorization grant for any reviewed caller.
"""
from __future__ import annotations

import copy
import hashlib
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_instance', str(SOURCE / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)


class InstanceRegistrationTest(unittest.TestCase):
    def setUp(self):
        parent = SOURCE / '.local/tmp/tpq-instance-registration'
        parent.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=parent)
        self.root = Path(self.temp.name)
        self.old = checker.ROOT, checker.OWNERSHIP, checker.REGISTER
        checker.ROOT = self.root
        checker.OWNERSHIP = self.root / 'resources/architecture/tpq-data-ownership.json'
        checker.REGISTER = self.root / 'history-not-present.json'
        self.module_root = 'server/app/modules/fixture/example'
        self.model = 'Fixture\\Model\\Catalog'
        self.path = self.module_root + '/src/Model/Catalog.php'
        self.entry = 'Fixture\\CatalogService'
        self.entry_path = self.module_root + '/src/CatalogService.php'
        self.schema = self.module_root + '/database/migrations/catalog.sql'
        self.write('server/composer.json', json.dumps({'autoload': {'psr-4': {'Fixture\\': 'app/modules/fixture/example/src/'}}}))
        self.manifest = {'key': 'fixture.example', 'database': {'owned_tables': ['pa_catalog']}, 'contracts': {'exports': [self.entry]}}
        self.write_manifest()
        self.model_bytes = '<?php\nnamespace Fixture\\Model;\nuse think\\Model;\nfinal class Catalog extends Model { protected $name = "catalog"; }\n'
        self.write(self.path, self.model_bytes)
        self.write(self.entry_path, '<?php\nnamespace Fixture;\nfinal class CatalogService { public function summaries(): array { return []; } }\n')
        self.write(self.schema, '-- Native catalog declaration\nCREATE TABLE `pa_catalog` (`id` BIGINT, `module_key` VARCHAR(96)) ENGINE=InnoDB;\n')
        self.item = {'model': self.model, 'table': 'pa_catalog', 'owner': 'instance', 'instance_review': {
            'classification': 'deployment-instance', 'reason': 'Fixed module definition catalog, not Tenant or child data.',
            'model_source': self.proof(self.path), 'schema_source': self.proof(self.schema),
            'access_sources': [dict(self.proof(self.entry_path), entry=self.entry)],
        }}

    def tearDown(self):
        checker.ROOT, checker.OWNERSHIP, checker.REGISTER = self.old
        self.temp.cleanup()

    def write(self, path, text):
        destination = self.root / path
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(text, encoding='utf-8')
        return destination

    def proof(self, path):
        return {'source': 'application', 'path': path, 'sha256': hashlib.sha256((self.root / path).read_bytes()).hexdigest()}

    def write_manifest(self):
        self.write(self.module_root + '/module.json', json.dumps(self.manifest))

    def errors(self, tenant_tables=None):
        return checker.instance_model_review_errors(self.item, self.root / self.path,
            {'pa_tenant_entry'} if tenant_tables is None else tenant_tables,
            {self.module_root: 'fixture.example'})

    def reprove_schema(self, text):
        self.write(self.schema, text)
        self.item['instance_review']['schema_source'] = self.proof(self.schema)

    def test_exact_catalog_review_accepts_native_model_without_authorizing_caller(self):
        self.assertEqual([], self.errors())
        self.assertFalse(checker.REGISTER.exists())

    def test_no_review_is_not_implicit_instance_approval(self):
        del self.item['instance_review']
        self.assertTrue(self.errors())

    def test_empty_review_or_reason_is_rejected(self):
        original = copy.deepcopy(self.item)
        for invalid in (None, {}, {'reason': ''}):
            self.item = copy.deepcopy(original)
            self.item['instance_review'] = invalid
            self.assertTrue(self.errors())
        self.item = original
        self.item['instance_review']['reason'] = ''
        self.assertTrue(self.errors())

    def test_shared_and_tenant_labels_cannot_reuse_instance_evidence(self):
        for owner in ('shared', 'tenant-gateway', 'tenant-orm'):
            self.item['owner'] = owner
            self.assertTrue(self.errors())

    def test_wrong_model_source_is_rejected(self):
        self.item['instance_review']['model_source'] = self.proof(self.entry_path)
        self.assertTrue(self.errors())

    def test_model_bytes_change_invalidates_review(self):
        self.write(self.path, self.model_bytes.replace('"catalog"', '"tenant_entry"'))
        self.assertTrue(self.errors())

    def test_wrong_base_does_not_inherit_this_review(self):
        self.write(self.path, self.model_bytes.replace('use think\\Model;', 'use PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel as Model;'))
        self.item['instance_review']['model_source'] = self.proof(self.path)
        self.assertTrue(self.errors())

    def test_declared_table_and_model_identity_still_match(self):
        self.item['table'] = 'pa_other'
        self.assertTrue(self.errors())

    def test_schema_tenant_column_is_not_instance_data(self):
        self.reprove_schema('CREATE TABLE `pa_catalog` (`id` BIGINT, `tenant_id` BIGINT) ENGINE=InnoDB;')
        self.assertTrue(self.errors())

    def test_source_inventory_tenant_evidence_wins_over_local_absent_column(self):
        self.assertTrue(self.errors({'pa_catalog'}))

    def test_child_foreign_key_is_not_shared_or_instance_data(self):
        self.reprove_schema('CREATE TABLE `pa_catalog` (`id` BIGINT, `entry_id` BIGINT, FOREIGN KEY (`entry_id`) REFERENCES `pa_tenant_entry` (`id`)) ENGINE=InnoDB;')
        self.assertTrue(self.errors())

    def test_commented_or_incomplete_sql_cannot_prove_instance_scope(self):
        for text in ('/*\nCREATE TABLE `pa_catalog` (`id` INT) ENGINE=InnoDB;\n*/', 'CREATE TABLE `pa_catalog` (`id` INT', '-- CREATE TABLE `pa_catalog` (`id` INT) ENGINE=InnoDB;'):
            self.reprove_schema(text)
            self.assertTrue(self.errors())

    def test_duplicate_declaration_is_ambiguous(self):
        sql = 'CREATE TABLE `pa_catalog` (`id` BIGINT) ENGINE=InnoDB;\n'
        self.reprove_schema(sql + sql)
        self.assertTrue(self.errors())

    def test_schema_source_must_be_native_same_module_migration(self):
        path = 'server/app/modules/other/example/database/migrations/catalog.sql'
        self.write(path, (self.root / self.schema).read_text())
        self.item['instance_review']['schema_source'] = self.proof(path)
        self.assertTrue(self.errors())

    def test_model_cannot_be_public_even_with_review(self):
        self.manifest['contracts']['exports'].append(self.model)
        self.write_manifest()
        self.assertTrue(self.errors())

    def test_empty_or_unrelated_access_proof_is_rejected(self):
        original = copy.deepcopy(self.item)
        self.item['instance_review']['access_sources'] = []
        self.assertTrue(self.errors())
        self.item = original
        self.item['instance_review']['access_sources'][0]['entry'] = self.model
        self.assertTrue(self.errors())

    def test_access_caller_change_and_case_drift_invalidate_review(self):
        original = copy.deepcopy(self.item)
        self.item['instance_review']['access_sources'][0]['sha256'] = '0' * 64
        self.assertTrue(self.errors())
        self.item = original
        self.item['instance_review']['access_sources'][0]['path'] = self.entry_path.replace('CatalogService.php', 'catalogService.php')
        self.assertTrue(self.errors())

    def test_quoted_comment_and_statement_markers_do_not_split_literals(self):
        self.reprove_schema("CREATE TABLE `pa_catalog` (`id` BIGINT, `description` VARCHAR(30) DEFAULT 'value; -- text') ENGINE=InnoDB;")
        self.assertEqual([], self.errors())

    def test_additional_schema_mutation_is_not_ignored(self):
        self.reprove_schema('CREATE TABLE `pa_catalog` (`id` BIGINT) ENGINE=InnoDB; ALTER TABLE `pa_catalog` ADD `tenant_id` BIGINT;')
        self.assertTrue(self.errors())

    def test_malformed_evidence_is_a_diagnostic_not_an_exception(self):
        original = copy.deepcopy(self.item)
        for key in ('model_source', 'schema_source', 'access_sources'):
            self.item = copy.deepcopy(original)
            self.item['instance_review'][key] = 'not-evidence'
            self.assertTrue(self.errors())

    def test_symlink_model_evidence_is_not_followed(self):
        target = self.root / self.path
        target.unlink()
        target.symlink_to('/outside/not-read.php')
        self.assertTrue(self.errors())


if __name__ == '__main__':
    unittest.main()
