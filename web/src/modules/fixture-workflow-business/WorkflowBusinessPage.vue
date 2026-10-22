<script setup lang="ts">
  import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
  } from 'vue';
  import { useRoute, useRouter } from 'vue-router';
  import { registerTenantDisposer } from '@peanut-admin/vue';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot, SESSION_KEY } from '@/utils/auth';
  import { hasPermission } from '@/hooks/permission';
  import WorkflowStartButton from '@/modules/peanut-workflow/runtime/WorkflowStartButton.vue';
  import { createHostWorkflowApi } from '@/modules/peanut-workflow/host-api';
  import { createWorkflowBusinessApi } from './api';
  import type { BusinessObject, BusinessScene, CollaborationView } from './api';

  const route = useRoute();
  const router = useRouter();
  const user = useUserStore();
  const controller = new AbortController();
  const api = createWorkflowBusinessApi(controller.signal);
  const workflow = createHostWorkflowApi();
  const { generation: identity } = getSessionSnapshot();
  const requestedScene = route.params.scene;
  const scene = ref<BusinessScene>(
    requestedScene === 'application' || requestedScene === 'purchase'
      ? requestedScene
      : 'receipt'
  );
  const businessDepartmentKey = ref('');
  const rows = ref<BusinessObject[]>([]);
  const selected = ref<BusinessObject | null>(null);
  const page = ref(1);
  const total = ref(0);
  const busy = ref(false);
  const error = ref('');
  const definitionRevision = ref<number | null>(null);
  const fields = reactive({
    title: '',
    amount_minor: 0,
    sku: 'SAMPLE-1',
    quantity: 1,
  });
  const completion = reactive({
    note: '',
    reference: '',
    result: 'completed' as 'completed' | 'rejected',
  });
  const collaboration = ref<CollaborationView | null>(null);
  const supplement = reactive({
    kind: 'comment' as 'comment' | 'supplement',
    note: '',
    reference: '',
  });
  let collaborationIdempotency = crypto.randomUUID();
  let disposed = false;
  const dispose = () => {
    disposed = true;
    controller.abort();
    workflow.dispose();
    rows.value = [];
    selected.value = null;
    collaboration.value = null;
    definitionRevision.value = null;
    Object.assign(fields, { title: '', amount_minor: 0, sku: '', quantity: 1 });
    Object.assign(completion, { note: '', reference: '' });
  };
  const unregister = registerTenantDisposer(
    `fixture.workflow-business.${crypto.randomUUID()}`,
    dispose
  );
  const valid = () => !disposed && identity === getSessionSnapshot().generation;
  const stop = watch(() => [user.id, user.tenantName], dispose);
  const storage = (event: StorageEvent) => {
    if (event.key === SESSION_KEY) dispose();
  };
  window.addEventListener('storage', storage);
  onBeforeUnmount(() => {
    unregister();
    stop();
    window.removeEventListener('storage', storage);
    dispose();
  });
  const run = async (operation: () => Promise<void>) => {
    if (busy.value || !valid()) return;
    busy.value = true;
    error.value = '';
    try {
      await operation();
    } catch (cause) {
      if (valid())
        error.value = cause instanceof Error ? cause.message : '业务操作失败';
    } finally {
      if (valid()) busy.value = false;
    }
  };
  const refresh = async () => {
    const result = await api.lists(scene.value, page.value);
    if (valid()) {
      rows.value = result.items;
      total.value = result.total;
    }
  };
  const select = async (key: string) => {
    const row = await api.detail(scene.value, key);
    if (!valid()) return;
    selected.value = row;
    Object.assign(fields, {
      title: row.title,
      amount_minor: row.amount_minor ?? 0,
      sku: row.sku ?? '',
      quantity: row.quantity ?? 1,
    });
    Object.assign(
      completion,
      { note: '', reference: '', result: 'completed' },
      row.completion_form ?? {}
    );
    definitionRevision.value = null;
    collaboration.value = null;
    if (
      typeof route.query.instance === 'string' &&
      typeof route.query.work_item === 'string'
    ) {
      const result = await api.collaboration(
        scene.value,
        key,
        route.query.instance,
        route.query.work_item
      );
      if (valid()) collaboration.value = result;
    }
    if (row.confirmed_revision_key && row.available_actions.includes('start')) {
      const definition = await workflow.definitionSummary(
        'fixture.workflow-business',
        scene.value,
        `fixture.workflow-business.${scene.value}`,
        row.object_key,
        controller.signal
      );
      if (valid()) definitionRevision.value = definition.draft_revision;
    }
  };
  const save = () =>
    run(async () => {
      const row = selected.value
        ? await api.save(scene.value, selected.value, { ...fields })
        : await api.create(scene.value, {
            ...fields,
            business_department_key: businessDepartmentKey.value || null,
          });
      await select(row.object_key);
      await refresh();
    });
  const confirm = () =>
    run(async () => {
      if (!selected.value) return;
      await api.confirm(scene.value, selected.value);
      await select(selected.value.object_key);
      await refresh();
    });
  const saveForm = () =>
    run(async () => {
      if (!selected.value) return;
      const row = await api.saveForm(scene.value, selected.value, {
        note: completion.note,
        reference: completion.reference,
        ...(scene.value === 'receipt' ? { result: completion.result } : {}),
      });
      if (valid()) selected.value = row;
    });
  const appendCollaboration = () =>
    run(async () => {
      if (!selected.value || !collaboration.value?.access.can_collaborate)
        return;
      await api.appendCollaboration(
        scene.value,
        selected.value.object_key,
        collaboration.value,
        { ...supplement },
        collaborationIdempotency
      );
      collaborationIdempotency = crypto.randomUUID();
      Object.assign(supplement, { kind: 'comment', note: '', reference: '' });
      await select(selected.value.object_key);
    });
  const startInput = computed(() =>
    selected.value?.confirmed_revision_key && definitionRevision.value
      ? {
          module_key: 'fixture.workflow-business',
          workflow_key: scene.value,
          subject_type: `fixture.workflow-business.${scene.value}`,
          subject_key: selected.value.object_key,
          subject_revision_key: selected.value.confirmed_revision_key,
          expected_revision: definitionRevision.value,
          business_department_key:
            selected.value.business_department_key ?? null,
          attachment_file_keys: [],
        }
      : null
  );
  const newObject = () => {
    selected.value = null;
    collaboration.value = null;
    definitionRevision.value = null;
    Object.assign(fields, {
      title: '',
      amount_minor: 0,
      sku: 'SAMPLE-1',
      quantity: 1,
    });
    Object.assign(completion, { note: '', reference: '' });
  };
  const changeScene = () =>
    run(async () => {
      newObject();
      page.value = 1;
      await refresh();
    });
  onMounted(() =>
    run(async () => {
      await refresh();
      if (
        typeof route.params.objectKey === 'string' &&
        route.params.objectKey !== ''
      )
        await select(route.params.objectKey);
    })
  );
