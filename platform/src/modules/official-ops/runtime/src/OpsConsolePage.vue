<script setup lang="ts">
  import {
    EmptyState,
    ForbiddenState,
    ModuleUnavailableState,
    PageContent,
    PageHeader,
    SessionExpiredState,
  } from '@peanut-admin/ui-vue';
  import {
    ElButton,
    ElDatePicker,
    ElInput,
    ElTabPane,
    ElTabs,
  } from 'element-plus';
  import { computed, onMounted, ref } from 'vue';
  import { LOG_SEVERITIES } from './contracts';
  import { useOpsConsoleRuntime } from './runtime';

  const runtime = useOpsConsoleRuntime();
  const state = runtime.state;
  const providerKey = ref(runtime.providers[0]?.key ?? '');
  const backupReferenceKey = ref('');
  const restoreTargetKey = ref('');
  const reasonKey = ref(runtime.maintenanceReasons[0] ?? '');
  const startsAt = ref('');
  const endsAt = ref('');
  const draftLogSource = ref(state.logSource);
  const draftLogSeverity = ref(state.logSeverity);
  const targets = computed(
    () =>
      runtime.providers.find((provider) => provider.key === providerKey.value)
        ?.restoreTargets ?? []
  );
  const activeMaintenance = computed(
    () => state.maintenance !== null && state.maintenance.state !== 'closed'
  );
  const chooseProvider = (): void => {
    restoreTargetKey.value = targets.value[0] ?? '';
  };
  const schedule = (): Promise<void> =>
    runtime.scheduleMaintenance({
      reasonKey: reasonKey.value,
      startsAt: startsAt.value,
      endsAt: endsAt.value,
    });
  const applyLogFilter = (): Promise<void> =>
    runtime.setLogFilter(draftLogSource.value, draftLogSeverity.value);

  onMounted(async () => {
    chooseProvider();
    await runtime.load();
    if (runtime.canReadLogs() && runtime.logSources.length > 0)
      await runtime.loadLogs();
  });
</script>

