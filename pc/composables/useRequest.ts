import {
  createClient,
  type ClientDecodeResult,
  type ClientTransportRequest,
} from '@peanut-admin/client';
import {
  createNuxtClientTransport,
  createNuxtSsrForwardHeaders,
} from '@peanut-admin/nuxt';

interface ApiResponse<T = unknown> {
  code: number;
  msg: string;
  data: T;
}

const isApiResponse = (value: unknown): value is ApiResponse =>
  typeof value === 'object' &&
  value !== null &&
  'code' in value &&
  'data' in value;

const decodeApiResponse = <T>(
  response: unknown,
  _request: ClientTransportRequest
): ClientDecodeResult<T> => {
  if (!isApiResponse(response)) {
    return {
      kind: 'business',
      code: 'API_RESPONSE_INVALID',
      message: '响应格式无效',
    };
  }
  if (response.code === 20000)
    return { kind: 'success', data: response.data as T };
  if (response.code === 40100) {
    return {
      kind: 'unauthorized',
      code: String(response.code),
      message: response.msg,
    };
  }
  return {
    kind: 'business',
    code: String(response.code),
    message: response.msg,
  };
};

export function useRequest() {
  const runtimeConfig = useRuntimeConfig();
  const configuredBaseUrl = String(runtimeConfig.public.apiBase || '');
  let baseUrl: string;
  let forwardHeaders: Readonly<Record<string, string>> | undefined;

  if (import.meta.server) {
    const requestHeaders = useRequestHeaders(['host']);
    const trustedHosts = String(runtimeConfig.trustedHosts || '')
      .split(',')
      .map((host) => host.trim())
      .filter(Boolean);
    baseUrl = String(runtimeConfig.upstreamOrigin || '');
    forwardHeaders = createNuxtSsrForwardHeaders({
      requestHost: requestHeaders.host || '',
      cookie: undefined,
      forwardedProto:
        runtimeConfig.forwardedProto === 'https' ? 'https' : 'http',
      trustedHosts,
    });
  } else {
    baseUrl = configuredBaseUrl || window.location.origin;
  }
  const client = createClient({
    transport: createNuxtClientTransport({
      baseUrl,
      forwardHeaders,
      $fetch: async (url, options) => {
        try {
          return await $fetch(
            url,
            options as unknown as Parameters<typeof $fetch>[1]
          );
        } catch (error) {
          const data =
            typeof error === 'object' && error !== null && 'data' in error
              ? error.data
              : undefined;
          if (isApiResponse(data)) return data;
          throw error;
        }
      },
    }),
    session: {
      accessToken: () =>
        import.meta.client ? useUserStore().token : undefined,
      clear: () => {
        if (import.meta.client) useUserStore().clearSession();
      },
    },
    decoder: decodeApiResponse,
    hooks: {
      unauthorized: async () => {
        await navigateTo('/login');
      },
      businessError: (error) => {
        if (import.meta.client) ElMessage.error(error.message || '请求失败');
      },
    },
  });

  return {
    get: <T = unknown>(
      url: string,
      params?: Record<string, unknown>,
      auth = true
    ) => client.request<T>({ path: url, method: 'GET', data: params, auth }),
    post: <T = unknown>(
      url: string,
      body?: Record<string, unknown> | null,
      auth = true
    ) =>
      client.request<T>({
        path: url,
        method: 'POST',
        data: body ?? undefined,
        auth,
      }),
  };
}
