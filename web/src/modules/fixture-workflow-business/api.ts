import axios from 'axios';
import { getSessionSnapshot } from '@/utils/auth';

export type BusinessScene = 'receipt';
export interface BusinessObject {
  object_key: string;
  scene: BusinessScene;
  title: string;
  revision: string;
  state: string;
  confirmed_revision_key: string | null;
  business_department_key?: string | null;
  amount_minor?: number;
  quantity?: number;
  sku?: string;
  completion_ready?: boolean;
  completion_form?: { note: string; reference: string };
  available_actions: string[];
}
export interface BusinessFields {
  title: string;
  amount_minor: number;
  sku: string;
  quantity: number;
}
export interface CollaborationView {
  access: {
    instance_key: string;
    work_item_key: string;
    instance_revision: number;
    node_revision: number;
    task_revision: number;
    can_collaborate: boolean;
    roles: string[];
  };
  entries: {
    entry_key: string;
    kind: 'comment' | 'supplement';
    note: string;
    reference: string;
    actor_member_id: string;
    created_at: string;
  }[];
}
const object = (value: unknown): Record<string, unknown> => {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('WORKFLOW_BUSINESS_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};
const parseCollaboration = (value: unknown): CollaborationView => {
  const row = object(value);
  const access = object(row.access);
  if (
    typeof access.instance_key !== 'string' ||
    typeof access.work_item_key !== 'string' ||
    typeof access.can_collaborate !== 'boolean' ||
    !Array.isArray(access.roles) ||
    access.roles.some(
      (role) => !['assignee', 'participant', 'watcher'].includes(String(role))
    ) ||
    ['instance_revision', 'node_revision', 'task_revision'].some(
      (key) => !Number.isSafeInteger(access[key]) || Number(access[key]) < 1
    ) ||
    !Array.isArray(row.entries) ||
    row.entries.some((candidate) => {
      const entry = object(candidate);
      return (
        !['comment', 'supplement'].includes(String(entry.kind)) ||
        [
          'entry_key',
          'note',
          'reference',
          'actor_member_id',
          'created_at',
        ].some((key) => typeof entry[key] !== 'string')
      );
    })
  )
    throw new Error('WORKFLOW_BUSINESS_COLLABORATION_INVALID');
  return row as unknown as CollaborationView;
};
export function parseBusinessObject(value: unknown): BusinessObject {
  const row = object(value);
  if (
    typeof row.object_key !== 'string' ||
    !/^object_[0-9a-f]{32}$/.test(row.object_key) ||
    row.scene !== 'receipt' ||
    typeof row.title !== 'string' ||
    typeof row.state !== 'string' ||
    typeof row.revision !== 'string' ||
    !/^[1-9][0-9]*$/.test(row.revision) ||
    (row.confirmed_revision_key != null &&
      (typeof row.confirmed_revision_key !== 'string' ||
        !/^revision_[0-9a-f]{32}$/.test(row.confirmed_revision_key)))
  )
    throw new Error('WORKFLOW_BUSINESS_RESPONSE_INVALID');
  ['amount_minor', 'quantity'].forEach((key) => {
    if (row[key] !== undefined && !Number.isSafeInteger(row[key]))
      throw new Error('WORKFLOW_BUSINESS_RESPONSE_INVALID');
  });
  if (
    row.available_actions !== undefined &&
    (!Array.isArray(row.available_actions) ||
      row.available_actions.some((action) => typeof action !== 'string'))
  )
    throw new Error('WORKFLOW_BUSINESS_RESPONSE_INVALID');
  return {
    ...row,
    confirmed_revision_key: row.confirmed_revision_key ?? null,
    available_actions: row.available_actions ?? [],
  } as BusinessObject;
}

/** V1 envelopes use native fetch, separate from the host's legacy Axios interceptor. */
export function createWorkflowBusinessApi(signal: AbortSignal) {
  const request = async (
    scene: BusinessScene,
    suffix?: string,
    method?: string,
    body?: unknown,
    idempotencyKey?: string
  ): Promise<{ data: unknown; meta: Record<string, unknown> }> => {
    const captured = getSessionSnapshot();
    if (!captured.token) throw new Error('WORKFLOW_BUSINESS_SESSION_REQUIRED');
    const base = new URL(axios.defaults.baseURL || '/', window.location.origin);
    const url = new URL(
      `/adminapi/api/v1/fixture/workflow-business/${scene}/objects${
        suffix ?? ''
      }`,
      base
    );
    const response = await fetch(url, {
      method: method ?? 'GET',
      credentials: 'same-origin',
      signal,
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${captured.token}`,
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
      },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const current = getSessionSnapshot();
    if (current.generation !== captured.generation || signal.aborted)
      throw new DOMException('Identity changed', 'AbortError');
    const envelope = object(await response.json());
    if (!response.ok) {
      const problem = typeof envelope.msg === 'string' ? envelope.msg : '';
      const legacyData =
        envelope.data && typeof envelope.data === 'object'
          ? (envelope.data as Record<string, unknown>)
          : {};
      throw new Error(
        problem || String(legacyData.error_code || response.status)
      );
    }
    if (!('data' in envelope))
      throw new Error('WORKFLOW_BUSINESS_RESPONSE_INVALID');
    return { data: envelope.data, meta: object(envelope.meta) };
  };
  const keyPath = (key: string) => {
    if (!/^object_[0-9a-f]{32}$/.test(key))
      throw new Error('WORKFLOW_BUSINESS_OBJECT_KEY_INVALID');
    return `/${encodeURIComponent(key)}`;
  };
  return {
    async lists(scene: BusinessScene, page: number) {
      const result = await request(scene, `?page=${page}&page_size=20`);
      const data = object(result.data);
      if (
        !Array.isArray(data.items) ||
        !Number.isSafeInteger(result.meta.total) ||
        Number(result.meta.total) < 0
      )
        throw new Error('WORKFLOW_BUSINESS_RESPONSE_INVALID');
      return {
        items: data.items.map(parseBusinessObject),
        total: Number(result.meta.total),
      };
    },
    async detail(scene: BusinessScene, key: string) {
      return parseBusinessObject((await request(scene, keyPath(key))).data);
    },
    async collaboration(
      scene: BusinessScene,
      key: string,
      instance: string,
      item: string
    ) {
      const query = new URLSearchParams({
        instance_key: instance,
        work_item_key: item,
      });
      return parseCollaboration(
        (await request(scene, `${keyPath(key)}/collaboration?${query}`)).data
      );
    },
    async appendCollaboration(
      scene: BusinessScene,
      key: string,
      view: CollaborationView,
      input: {
        kind: 'comment' | 'supplement';
        note: string;
        reference: string;
      },
      idempotencyKey: string
    ) {
      const {
        instance_key: instanceKey,
        work_item_key: workItemKey,
        instance_revision: instanceRevision,
        node_revision: nodeRevision,
        task_revision: taskRevision,
      } = view.access;
      const receipt = object(
        (
          await request(
            scene,
            `${keyPath(key)}/collaboration`,
            'POST',
            {
              ...input,
              instance_key: instanceKey,
              work_item_key: workItemKey,
              instance_revision: instanceRevision,
              node_revision: nodeRevision,
              task_revision: taskRevision,
            },
            idempotencyKey
          )
        ).data
      );
      if (typeof receipt.entry_key !== 'string')
        throw new Error('WORKFLOW_BUSINESS_COLLABORATION_INVALID');
      return receipt;
    },
    async create(scene: BusinessScene, fields: BusinessFields) {
      return parseBusinessObject(
        (await request(scene, '', 'POST', fields)).data
      );
    },
    async save(
      scene: BusinessScene,
      row: BusinessObject,
      fields: BusinessFields
    ) {
      return parseBusinessObject(
        (
          await request(scene, keyPath(row.object_key), 'PUT', {
            ...fields,
            revision: row.revision,
          })
        ).data
      );
    },
    async confirm(scene: BusinessScene, row: BusinessObject) {
      const result = await request(
        scene,
        `${keyPath(row.object_key)}/confirm`,
        'POST',
        { revision: row.revision },
        crypto.randomUUID()
      );
      const receipt = object(result.data);
      if (
        typeof receipt.revision_key !== 'string' ||
        !/^revision_[0-9a-f]{32}$/.test(receipt.revision_key) ||
        typeof receipt.sha256 !== 'string' ||
        !/^[0-9a-f]{64}$/.test(receipt.sha256)
      )
        throw new Error('WORKFLOW_BUSINESS_RECEIPT_INVALID');
      return receipt;
    },
    async saveForm(
      scene: BusinessScene,
      row: BusinessObject,
      form: { note: string; reference: string }
    ) {
      return parseBusinessObject(
        (
          await request(scene, `${keyPath(row.object_key)}/form`, 'PUT', {
            ...form,
            revision: row.revision,
          })
        ).data
      );
    },
  };
}
