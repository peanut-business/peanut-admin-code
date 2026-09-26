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

type FetchMethod =
  | 'GET'
  | 'HEAD'
  | 'POST'
  | 'PUT'
  | 'DELETE'
  | 'PATCH'
  | 'OPTIONS'
  | 'CONNECT'
  | 'TRACE';

const isFetchMethod = (value: unknown): value is FetchMethod => {
  switch (value) {
    case 'GET':
    case 'HEAD':
    case 'POST':
    case 'PUT':
    case 'DELETE':
    case 'PATCH':
    case 'OPTIONS':
    case 'CONNECT':
    case 'TRACE':
      return true;
    default:
      return false;
  }
};

// This composable exposes record-shaped GET queries and POST documents, not arbitrary fetch bodies.
const isFetchRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

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
        const method = options?.method;
        const query = options?.query;
        const body = options?.body;
        if (method !== undefined && !isFetchMethod(method)) {
          throw new Error('PC_FETCH_METHOD_INVALID');
        }
        if (query !== undefined && !isFetchRecord(query)) {
          throw new Error('PC_FETCH_QUERY_INVALID');
        }
        if (body !== undefined && body !== null && !isFetchRecord(body)) {
          throw new Error('PC_FETCH_BODY_INVALID');
        }
        try {
          return await $fetch<unknown>(
            url,
            options === undefined
              ? undefined
              : {
                  method,
                  query,
                  body,
                  headers: options.headers,
                }
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
