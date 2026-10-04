<script setup lang="ts">
  import {
    EmptyState,
    ForbiddenState,
    ModuleUnavailableState,
    PageContent,
    PageHeader,
    PageToolbar,
    SessionExpiredState,
  } from '@peanut-admin/ui-vue';
  import { ElButton } from 'element-plus';
  import { computed, onMounted } from 'vue';
  import { useTaskJobRuntime } from './runtime';

  const runtime = useTaskJobRuntime();
  const state = runtime.state;
  const canManage = computed(runtime.canManage);
  const statuses = [
    'queued',
    'running',
    'succeeded',
    'dead',
    'cancelled',
  ] as const;

  onMounted(runtime.load);
</script>

<template>
  <PageContent class="task-job-page">
    <PageHeader>
      Tasks
      <template #actions>
        <ElButton
          :loading="state.loading"
          :disabled="state.mutating"
          @click="runtime.load"
        >
          Reload
        </ElButton>
      </template>
    </PageHeader>
    <PageToolbar label="Task status">
      <ElButton
        v-for="status in statuses"
        :key="status"
        :type="state.status === status ? 'primary' : 'default'"
        @click="runtime.setStatus(status)"
      >
        {{ status }}
      </ElButton>
    </PageToolbar>

    <SessionExpiredState
      v-if="state.error?.status === 401"
      :message="state.error.message"
    />
    <ForbiddenState
      v-else-if="state.error?.status === 403"
      :message="state.error.message"
    />
    <ModuleUnavailableState
      v-else-if="state.error?.status === 503"
      :message="state.error.message"
      @action="runtime.load"
    />
    <section v-else-if="state.error" role="alert" class="task-state">
      <h2>Unable to complete the task request</h2>
      <p>{{ state.error.message }}</p>
      <p v-if="state.error.requestId">
        Request ID: {{ state.error.requestId }}
      </p>
    </section>
    <div v-else-if="state.loading" class="task-state" role="status">
      Loading tasks...
    </div>
    <EmptyState
      v-else-if="state.items.length === 0"
      title="No tasks"
      message="No tasks match this status."
    />
    <div v-else class="task-table-wrap">
      <table class="task-table">
        <thead
          ><tr
            ><th>Type</th><th>Status</th><th>Attempts</th><th>Error</th
            ><th>Updated</th><th>Actions</th></tr
          ></thead
        >
        <tbody>
          <tr v-for="job in state.items" :key="job.jobKey">
            <td>{{ job.taskType }}</td
            ><td>{{ job.status }}</td>
            <td>{{ job.attemptCount }} / {{ job.maxAttempts }}</td>
            <td>{{ job.lastErrorCode ?? '-' }}</td
            ><td>{{ job.updatedAt }}</td>
            <td>
              <ElButton
                v-if="job.status === 'queued'"
                text
                :disabled="!canManage || state.mutating"
                @click="runtime.cancel(job)"
              >
                Cancel
              </ElButton>
              <ElButton
                v-if="job.status === 'dead'"
                text
                :disabled="!canManage || state.mutating"
                @click="runtime.retry(job)"
              >
                Retry
              </ElButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PageContent>
</template>

<style scoped>
  .task-state {
    padding: 24px 0;
  }
  .task-table-wrap {
    overflow-x: auto;
  }
  .task-table {
    width: 100%;
    border-collapse: collapse;
  }
  .task-table th,
  .task-table td {
    padding: 10px 8px;
    border-bottom: 1px solid var(--el-border-color);
    text-align: left;
  }
</style>
