import {
  ClientRequestError,
  createClient,
  type ClientDecodeResult,
} from '@peanut-admin/client';
import { createUniAppClientTransport } from '@peanut-admin/uniapp';
import { useUserStore } from '@/store/user';

const configuredBaseUrl = import.meta.env.VITE_APP_BASE_URL || '';

interface RuntimeLocation {
  origin?: unknown;
}

interface RuntimeGlobal {
  location?: RuntimeLocation;
}

interface RequestOptions {
  url: string;
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  data?: Record<string, any>;
  header?: Record<string, string>;
  /** skip auth check — for login/register/public routes */
  auth?: boolean;
}

interface ApiResponse<T = unknown> {
  code: number;
  msg: string;
  data: T;
}

function baseUrl(): string {
  if (configuredBaseUrl) return configuredBaseUrl;
  const origin = (globalThis as unknown as RuntimeGlobal).location?.origin;
  return typeof origin === 'string' ? origin : '';
}

function decodeResponse<T>(response: unknown): ClientDecodeResult<T> {
  const result = response as Partial<ApiResponse<T>>;
  if (result.code === 20000) {
    return { kind: 'success', data: result.data as T };
  }
  if (result.code === 40100) {
    return {
      kind: 'unauthorized',
      code: 'AUTH_REQUIRED',
      message: result.msg || '请先登录',
    };
  }
  return {
    kind: 'business',
    code: 'BUSINESS_REJECTED',
    message: result.msg || '请求失败',
  };
}

const transport = createUniAppClientTransport({
  baseUrl: baseUrl(),
  request: (options) => {
    uni.request({
      url: options.url,
      method: options.method as UniNamespace.RequestOptions['method'],
      data: options.data as UniNamespace.RequestOptions['data'],
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
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  get<T = unknown>(url: string, data?: Record<string, any>, auth = true) {
    return request<T>({ url, method: 'GET', data, auth });
  },
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  post<T = unknown>(url: string, data?: Record<string, any>, auth = true) {
    return request<T>({ url, method: 'POST', data, auth });
  },
};
