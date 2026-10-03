import {
  ClientRequestError,
  createClient,
  type ClientDecodeResult,
} from '@peanut-admin/client';
import { createUniAppClientTransport } from '@peanut-admin/uniapp';
import { useUserStore } from '@/store/user';

const configuredBaseUrl = import.meta.env.VITE_APP_BASE_URL || '';

interface RequestOptions {
  url: string;
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  data?: Record<string, unknown>;
  header?: Record<string, string>;
  /** skip auth check — for login/register/public routes */
  auth?: boolean;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isRequestMethod(
  value: string
): value is NonNullable<UniNamespace.RequestOptions['method']> {
  switch (value) {
    case 'OPTIONS':
    case 'GET':
    case 'HEAD':
    case 'POST':
    case 'PUT':
    case 'DELETE':
    case 'TRACE':
    case 'CONNECT':
      return true;
    default:
      return false;
  }
}

function baseUrl(): string {
  if (configuredBaseUrl) return configuredBaseUrl;
  const runtime: unknown = globalThis;
  if (!isRecord(runtime) || !isRecord(runtime.location)) return '';
  const { origin } = runtime.location;
  return typeof origin === 'string' ? origin : '';
}

function decodeResponse(response: unknown): ClientDecodeResult {
  if (
    !isRecord(response) ||
    typeof response.code !== 'number' ||
    !Number.isFinite(response.code) ||
    (response.msg !== undefined && typeof response.msg !== 'string') ||
    (response.code === 20000 && !('data' in response))
  ) {
    return {
      kind: 'business',
      code: 'API_RESPONSE_INVALID',
      message: '响应格式无效',
    };
  }
  // Only the shared envelope is validated here; endpoint-specific data remains unknown.
  if (response.code === 20000) {
    return { kind: 'success', data: response.data };
  }
  if (response.code === 40100) {
    return {
      kind: 'unauthorized',
      code: 'AUTH_REQUIRED',
      message: response.msg || '请先登录',
    };
  }
  return {
    kind: 'business',
    code: 'BUSINESS_REJECTED',
    message: response.msg || '请求失败',
  };
}

const transport = createUniAppClientTransport({
  baseUrl: baseUrl(),
  request: (options) => {
    if (!isRequestMethod(options.method)) {
      throw new Error('UNIAPP_REQUEST_METHOD_INVALID');
    }
    if (options.data !== undefined && !isRecord(options.data)) {
      throw new Error('UNIAPP_REQUEST_DATA_INVALID');
    }
    uni.request({
      url: options.url,
      method: options.method,
      data: options.data,
      header: options.header,
      success: (response) => options.success?.({ data: response.data }),
      fail: options.fail,
    });
  },
});

const client = createClient({
  transport,
  session: {
    accessToken: () => useUserStore().token,
    clear: () => useUserStore().logout(),
  },
  decoder: decodeResponse,
  hooks: {
    unauthorized: () => uni.reLaunch({ url: '/pages/login/login' }),
    businessError: (error) =>
      uni.showToast({ title: error.message || '请求失败', icon: 'none' }),
  },
});

async function request<T = unknown>(options: RequestOptions): Promise<T> {
  try {
    return await client.request<T>({
      path: options.url,
      method: options.method,
      data: options.data,
      headers: {
        'Content-Type': 'application/json',
        ...options.header,
      },
      auth: options.auth,
    });
  } catch (error) {
    if (error instanceof ClientRequestError && error.kind === 'transport') {
      uni.showToast({ title: '网络错误，请稍后重试', icon: 'none' });
    }
    throw error;
  }
}

export const http = {
  get<T = unknown>(url: string, data?: Record<string, unknown>, auth = true) {
    return request<T>({ url, method: 'GET', data, auth });
  },
  post<T = unknown>(url: string, data?: Record<string, unknown>, auth = true) {
    return request<T>({ url, method: 'POST', data, auth });
  },
};
