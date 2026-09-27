"""Exercise reviewed module registrations against explicit immutable sources.

This inspects real source declarations and mutates only a temporary registration.
It neither executes application PHP nor reads historical exception records.
"""
from __future__ import annotations

import argparse
from contextlib import ExitStack
import copy
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('tpq_reviewed_modules', str(ROOT / 'scripts/check-thinkphp-architecture'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checker = importlib.util.module_from_spec(spec)
loader.exec_module(checker)

# Independent expectations, deliberately not generated from the registry or scanner.
EXPECTED = {
    'ArtifactRevision': ('artifact_revision', 'ThinkPhpArtifactRevisionRepository', {
        'ArtifactRecord': 'pa_artifact', 'ArtifactRevisionRecord': 'pa_artifact_revision',
    }),
    'EntitlementQuota': ('entitlement_quota', 'ThinkPhpEntitlementQuotaRepository', {
        'EntitlementGrantRecord': 'pa_entitlement_grant',
        'EntitlementPolicyRevisionRecord': 'pa_entitlement_policy_revision',
        'EntitlementReservationRecord': 'pa_entitlement_reservation',
        'EntitlementUsageLedgerRecord': 'pa_entitlement_usage_ledger',
        'EntitlementUsageWindowRecord': 'pa_entitlement_usage_window',
    }),
    'Workflow': ('workflow', 'ThinkPhpWorkflowRepository', {
        'WorkflowDefinitionRecord': 'pa_workflow_definition',
        'WorkflowDefinitionVersionRecord': 'pa_workflow_definition_version',
        'WorkflowInstanceRecord': 'pa_workflow_instance',
        'WorkflowWorkItemRecord': 'pa_workflow_work_item',
        'WorkflowEventRecord': 'pa_workflow_event',
    }),
}
OPTIONS = None


class ReviewedModulesTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.stack = ExitStack()
        cls.addClassCleanup(cls.stack.close)
        cls.original = checker.read_ownership()
        cls.stack.enter_context(checker.php_core_snapshot(OPTIONS.php_core_root, OPTIONS.php_core_ref))
        cls.stack.enter_context(checker.ownership_source_snapshot(OPTIONS.source_ref))
        cls.original_ownership = checker.OWNERSHIP
        cls.original_history = checker.REGISTER
        parent = ROOT / '.local/tmp/tpq-reviewed-modules'
        parent.mkdir(parents=True, exist_ok=True)
        cls.temp = cls.stack.enter_context(tempfile.TemporaryDirectory(dir=parent))
        checker.OWNERSHIP = Path(cls.temp) / 'ownership.json'
        checker.REGISTER = Path(cls.temp) / 'history-not-present.json'
        cls.addClassCleanup(cls.restore)

    @classmethod
    def restore(cls):
        checker.OWNERSHIP = cls.original_ownership
        checker.REGISTER = cls.original_history

    def setUp(self):
        self.registry = copy.deepcopy(self.original)
        self.save()

    def save(self):
        checker.OWNERSHIP.write_text(json.dumps(self.registry), encoding='utf-8')

    def report(self):
        return checker.current_ownership_report(OPTIONS.source_ref)

    def test_all_twelve_records_match_native_model_table_and_module(self):
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        owners, _ = checker.module_table_inventory()
        for name, (slug, repository, expected) in EXPECTED.items():
            entry = f'PeanutAdmin\\Modules\\{name}\\Persistence\\{repository}'
            for model, table in expected.items():
                with self.subTest(model=model):
                    fqcn = f'PeanutAdmin\\Modules\\{name}\\Persistence\\Model\\{model}'
                    self.assertIn(fqcn, models)
                    self.assertEqual('tenant-gateway', models[fqcn]['owner'])
                    self.assertEqual(table, models[fqcn]['table'])
                    self.assertEqual(entry, models[fqcn]['access_entry'])
                    self.assertEqual(entry, tables[table]['access_entry'])
                    self.assertEqual('peanut.' + slug.replace('_', '-'), owners[table])
                    path = checker.composer_model_path(fqcn)
                    self.assertEqual((fqcn, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(path))
                    self.assertEqual(table, checker.source_model_table(path))

    def test_gateways_and_scoped_dependencies_match_reviewed_bytes(self):
        contracts = {f'PeanutAdmin\\Modules\\{name}\\Persistence\\{values[1]}': self.registry['access_contracts'][f'PeanutAdmin\\Modules\\{name}\\Persistence\\{values[1]}'] for name, values in EXPECTED.items()}
        self.assertEqual([], checker.access_contract_errors(contracts))
        for contract in contracts.values():
            self.assertEqual('explicit-tenant-column', contract['scope'])
            self.assertFalse(contract['pending_operations'])
            self.assertFalse(contract['other_operations'])
            self.assertTrue(any(x['path'].endswith('/Tenancy/TenantScope.php') for x in contract['support_sources']))

    def test_registered_modules_do_not_turn_pending_scope_into_a_pass(self):
        # A synthetic pending classification must remain visible even after all
        # unrelated real backlog items have eventually been registered.
        entry = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\ThinkPhpWorkflowRepository'
        contract = self.registry['access_contracts'][entry]
        contract['tenant_operations'].remove('events')
        contract['pending_operations'].append('events')
        self.save()
        report = self.report()
        self.assertIn(report['status'], {'registration_failed', 'decision_required', 'check_incomplete'})
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])
        self.assertFalse(report['historical_register_read'])
        self.assertTrue(any(item['entry'] == entry and item['operations'] == ['events'] for item in report['needs_decision']))
        selected = {f'PeanutAdmin\\Modules\\{name}\\Persistence\\Model\\{model}' for name, (_, _, models) in EXPECTED.items() for model in models}
        self.assertFalse(selected.intersection(report['missing_models']))

    def test_deleting_one_model_is_a_missing_registration_not_a_pass(self):
        model = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\Model\\WorkflowEventRecord'
        self.registry['model_owners'] = [r for r in self.registry['model_owners'] if r['model'] != model]
        self.save()
        self.assertIn(model, self.report()['missing_models'])

    def test_foreign_table_assignment_is_rejected(self):
        row = next(r for r in self.registry['model_owners'] if r['table'] == 'pa_artifact')
        row['table'] = 'pa_workflow_instance'
        self.save()
        self.assertTrue(any('table declaration mismatch' in e for e in checker.ownership_errors()))
        self.assertTrue(any('module table owner mismatch' in e for e in checker.ownership_errors()))

    def test_foreign_gateway_is_not_a_same_module_access_entry(self):
        row = next(r for r in self.registry['model_owners'] if r['table'] == 'pa_artifact')
        row['access_entry'] = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\ThinkPhpWorkflowRepository'
        self.save()
        self.assertTrue(any('ACCESS_MODULE_OWNER' in e for e in checker.ownership_errors()))

    def test_scope_review_digest_tampering_invalidates_registration(self):
        entry = 'PeanutAdmin\\Modules\\ArtifactRevision\\Persistence\\ThinkPhpArtifactRevisionRepository'
        self.registry['access_contracts'][entry]['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in checker.ownership_errors()))

    def test_notification_six_records_have_exact_private_owner_and_table(self):
        expected = {
            'NotificationTemplateRecord': 'pa_notification_template',
            'NotificationMessageRecord': 'pa_notification_message',
            'NotificationAttachmentRecord': 'pa_notification_attachment',
            'NotificationOutboxRecord': 'pa_notification_outbox',
            'NotificationEventRecord': 'pa_notification_event',
            'SmsRateBucketRecord': 'pa_sms_rate_bucket',
        }
        entry = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\NotificationStore'
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        native, _ = checker.module_table_inventory()
        self.assertNotIn(entry, checker.declared_module_exports())
        for name, table in expected.items():
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\Model\\' + name
                self.assertEqual(table, models[model]['table'])
                self.assertEqual('tenant-gateway', models[model]['owner'])
                self.assertEqual(entry, models[model]['access_entry'])
                self.assertEqual(entry, tables[table]['access_entry'])
                self.assertEqual('official.notification', native[table])
                self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(checker.composer_model_path(model)))

    def test_notification_review_includes_recipient_and_leased_worker_callers(self):
        entry = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\NotificationStore'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual([], checker.access_contract_errors({entry: contract}))
        paths = {Path(item['path']).name for item in contract['support_sources']}
        self.assertTrue({'NotificationService.php', 'NotificationInboxService.php', 'SmsTaskHandler.php', 'InboxTaskHandler.php'}.issubset(paths))
        self.assertFalse(contract['other_operations'])
        self.assertFalse(contract['pending_operations'])

    def test_notification_explicit_predicates_are_not_automatic_orm_scope(self):
        row = next(item for item in self.registry['model_owners'] if item['table'] == 'pa_notification_message')
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and 'NotificationMessageRecord' in error for error in checker.ownership_errors()))

    def test_worker_review_drift_requires_revalidation(self):
        entry = 'PeanutAdmin\\Modules\\Notification\\Delivery\\Persistence\\NotificationStore'
        dependency = next(item for item in self.registry['access_contracts'][entry]['support_sources'] if item['path'].endswith('/SmsTaskHandler.php'))
        dependency['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_integration_five_models_match_native_schema_and_private_owner(self):
        expected = {
            'IntegrationMachineIdentityRecord': 'pa_integration_machine_identity',
            'IntegrationWebhookEndpointRecord': 'pa_integration_webhook_endpoint',
            'IntegrationWebhookDeliveryRecord': 'pa_integration_webhook_delivery',
            'IntegrationWebhookAttemptRecord': 'pa_integration_webhook_attempt',
            'IntegrationSecurityEventRecord': 'pa_integration_security_event',
        }
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        native, _ = checker.module_table_inventory()
        declarations = checker.module_php_schema_inventory()
        self.assertFalse(declarations['gaps'])
        parsed_tables = {row['table'] for row in declarations['records']}
        self.assertNotIn(entry, checker.declared_module_exports())
        self.assertNotIn('PeanutAdmin\\Modules\\Integration\\Contract\\IntegrationSecurityRepository', checker.declared_module_exports())
        for name, table in expected.items():
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Integration\\Model\\' + name
                self.assertEqual(table, models[model]['table'])
                self.assertEqual('tenant-gateway', models[model]['owner'])
                self.assertEqual(entry, models[model]['access_entry'])
                self.assertEqual(entry, tables[table]['access_entry'])
                self.assertEqual('official.integration', native[table])
                self.assertIn(table, parsed_tables)
                self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(checker.composer_model_path(model)))

    def test_integration_authentication_and_system_methods_are_not_tenant_authority(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual([], checker.access_contract_errors({entry: contract}))
        self.assertEqual({'machineByDigest', 'touchMachine'}, set(contract['other_operations']))
        self.assertEqual(['purgeExpiredDeliveryData'], contract['pending_operations'])
        self.assertTrue({'createMachine', 'rotateMachine', 'enqueueDelivery', 'claimDelivery', 'deliveryRecords'}.issubset(contract['tenant_operations']))
        report = self.report()
        self.assertTrue(any(item['entry'] == entry and item['operations'] == ['purgeExpiredDeliveryData'] for item in report['needs_decision']))
        self.assertNotEqual('ownership_passed', report['status'])
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_integration_scope_review_includes_actual_identity_and_transaction_callers(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        paths = {Path(item['path']).name for item in self.registry['access_contracts'][entry]['support_sources']}
        self.assertTrue({'MachineIdentityService.php', 'MachineScopeGrantPolicy.php', 'IntegrationAdminApplicationService.php',
                         'WebhookService.php', 'WebhookDeliveryLogService.php', 'TrustedWebhookPublisher.php',
                         'WebhookDispatcher.php', 'Schema.php', 'ModuleProvider.php', 'TenantModel.php'}.issubset(paths))

    def test_integration_authentication_caller_drift_invalidates_scope_review(self):
        entry = 'PeanutAdmin\\Modules\\Integration\\Infrastructure\\Persistence\\ThinkPhpIntegrationSecurityRepository'
        caller = next(item for item in self.registry['access_contracts'][entry]['support_sources']
                      if item['path'].endswith('/Application/MachineIdentityService.php'))
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_integration_explicit_tenant_data_is_not_global_orm_scope(self):
        row = next(item for item in self.registry['model_owners'] if item['table'] == 'pa_integration_machine_identity')
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and 'IntegrationMachineIdentityRecord' in error
                            for error in checker.ownership_errors()))

    def test_settings_edition_models_match_owner_access_instead_of_catalog_mutation(self):
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = {row['table']: row for row in self.registry['tenant_tables']}
        native, _ = checker.module_table_inventory()
        gateway = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingAdminService'
        for name, table in [('TenantSettingValue', 'pa_setting_tenant_value'), ('TargetSettingValue', 'pa_setting_target_value')]:
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Settings\\Model\\' + name
                self.assertEqual(table, models[model]['table'])
                self.assertEqual('tenant-gateway', models[model]['owner'])
                self.assertEqual(gateway, models[model]['access_entry'])
                self.assertEqual(gateway, tables[table]['access_entry'])
                self.assertEqual('official.settings', native[table])
                self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\EditionTenantModel'), checker.model_header(checker.composer_model_path(model)))
                self.assertIn(table, checker.schema_tenant_tables())
        self.assertNotIn(gateway, checker.declared_module_exports())

    def test_settings_deployment_and_helper_methods_are_not_tenant_authority(self):
        entry = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingAdminService'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual([], checker.access_contract_errors({entry: contract}))
        self.assertEqual({'replaceTenant', 'unsetTenant', 'replaceTarget', 'unsetTarget'}, set(contract['tenant_operations']))
        self.assertEqual({'replaceDeployment', 'unsetDeployment', 'prepareStorage', 'assertValidInterval', 'emptyStorage'}, set(contract['other_operations']))
        self.assertFalse(contract['pending_operations'])
        resolver = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingResolver'
        self.assertEqual([], checker.access_contract_errors({resolver: self.registry['access_contracts'][resolver]}))
        self.assertEqual({'resolveTenant', 'resolveTarget'}, set(self.registry['access_contracts'][resolver]['tenant_operations']))
        self.assertEqual(['resolveDeployment'], self.registry['access_contracts'][resolver]['other_operations'])

    def test_settings_scope_review_pins_effective_mode_target_and_http_authority(self):
        entry = 'PeanutAdmin\\Modules\\Settings\\Application\\SettingAdminService'
        support = self.registry['access_contracts'][entry]['support_sources']
        self.assertTrue({'EditionTenantModel.php', 'TenantColumnScope.php', 'SettingResolver.php',
                         'TargetSettingWriter.php', 'SettingsHttpApplicationService.php', 'ModuleProvider.php'}
                        .issubset({Path(item['path']).name for item in support}))
        caller = next(item for item in support if item['path'].endswith('/SettingsHttpApplicationService.php'))
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_settings_explicit_edition_scope_is_not_automatic_global_scope(self):
        model = 'PeanutAdmin\\Modules\\Settings\\Model\\TenantSettingValue'
        row = next(item for item in self.registry['model_owners'] if item['model'] == model)
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and model in error for error in checker.ownership_errors()))

    def test_settings_native_instance_models_have_bound_evidence(self):
        models = {row['model']: row for row in self.registry['model_owners']}
        tenant_tables = checker.schema_tenant_tables()
        _, roots = checker.module_table_inventory()
        for name, table in [('DeploymentSettingValue', 'pa_setting_deployment_value'), ('SettingDefinitionRecord', 'pa_setting_definition')]:
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Settings\\Model\\' + name
                row = models[model]
                path = checker.composer_model_path(model)
                self.assertEqual('instance', row['owner'])
                self.assertEqual(table, row['table'])
                self.assertEqual((model, 'think\\Model'), checker.model_header(path))
                self.assertNotIn(table, tenant_tables)
                self.assertEqual([], checker.instance_model_review_errors(row, path, tenant_tables, roots))
                self.assertNotIn(model, checker.declared_module_exports())
        report = self.report()
        self.assertFalse(any(model in report['missing_models'] for model in models if model.endswith(('DeploymentSettingValue', 'SettingDefinitionRecord'))))
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_removing_native_instance_review_does_not_grant_a_base_class_exemption(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_setting_deployment_value')
        del row['instance_review']
        self.save()
        self.assertTrue(any('INSTANCE_REVIEW_REQUIRED' in error for error in checker.ownership_errors()))

    def test_changed_instance_catalog_caller_requires_review(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_setting_definition')
        evidence = next(item for item in row['instance_review']['access_sources'] if item['entry'].endswith('SettingCatalogService'))
        evidence['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_reference_code_set_is_a_deployment_catalog_not_tenant_values(self):
        model = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeSetRecord'
        row = next(row for row in self.registry['model_owners'] if row['model'] == model)
        path = checker.composer_model_path(model)
        tables = checker.schema_tenant_tables()
        owners, roots = checker.module_table_inventory()
        self.assertEqual('instance', row['owner'])
        self.assertEqual('pa_reference_code_set', row['table'])
        self.assertEqual('official.reference-codes', owners[row['table']])
        self.assertNotIn(row['table'], tables)
        self.assertNotIn(model, checker.declared_module_exports())
        self.assertEqual([], checker.instance_model_review_errors(row, path, tables, roots))
        report = self.report()
        self.assertNotIn(model, report['missing_models'])
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_reference_version_without_tenant_column_cannot_borrow_catalog_review(self):
        catalog = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_set')
        item = copy.deepcopy(catalog)
        item['model'] = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryVersionRecord'
        item['table'] = 'pa_reference_code_entry_version'
        path = checker.composer_model_path(item['model'])
        import hashlib
        item['instance_review']['model_source'] = {
            'source': 'application', 'path': checker.relative(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
        }
        _, roots = checker.module_table_inventory()
        self.assertTrue(any('INSTANCE_SCHEMA_TENANT_PARENT' in error for error in
                            checker.instance_model_review_errors(item, path, checker.schema_tenant_tables(), roots)))

    def test_changed_reference_catalog_source_does_not_keep_instance_review_valid(self):
        row = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_set')
        source = next(source for source in row['instance_review']['access_sources'] if source['entry'].endswith('ReferenceCodeCatalogService'))
        source['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_identity_authorization_catalogs_have_native_instance_provenance(self):
        expected = {
            'ProtectedResourceRecord': 'pa_protected_resource', 'TargetTypeRecord': 'pa_target_type',
            'ResourceOperationRecord': 'pa_resource_operation',
            'ResourceOperationTargetTypeRecord': 'pa_resource_operation_target_type',
            'ResourceOperationPermissionRecord': 'pa_resource_operation_permission',
            'DataConditionDefinitionRecord': 'pa_data_condition_definition',
            'ResourceOperationConditionRecord': 'pa_resource_operation_condition',
        }
        models = {row['model']: row for row in self.registry['model_owners']}
        tables = checker.schema_tenant_tables()
        owners, roots = checker.module_table_inventory()
        for name, table in expected.items():
            with self.subTest(model=name):
                model = 'PeanutAdmin\\Modules\\Identity\\Authorization\\Model\\' + name
                item = models[model]
                self.assertEqual((table, 'instance'), (item['table'], item['owner']))
                self.assertEqual('official.identity', owners[table])
                self.assertNotIn(model, checker.declared_module_exports())
                self.assertEqual([], checker.instance_model_review_errors(item, checker.composer_model_path(model), tables, roots))
                self.assertEqual('PeanutAdmin\\Modules\\Identity\\Authorization\\Persistence\\Schema\\AuthorizationSchema', item['instance_review']['schema_source']['entry'])
                self.assertTrue(item['instance_review']['schema_source']['migration_sources'])

    def test_changed_native_catalog_migration_requires_reverification(self):
        item = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_protected_resource')
        item['instance_review']['schema_source']['migration_sources'][0]['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('INSTANCE_SCHEMA_TRACE' in error for error in checker.ownership_errors()))

    def test_authorization_catalog_metadata_does_not_imply_tenant_grants(self):
        item = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_data_condition_definition')
        item['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error for error in checker.ownership_errors()))
        report = self.report()
        self.assertEqual('not_run', report['business_scan'])
        self.assertIsNone(report['finding_count'])

    def test_reference_entry_uses_its_private_scoped_store(self):
        model = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryRecord'
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        row = next(item for item in self.registry['model_owners'] if item['model'] == model)
        table = next(item for item in self.registry['tenant_tables'] if item['table'] == 'pa_reference_code_entry')
        self.assertEqual(('pa_reference_code_entry', 'tenant-gateway', entry), (row['table'], row['owner'], row['access_entry']))
        self.assertEqual(entry, table['access_entry'])
        self.assertEqual((model, 'PeanutAdmin\\Kernel\\Persistence\\Model\\TenantModel'), checker.model_header(checker.composer_model_path(model)))
        self.assertNotIn(entry, checker.declared_module_exports())
        self.assertEqual([], checker.access_contract_errors({entry: self.registry['access_contracts'][entry]}))
        self.assertNotIn(model, self.report()['missing_models'])

    def test_reference_revalidated_snapshot_pins_both_membership_boundaries(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        self.assertEqual({'create', 'replace', 'retire', 'snapshot', 'pageSnapshot'}, set(contract['tenant_operations']))
        self.assertEqual({'atomically', 'synchronize', 'assertCurrentDefinition', 'definitionSummaries'}, set(contract['other_operations']))
        self.assertFalse(contract['pending_operations'])
        paths = {Path(item['path']).name for item in contract['support_sources']}
        self.assertTrue({'AdminDirectoryQuery.php', 'TenantMemberDirectory.php', 'ThinkPhpTenantMemberDirectory.php',
                         'ModuleProvider.php', 'ReferenceCodesHttpApplicationService.php', 'ReferenceCodeAdminService.php',
                         'ReferenceCodeQuery.php'}.issubset(paths))
        source, _ = checker.reviewed_source(contract)
        self.assertIn('function assertMemberReferences(', checker.php_structure(source.read_text()))
        self.assertIn('function referenceMemberId(', checker.php_structure(source.read_text()))
        self.assertIn('PeanutAdmin\\Modules\\Identity\\Contract\\AdminDirectoryQuery', checker.declared_module_exports())

    def test_reference_membership_dependency_drift_is_not_auto_approved(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        proof = next(item for item in contract['support_sources'] if item['path'].endswith('/AdminDirectoryQuery.php'))
        proof['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_reference_tenant_entry_is_not_automatic_global_scope(self):
        row = next(item for item in self.registry['model_owners'] if item['table'] == 'pa_reference_code_entry')
        row['owner'] = 'tenant-orm'
        self.save()
        self.assertTrue(any('must extend TenantOwnedModel' in error and 'ReferenceCodeEntryRecord' in error
                            for error in checker.ownership_errors()))

    def test_reference_snapshot_cannot_disappear_from_method_roles(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        contract['tenant_operations'].remove('snapshot')
        self.assertTrue(any('ACCESS_METHOD_COVERAGE' in error for error in checker.access_contract_errors({entry: contract})))

    def test_reference_version_belongs_through_exact_scoped_parent(self):
        child_name = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryVersionRecord'
        child = next(row for row in self.registry['model_owners'] if row['model'] == child_name)
        parent_name = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeEntryRecord'
        self.assertEqual('tenant-gateway', child['owner'])
        self.assertEqual(parent_name, child['parent_review']['parent_model'])
        self.assertEqual(('entry_id', 'id'), (child['parent_review']['column'], child['parent_review']['parent_column']))
        tables = checker.schema_tenant_tables()
        self.assertNotIn(child['table'], tables)
        self.assertFalse(any(row['table'] == child['table'] for row in self.registry['tenant_tables']))
        _, roots = checker.module_table_inventory()
        self.assertEqual([], checker.parent_model_review_errors(child, checker.composer_model_path(child_name), self.registry, tables, roots))
        self.assertNotIn(child_name, self.report()['missing_models'])

    def test_reference_version_relation_cannot_be_repointed_to_deployment_catalog(self):
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        child['parent_review']['parent_model'] = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\Model\\ReferenceCodeSetRecord'
        self.save()
        self.assertTrue(any('PARENT_SCOPED_REGISTRATION_REQUIRED' in error for error in checker.ownership_errors()))

    def test_reference_version_cannot_be_counted_as_an_additional_direct_tenant_table(self):
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        self.registry['tenant_tables'].append({'table': child['table'], 'owner': 'tenant-gateway', 'access_entry': child['access_entry']})
        self.save()
        self.assertTrue(any('PARENT_CHILD_IS_NOT_A_DIRECT_TENANT_TABLE' in error for error in checker.ownership_errors()))

    def test_reference_version_changed_parent_source_invalidates_ownership(self):
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        child['parent_review']['parent_source']['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_reference_page_role_is_required_for_parent_and_gateway(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        child = next(row for row in self.registry['model_owners'] if row['table'] == 'pa_reference_code_entry_version')
        self.assertIn('pageSnapshot', contract['tenant_operations'])
        self.assertIn('pageSnapshot', child['parent_review']['operations'])
        child['parent_review']['operations'].remove('pageSnapshot')
        self.save()
        self.assertTrue(any('PARENT_OPERATION_REVIEW_REQUIRED' in error for error in checker.ownership_errors()))

    def test_reference_page_validation_caller_drift_requires_new_review(self):
        entry = 'PeanutAdmin\\Modules\\ReferenceCodes\\Versioned\\Persistence\\ReferenceCodeStore'
        contract = self.registry['access_contracts'][entry]
        caller = next(row for row in contract['support_sources'] if row['path'].endswith('/ReferenceCodeQuery.php'))
        source, _ = checker.reviewed_source(caller)
        self.assertIn('->pageSnapshot(', source.read_text())
        self.assertIn('$this->hydrate($definition, $raw, $instant)', source.read_text())
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in error for error in checker.ownership_errors()))

    def test_workflow_trusted_caller_is_in_the_review_boundary(self):
        entry = 'PeanutAdmin\\Modules\\Workflow\\Persistence\\ThinkPhpWorkflowRepository'
        support = self.registry['access_contracts'][entry]['support_sources']
        caller = next(item for item in support if item['path'].endswith('/Application/WorkflowRuntime.php'))
        checker.reviewed_source(caller)
        caller['sha256'] = '0' * 64
        self.save()
        self.assertTrue(any('SOURCE_REVIEW_STALE' in e for e in checker.ownership_errors()))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-ref', required=True)
    parser.add_argument('--php-core-root', required=True)
    parser.add_argument('--php-core-ref', required=True)
    OPTIONS, rest = parser.parse_known_args()
    unittest.main(argv=[__file__, *rest])
