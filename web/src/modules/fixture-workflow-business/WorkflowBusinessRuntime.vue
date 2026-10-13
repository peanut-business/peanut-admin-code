<script setup lang="ts">
  import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
  import { useRoute } from 'vue-router';
  import { registerTenantDisposer } from '@peanut-admin/vue';
  import WorkflowRuntimePage from '@/modules/peanut-workflow/runtime/WorkflowRuntimePage.vue';
  import { provideWorkflowTaskForm } from '@/modules/peanut-workflow/runtime/business-forms';
  import type { WorkflowTaskFormProvider } from '@/modules/peanut-workflow/runtime/business-forms';
  import { getSessionSnapshot, SESSION_KEY } from '@/utils/auth';
  import { useUserStore } from '@/store';
  import { createWorkflowBusinessApi } from './api';
  import type { BusinessObject, BusinessScene } from './api';

  const controller = new AbortController();
  const api = createWorkflowBusinessApi(controller.signal);
  const { generation } = getSessionSnapshot();
  const user = useUserStore();
  const route = useRoute();
  const instanceKey = computed(() =>
    typeof route.query.instance === 'string' && route.query.instance !== ''
      ? route.query.instance
      : undefined
  );
  const open = ref(false);
  const saving = ref(false);
  const error = ref('');
  const action = ref('');
  const subject = ref<BusinessObject | null>(null);
  const form = reactive({
    note: '',
    reference: '',
    title: '',
    amount_minor: 0,
    quantity: 1,
    sku: '',
  });
  type Result = Awaited<ReturnType<WorkflowTaskFormProvider>>;
  let resolveForm: ((result: Result) => void) | null = null;
  let pinnedRevision = '';
  let disposed = false;
  const valid = () =>
    !disposed && generation === getSessionSnapshot().generation;
  const finish = (result: Result) => {
    resolveForm?.(result);
    resolveForm = null;
    open.value = false;
    subject.value = null;
    Object.assign(form, {
      note: '',
      reference: '',
      title: '',
      amount_minor: 0,
      quantity: 1,
      sku: '',
    });
  };
  const dispose = () => {
    disposed = true;
    controller.abort();
    finish(null);
  };
  const unregister = registerTenantDisposer(
    `fixture.workflow-business.form.${crypto.randomUUID()}`,
    dispose
  );
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

  provideWorkflowTaskForm(async ({ action: requested, instance }) => {
    if (requested !== 'complete' && requested !== 'resubmit')
      throw new Error('业务表单只支持完成和补正重新送审。');
    if (!valid() || resolveForm) return null;
    const match =
      /^fixture\.workflow-business\.(application|purchase|receipt)$/.exec(
        instance.subject_type
      );
    if (!match) throw new Error('该页面只办理所属样例业务场景。');
    const row = await api.detail(
      match[1] as BusinessScene,
      instance.subject_key
    );
    if (!valid()) return null;
    subject.value = row;
    pinnedRevision = instance.subject_revision_key;
    action.value = requested;
    error.value = '';
    Object.assign(form, {
      ...row.completion_form,
      title: row.title,
      amount_minor: row.amount_minor ?? 0,
      quantity: row.quantity ?? 1,
      sku: row.sku ?? '',
    });
    open.value = true;
    return new Promise<Result>((resolve) => {
      resolveForm = resolve;
    });
  });
  const submit = async () => {
    if (!subject.value || saving.value || !valid()) return;
    saving.value = true;
    error.value = '';
    try {
      const row = subject.value;
      if (action.value === 'resubmit') {
        const saved = await api.save(row.scene, row, {
          title: form.title,
          amount_minor: form.amount_minor,
          quantity: form.quantity,
          sku: form.sku,
        });
        subject.value = saved; // Preserve the new optimistic revision if confirmation needs retry.
        const receipt = await api.confirm(row.scene, saved);
        const confirmed = await api.detail(row.scene, row.object_key);
        if (!valid()) return;
        subject.value = confirmed;
        if (
          !confirmed.confirmed_revision_key ||
          confirmed.confirmed_revision_key !== receipt.revision_key ||
          confirmed.confirmed_revision_key === pinnedRevision
        )
          throw new Error('重提需要新的确认版本。');
        finish({
          subject_revision_key: confirmed.confirmed_revision_key,
          attachment_file_keys: [],
        });
      } else if (action.value === 'complete') {
        const saved = await api.saveForm(row.scene, row, {
          note: form.note,
          reference: form.reference,
        });
        subject.value = saved;
        const confirmed = await api.detail(row.scene, row.object_key);
        if (!valid()) return;
        subject.value = confirmed;
        if (
          !confirmed.completion_ready ||
          confirmed.confirmed_revision_key !== pinnedRevision
        )
          throw new Error('办理表单须对应当前送审版本。');
        finish({ input: {}, attachment_file_keys: [] });
      } else {
        throw new Error('业务表单只支持完成和补正重新送审。');
      }
    } catch (cause) {
      if (valid())
        error.value =
          cause instanceof Error ? cause.message : '业务表单保存失败';
    } finally {
      saving.value = false;
    }
  };
</script>

<template>
  <div>
    <WorkflowRuntimePage :instance-key="instanceKey">
      <template #business-collaboration="{ instance }">
        <p v-for="item in instance.own_work_items" :key="item.work_item_key">
          <router-link
            :to="{
              path: `/app/fixtures/workflow-business/${
                instance.subject_type.split('.').slice(-1)[0]
              }/${instance.subject_key}`,
              query: {
                instance: instance.instance_key,
                work_item: item.work_item_key,
              },
            }"
          >
            查看当前获准协作资料与评论（{{ item.label }}）
          </router-link>
        </p>
      </template>
    </WorkflowRuntimePage>
    <el-dialog
      v-model="open"
      :title="
        action === 'resubmit' ? '修改业务并确认重提版本' : '保存业务办理表单'
      "
      :close-on-click-modal="false"
      width="min(92vw, 560px)"
      @closed="finish(null)"
    >
      <el-alert v-if="error" :title="error" type="error" :closable="false" />
      <el-form
        :disabled="saving"
        label-position="top"
        class="business-task-form"
      >
        <template v-if="action === 'resubmit'">
          <el-form-item label="标题"
            ><el-input v-model="form.title" maxlength="120"
          /></el-form-item>
          <el-form-item label="金额（最小单位）"
            ><el-input-number
              v-model="form.amount_minor"
              :min="0"
              :max="1000000000"
              :precision="0"
          /></el-form-item>
          <el-form-item label="合成物料编码"
            ><el-input v-model="form.sku" maxlength="64"
          /></el-form-item>
          <el-form-item label="数量"
            ><el-input-number
              v-model="form.quantity"
              :min="1"
              :max="1000000"
              :precision="0"
          /></el-form-item>
        </template>
        <template v-else>
          <el-form-item label="办理凭据编号"
            ><el-input v-model="form.reference" maxlength="128"
          /></el-form-item>
          <el-form-item label="办理说明"
            ><el-input v-model="form.note" type="textarea" maxlength="1000"
          /></el-form-item>
        </template>
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="finish(null)">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit"
          >保存并返回待办确认</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
  .business-task-form :deep(.el-input-number) {
    width: 100%;
  }
  .business-task-form :deep(.el-form-item__content) {
    min-width: 0;
  }
</style>
