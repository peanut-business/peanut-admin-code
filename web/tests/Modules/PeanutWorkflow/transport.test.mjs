import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { createWorkflowApi } from '../../../.local/w20-tests/modules/peanut-workflow/api.js';

const require = createRequire(
  new URL('../../../package.json', import.meta.url)
);
const axios = require('axios');
const task = {
  work_item_key: '9007199254740993',
  instance_key: '9007199254740994',
  node_execution_key: '9007199254740995',
  label: '审批',
  type: 'approval',
  revision: 3,
  instance_revision: 5,
  node_revision: 4,
  status: 'pending',
  assignment_state: 'assigned',
  owner_member_id: '20',
  assignee_member_id: '20',
  relations: [],
  available_actions: [
    'claim',
    'release',
    'approve',
    'reject',
    'complete',
    'return',
  ],
  decision: null,
};
const instance = {
  instance_key: task.instance_key,
  status: 'running',
  result_code: null,
  revision: 5,
  active_nodes: [
    {
      node_execution_key: task.node_execution_key,
      label: '审批',
      status: 'active',
      revision: 4,
    },
  ],
  own_work_items: [task],
  available_actions: ['withdraw', 'terminate', 'retry', 'reassign', 'resubmit'],
  event_cursor: 2,
  blocker: null,
  subject_revision_key: 'revision-A',
};

test('transport uses exact frozen request shapes, revision checks, bigint IDs and idempotency headers', async () => {
  const calls = [];
  const priorAdapter = axios.defaults.adapter;
  const priorBase = axios.defaults.baseURL;
  axios.defaults.baseURL = '/adminapi';
  // Model the existing host interceptor returning the server envelope.
  const interceptor = axios.interceptors.response.use(
    (response) => response.data
  );
  axios.defaults.adapter = async (config) => {
    calls.push({
      url: config.url,
      body: JSON.parse(config.data),
      key: config.headers['Idempotency-Key'],
    });
    return {
      data: { code: 20000, data: instance },
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    };
  };
  try {
    const api = createWorkflowApi();
    const revisions = {
      node_execution_key: task.node_execution_key,
      expected_revision: 5,
      expected_node_revision: 4,
      expected_task_revision: 3,
    };
    await api.taskAction(task, instance, 'claim', '', 'claim-key');
    assert.deepEqual(calls.at(-1), {
      url: `/api/v1/workflow/tasks/${task.work_item_key}/claim`,
      body: revisions,
      key: 'claim-key',
    });
    await api.taskAction(task, instance, 'release', '', 'release-key');
    assert.deepEqual(calls.at(-1).body, revisions);
    await api.taskAction(task, instance, 'reject', '理由', 'decision-key');
    assert.deepEqual(calls.at(-1).body, {
      ...revisions,
      decision: 'reject',
      comment: '理由',
      attachment_file_keys: [],
    });
    assert.ok(calls.at(-1).url.endsWith('/decisions'));
    await api.taskAction(
      task,
      instance,
      'complete',
      '办理完成',
      'complete-key'
    );
    assert.deepEqual(calls.at(-1).body, {
      ...revisions,
      comment: '办理完成',
      attachment_file_keys: [],
      input: {},
    });
    assert.ok(calls.at(-1).url.endsWith('/completions'));
    await api.taskAction(task, instance, 'return', '补正', 'return-key');
    assert.deepEqual(calls.at(-1).body, {
      ...revisions,
      comment: '补正',
      attachment_file_keys: [],
    });
    await api.instanceAction(instance, 'retry', '修复已完成', 'retry-key');
    assert.deepEqual(calls.at(-1).body, {
      expected_revision: 5,
      comment: '修复已完成',
    });
    await api.instanceAction(
      instance,
      'reassign',
      '替代原责任席位',
      'reassign-key',
      {
        work_item_key: task.work_item_key,
        assignee_member_id: '9007199254740999',
        expected_task_revision: 3,
      }
    );
    assert.equal(calls.at(-1).body.assignee_member_id, '9007199254740999');
    assert.equal('reason' in calls.at(-1).body, false);
    await assert.rejects(
      api.taskAction(
        { ...task, available_actions: [] },
        instance,
        'approve',
        '',
        'denied'
      ),
      /unavailable/
    );
    assert.equal(calls.length, 7);
  } finally {
    axios.interceptors.response.eject(interceptor);
    axios.defaults.adapter = priorAdapter;
    axios.defaults.baseURL = priorBase;
  }
});
