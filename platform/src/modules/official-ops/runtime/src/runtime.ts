import { inject, reactive } from 'vue'
import type { InjectionKey } from 'vue'
import { LOG_SEVERITIES, parseMaintenance, parseOpsStatus, parseOpsTask, parseRuntimeLogs } from './contracts'
import type { LogSeverity, MaintenanceScheduleInput, MaintenanceWindow, OpsConsoleTransport, OpsStatus, OpsTask, OpsTransportResult, RuntimeLogEntry } from './contracts'

export const OPS_CONSOLE_STORE_KEY = 'peanut.ops-console.runtime' as const
export const OPS_READ_PERMISSION = 'platform.ops.read' as const
export const OPS_BACKUP_PERMISSION = 'platform.ops.backup.manage' as const
export const OPS_RESTORE_PERMISSION = 'platform.ops.restore.manage' as const
export const OPS_MAINTENANCE_PERMISSION = 'platform.ops.maintenance.manage' as const
export const OPS_LOGS_PERMISSION = 'platform.ops.logs.read' as const

export interface OpsProviderOption { readonly key: string; readonly backup: boolean; readonly restoreTargets: readonly string[] }
export interface OpsConsoleError { readonly code: string; readonly message: string; readonly requestId: string | null; readonly status: number | null }
export interface OpsConsoleState {
  overview: OpsStatus | null; maintenance: MaintenanceWindow | null; tasks: OpsTask[]; logs: RuntimeLogEntry[]
  logSource: string; logSeverity: LogSeverity; logCursor: string | null; logNextCursor: string | null
  loading: boolean; logsLoading: boolean; mutating: boolean; error: OpsConsoleError | null; logsError: OpsConsoleError | null
}
export interface OpsConsoleRuntimeOptions {
  readonly transport: OpsConsoleTransport
  readonly providers: readonly OpsProviderOption[]
  readonly maintenanceReasons: readonly string[]
  readonly logSources: readonly string[]
  readonly canRead: () => boolean
  readonly canBackup: () => boolean
  readonly canRestore: () => boolean
  readonly canMaintain: () => boolean
  readonly canReadLogs: () => boolean
  readonly idempotencyKey?: () => string
}
export interface OpsConsoleRuntime {
  readonly state: OpsConsoleState
  readonly providers: readonly OpsProviderOption[]
  readonly maintenanceReasons: readonly string[]
  readonly logSources: readonly string[]
  readonly canBackup: () => boolean
  readonly canRestore: () => boolean
  readonly canMaintain: () => boolean
  readonly canReadLogs: () => boolean
  load: () => Promise<void>
  loadLogs: (reset?: boolean) => Promise<void>
  setLogFilter: (source: string, severity: LogSeverity) => Promise<void>
  submitBackup: (providerKey: string) => Promise<void>
  submitRestore: (providerKey: string, backupReferenceKey: string, targetKey: string) => Promise<void>
  refreshTask: (task: OpsTask) => Promise<void>
  scheduleMaintenance: (input: MaintenanceScheduleInput) => Promise<void>
  closeMaintenance: () => Promise<void>
  dispose: () => void
}

const problemMessages: Readonly<Record<string, string>> = {
  OPS_REQUEST_INVALID: 'The operations request was rejected.', OPS_PERMISSION_DENIED: 'You do not have permission for this operation.',
  OPS_PROVIDER_NOT_FOUND: 'The selected provider is unavailable.', OPS_TASK_NOT_FOUND: 'The operation task was not found.',
  OPS_IDEMPOTENCY_CONFLICT: 'This request conflicts with an earlier operation.', OPS_OPERATION_IN_PROGRESS: 'An operation is already in progress.',
  OPS_REVISION_CONFLICT: 'The maintenance window changed. Reload and try again.', OPS_RESTORE_TARGET_INVALID: 'The selected restore target is unavailable.',
  OPS_MAINTENANCE_INVALID: 'The maintenance window is invalid.', OPS_STATUS_UNAVAILABLE: 'Operations status is unavailable.',
  OPS_PROVIDER_UNAVAILABLE: 'The operation provider is unavailable.', OPS_TASK_UNAVAILABLE: 'The operation task service is unavailable.',
  OPS_LOGS_UNAVAILABLE: 'Runtime events are unavailable.', OPS_INTERNAL_ERROR: 'The operation could not be completed.',
}
const localError = (code: string, status: number | null = null): OpsConsoleError => ({
  code, message: problemMessages[code] ?? 'The operation could not be completed.', requestId: null, status,
})
const failure = (result: OpsTransportResult): OpsConsoleError => {
  const body = typeof result.body === 'object' && result.body !== null && !Array.isArray(result.body) ? result.body as Record<string, unknown> : {}
  const code = typeof body.code === 'string' && Object.hasOwn(problemMessages, body.code) ? body.code : 'OPS_INTERNAL_ERROR'
  const candidate = body.request_id ?? result.headers.get('X-Request-Id')
  return { code, message: problemMessages[code]!, requestId: typeof candidate === 'string' && /^[A-Za-z0-9._-]{1,128}$/.test(candidate) ? candidate : null, status: result.status }
}
const responseStatus = (result: OpsTransportResult, expected: readonly number[]): OpsConsoleError | null => expected.includes(result.status) ? null : failure(result)
const optionKey = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/
const backupReference = /^backup_[A-Za-z0-9_-]{8,200}$/

