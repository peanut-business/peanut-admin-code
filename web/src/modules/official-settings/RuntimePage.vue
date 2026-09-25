<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { hasPermission } from '@/hooks/permission';
  import { getToken } from '@/utils/auth';
  import SettingsPage from './runtime/src/SettingsPage.vue';
  import {
    createSettingsFetchTransport,
    createSettingsRuntime,
    SETTINGS_MANAGE_PERMISSION,
    SETTINGS_READ_PERMISSION,
    settingsRuntimeKey,
  } from './runtime/src';

  const apiBaseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const authenticatedFetch = (request: Request): Promise<Response> => {
    const headers = new Headers(request.headers);
    const token = getToken();
    if (token) headers.set('Authorization', `Bearer ${token}`);
    return fetch(new Request(request, { headers }));
  };
  const runtime = createSettingsRuntime({
    transport: createSettingsFetchTransport({
      baseUrl: apiBaseUrl,
      fetch: authenticatedFetch,
    }),
    canRead: () => hasPermission(SETTINGS_READ_PERMISSION),
    canManage: () => hasPermission(SETTINGS_MANAGE_PERMISSION),
  });

  provide(settingsRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <SettingsPage />
</template>
