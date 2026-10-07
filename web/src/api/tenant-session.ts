import axios from 'axios';
import type {
  TenantAuthentication,
  TenantSessionOutcome,
  TenantSelection,
} from '@peanut-admin/vue';
import { getSessionSnapshot } from '@/utils/auth';

const tenantClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
  timeout: 15_000,
});

tenantClient.interceptors.response.use(undefined, (error: unknown) => {
  if (axios.isAxiosError<{ msg?: unknown }>(error)) {
    const message = error.response?.data?.msg;
    if (typeof message === 'string' && message.trim() !== '') {
      error.message = message;
    }
  }
  return Promise.reject(error);
});

let sessionCookieQueue: Promise<void> = Promise.resolve();

function queueSessionCookieRequest<T>(
  operation: () => Promise<T>,
  generation = getSessionSnapshot().generation
): Promise<T> {
  const request = sessionCookieQueue.then(() => {
    if (getSessionSnapshot().generation !== generation) {
      throw new Error('The tenant session has changed.');
    }
    return operation();
  });
  sessionCookieQueue = request.then(
    () => undefined,
    () => undefined
  );
  return request;
}

type TenantEnvelope<T> =
  | {
      data: T;
      meta: { request_id: string };
    }
  | {
      code: number;
      msg: string;
      data: null;
    };

function tenantData<T>(response: TenantEnvelope<T>): T {
  if (
    !response ||
    typeof response !== 'object' ||
    'code' in response ||
    response.data === null ||
    response.data === undefined
  ) {
    const failed = response as Partial<{ msg: string }> | null | undefined;
    throw new Error(failed?.msg || 'Tenant session returned no session data.');
  }
  return response.data;
}

export async function tenantLogin(
  email: string,
  password: string,
  generation?: number
) {
  return queueSessionCookieRequest(async () => {
    const response = await tenantClient.post<
      TenantEnvelope<TenantSessionOutcome>
    >('/adminapi/tenant/session/login', { email, password });
    return tenantData(response.data);
  }, generation);
}

export async function selectTenant(
  challengeToken: string,
  tenantId: number,
  generation?: number
) {
  return queueSessionCookieRequest(async () => {
    const response = await tenantClient.post<
      TenantEnvelope<TenantAuthentication>
    >('/adminapi/tenant/session/select', {
      challenge_token: challengeToken,
      tenant_id: tenantId,
    });
    return tenantData(response.data);
  }, generation);
}

export async function tenantSwitch(accessToken: string) {
  const response = await tenantClient.post<TenantEnvelope<TenantSelection>>(
    '/adminapi/tenant/session/switch',
    {},
    { headers: { Authorization: `Bearer ${accessToken}` } }
  );
  return tenantData(response.data);
}

export async function refreshTenantSession(
  accessToken: string,
  generation: number
) {
  return queueSessionCookieRequest(async () => {
    const response = await tenantClient.post<
      TenantEnvelope<TenantAuthentication>
    >(
      '/adminapi/tenant/session/refresh',
      {},
      { headers: { Authorization: `Bearer ${accessToken}` } }
    );
    return tenantData(response.data);
  }, generation);
}

export async function tenantLogout(accessToken: string, generation?: number) {
  return queueSessionCookieRequest(async () => {
    await tenantClient.post(
      '/adminapi/tenant/session/logout',
      {},
      { headers: { Authorization: `Bearer ${accessToken}` } }
    );
  }, generation);
}