export const createOpsConsoleRuntime = (options: OpsConsoleRuntimeOptions): OpsConsoleRuntime => {
  const providers = options.providers.filter(provider => optionKey.test(provider.key)
    && provider.restoreTargets.every(target => optionKey.test(target) && !/(?:^|[.-])(?:active|current|primary|prod|production)(?:$|[.-])/.test(target)))
  const maintenanceReasons = options.maintenanceReasons.filter(reason => optionKey.test(reason))
  const logSources = options.logSources.filter(source => optionKey.test(source))
  const state = reactive<OpsConsoleState>({
    overview: null, maintenance: null, tasks: [], logs: [], logSource: logSources[0] ?? '', logSeverity: 'warning',
    logCursor: null, logNextCursor: null, loading: false, logsLoading: false, mutating: false, error: null, logsError: null,
  })
  const controllers = new Set<AbortController>(); let generation = 0
  let logGeneration = 0; let logController: AbortController | null = null
  const run = async <T>(operation: (signal: AbortSignal) => Promise<T>): Promise<T> => {
    const controller = new AbortController(); controllers.add(controller)
    try { return await operation(controller.signal) } finally { controllers.delete(controller) }
  }
  const key = (): string => options.idempotencyKey?.() ?? `ops-${crypto.randomUUID()}`
  const invalidateLogs = (): void => {
    logGeneration += 1; logController?.abort(); logController = null; state.logsLoading = false
    state.logs = []; state.logCursor = null; state.logNextCursor = null
  }
  const load = async (): Promise<void> => {
    const current = ++generation; state.loading = true; state.error = null
    if (!options.canRead()) { state.error = localError('OPS_PERMISSION_DENIED', 403); state.loading = false; return }
    try {
      const [statusResult, maintenanceResult] = await Promise.all([
        run(signal => options.transport.overview(signal)), run(signal => options.transport.maintenance(signal)),
      ])
      if (current !== generation) return
      const statusFailure = responseStatus(statusResult, [200]); const maintenanceFailure = responseStatus(maintenanceResult, [200])
      if (statusFailure !== null || maintenanceFailure !== null) { state.error = statusFailure ?? maintenanceFailure; return }
      state.overview = parseOpsStatus(statusResult.body); state.maintenance = parseMaintenance(maintenanceResult.body)
    } catch { if (current === generation) state.error = localError('OPS_STATUS_UNAVAILABLE') }
    finally { if (current === generation) state.loading = false }
  }
  const loadLogs = async (reset = true): Promise<void> => {
    if (reset) invalidateLogs()
    else if (state.logsLoading) return
    if (!options.canReadLogs()) { state.logsError = localError('OPS_PERMISSION_DENIED', 403); return }
    if (!logSources.includes(state.logSource)) { state.logsError = localError('OPS_REQUEST_INVALID', 400); return }
    const current = logGeneration; const source = state.logSource; const severity = state.logSeverity
    const cursor = reset ? null : state.logNextCursor
    if (!reset && cursor === null) return
    const controller = new AbortController(); logController = controller
    state.logsLoading = true; state.logsError = null
    try {
      const result = await options.transport.logs(source, severity, cursor, 50, controller.signal)
      if (current !== logGeneration || logController !== controller || source !== state.logSource || severity !== state.logSeverity
        || (!reset && cursor !== state.logNextCursor)) return
      const error = responseStatus(result, [200]); if (error !== null) { state.logsError = error; return }
      const page = parseRuntimeLogs(result.body)
      if (page.nextCursor !== null && page.nextCursor === cursor) { state.logsError = localError('OPS_LOGS_UNAVAILABLE'); return }
      state.logs = reset ? [...page.items] : [...state.logs, ...page.items]; state.logCursor = cursor; state.logNextCursor = page.nextCursor
    } catch { if (current === logGeneration && logController === controller) state.logsError = localError('OPS_LOGS_UNAVAILABLE') }
    finally {
      if (current === logGeneration && logController === controller) { logController = null; state.logsLoading = false }
    }
  }
  const mutateTask = async (permission: () => boolean, operation: (signal: AbortSignal) => Promise<OpsTransportResult>): Promise<void> => {
    if (state.mutating || !permission()) { if (!permission()) state.error = localError('OPS_PERMISSION_DENIED', 403); return }
    const current = generation; state.mutating = true; state.error = null
    try {
      const result = await run(operation); if (current !== generation) return
      const error = responseStatus(result, [200, 201, 202]); if (error !== null) { state.error = error; return }
      const task = parseOpsTask(result.body); state.tasks = [task, ...state.tasks.filter(item => item.taskKey !== task.taskKey)]
    } catch { if (current === generation) state.error = localError('OPS_TASK_UNAVAILABLE') }
    finally { if (current === generation) state.mutating = false }
  }
  const mutateMaintenance = async (operation: (signal: AbortSignal) => Promise<OpsTransportResult>): Promise<void> => {
    if (state.mutating || !options.canMaintain()) { if (!options.canMaintain()) state.error = localError('OPS_PERMISSION_DENIED', 403); return }
    const current = generation; state.mutating = true; state.error = null
    try {
      const result = await run(operation); if (current !== generation) return
      const error = responseStatus(result, [200, 201]); if (error !== null) { state.error = error; return }
      state.maintenance = parseMaintenance(result.body)
    } catch { if (current === generation) state.error = localError('OPS_INTERNAL_ERROR') }
    finally { if (current === generation) state.mutating = false }
  }
  return {
    state, providers, maintenanceReasons, logSources,
    canBackup: options.canBackup, canRestore: options.canRestore, canMaintain: options.canMaintain, canReadLogs: options.canReadLogs,
    load, loadLogs,
    async setLogFilter(source, severity) {
      if (!logSources.includes(source) || !LOG_SEVERITIES.includes(severity)) { state.logsError = localError('OPS_REQUEST_INVALID', 400); return }
      state.logSource = source; state.logSeverity = severity; await loadLogs(true)
    },
    submitBackup(providerKey) {
      const provider = providers.find(item => item.key === providerKey && item.backup)
      if (provider === undefined) { state.error = localError('OPS_PROVIDER_NOT_FOUND', 404); return Promise.resolve() }
      return mutateTask(options.canBackup, signal => options.transport.submitBackup(provider.key, key(), signal))
    },
    submitRestore(providerKey, backupReferenceKey, targetKey) {
      const provider = providers.find(item => item.key === providerKey)
      if (provider === undefined || !provider.restoreTargets.includes(targetKey) || !backupReference.test(backupReferenceKey)) { state.error = localError('OPS_RESTORE_TARGET_INVALID', 422); return Promise.resolve() }
      return mutateTask(options.canRestore, signal => options.transport.submitRestore(provider.key, backupReferenceKey, targetKey, key(), signal))
    },
    async refreshTask(task) {
      if (!options.canRead()) { state.error = localError('OPS_PERMISSION_DENIED', 403); return }
      const current = generation
      try {
        const result = await run(signal => options.transport.task(task.taskKey, signal)); if (current !== generation) return
        const error = responseStatus(result, [200]); if (error !== null) { state.error = error; return }
        const refreshed = parseOpsTask(result.body); state.tasks = state.tasks.map(item => item.taskKey === refreshed.taskKey ? refreshed : item)
      } catch { if (current === generation) state.error = localError('OPS_TASK_UNAVAILABLE') }
    },
    scheduleMaintenance(input) {
      if (!maintenanceReasons.includes(input.reasonKey)) { state.error = localError('OPS_MAINTENANCE_INVALID', 422); return Promise.resolve() }
      const expected = state.maintenance?.revision ?? 0
      return mutateMaintenance(signal => options.transport.scheduleMaintenance(input, expected, key(), signal))
    },
    closeMaintenance() {
      const window = state.maintenance
      if (window === null || window.state === 'closed') { state.error = localError('OPS_MAINTENANCE_INVALID', 422); return Promise.resolve() }
      return mutateMaintenance(signal => options.transport.closeMaintenance(window.maintenanceKey, window.revision, key(), signal))
    },
    dispose() {
      generation += 1; invalidateLogs(); for (const controller of controllers) controller.abort(); controllers.clear()
      state.overview = null; state.maintenance = null; state.tasks = []; state.logs = []; state.logCursor = null; state.logNextCursor = null
      state.loading = false; state.logsLoading = false; state.mutating = false; state.error = null; state.logsError = null
    },
  }
}

export const opsConsoleRuntimeKey: InjectionKey<OpsConsoleRuntime> = Symbol(OPS_CONSOLE_STORE_KEY)
export const useOpsConsoleRuntime = (): OpsConsoleRuntime => {
  const runtime = inject(opsConsoleRuntimeKey); if (runtime === undefined) throw new Error('OPS_CONSOLE_RUNTIME_MISSING'); return runtime
}
