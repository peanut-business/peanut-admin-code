export default {
  'menu.system.configurationTransfer': '配置转移',

  'configurationTransfer.notice.title': '安全的租户配置包',
  'configurationTransfer.notice.description':
    '配置包只包含当前租户的逻辑配置和秘密引用，不包含租户 ID、密码、Token、Cookie 或密钥。导入前请先预演并确认冲突处理策略。',
  'configurationTransfer.exportHint':
    '导出的 JSON 可保存后在另一套 Scaffold Product Token 环境中预演。',
  'configurationTransfer.field.package': '配置包 JSON',
  'configurationTransfer.field.package.placeholder':
    '请粘贴由 Scaffold Product Token 导出的配置包 JSON',
  'configurationTransfer.field.conflictPolicy': '冲突处理',
  'configurationTransfer.field.secretBindings': '秘密重绑定',
  'configurationTransfer.field.secret.placeholder':
    '仅在确认来源后输入目标环境秘密',
  'configurationTransfer.secretHint':
    '秘密只在本次请求内传输，不会写入配置包。未填写已配置引用时无法应用。',
  'configurationTransfer.policy.abort': '发现冲突即停止',
  'configurationTransfer.policy.overwrite': '覆盖目标配置',
  'configurationTransfer.policy.skip': '跳过冲突项',
  'configurationTransfer.operation.export': '导出配置包',
  'configurationTransfer.operation.dryRun': '预演导入',
  'configurationTransfer.operation.apply': '应用配置包',
  'configurationTransfer.plan.title': '导入计划',
  'configurationTransfer.plan.checksum': '校验和',
  'configurationTransfer.plan.total': '配置项',
  'configurationTransfer.plan.conflicts': '冲突项',
  'configurationTransfer.plan.missingSecrets':
    '仍缺少 {count} 个秘密引用的重绑定',
  'configurationTransfer.plan.conflictCount':
    '检测到 {count} 个冲突，请按策略处理',
  'configurationTransfer.plan.adapter': '配置集合',
  'configurationTransfer.plan.key': '配置键',
  'configurationTransfer.plan.action': '计划动作',
  'configurationTransfer.plan.revision': '当前版本',
  'configurationTransfer.status.ready': '可应用',
  'configurationTransfer.status.blocked': '已阻塞',
  'configurationTransfer.status.applied': '已应用',
  'configurationTransfer.action.create': '新增',
  'configurationTransfer.action.replace': '覆盖',
  'configurationTransfer.action.replace-secret': '重绑定秘密',
  'configurationTransfer.action.unchanged': '无变化',
  'configurationTransfer.action.skip': '跳过',
  'configurationTransfer.action.conflict': '冲突',
  'configurationTransfer.confirm.title': '确认应用配置包',
  'configurationTransfer.confirm.apply':
    '应用后会修改当前租户配置，并为本次操作记录审计。确定继续吗？',
  'configurationTransfer.message.exported': '配置包已下载',
  'configurationTransfer.message.applied': '配置包已应用',
  'configurationTransfer.error.invalidJson': '配置包不是有效的 JSON 对象',
  'configurationTransfer.error.requestFailed': '配置转移请求失败',
};
