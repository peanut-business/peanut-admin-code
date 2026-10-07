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
  import type {
    HealthStatus,
    LogSeverity,
    MaintenanceState,
    OpsTaskStatus,
    UpgradeState,
  } from './contracts';
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
  const healthLabels: Record<HealthStatus, string> = {
    healthy: '健康',
    degraded: '降级',
    unhealthy: '异常',
  };
  const upgradeLabels: Record<UpgradeState, string> = {
    configuration_required: '需要配置',
    blocked: '已阻断',
    ready: '就绪',
    running: '运行中',
    succeeded: '成功',
    failed: '失败',
  };
  const taskLabels: Record<OpsTaskStatus, string> = {
    queued: '排队中',
    running: '运行中',
    succeeded: '成功',
    dead: '失败终止',
    cancelled: '已取消',
  };
  const maintenanceLabels: Record<MaintenanceState, string> = {
    scheduled: '已安排',
    active: '进行中',
    closed: '已关闭',
  };
  const severityLabels: Record<LogSeverity, string> = {
    info: '信息',
    warning: '警告',
    error: '错误',
    critical: '严重',
  };
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
      运维控制台
      <template #actions>
        <ElButton
          :loading="state.loading"
          :disabled="state.mutating"
          @click="runtime.load"
        >
          重新加载
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
      <h2>无法加载运维信息</h2><p>{{ state.error.message }}</p
      ><p v-if="state.error.requestId">
        请求编号：{{ state.error.requestId }}
      </p>
    </section>
    <div
      v-else-if="state.loading && state.overview === null"
      class="ops-state"
      role="status"
    >
      正在加载运维信息…
    </div>

    <ElTabs v-else-if="state.overview !== null" class="ops-tabs">
      <ElTabPane label="概览">
        <section class="ops-section" aria-labelledby="ops-health-heading">
          <h2 id="ops-health-heading"> 运行状态 </h2>
          <dl class="ops-facts">
            <div
              ><dt>健康状态</dt
              ><dd>{{ healthLabels[state.overview.health.status] }}</dd></div
            >
            <div>
              <dt>提交</dt
              ><dd class="mono">
                {{ state.overview.version.commit }}
              </dd>
            </div>
            <div>
              <dt>源码树</dt
              ><dd class="mono">
                {{ state.overview.version.tree }}
              </dd>
            </div>
            <div
              ><dt>构建时间</dt
              ><dd>{{ state.overview.version.builtAt }}</dd></div
            >
            <div
              ><dt>迁移</dt
              ><dd
                >{{ state.overview.migrations.applied }} /
                {{ state.overview.migrations.target }}</dd
              ></div
            >
            <div
              ><dt>升级</dt
              ><dd
                >{{ upgradeLabels[state.overview.upgrade.state] }} ({{
                  state.overview.upgrade.code
                }})</dd
              ></div
            >
          </dl>
          <div class="table-wrap">
            <table>
              <thead
                ><tr
                  ><th>检查项</th><th>状态</th><th>关键项</th><th>耗时</th></tr
                ></thead
              >
              <tbody>
                <tr
                  v-for="check in state.overview.health.checks"
                  :key="check.key"
                >
                  <td>{{ check.key }}</td
                  ><td>{{ check.status === 'up' ? '正常' : '异常' }}</td
                  ><td>{{ check.critical ? '是' : '否' }}</td
                  ><td>{{ check.latencyMs }} ms</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </ElTabPane>

      <ElTabPane label="备份与恢复">
        <section class="ops-section" aria-labelledby="ops-backup-heading">
          <h2 id="ops-backup-heading"> 备份与恢复验证 </h2>
          <div class="ops-controls">
            <label
              >服务提供者<select
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
              创建备份
            </ElButton>
          </div>
          <div class="ops-controls">
            <label
              >备份引用<ElInput
                v-model="backupReferenceKey"
                :disabled="state.mutating"
            /></label>
            <label
              >新目标<select
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
              恢复并验证
            </ElButton>
          </div>
          <section v-if="state.error" role="alert" class="inline-error">
            <p>{{ state.error.message }}</p
            ><p v-if="state.error.requestId">
              请求编号：{{ state.error.requestId }}
            </p>
          </section>
          <EmptyState
            v-if="state.tasks.length === 0"
            title="暂无运维任务"
            message="本次会话尚未提交备份或恢复验证任务。"
          />
          <div v-else class="table-wrap">
            <table>
              <thead
                ><tr
                  ><th>类型</th><th>状态</th><th>尝试次数</th><th>更新时间</th
                  ><th>操作</th></tr
                ></thead
              >
              <tbody>
                <tr v-for="task in state.tasks" :key="task.taskKey">
                  <td>{{
                    task.taskType === 'ops.backup.create'
                      ? '创建备份'
                      : '恢复验证'
                  }}</td
                  ><td>{{ taskLabels[task.status] }}</td
                  ><td>{{ task.attemptCount }} / {{ task.maxAttempts }}</td
                  ><td>{{ task.updatedAt }}</td
                  ><td>
                    <ElButton text @click="runtime.refreshTask(task)">
                      刷新
                    </ElButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </ElTabPane>

      <ElTabPane label="维护窗口">
        <section class="ops-section" aria-labelledby="ops-maintenance-heading">
          <h2 id="ops-maintenance-heading"> 维护窗口 </h2>
          <dl v-if="state.maintenance" class="ops-facts">
            <div
              ><dt>状态</dt
              ><dd>{{ maintenanceLabels[state.maintenance.state] }}</dd></div
            ><div
              ><dt>原因</dt><dd>{{ state.maintenance.reasonKey }}</dd></div
            ><div
              ><dt>开始时间</dt><dd>{{ state.maintenance.startsAt }}</dd></div
            ><div
              ><dt>结束时间</dt><dd>{{ state.maintenance.endsAt }}</dd></div
            ><div
              ><dt>版本</dt><dd>{{ state.maintenance.revision }}</dd></div
            >
          </dl>
          <div class="ops-controls">
            <label
              >原因<select v-model="reasonKey" :disabled="state.mutating"
                ><option
                  v-for="reason in runtime.maintenanceReasons"
                  :key="reason"
                  :value="reason"
                  >{{ reason }}</option
                ></select
              ></label
            >
            <label
              >开始时间<ElDatePicker
                v-model="startsAt"
                type="datetime"
                value-format="YYYY-MM-DDTHH:mm:ss.SSS[Z]"
            /></label>
            <label
              >结束时间<ElDatePicker
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
              {{ state.maintenance === null ? '安排维护' : '替换安排' }}
            </ElButton>
            <ElButton
              :disabled="
                !runtime.canMaintain() || state.mutating || !activeMaintenance
              "
              @click="runtime.closeMaintenance"
            >
              关闭维护窗口
            </ElButton>
          </div>
        </section>
      </ElTabPane>

      <ElTabPane label="运行事件">
        <section class="ops-section" aria-labelledby="ops-logs-heading">
          <h2 id="ops-logs-heading"> 结构化运行事件 </h2>
          <ForbiddenState
            v-if="!runtime.canReadLogs()"
            message="无权读取运行事件。"
          />
          <template v-else>
            <div class="ops-controls">
              <label
                >来源<select v-model="draftLogSource" aria-label="来源"
                  ><option
                    v-for="source in runtime.logSources"
                    :key="source"
                    :value="source"
                    >{{ source }}</option
                  ></select
                ></label
              ><label
                >级别<select v-model="draftLogSeverity" aria-label="级别"
                  ><option
                    v-for="severity in LOG_SEVERITIES"
                    :key="severity"
                    :value="severity"
                    >{{ severityLabels[severity] }}</option
                  ></select
                ></label
              ><ElButton :loading="state.logsLoading" @click="applyLogFilter">
                应用
              </ElButton>
            </div>
            <section v-if="state.logsError" role="alert" class="inline-error">
              <p>{{ state.logsError.message }}</p
              ><p v-if="state.logsError.requestId">
                请求编号：{{ state.logsError.requestId }}
              </p>
            </section>
            <EmptyState
              v-else-if="state.logs.length === 0 && !state.logsLoading"
              title="暂无运行事件"
              message="没有符合当前筛选条件的结构化事件。"
            />
            <div v-else class="table-wrap">
              <table>
                <thead
                  ><tr
                    ><th>时间</th><th>级别</th><th>组件</th><th>事件</th
                    ><th>出现次数</th></tr
                  ></thead
                ><tbody>
                  <tr
                    v-for="entry in state.logs"
                    :key="`${entry.eventKey}-${entry.occurredAt}`"
                  >
                    <td>{{ entry.occurredAt }}</td
                    ><td>{{ severityLabels[entry.severity] }}</td
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
              加载更多
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
