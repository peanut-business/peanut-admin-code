export default {
  'menu.system.configurationTransfer': 'Configuration Transfer',

  'configurationTransfer.notice.title': 'Safe Tenant configuration package',
  'configurationTransfer.notice.description':
    'Packages contain only logical configuration and secret references for the current Tenant. They never contain Tenant IDs, passwords, tokens, cookies, or keys. Preview a package before applying it and choose a conflict policy.',
  'configurationTransfer.exportHint':
    'Save the exported JSON and preview it in another Scaffold Product Token environment.',
  'configurationTransfer.field.package': 'Configuration package JSON',
  'configurationTransfer.field.package.placeholder':
    'Paste a JSON package exported by Scaffold Product Token',
  'configurationTransfer.field.conflictPolicy': 'Conflict policy',
  'configurationTransfer.field.secretBindings': 'Secret rebinding',
  'configurationTransfer.field.secret.placeholder':
    'Enter the target secret only after verifying its source',
  'configurationTransfer.secretHint':
    'Secrets are sent only with this request and are never written to the package. Configured references must be rebound before applying.',
  'configurationTransfer.policy.abort': 'Abort on conflict',
  'configurationTransfer.policy.overwrite': 'Overwrite target values',
  'configurationTransfer.policy.skip': 'Skip conflicting entries',
  'configurationTransfer.operation.export': 'Export package',
  'configurationTransfer.operation.dryRun': 'Preview import',
  'configurationTransfer.operation.apply': 'Apply package',
  'configurationTransfer.plan.title': 'Import plan',
  'configurationTransfer.plan.checksum': 'Checksum',
  'configurationTransfer.plan.total': 'Entries',
  'configurationTransfer.plan.conflicts': 'Conflicts',
  'configurationTransfer.plan.missingSecrets':
    '{count} secret references still need rebinding',
  'configurationTransfer.plan.conflictCount':
    '{count} conflicts detected; review the selected policy',
  'configurationTransfer.plan.adapter': 'Collection',
  'configurationTransfer.plan.key': 'Configuration key',
  'configurationTransfer.plan.action': 'Planned action',
  'configurationTransfer.plan.revision': 'Current revision',
  'configurationTransfer.status.ready': 'Ready',
  'configurationTransfer.status.blocked': 'Blocked',
  'configurationTransfer.status.applied': 'Applied',
  'configurationTransfer.action.create': 'Create',
  'configurationTransfer.action.replace': 'Overwrite',
  'configurationTransfer.action.replace-secret': 'Rebind secret',
  'configurationTransfer.action.unchanged': 'Unchanged',
  'configurationTransfer.action.skip': 'Skip',
  'configurationTransfer.action.conflict': 'Conflict',
  'configurationTransfer.confirm.title': 'Confirm configuration package',
  'configurationTransfer.confirm.apply':
    'This changes the current Tenant configuration and records an audit event. Continue?',
  'configurationTransfer.message.exported': 'Configuration package downloaded',
  'configurationTransfer.message.applied': 'Configuration package applied',
  'configurationTransfer.error.invalidJson':
    'The package is not a valid JSON object',
  'configurationTransfer.error.requestFailed': 'Configuration transfer failed',
};
