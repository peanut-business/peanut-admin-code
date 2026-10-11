<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
  import { createWorkflowApi } from '../api';
  import type {
    InboxFilter,
    WorkItem,
    WorkflowEvent,
    WorkflowInstance,
  } from '../types';
  import { useWorkflowLifecycle } from './lifecycle';
  import {
    INSTANCE_ACTION_LABELS,
    requiresReason,
    TASK_ACTION_LABELS,
    visibleTaskActions,
  } from './task-actions';

  const api = createWorkflowApi();
  const filter = ref<InboxFilter>('assigned');
  const page = ref(1);
  const count = ref(0);
  const tasks = shallowRef<
    {
      instance_key: string;
      label: string;
      status: string;
      owner_member_id: string | null;
      assignee_member_id: string | null;
    }[]
  >([]);
  const instance = shallowRef<WorkflowInstance | null>(null);
  const events = shallowRef<WorkflowEvent[]>([]);
  const loading = ref(false);
  const submitting = ref(false);
  const error = ref('');
  const notice = ref('');
  const action = ref('');
  const selectedTask = shallowRef<WorkItem | null>(null);
  const comment = ref('');
  const dialog = ref(false);
  const newSubjectRevision = ref('');
  const reassignTaskKey = ref('');
  const newAssignee = ref('');
  let requestEpoch = 0;
  let controller: AbortController | null = null;
  let timer: ReturnType<typeof setTimeout> | null = null;
  const lifecycle = useWorkflowLifecycle(() => {
    requestEpoch += 1;
    controller?.abort();
    if (timer) clearTimeout(timer);
    tasks.value = [];
    instance.value = null;
    events.value = [];
    comment.value = '';
    dialog.value = false;
  });
  const instanceActions = computed(
    () =>
      instance.value?.available_actions.filter((entry) =>
        Object.prototype.hasOwnProperty.call(INSTANCE_ACTION_LABELS, entry)
      ) || []
  );
  const filterOptions: { value: InboxFilter; label: string }[] = [
    { value: 'assigned', label: '我的待办' },
    { value: 'claimable', label: '待领取' },
    { value: 'completed', label: '已办' },
    { value: 'initiated', label: '我发起的' },
    { value: 'participant', label: '我参与的' },
    { value: 'watcher', label: '抄送给我的' },
  ];
  const fail = (cause: unknown) => {
    error.value = cause instanceof Error ? cause.message : '请求失败';
  };
  async function load() {
    if (!lifecycle.valid()) return;
    controller?.abort();
    controller = new AbortController();
    const epoch = ++requestEpoch;
    loading.value = true;
    error.value = '';
    try {
      const result = await api.inbox(
        filter.value,
        page.value,
        controller.signal
      );
      if (epoch !== requestEpoch || !lifecycle.valid()) return;
      tasks.value = result.lists.map((item) =>
        'work_item_key' in item
          ? item
          : {
              instance_key: item.instance_key,
              label: item.instance_key,
              status: item.status,
              owner_member_id: null,
              assignee_member_id: null,
            }
      );
      count.value = result.count;
    } catch (cause) {
      if (epoch === requestEpoch && lifecycle.valid()) fail(cause);
    } finally {
      if (epoch === requestEpoch && lifecycle.valid()) loading.value = false;
    }
  }
  async function showInstance(key: string) {
    if (!lifecycle.valid()) return;
    controller?.abort();
    controller = new AbortController();
    const epoch = ++requestEpoch;
    loading.value = true;
    error.value = '';
    instance.value = null;
    events.value = [];
    try {
      const detail = await api.instance(key, controller.signal);
      const history = await api.events(key, '0', controller.signal);
      if (epoch !== requestEpoch || !lifecycle.valid()) return;
      instance.value = detail;
      events.value = history;
    } catch (cause) {
      if (epoch === requestEpoch && lifecycle.valid()) fail(cause);
    } finally {
      if (epoch === requestEpoch && lifecycle.valid()) loading.value = false;
    }
  }
  function openAction(next: string, task: WorkItem | null = null) {
    action.value = next;
    selectedTask.value = task;
    comment.value = '';
    newSubjectRevision.value = '';
    reassignTaskKey.value = '';
    newAssignee.value = '';
    newSubjectRevision.value = '';
    reassignTaskKey.value = '';
    newAssignee.value = '';
    dialog.value = true;
    notice.value = '';
  }
  async function submit() {
    if (!instance.value || submitting.value || !lifecycle.valid()) return;
    if (requiresReason(action.value) && !comment.value.trim()) {
      error.value = '该操作必须填写原因';
      return;
    }
    const reassignTask = instance.value.own_work_items.find(
      (entry) => entry.work_item_key === reassignTaskKey.value
    );
    if (action.value === 'resubmit' && !newSubjectRevision.value.trim()) {
      error.value = '重新送审必须提供已确认的新材料版本';
      return;
    }
    if (
      action.value === 'reassign' &&
      (!reassignTask || !/^[1-9]\d*$/.test(newAssignee.value))
    ) {
      error.value = '请选择可见待处理工作项，并填写合法的新办理人成员 ID';
      return;
    }
    submitting.value = true;
    error.value = '';
    const prior = instance.value;
    const task = selectedTask.value;
    try {
      const result = task
        ? await api.taskAction(
            task,
            prior,
            action.value,
            comment.value.trim(),
            crypto.randomUUID()
          )
        : await api.instanceAction(
            prior,
            action.value,
            comment.value.trim(),
            crypto.randomUUID(),
            action.value === 'resubmit'
              ? { subject_revision_key: newSubjectRevision.value.trim() }
              : action.value === 'reassign' && reassignTask
              ? {
                  work_item_key: reassignTask.work_item_key,
                  assignee_member_id: newAssignee.value,
                  expected_task_revision: reassignTask.revision,
                }
              : undefined
          );
      if (!lifecycle.valid()) return;
      instance.value = result;
      dialog.value = false;
      notice.value =
        result.status === 'blocked'
          ? '本次操作已记录，下一步骤阻塞，请查看原因。'
          : '操作已记录，以当前流程状态为准。';
      await load();
    } catch (cause) {
      if (!lifecycle.valid()) return;
      fail(cause);
      notice.value =
        '操作失败时不会自动重新提交。保留本地意见，请刷新当前状态后核对。';
      // Refresh state after a possible conflict; preserve the unsent opinion.
      try {
        const current = await api.instance(prior.instance_key);
        if (lifecycle.valid()) instance.value = current;
      } catch {
        /* Keep the original error visible. */
      }
    } finally {
      if (lifecycle.valid()) submitting.value = false;
    }
  }
  function schedule() {
    timer = setTimeout(async () => {
      if (!document.hidden && !dialog.value && !submitting.value) await load();
      if (lifecycle.valid()) schedule();
    }, 15000);
  }
  function visibility() {
    if (document.hidden && timer) {
      clearTimeout(timer);
      timer = null;
    } else if (!document.hidden && !timer && lifecycle.valid()) {
      void load();
      schedule();
    }
  }
  document.addEventListener('visibilitychange', visibility);
  onBeforeUnmount(() =>
    document.removeEventListener('visibilitychange', visibility)
  );
  void load();
  schedule();
