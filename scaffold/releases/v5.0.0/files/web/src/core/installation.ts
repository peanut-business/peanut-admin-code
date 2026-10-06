import { ref } from 'vue';
import {
  getInstallationStatus,
  type InstallationStatus,
} from '@/api/installation';

export const installationStatus = ref<InstallationStatus | null>(null);

let pendingStatus: Promise<InstallationStatus> | null = null;

function configuredDeploymentMode() {
  return import.meta.env.VITE_DEPLOYMENT_MODE === 'multi-tenant'
    ? 'multi-tenant'
    : 'standalone';
}

function blockedStatus(): InstallationStatus {
  return {
    state: 'blocked',
    mode: 'guided',
    deployment_mode: configuredDeploymentMode(),
    preflight: {
      status: 'blocked',
      code: 'INSTALL_STATUS_UNAVAILABLE',
      reason: '无法读取安装状态。',
      remediation: '请检查部署服务后重新检查。',
      checks: [],
    },
  };
}

/**
 * Load the installation state once for the router and the installation page.
 * A failed status request deliberately becomes a blocked state so that the
 * application never falls through to authenticated routes without knowing
 * whether the database is installed.
 */
export function bootstrapInstallationStatus(
  force = false
): Promise<InstallationStatus> {
  if (!force && installationStatus.value) {
    return Promise.resolve(installationStatus.value);
  }
  if (pendingStatus) {
    return pendingStatus;
  }
  pendingStatus = getInstallationStatus()
    .then((status) => {
      installationStatus.value = status;
      return status;
    })
    .catch(() => {
      const status = blockedStatus();
      installationStatus.value = status;
      return status;
    })
    .finally(() => {
      pendingStatus = null;
    });
  return pendingStatus;
}

export const loadInstallationStatus = bootstrapInstallationStatus;

export function shouldShowInstallation(status: InstallationStatus | null) {
  return status?.state === 'uninstalled' && status.mode === 'guided';
}

export function markInstallationInstalled() {
  const current = installationStatus.value;
  if (!current) return;
  installationStatus.value = {
    ...current,
    state: 'installed',
  };
}
