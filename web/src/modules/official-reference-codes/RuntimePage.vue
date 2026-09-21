<script setup lang="ts">
import { onBeforeUnmount, provide } from 'vue';
import { hasPermission } from '@/hooks/permission';
import { getToken } from '@/utils/auth';
import ReferenceCodesPage from './runtime/src/ReferenceCodesPage.vue';
import {
  createReferenceCodesFetchTransport,
  createReferenceCodesRuntime,
  REFERENCE_CODES_MANAGE_PERMISSION,
  REFERENCE_CODES_READ_PERMISSION,
  referenceCodesRuntimeKey,
} from './runtime/src';

const apiBaseUrl = new URL(import.meta.env.VITE_API_BASE_URL || '/', window.location.origin).toString();
const authenticatedFetch = (request: Request): Promise<Response> => {
  const headers = new Headers(request.headers);
  const token = getToken();
  if (token) headers.set('Authorization', `Bearer ${token}`);
  return fetch(new Request(request, { headers }));
};
const runtime = createReferenceCodesRuntime({
  transport: createReferenceCodesFetchTransport({ baseUrl: apiBaseUrl, fetch: authenticatedFetch }),
  canRead: () => hasPermission(REFERENCE_CODES_READ_PERMISSION),
  canManage: () => hasPermission(REFERENCE_CODES_MANAGE_PERMISSION),
});

provide(referenceCodesRuntimeKey, runtime);
onBeforeUnmount(runtime.dispose);
</script>

<template>
  <ReferenceCodesPage />
</template>