</script>

<template>
  <section class="workflow-runtime" aria-label="工作流运行中心">
    <h1>流程运行中心</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <div class="toolbar">
      <el-select
        v-model="filter"
        aria-label="任务列表范围"
        @change="
          page = 1;
          load();
        "
        ><el-option
          v-for="item in filterOptions"
          :key="item.value"
          :label="item.label"
          :value="item.value"
      /></el-select>
      <el-button :loading="loading" @click="load">刷新当前列表</el-button>
    </div>
    <el-table v-loading="loading" :data="tasks" empty-text="当前范围没有工作项">
      <el-table-column prop="label" label="任务" min-width="160" />
      <el-table-column prop="status" label="状态" width="110" />
      <el-table-column label="当前办理人" min-width="130"
        ><template #default="{ row }">{{
          row.assignee_member_id || '尚未领取'
        }}</template></el-table-column
      >
      <el-table-column label="任务归属人" min-width="130"
        ><template #default="{ row }">{{
          row.owner_member_id || '未分配'
        }}</template></el-table-column
      >
      <el-table-column label="操作" width="120"
        ><template #default="{ row }"
          ><el-button link @click="showInstance(row.instance_key)"
            >查看流程</el-button
          ></template
        ></el-table-column
      >
    </el-table>
    <el-pagination
      v-model:current-page="page"
      :page-size="20"
      :total="count"
      layout="prev, pager, next, total"
      @current-change="load"
    />
    <section v-if="instance" aria-label="流程详情" class="detail">
      <h2>流程 {{ instance.instance_key }}</h2>
      <p
        >当前状态：{{ instance.status }}；流程结果：{{
          instance.result_code || '尚未确定'
        }}</p
      >
      <el-alert
        v-if="instance.blocker"
        :title="instance.blocker.message"
        type="warning"
        :closable="false"
      />
      <p>送审版本：{{ instance.subject_revision_key }}</p>
      <el-timeline
        ><el-timeline-item
          v-for="node in instance.active_nodes"
          :key="node.node_execution_key"
          >{{ node.label }} — {{ node.status }}</el-timeline-item
        ></el-timeline
      >
      <div
        v-for="task in instance.own_work_items"
        :key="task.work_item_key"
        class="work-item"
      >
        <h3>{{ task.label }}</h3>
        <p
          >归属人：{{ task.owner_member_id || '未分配' }}；当前办理人：{{
            task.assignee_member_id || '待领取'
          }}；本人关系：{{ task.relations.join('、') || '无' }}</p
        >
        <p v-if="task.decision">已记录意见：{{ task.decision }}</p>
        <el-button
          v-for="entry in visibleTaskActions(task)"
          :key="entry"
          :disabled="submitting"
          @click="openAction(entry, task)"
          >{{ TASK_ACTION_LABELS[entry] }}</el-button
        >
      </div>
      <p>办理表单由所属业务页面提供。保存业务草稿与完成工作项是不同操作。</p>
      <el-button
        v-for="entry in instanceActions"
        :key="entry"
        :disabled="submitting"
        @click="openAction(entry)"
        >{{ INSTANCE_ACTION_LABELS[entry] }}</el-button
      >
      <h3>处理历史</h3
      ><el-timeline
        ><el-timeline-item
          v-for="event in events"
          :key="event.sequence_no"
          :timestamp="event.occurred_at"
          ><span>{{ event.event_key }} {{ event.actor_member_id || '' }}</span
          ><p class="safe-text">{{ event.comment }}</p></el-timeline-item
        ></el-timeline
      >
    </section>
    <el-dialog
      v-model="dialog"
      :title="TASK_ACTION_LABELS[action] || INSTANCE_ACTION_LABELS[action]"
      width="min(92vw, 560px)"
      :close-on-click-modal="false"
    >
      <p v-if="instance"
        >正在处理送审版本
        {{ instance.subject_revision_key }}，不修改已送审材料。</p
      >
      <p v-if="action === 'terminate'"
        >终止不会撤销已经成立的业务效果；正在执行或结果未知的动作须先核对。</p
      >
      <el-input
        v-if="action === 'resubmit'"
        v-model="newSubjectRevision"
        aria-label="已确认的新材料版本键"
        placeholder="已确认的新材料版本键；服务端重新核对材料与业务权限"
      />
      <template v-if="action === 'reassign' && instance">
        <el-select v-model="reassignTaskKey" aria-label="重分配工作项"
          ><el-option
            v-for="item in instance.own_work_items.filter(
              (entry) => entry.status === 'pending'
            )"
            :key="item.work_item_key"
            :label="`${item.label} (${item.work_item_key})`"
            :value="item.work_item_key"
        /></el-select>
        <el-input
          v-model="newAssignee"
          inputmode="numeric"
          aria-label="新办理人成员 ID"
          placeholder="新办理人成员 ID（服务端核对原责任来源、权限与职责分离）"
        />
      </template>
      <el-input
        v-model="comment"
        type="textarea"
        :maxlength="2000"
        :rows="4"
        :aria-label="
          requiresReason(action) ? '操作原因（必填）' : '处理意见（可选）'
        "
        :placeholder="
          requiresReason(action) ? '请填写原因（必填）' : '处理意见（可选）'
        "
      />
      <template #footer
        ><el-button :disabled="submitting" @click="dialog = false"
          >取消</el-button
        ><el-button type="primary" :loading="submitting" @click="submit"
          >确认提交</el-button
        ></template
      >
    </el-dialog>
  </section>
</template>

<style scoped>
  .workflow-runtime {
    padding: 20px;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 16px 0;
  }
  .detail,
  .work-item {
    padding: 16px;
    margin-top: 16px;
    border: 1px solid var(--el-border-color);
    border-radius: 8px;
  }
  .safe-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  @media (max-width: 600px) {
    .workflow-runtime {
      padding: 10px;
    }
    .work-item .el-button {
      margin: 4px;
    }
  }
</style>
