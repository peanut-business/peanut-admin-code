<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import useUser from '@/hooks/user';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot, getToken } from '@/utils/auth';
  import IntegrationSecurityPage from './runtime/IntegrationSecurityPage.vue';
  import {
    createIntegrationSecurityFetchTransport,
    createIntegrationSecurityRuntime,
    integrationSecurityRuntimeKey,
  } from './runtime';

  const { logout } = useUser();
  const sessionGeneration = getSessionSnapshot().generation;
  const user = useUserStore();
  const allowed = (permission: string): boolean =>
    user.permissions.includes(permission);
  const runtime = createIntegrationSecurityRuntime({
    transport: createIntegrationSecurityFetchTransport({
      baseUrl: window.location.origin,
      authorization: getToken,
    }),
    permissions: {
      canReadMachines: () => allowed('official.integration.machine.read'),
      canManageMachines: () => allowed('official.integration.machine.manage'),
      canReadWebhooks: () => allowed('official.integration.webhook.read'),
      canManageWebhooks: () => allowed('official.integration.webhook.manage'),
      canReadDeliveries: () => allowed('official.integration.delivery.read'),
      canReadSessions: () => allowed('official.integration.session.read'),
      canRevokeSession: () => allowed('official.integration.session.revoke'),
    },
    onCurrentSessionRevoked: async () => {
      if (getSessionSnapshot().generation === sessionGeneration) await logout();
    },
  });

  provide(integrationSecurityRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <IntegrationSecurityPage />
</template>
