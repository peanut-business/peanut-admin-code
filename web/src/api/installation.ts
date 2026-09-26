import axios, { type AxiosResponse } from 'axios';

export type InstallationState = 'uninstalled' | 'installed' | 'blocked';
export type InstallationMode = 'guided' | 'automatic';
export type InstallationDeploymentMode = 'standalone' | 'multi-tenant';

export interface InstallationPreflightCheck {
  id?: string;
  status?: string;
  code?: string;
  reason?: string;
  remediation?: string;
}

export interface InstallationModuleOption {
  key: string;
  name?: string;
  label?: string;
  description?: string;
  required?: boolean;
  default?: boolean;
  selected?: boolean;
}

export interface InstallationPreflight {
  status?: string;
  code?: string;
  reason?: string;
  remediation?: string;
  checks?: InstallationPreflightCheck[];
  modules?: Array<string | InstallationModuleOption>;
  official_modules?: Array<string | InstallationModuleOption>;
  [key: string]: unknown;
}

export interface InstallationHealth {
  [key: string]: unknown;
}

export interface InstallationStatus {
  state: InstallationState;
  mode: InstallationMode;
  deployment_mode: InstallationDeploymentMode;
  preflight: InstallationPreflight | null;
  official_modules?: Array<string | InstallationModuleOption>;
  code?: string;
  retryable?: boolean;
  health?: InstallationHealth | null;
}

export interface InstallationExecutePayload {
  admin_email: string;
  admin_password: string;
  platform_email: string;
  platform_password: string;
  official_modules: string[];
}

export interface InstallationExecuteResult {
  state: 'installed';
  health: InstallationHealth;
}

// This client intentionally has no shared request interceptor. The status
// endpoint is public, and the execute endpoint receives only the one-time
// setup token supplied by the caller.
export const installationClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
});

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isPreflightCheck(value: unknown): value is InstallationPreflightCheck {
  return (
    isRecord(value) &&
    ['id', 'status', 'code', 'reason', 'remediation'].every(
      (key) => value[key] === undefined || typeof value[key] === 'string'
    )
  );
}

function isModuleOption(value: unknown): value is InstallationModuleOption {
  return (
    isRecord(value) &&
    typeof value.key === 'string' &&
    ['name', 'label', 'description'].every(
      (key) => value[key] === undefined || typeof value[key] === 'string'
    ) &&
    ['required', 'default', 'selected'].every(
      (key) => value[key] === undefined || typeof value[key] === 'boolean'
    )
  );
}

function isModuleOptions(
  value: unknown
): value is Array<string | InstallationModuleOption> {
  return (
    Array.isArray(value) &&
    value.every((item) => typeof item === 'string' || isModuleOption(item))
  );
}

function isPreflight(value: unknown): value is InstallationPreflight {
  return (
    isRecord(value) &&
    ['status', 'code', 'reason', 'remediation'].every(
      (key) => value[key] === undefined || typeof value[key] === 'string'
    ) &&
    (value.checks === undefined ||
      (Array.isArray(value.checks) && value.checks.every(isPreflightCheck))) &&
    (value.modules === undefined || isModuleOptions(value.modules)) &&
    (value.official_modules === undefined ||
      isModuleOptions(value.official_modules))
  );
}

function unwrap(response: AxiosResponse<unknown>): unknown {
  const body = response.data;
  if (!isRecord(body) || !('data' in body)) {
    throw new Error('Installation API returned an invalid response.');
  }
  if (body.code !== 20000) {
    throw new Error(
      typeof body.msg === 'string'
        ? body.msg
        : 'Installation API returned an error.'
    );
  }
  return body.data;
}

function normalizeStatus(value: unknown): InstallationStatus {
  if (!isRecord(value)) {
    throw new Error('Installation status returned an invalid payload.');
  }
  const {
    state,
    mode,
    deployment_mode: deploymentMode,
    preflight,
    official_modules: officialModules,
    code,
    retryable,
    health,
  } = value;
  if (
    (state !== 'uninstalled' && state !== 'installed' && state !== 'blocked') ||
    (mode !== 'guided' && mode !== 'automatic') ||
    (deploymentMode !== 'standalone' && deploymentMode !== 'multi-tenant')
  ) {
    throw new Error('Installation status returned an invalid state.');
  }
  if (
    preflight !== null &&
    preflight !== undefined &&
    !isPreflight(preflight)
  ) {
    throw new Error('Installation status returned an invalid preflight.');
  }
  if (
    (officialModules !== undefined && !isModuleOptions(officialModules)) ||
    (code !== undefined && typeof code !== 'string') ||
    (retryable !== undefined && typeof retryable !== 'boolean') ||
    (health !== undefined && health !== null && !isRecord(health))
  ) {
    throw new Error('Installation status returned invalid optional fields.');
  }
  return {
    ...value,
    state,
    mode,
    deployment_mode: deploymentMode,
    preflight: preflight ?? null,
    official_modules: officialModules,
    code,
    retryable,
    health,
  };
}

function normalizeExecuteResult(value: unknown): InstallationExecuteResult {
  if (!isRecord(value) || value.state !== 'installed' || !('health' in value)) {
    throw new Error('Installation did not return a completed health state.');
  }
  const { health } = value;
  if (!isRecord(health)) {
    throw new Error('Installation returned an invalid health state.');
  }
  return { ...value, state: 'installed', health };
}

export async function getInstallationStatus(): Promise<InstallationStatus> {
  const response = await installationClient.get<unknown>('/installapi/status');
  return normalizeStatus(unwrap(response));
}

export async function executeInstallation(
  setupToken: string,
  payload: InstallationExecutePayload
): Promise<InstallationExecuteResult> {
  if (!setupToken.trim()) {
    throw new Error('A setup token is required to start installation.');
  }
  const requestPayload: InstallationExecutePayload = {
    admin_email: payload.admin_email,
    admin_password: payload.admin_password,
    platform_email: payload.platform_email,
    platform_password: payload.platform_password,
    official_modules: [...payload.official_modules],
  };
  const response = await installationClient.post<unknown>(
    '/installapi/execute',
    requestPayload,
    {
      headers: {
        Authorization: `Bearer ${setupToken.trim()}`,
      },
    }
  );
  return normalizeExecuteResult(unwrap(response));
}
