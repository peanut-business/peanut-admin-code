import {
  createClient,
  type ClientDecodeResult,
  type ClientTransportRequest,
} from '@peanut-admin/client'
import { createNuxtClientTransport } from '@peanut-admin/nuxt'

interface ApiResponse<T = unknown> {
  code: number
  msg: string
  data: T
}

const isApiResponse = (value: unknown): value is ApiResponse => (
  typeof value === 'object'
  && value !== null
  && 'code' in value
  && 'data' in value
)

const decodeApiResponse = <T>(
  response: unknown,
  _request: ClientTransportRequest,
): ClientDecodeResult<T> => {
  if (!isApiResponse(response)) {
    return { kind: 'business', code: 'API_RESPONSE_INVALID', message: '响应格式无效' }
  }
  if (response.code === 20000) return { kind: 'success', data: response.data as T }
  if (response.code === 40100) {
    return { kind: 'unauthorized', code: String(response.code), message: response.msg }
  }
  return { kind: 'business', code: String(response.code), message: response.msg }
}

export function useRequest() {
  const configuredBaseUrl = String(useRuntimeConfig().public.apiBase || '')
  const baseUrl = configuredBaseUrl || (import.meta.client ? window.location.origin : 'http://localhost')
  const userStore = useUserStore()

  const client = createClient({
    transport: createNuxtClientTransport({
      baseUrl,
      $fetch: async (url, options) => {
        try {
          return await $fetch(
            url,
            options as unknown as Parameters<typeof $fetch>[1],
          )
        } catch (error) {
          const data = typeof error === 'object' && error !== null && 'data' in error
            ? error.data
            : undefined
          if (isApiResponse(data)) return data
          throw error
        }
      },
    }),
    session: {
      accessToken: () => userStore.token,
      clear: () => userStore.logout(),
    },
    decoder: decodeApiResponse,
    hooks: {
      unauthorized: async () => {
        await navigateTo('/login')
      },
      businessError: (error) => {
        ElMessage.error(error.message || '请求失败')
      },
    },
  })

  return {
    get: <T = unknown>(url: string, params?: Record<string, unknown>, auth = true) =>
      client.request<T>({ path: url, method: 'GET', data: params, auth }),
    post: <T = unknown>(url: string, body?: Record<string, unknown> | null, auth = true) =>
      client.request<T>({ path: url, method: 'POST', data: body ?? undefined, auth }),
  }
}
