<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { hasPermission } from '@/hooks/permission';
  import { getToken } from '@/utils/auth';
  import TaskJobPage from './optional-runtime/src/TaskJobPage.vue';
  import {
    createTaskJobFetchTransport,
    createTaskJobRuntime,
    TASK_JOB_MANAGE_PERMISSION,
    TASK_JOB_READ_PERMISSION,
    taskJobRuntimeKey,
  } from './optional-runtime/src';

  const baseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const authenticatedFetch = (request: Request): Promise<Response> => {
    const headers = new Headers(request.headers);
    const token = getToken();
    if (token) headers.set('Authorization', `Bearer ${token}`);
    return fetch(new Request(request, { headers }));
  };
  const runtime = createTaskJobRuntime({
    transport: createTaskJobFetchTransport({
      baseUrl,
      fetch: authenticatedFetch,
    }),
    canRead: () => hasPermission(TASK_JOB_READ_PERMISSION),
    canManage: () => hasPermission(TASK_JOB_MANAGE_PERMISSION),
  });

  provide(taskJobRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <TaskJobPage />
</template>