<template>
  <PageContent class="ops-console-page">
    <PageHeader>
      Operations
      <template #actions>
        <ElButton
          :loading="state.loading"
          :disabled="state.mutating"
          @click="runtime.load"
        >
          Reload
        </ElButton>
      </template>
    </PageHeader>

    <SessionExpiredState
      v-if="state.error?.status === 401"
      :message="state.error.message"
    />
    <ForbiddenState
      v-else-if="state.error?.status === 403 && state.overview === null"
      :message="state.error.message"
    />
    <ModuleUnavailableState
      v-else-if="state.error?.status === 503 && state.overview === null"
      :message="state.error.message"
      @action="runtime.load"
    />
    <section
      v-else-if="state.error && state.overview === null"
      role="alert"
      class="ops-state"
    >
      <h2>Unable to load operations</h2><p>{{ state.error.message }}</p
      ><p v-if="state.error.requestId">
        Request ID: {{ state.error.requestId }}
      </p>
    </section>
    <div
      v-else-if="state.loading && state.overview === null"
      class="ops-state"
      role="status"
    >
      Loading operations...
    </div>

    <ElTabs v-else-if="state.overview !== null" class="ops-tabs">
      <ElTabPane label="Overview">
        <section class="ops-section" aria-labelledby="ops-health-heading">
          <h2 id="ops-health-heading"> Runtime evidence </h2>
          <dl class="ops-facts">
            <div
              ><dt>Health</dt><dd>{{ state.overview.health.status }}</dd></div
            >
            <div>
              <dt>Commit</dt
              ><dd class="mono">
                {{ state.overview.version.commit }}
              </dd>
            </div>
            <div>
              <dt>Tree</dt
              ><dd class="mono">
                {{ state.overview.version.tree }}
              </dd>
            </div>
            <div
              ><dt>Built</dt><dd>{{ state.overview.version.builtAt }}</dd></div
            >
            <div
              ><dt>Migrations</dt
              ><dd
                >{{ state.overview.migrations.applied }} /
                {{ state.overview.migrations.target }}</dd
              ></div
            >
            <div
              ><dt>Upgrade</dt
              ><dd
                >{{ state.overview.upgrade.state }} ({{
                  state.overview.upgrade.code
                }})</dd
              ></div
            >
          </dl>
          <div class="table-wrap">
            <table>
              <thead
                ><tr
                  ><th>Check</th><th>Status</th><th>Critical</th
                  ><th>Latency</th></tr
                ></thead
              >
              <tbody>
                <tr
                  v-for="check in state.overview.health.checks"
                  :key="check.key"
                >
                  <td>{{ check.key }}</td
                  ><td>{{ check.status }}</td
                  ><td>{{ check.critical ? 'yes' : 'no' }}</td
                  ><td>{{ check.latencyMs }} ms</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </ElTabPane>

      <ElTabPane label="Recovery">
        <section class="ops-section" aria-labelledby="ops-backup-heading">
          <h2 id="ops-backup-heading"> Backup and restore verification </h2>
          <div class="ops-controls">
            <label
              >Provider<select
                v-model="providerKey"
                :disabled="state.mutating"
                @change="chooseProvider"
                ><option
                  v-for="provider in runtime.providers"
                  :key="provider.key"
                  :value="provider.key"
                  >{{ provider.key }}</option
                ></select
              ></label
            >
            <ElButton
              type="primary"
              :disabled="
                !runtime.canBackup() ||
                state.mutating ||
                !runtime.providers.find(
                  (provider) => provider.key === providerKey
                )?.backup
              "
              @click="runtime.submitBackup(providerKey)"
            >
              Create backup
            </ElButton>
          </div>
          <div class="ops-controls">
            <label
              >Backup reference<ElInput
                v-model="backupReferenceKey"
                :disabled="state.mutating"
            /></label>
            <label
              >New target<select
                v-model="restoreTargetKey"
                :disabled="state.mutating"
                ><option
                  v-for="target in targets"
                  :key="target"
                  :value="target"
                  >{{ target }}</option
                ></select
              ></label
            >
            <ElButton
              :disabled="
                !runtime.canRestore() ||
                state.mutating ||
                restoreTargetKey === ''
              "
              @click="
                runtime.submitRestore(
                  providerKey,
                  backupReferenceKey,
                  restoreTargetKey
                )
              "
            >
              Restore and verify
            </ElButton>
          </div>
          <section v-if="state.error" role="alert" class="inline-error">
            <p>{{ state.error.message }}</p
            ><p v-if="state.error.requestId">
              Request ID: {{ state.error.requestId }}
            </p>
          </section>
          <EmptyState
            v-if="state.tasks.length === 0"
            title="No operation tasks"
            message="No backup or restore-verification tasks were submitted in this session."
          />
          <div v-else class="table-wrap">
            <table>
              <thead
                ><tr
                  ><th>Type</th><th>Status</th><th>Attempts</th><th>Updated</th
                  ><th>Action</th></tr
                ></thead
              >
              <tbody>
                <tr v-for="task in state.tasks" :key="task.taskKey">
                  <td>{{ task.taskType }}</td
                  ><td>{{ task.status }}</td
                  ><td>{{ task.attemptCount }} / {{ task.maxAttempts }}</td
                  ><td>{{ task.updatedAt }}</td
                  ><td>
                    <ElButton text @click="runtime.refreshTask(task)">
                      Refresh
                    </ElButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </ElTabPane>

      <ElTabPane label="Maintenance">
        <section class="ops-section" aria-labelledby="ops-maintenance-heading">
          <h2 id="ops-maintenance-heading"> Maintenance window </h2>
          <dl v-if="state.maintenance" class="ops-facts">
            <div
              ><dt>State</dt><dd>{{ state.maintenance.state }}</dd></div
            ><div
              ><dt>Reason</dt><dd>{{ state.maintenance.reasonKey }}</dd></div
            ><div
              ><dt>Starts</dt><dd>{{ state.maintenance.startsAt }}</dd></div
            ><div
              ><dt>Ends</dt><dd>{{ state.maintenance.endsAt }}</dd></div
            ><div
              ><dt>Revision</dt><dd>{{ state.maintenance.revision }}</dd></div
            >
          </dl>
          <div class="ops-controls">
            <label
              >Reason<select v-model="reasonKey" :disabled="state.mutating"
                ><option
                  v-for="reason in runtime.maintenanceReasons"
                  :key="reason"
                  :value="reason"
                  >{{ reason }}</option
                ></select
              ></label
            >
            <label
              >Starts<ElDatePicker
                v-model="startsAt"
                type="datetime"
                value-format="YYYY-MM-DDTHH:mm:ss.SSS[Z]"
            /></label>
            <label
              >Ends<ElDatePicker
                v-model="endsAt"
                type="datetime"
                value-format="YYYY-MM-DDTHH:mm:ss.SSS[Z]"
            /></label>
            <ElButton
              type="primary"
              :disabled="
                !runtime.canMaintain() ||
                state.mutating ||
                reasonKey === '' ||
                startsAt === '' ||
                endsAt === ''
              "
              @click="schedule"
            >
              {{ state.maintenance === null ? 'Schedule' : 'Replace' }}
            </ElButton>
            <ElButton
              :disabled="
                !runtime.canMaintain() || state.mutating || !activeMaintenance
              "
              @click="runtime.closeMaintenance"
            >
              Close
            </ElButton>
          </div>
        </section>
      </ElTabPane>

      <ElTabPane label="Runtime events">
        <section class="ops-section" aria-labelledby="ops-logs-heading">
          <h2 id="ops-logs-heading"> Structured runtime events </h2>
          <ForbiddenState
            v-if="!runtime.canReadLogs()"
            message="You do not have permission to read runtime events."
          />
          <template v-else>
            <div class="ops-controls">
              <label
                >Source<select v-model="draftLogSource" aria-label="Source"
                  ><option
                    v-for="source in runtime.logSources"
                    :key="source"
                    :value="source"
                    >{{ source }}</option
                  ></select
                ></label
              ><label
                >Severity<select
                  v-model="draftLogSeverity"
                  aria-label="Severity"
                  ><option
                    v-for="severity in LOG_SEVERITIES"
                    :key="severity"
                    :value="severity"
                    >{{ severity }}</option
                  ></select
                ></label
              ><ElButton :loading="state.logsLoading" @click="applyLogFilter">
                Apply
              </ElButton>
            </div>
            <section v-if="state.logsError" role="alert" class="inline-error">
              <p>{{ state.logsError.message }}</p
              ><p v-if="state.logsError.requestId">
                Request ID: {{ state.logsError.requestId }}
              </p>
            </section>
            <EmptyState
              v-else-if="state.logs.length === 0 && !state.logsLoading"
              title="No runtime events"
              message="No structured events match this filter."
            />
            <div v-else class="table-wrap">
              <table>
                <thead
                  ><tr
                    ><th>Time</th><th>Severity</th><th>Component</th
                    ><th>Event</th><th>Occurrences</th></tr
                  ></thead
                ><tbody>
                  <tr
                    v-for="entry in state.logs"
                    :key="`${entry.eventKey}-${entry.occurredAt}`"
                  >
                    <td>{{ entry.occurredAt }}</td
                    ><td>{{ entry.severity }}</td
                    ><td>{{ entry.componentKey }}</td
                    ><td>{{ entry.message }}</td
                    ><td>{{ entry.occurrences }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <ElButton
              v-if="state.logNextCursor"
              :loading="state.logsLoading"
              @click="runtime.loadLogs(false)"
            >
              Load more
            </ElButton>
          </template>
        </section>
      </ElTabPane>
    </ElTabs>
  </PageContent>
</template>

<style scoped>
  .ops-state,
  .ops-section {
    padding: 20px 0;
  }
  .ops-section h2 {
    margin: 0 0 16px;
    font-size: 20px;
    letter-spacing: 0;
  }
  .ops-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px 24px;
    margin: 0 0 20px;
  }
  .ops-facts div {
    min-width: 0;
  }
  .ops-facts dt {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .ops-facts dd {
    margin: 4px 0 0;
    overflow-wrap: anywhere;
  }
  .ops-controls {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .ops-controls label {
    display: grid;
    min-width: 180px;
    gap: 6px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .ops-controls select {
    height: 32px;
    padding: 0 28px 0 10px;
    border: 1px solid var(--el-border-color);
    border-radius: 4px;
    background: var(--el-bg-color);
    color: var(--el-text-color-primary);
  }
  .inline-error {
    padding: 12px 0;
    color: var(--el-color-danger);
  }
  .inline-error p {
    margin: 0 0 4px;
  }
  .table-wrap {
    width: 100%;
    overflow-x: auto;
    margin-bottom: 16px;
  }
  table {
    width: 100%;
    min-width: 680px;
    border-collapse: collapse;
  }
  th,
  td {
    padding: 10px 8px;
    border-bottom: 1px solid var(--el-border-color);
    text-align: left;
  }
  th {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
  @media (max-width: 640px) {
    .ops-controls {
      align-items: stretch;
      flex-direction: column;
    }
    .ops-controls label {
      width: 100%;
    }
    .ops-controls :deep(.el-button) {
      width: 100%;
    }
  }
</style>
