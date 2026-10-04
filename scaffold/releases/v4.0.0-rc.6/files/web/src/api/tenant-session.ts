import axios from 'axios';
import type {
  TenantAuthentication,
  TenantSessionOutcome,
  TenantSelection,
} from '@peanut-admin/vue';

const tenantClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
});

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

export async function tenantLogin(email: string, password: string) {
  const response = await tenantClient.post<
    TenantEnvelope<TenantSessionOutcome>
  >('/adminapi/tenant/session/login', { email, password });
  return tenantData(response.data);
}

export async function selectTenant(challengeToken: string, tenantId: number) {
  const response = await tenantClient.post<
    TenantEnvelope<TenantAuthentication>
  >('/adminapi/tenant/session/select', {
    challenge_token: challengeToken,
    tenant_id: tenantId,
  });
  return tenantData(response.data);
}

export async function tenantSwitch(accessToken: string) {
  const response = await tenantClient.post<TenantEnvelope<TenantSelection>>(
    '/adminapi/tenant/session/switch',
    {},
    { headers: { Authorization: `Bearer ${accessToken}` } }
  );
  return tenantData(response.data);
}

export async function refreshTenantSession() {
  const response = await tenantClient.post<
    TenantEnvelope<TenantAuthentication>
  >('/adminapi/tenant/session/refresh');
  return tenantData(response.data);
}

export async function tenantLogout(accessToken: string) {
  await tenantClient.post(
    '/adminapi/tenant/session/logout',
    {},
    { headers: { Authorization: `Bearer ${accessToken}` } }
  );
}
