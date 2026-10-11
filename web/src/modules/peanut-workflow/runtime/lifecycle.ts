import { onBeforeUnmount, watch } from 'vue';
import { registerTenantDisposer } from '@peanut-admin/vue';
import { useUserStore } from '@/store';
import { getSessionSnapshot, SESSION_KEY } from '@/utils/auth';

/** A page owns its state. Identity changes dispose it even before route removal. */
export function useWorkflowLifecycle(dispose: () => void) {
  const user = useUserStore();
  let generation = getSessionSnapshot().generation;
  let disposed = false;
  const close = () => {
    if (!disposed) {
      disposed = true;
      dispose();
    }
  };
  const unregister = registerTenantDisposer(
    `peanut.workflow.page.${crypto.randomUUID()}`,
    close
  );
  const valid = () => {
    if (generation !== getSessionSnapshot().generation) close();
    return !disposed;
  };
  const stop = watch(
    () => [user.id, user.tenantName],
    () => {
      generation = -1;
      close();
    }
  );
  const storage = (event: StorageEvent) => {
    if (event.key === SESSION_KEY) close();
  };
  window.addEventListener('storage', storage);
  onBeforeUnmount(() => {
    unregister();
    stop();
    window.removeEventListener('storage', storage);
    close();
  });
  return { valid, close };
}
