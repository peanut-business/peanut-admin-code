import { defineAdminModule } from '@peanut-admin/vue'
import { inject, reactive } from 'vue'
import type { AdminModuleContribution } from '@peanut-admin/vue'
import type { InjectionKey } from 'vue'

import { parseBulkResult, parseNotificationList, parseNotificationResponse } from './contracts'
import type {
  NotificationBulkAction,
  NotificationFilter,
  NotificationMessage,
  NotificationTransport,
  NotificationTransportResult,
} from './contracts'

export const NOTIFICATION_SMS_MODULE_KEY = 'official.notification' as const
export const NOTIFICATION_SMS_ROUTE_NAME = 'official.notification.inbox' as const
export const NOTIFICATION_SMS_ROUTE_PATH = '/notice/inbox' as const
export const NOTIFICATION_SMS_READ_PERMISSION = 'official.notification.inbox.read' as const
export const NOTIFICATION_SMS_MANAGE_PERMISSION = 'official.notification.inbox.manage' as const
export const NOTIFICATION_SMS_STORE_KEY = 'official.notification.inbox.runtime' as const

export interface NotificationError { readonly message: string; readonly requestId: string | null; readonly status: number | null }
export interface NotificationState {
  items: NotificationMessage[]
  status: NotificationFilter
  page: number
  pageSize: number
  total: number
  selected: Set<string>
  loading: boolean
  mutating: boolean
  error: NotificationError | null
}
export interface NotificationRuntime {
  readonly state: NotificationState
  readonly canManage: () => boolean
  load: () => Promise<void>
  setStatus: (status: NotificationFilter) => Promise<void>
  toggle: (messageKey: string) => void
  markRead: (message: NotificationMessage) => Promise<void>
  bulk: (action: NotificationBulkAction) => Promise<void>
  dispose: () => void
}
export interface NotificationRuntimeOptions {
  readonly transport: NotificationTransport
  readonly canRead: () => boolean
  readonly canManage?: () => boolean
}

const failure = (result: NotificationTransportResult): NotificationError => {
  const body = typeof result.body === 'object' && result.body !== null && !Array.isArray(result.body)
    ? result.body as Record<string, unknown> : {}
  const requestId = body.request_id ?? result.headers.get('X-Request-Id')
  return {
    message: typeof body.detail === 'string' && body.detail !== '' ? body.detail : `Notification request failed (${result.status}).`,
    requestId: typeof requestId === 'string' && requestId !== '' ? requestId : null,
    status: result.status,
  }
}

export const createNotificationRuntime = (options: NotificationRuntimeOptions): NotificationRuntime => {
  const canManage = options.canManage ?? options.canRead
  const state = reactive<NotificationState>({
    items: [], status: 'all', page: 1, pageSize: 20, total: 0, selected: new Set(),
    loading: false, mutating: false, error: null,
  })
  const controllers = new Set<AbortController>()
  let generation = 0
  const run = async <T>(operation: (signal: AbortSignal) => Promise<T>): Promise<T> => {
    const controller = new AbortController(); controllers.add(controller)
    try { return await operation(controller.signal) } finally { controllers.delete(controller) }
  }
  const load = async (): Promise<void> => {
    const current = ++generation; state.loading = true; state.error = null
    try {
      if (!options.canRead()) throw new Error('NOTIFICATION_PERMISSION_DENIED')
      const result = await run(signal => options.transport.list(state.status, state.page, state.pageSize, signal))
      if (current !== generation) return
      if (result.status !== 200) { state.error = failure(result); return }
      const list = parseNotificationList(result.body)
      state.items = [...list.items]; state.page = list.page; state.pageSize = list.pageSize; state.total = list.total
      state.selected.clear()
    } catch {
      if (current === generation) state.error = { message: 'The notification service could not be reached.', requestId: null, status: null }
    } finally { if (current === generation) state.loading = false }
  }
  const mutate = async (operation: (signal: AbortSignal) => Promise<NotificationTransportResult>, parser: (body: unknown) => unknown): Promise<void> => {
    if (state.mutating || !canManage()) return
    const current = generation
    state.mutating = true; state.error = null
    try {
      const result = await run(operation)
      if (current !== generation) return
      if (result.status !== 200) { state.error = failure(result); return }
      parser(result.body)
      if (current !== generation) return
      state.mutating = false
      await load()
    } catch {
      if (current === generation) state.error = { message: 'The notification action could not be completed.', requestId: null, status: null }
    } finally { if (current === generation) state.mutating = false }
  }
  return {
    state,
    canManage,
    load,
    async setStatus(status) { state.status = status; state.page = 1; await load() },
    toggle(messageKey) {
      if (state.selected.has(messageKey)) state.selected.delete(messageKey)
      else if (state.selected.size < 100) state.selected.add(messageKey)
    },
    markRead(message) {
      if (message.status !== 'unread') return Promise.resolve()
      return mutate(signal => options.transport.markRead(message.messageKey, message.revision, signal), parseNotificationResponse)
    },
    bulk(action) {
      const keys = [...state.selected]
      if (keys.length === 0) return Promise.resolve()
      return mutate(signal => options.transport.bulk(keys, action, signal), parseBulkResult)
    },
    dispose() {
      generation += 1
      for (const controller of controllers) controller.abort()
      controllers.clear()
      state.items = []; state.status = 'all'; state.page = 1; state.pageSize = 20; state.total = 0
      state.selected.clear(); state.loading = false; state.mutating = false; state.error = null
    },
  }
}

export const notificationRuntimeKey: InjectionKey<NotificationRuntime> = Symbol(NOTIFICATION_SMS_STORE_KEY)
export const useNotificationRuntime = (): NotificationRuntime => {
  const runtime = inject(notificationRuntimeKey)
  if (runtime === undefined) throw new Error('NOTIFICATION_RUNTIME_MISSING')
  return runtime
}
export const createNotificationModuleContribution = (runtime: NotificationRuntime): AdminModuleContribution => defineAdminModule({
  key: NOTIFICATION_SMS_MODULE_KEY,
  routes: [{
    name: NOTIFICATION_SMS_ROUTE_NAME,
    path: NOTIFICATION_SMS_ROUTE_PATH,
    component: async () => ({ default: (await import('./NotificationInboxPage.vue')).default }),
    access: { moduleKey: NOTIFICATION_SMS_MODULE_KEY, permissionKeys: [NOTIFICATION_SMS_READ_PERMISSION] },
  }],
  disposeOnTenantChange: true,
  stores: [{ key: NOTIFICATION_SMS_STORE_KEY, dispose: runtime.dispose }],
})