</script>

<template>
  <div class="p-4 space-y-4">
    <el-alert
      title="开发与验收专用样例：使用隔离合成数据；不代表正式采购、库存产品。"
      type="info"
      :closable="false"
    />
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-select v-model="scene" :disabled="busy" @change="changeScene">
      <el-option label="申请审批" value="application" />
      <el-option label="采购办理" value="purchase" />
      <el-option label="入库办理" value="receipt" />
    </el-select>
    <el-button
      v-if="hasPermission(['fixture.workflow-business.create'])"
      :disabled="busy"
      @click="newObject"
      >新建样例对象</el-button
    >
    <el-table
      v-loading="busy"
      :data="rows"
      @row-click="(row: BusinessObject) => run(() => select(row.object_key))"
    >
      <el-table-column prop="title" label="业务对象" />
      <el-table-column prop="scene" label="场景" />
      <el-table-column prop="state" label="业务状态" />
      <el-table-column prop="revision" label="业务修订" />
    </el-table>
    <el-pagination
      v-model:current-page="page"
      :total="total"
      :page-size="20"
      layout="prev, pager, next"
      @current-change="() => run(refresh)"
    />
    <el-card>
      <template #header>{{
        selected ? `业务对象 ${selected.object_key}` : '新建业务对象'
      }}</template>
      <el-form label-width="120px" :disabled="busy">
        <el-form-item v-if="!selected" label="业务部门键">
          <el-input
            v-model="businessDepartmentKey"
            maxlength="128"
            placeholder="已登记的本租户部门键（可选）"
          />
        </el-form-item>
        <el-form-item label="标题"
          ><el-input v-model="fields.title" maxlength="120"
        /></el-form-item>
        <el-form-item label="金额（最小单位）"
          ><el-input-number
            v-model="fields.amount_minor"
            :min="0"
            :max="1000000000"
            :precision="0"
        /></el-form-item>
        <el-form-item label="合成物料编码"
          ><el-input v-model="fields.sku" maxlength="64"
        /></el-form-item>
        <el-form-item label="数量"
          ><el-input-number
            v-model="fields.quantity"
            :min="1"
            :max="1000000"
            :precision="0"
        /></el-form-item>
      </el-form>
      <el-button
        v-if="
          (!selected || selected.available_actions.includes('update')) &&
          hasPermission([
            'fixture.workflow-business.update',
            'fixture.workflow-business.create',
          ])
        "
        :loading="busy"
        @click="save"
        >保存业务草稿</el-button
      >
      <el-button
        v-if="
          selected?.state === 'draft' &&
          selected.available_actions.includes('confirm')
        "
        :disabled="busy"
        @click="confirm"
        >确认不可变版本</el-button
      >
      <div v-if="selected?.confirmed_revision_key" class="mt-3"
        >送审版本：{{ selected.confirmed_revision_key }}</div
      >
      <WorkflowStartButton
        v-if="startInput && selected"
        :input="startInput"
        :available-actions="selected.available_actions"
        @started="
          (instance) =>
            router.push({
              name: 'fixture-workflow-business-runtime',
              query: { instance: instance.instance_key },
            })
        "
      />
    </el-card>
    <el-card
      v-if="
        selected?.state === 'confirmed' &&
        selected.available_actions.includes('complete')
      "
    >
      <template #header>业务办理表单</template>
      <el-form label-width="120px" :disabled="busy">
        <el-form-item v-if="scene === 'receipt'" label="验收结果">
          <el-select v-model="completion.result">
            <el-option label="验收通过" value="completed" />
            <el-option label="验收异常，转主管处理" value="rejected" />
          </el-select>
        </el-form-item>
        <el-form-item label="办理凭据编号"
          ><el-input v-model="completion.reference" maxlength="128"
        /></el-form-item>
        <el-form-item label="办理说明"
          ><el-input v-model="completion.note" type="textarea" maxlength="1000"
        /></el-form-item>
      </el-form>
      <el-button :loading="busy" @click="saveForm">保存办理表单</el-button>
      <p class="mt-2"
        >保存表单不会同意、拒绝或完成工作流任务；请在工作流待办中执行当前获准动作。</p
      >
    </el-card>
    <el-card v-if="collaboration" aria-label="协作资料与评论">
      <template #header>协作资料与评论</template>
      <p>协作记录不会更改送审材料、审批意见或流程状态。抄送身份仅可查看。</p>
      <el-table :data="collaboration.entries">
        <el-table-column prop="kind" label="类型" />
        <el-table-column prop="note" label="说明" />
        <el-table-column prop="reference" label="补充资料引用" />
        <el-table-column prop="actor_member_id" label="成员" />
        <el-table-column prop="created_at" label="时间" />
      </el-table>
      <el-form
        v-if="collaboration.access.can_collaborate"
        :disabled="busy"
        label-width="120px"
      >
        <el-form-item label="类型"
          ><el-select v-model="supplement.kind"
            ><el-option label="评论" value="comment" /><el-option
              label="补充资料"
              value="supplement" /></el-select
        ></el-form-item>
        <el-form-item label="说明"
          ><el-input v-model="supplement.note" type="textarea" maxlength="1000"
        /></el-form-item>
        <el-form-item label="资料引用"
          ><el-input v-model="supplement.reference" maxlength="128"
        /></el-form-item>
        <el-button :loading="busy" @click="appendCollaboration"
          >追加协作记录</el-button
        >
      </el-form>
    </el-card>
  </div>
</template>
